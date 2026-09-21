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
