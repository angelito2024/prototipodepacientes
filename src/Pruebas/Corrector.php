<?php
declare(strict_types=1);

namespace Centro\Pruebas;

/**
 * Corrige un protocolo a partir de la definición guardada de la prueba.
 *
 * Corrige el servidor, nunca el navegador: la clave de un test psicológico
 * no puede viajar al equipo del paciente, y un puntaje que llega calculado
 * desde fuera no se puede creer.
 *
 * Verificado contra el Excel del propio psicólogo: con el mismo protocolo
 * da las mismas 25 puntuaciones directas, las mismas tasas base, los mismos
 * percentiles y los mismos cinco índices de validez.
 */
final class Corrector
{
    /**
     * @param array               $definicion  lo que guarda la tabla `pruebas`
     * @param array<int,string>   $respuestas  ítem => 'V' | 'F'
     */
    public static function corregir(array $definicion, array $respuestas): array
    {
        $nItems     = (int) ($definicion['nItems'] ?? 0);
        $contestados = 0;
        $sinResponder = [];
        for ($i = 1; $i <= $nItems; $i++) {
            if (isset($respuestas[$i]) && $respuestas[$i] !== '') {
                $contestados++;
            } else {
                $sinResponder[] = $i;
            }
        }

        // Las pruebas de escala (1 a 5) se puntúan distinto de las de
        // Verdadero/Falso: cada escala suma respuestas y el bruto se lleva
        // a un cociente con la media y la desviación de la muestra.
        if (($definicion['tipo'] ?? '') === 'likert') {
            return self::corregirLikert($definicion, $respuestas, $contestados, $sinResponder);
        }

        $escalas = [];
        foreach ($definicion['escalas'] as $e) {
            $pd = 0;
            foreach ($e['prototipicos'] as $it) {
                if (($respuestas[$it['item']] ?? null) === $it['resp']) {
                    $pd += (int) $e['peso'];
                }
            }
            foreach ($e['secundarios'] as $it) {
                if (($respuestas[$it['item']] ?? null) === $it['resp']) {
                    $pd += 1;
                }
            }
            $tb  = $definicion['baremos']['pdATb'][$e['codigo']][$pd] ?? null;
            $pct = $tb === null ? null : ($definicion['baremos']['tbAPct'][$e['codigo']][$tb] ?? null);

            $grupo = $e['grupo'] === 'sindrome' || $e['grupo'] === 'grave' ? 'sindrome' : 'personalidad';
            $escalas[] = [
                'codigo'         => $e['codigo'],
                'nombre'         => $e['nombre'],
                'grupo'          => $e['grupo'],
                'pd'             => $pd,
                'tb'             => $tb,
                'percentil'      => $pct,
                'interpretacion' => $tb === null ? null
                    : self::rotulo($definicion['cortes'][$grupo] ?? [], $tb),
            ];
        }

        return [
            'corregidoEn'   => date('c'),
            'nItems'        => $nItems,
            'contestados'   => $contestados,
            'sinResponder'  => $sinResponder,
            'completo'      => $sinResponder === [],
            'validez'       => self::validez($definicion, $respuestas),
            'escalas'       => $escalas,
        ];
    }

    /**
     * Pruebas de escala, como el ICE BarOn.
     *
     * Cada subescala suma las respuestas de sus ítems, dando vuelta las de
     * los ítems inversos —los redactados al revés, donde un 5 significa lo
     * contrario que en el resto—. Ese bruto se convierte en cociente:
     *
     *     CE = ((bruto − media) / desviación) × 15 + 100
     *
     * Si la prueba está cargada sin la clave de inversión, se devuelven las
     * respuestas y nada más: no se inventan puntajes. El protocolo queda
     * guardado y se corrige entero cuando la clave esté.
     */
    private static function corregirLikert(
        array $definicion, array $respuestas, int $contestados, array $sinResponder
    ): array {
        $base = [
            'corregidoEn'  => date('c'),
            'nItems'       => (int) $definicion['nItems'],
            'contestados'  => $contestados,
            'sinResponder' => $sinResponder,
            'completo'     => $sinResponder === [],
            'validez'      => [],
            'escalas'      => [],
        ];

        if (($definicion['correccionPendiente'] ?? false) === true) {
            return $base + [
                'sinCorregir' => true,
                'motivo'      => 'Las respuestas quedaron guardadas, pero esta prueba todavía '
                               . 'no se puede puntuar: falta la clave de ítems inversos. '
                               . 'En cuanto se cargue, este protocolo se corrige solo.',
            ];
        }

        $inversos = array_flip($definicion['inversos'] ?? []);
        $min = 1;
        $max = count($definicion['opciones'] ?? []) ?: 5;
        $valorDe = static function (int $item) use ($respuestas, $inversos, $min, $max): ?int {
            $r = $respuestas[$item] ?? null;
            if ($r === null || !is_numeric($r)) {
                return null;
            }
            $v = (int) $r;
            // Dar vuelta: con 5 opciones, 1↔5, 2↔4 y el 3 queda igual.
            return isset($inversos[$item]) ? ($min + $max - $v) : $v;
        };

        // --- Subescalas ---
        $bruto = [];
        $escalas = [];
        foreach ($definicion['escalas'] as $e) {
            $suma = 0;
            $faltan = 0;
            foreach ($e['items'] as $item) {
                $v = $valorDe($item);
                if ($v === null) { $faltan++; continue; }
                $suma += $v;
            }
            $bruto[$e['codigo']] = $suma;
            $escalas[] = [
                'codigo' => $e['codigo'], 'nombre' => $e['nombre'], 'grupo' => $e['grupo'],
                'pd' => $suma, 'sinResponder' => $faltan,
            ] + self::cociente($definicion, $e['codigo'], $suma);
        }

        // --- Componentes y total ---
        foreach ($definicion['componentes'] ?? [] as $c) {
            $suma = 0;
            foreach ($c['subescalas'] as $cod) {
                $suma += $bruto[$cod] ?? 0;
            }
            // Hay ítems que puntúan en dos subescalas: se contarían dos
            // veces al sumar el componente, así que se descuentan una.
            foreach ($c['descontar'] ?? [] as $item) {
                $v = $valorDe($item);
                if ($v !== null) {
                    $suma -= $v;
                }
            }
            $escalas[] = [
                'codigo' => $c['codigo'], 'nombre' => $c['nombre'],
                'grupo'  => $c['codigo'] === 'total' ? 'total' : 'componente',
                'pd'     => $suma, 'sinResponder' => 0,
            ] + self::cociente($definicion, $c['codigo'], $suma);
        }

        // Cuidado: "+" entre arrays conserva la clave que ya estaba, así que
        // aquí no sirve para reemplazar 'escalas'.
        $base['escalas'] = $escalas;
        return $base;
    }

    /** Lleva un puntaje bruto a cociente con los baremos de la prueba. */
    private static function cociente(array $definicion, string $codigo, int $bruto): array
    {
        $b = $definicion['baremos'][$codigo] ?? null;
        if ($b === null || (float) $b['ds'] <= 0) {
            return ['tb' => null, 'percentil' => null, 'interpretacion' => null];
        }
        $ce = (($bruto - (float) $b['media']) / (float) $b['ds']) * 15 + 100;
        $ce = (int) round($ce);
        return [
            'tb'             => $ce,   // el panel lo muestra en la columna del puntaje
            'percentil'      => null,  // el BarOn no trae tabla de percentiles
            'interpretacion' => self::rotulo($definicion['cortes']['ce'] ?? [], $ce),
        ];
    }

    private static function validez(array $definicion, array $respuestas): array
    {
        $v = $definicion['validez'];
        $cortes = $definicion['cortes']['validez'] ?? [];

        $contar = static function (array $items) use ($respuestas): int {
            $n = 0;
            foreach ($items as $it) {
                if (($respuestas[$it['item']] ?? null) === $it['resp']) {
                    $n++;
                }
            }
            return $n;
        };

        $out = [];

        // V · Invalidez. Son frases que nadie contesta que sí salvo que no
        // esté leyendo ("El año pasado aparecí en la portada de revistas").
        $pd = $contar($v['V']['items']);
        $out[] = ['codigo' => 'V', 'nombre' => $v['V']['nombre'], 'pd' => $pd, 'tb' => $pd,
                  'interpretacion' => self::rotulo($cortes['V'] ?? [], $pd)];

        // W · Inconsistencia. Un punto por cada par contestado al revés;
        // medio punto si una de las dos frases quedó en blanco.
        $w = 0.0;
        foreach ($v['W']['pares'] as $par) {
            $nV = 0; $nF = 0;
            foreach ($par as $it) {
                $r = $respuestas[$it['item']] ?? null;
                if ($r === 'V') $nV++;
                if ($r === 'F') $nF++;
            }
            $w += (($nV === 1 ? 1 : 0) + ($nF === 1 ? 1 : 0)) / 2;
        }
        $out[] = ['codigo' => 'W', 'nombre' => $v['W']['nombre'], 'pd' => $w, 'tb' => $w,
                  'interpretacion' => self::rotulo($cortes['W'] ?? [], $w)];

        // X, Y, Z sí tienen tasa base propia.
        foreach (['X', 'Y', 'Z'] as $cod) {
            $pd = $contar($v[$cod]['items']);
            $tb = $v[$cod]['tb'][$pd] ?? null;
            $out[] = ['codigo' => $cod, 'nombre' => $v[$cod]['nombre'], 'pd' => $pd, 'tb' => $tb,
                      'interpretacion' => $tb === null ? null : self::rotulo($cortes[$cod] ?? [], $tb)];
        }
        return $out;
    }

    private static function rotulo(array $cortes, float $valor): ?string
    {
        foreach ($cortes as $c) {
            if ($valor <= (float) $c['hasta']) {
                return (string) $c['texto'];
            }
        }
        return null;
    }
}
