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
        // Las escalas de tamizaje (PHQ-9, GAD-7, Zung) son más simples: se
        // suman las respuestas y el total cae en un rango de gravedad. No
        // tienen baremos ni componentes.
        if (($definicion['tipo'] ?? '') === 'suma') {
            return self::corregirSuma($definicion, $respuestas, $contestados, $sinResponder);
        }
        // Las pautas de observación no tienen puntaje ni baremo: se mira,
        // por área, qué logra el niño y qué todavía no.
        if (($definicion['tipo'] ?? '') === 'cotejo') {
            return self::corregirCotejo($definicion, $respuestas, $contestados, $sinResponder);
        }
        // Verdadero/Falso contra una clave, repartido en subescalas, con
        // una escala de mentiras que puede invalidar todo (Coopersmith).
        if (($definicion['tipo'] ?? '') === 'clave') {
            return self::corregirClave($definicion, $respuestas, $contestados, $sinResponder);
        }
        // Varias escalas que se suman directo, algunas con ítems
        // invertidos, y un baremo por sexo y curso (STAIC).
        if (($definicion['tipo'] ?? '') === 'escalas_directas') {
            return self::corregirEscalasDirectas($definicion, $respuestas, $contestados, $sinResponder);
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
     * Escalas de tamizaje: se suma lo respondido y el total cae en un
     * rango de gravedad.
     *
     * Sirven para decidir si hace falta una evaluación más larga, no para
     * diagnosticar: por eso el resultado dice "sugiere" y no "presenta".
     *
     * Algunas tienen ítems que obligan a mirar aparte del total —el 9 del
     * PHQ-9 pregunta por ideas de muerte—: si se marcan, el resultado lo
     * dice arriba, porque un total bajo puede esconder una respuesta que
     * no se puede dejar pasar.
     */
    private static function corregirSuma(
        array $definicion, array $respuestas, int $contestados, array $sinResponder
    ): array {
        $inversos = array_flip($definicion['inversos'] ?? []);
        $min = (int) ($definicion['valorMinimo'] ?? 0);
        $max = $min + count($definicion['opciones'] ?? []) - 1;

        $total = 0;
        foreach ($definicion['items'] as $it) {
            $r = $respuestas[$it['n']] ?? null;
            if ($r === null || !is_numeric($r)) {
                continue;
            }
            $v = (int) $r;
            $total += isset($inversos[$it['n']]) ? ($min + $max - $v) : $v;
        }

        // Respuestas que se revisan una por una, aparte del total.
        $alertas = [];
        foreach ($definicion['itemsCriticos'] ?? [] as $crit) {
            $r = $respuestas[$crit['item']] ?? null;
            if ($r !== null && is_numeric($r) && (int) $r >= (int) $crit['desde']) {
                $alertas[] = ['item' => $crit['item'], 'texto' => $crit['aviso'],
                              'respuesta' => (int) $r];
            }
        }

        $escala = [
            'codigo'         => $definicion['codigo'],
            'nombre'         => $definicion['nombreEscala'] ?? $definicion['nombre'],
            'grupo'          => 'total',
            'pd'             => $total,
            'tb'             => $total,
            'percentil'      => null,
            'interpretacion' => self::rotulo($definicion['cortes']['total'] ?? [], $total),
            'maximo'         => (count($definicion['items']) * $max),
        ];

        return [
            'corregidoEn'   => date('c'),
            'nItems'        => (int) $definicion['nItems'],
            'contestados'   => $contestados,
            'sinResponder'  => $sinResponder,
            'completo'      => $sinResponder === [],
            'validez'       => [],
            'escalas'       => [$escala],
            'alertas'       => $alertas,
            'revisarAntes'  => ($definicion['revisarAntes'] ?? false) === true,
        ];
    }

    /**
     * Pautas de observación: las que marca el profesional mirando al niño.
     *
     * No dan puntaje ni percentil, y está bien que no lo den: son listas de
     * cotejo, no tests normados. Lo que dan es otra cosa, y es la que sirve
     * en la sesión siguiente:
     *
     *   · por área, cuánto de lo esperado para su edad ya logra;
     *   · la lista de lo que todavía no, que es el plan de trabajo;
     *   · lo que no se pudo evaluar, dicho como tal.
     *
     * Ese último punto es el que la hoja de papel no permitía. Con solo SÍ
     * y NO, un niño que ese día no quiso colaborar quedaba registrado como
     * que "no lo hace", y eso es falso: no se sabe. Acá el porcentaje se
     * calcula solo sobre lo que de verdad se observó.
     */
    /**
     * Verdadero/Falso contra una clave, repartido en subescalas.
     *
     * Es la forma del Inventario de Autoestima de Coopersmith y de varias
     * pruebas de su época: cada ítem acierta o no contra una clave fija,
     * los aciertos se agrupan por área, y el total se multiplica para
     * llevarlo a una escala de 0 a 100.
     *
     * Lo que distingue a esta familia es la **escala de validez** —en
     * Coopersmith, la de mentiras—. No mide autoestima: mide si el chico
     * contestó de verdad. Si pasa del corte, el inventario **no se
     * interpreta**, y eso tiene que decirse arriba y sin rodeos, no como
     * una nota al pie que se lee después de haber sacado conclusiones.
     *
     * Por eso acá la validez no es un adorno: cuando se pasa, las escalas
     * siguen calculándose —el profesional tiene derecho a verlas— pero
     * cada una viene marcada como no interpretable.
     */
    private static function corregirClave(
        array $definicion, array $respuestas, int $contestados, array $sinResponder
    ): array {
        $clave = $definicion['clave'] ?? [];          // n => 'V' | 'F'
        $factor = (int) ($definicion['factor'] ?? 1); // Coopersmith: ×2

        // Primero la escala de validez: de ella depende cómo se lee todo.
        $validez = [];
        $invalida = false;
        $vDef = $definicion['validez'] ?? null;
        if (is_array($vDef)) {
            $pdV = 0;
            foreach ($vDef['items'] as $n) {
                if (isset($respuestas[$n]) && (string) $respuestas[$n] === (string) ($clave[$n] ?? '')) {
                    $pdV++;
                }
            }
            $invalida = $pdV > (int) $vDef['maximo'];
            $validez[] = [
                'codigo' => (string) ($vDef['codigo'] ?? 'validez'),
                'nombre' => (string) ($vDef['nombre'] ?? 'Escala de validez'),
                'pd'     => $pdV,
                'maximo' => count($vDef['items']),
                'corte'  => (int) $vDef['maximo'],
                'alerta' => $invalida,
                'texto'  => $invalida
                    ? (string) ($vDef['siSupera'] ?? 'El inventario no es interpretable.')
                    : (string) ($vDef['siPasa'] ?? 'Respondió de forma confiable.'),
            ];
        }

        $escalas = [];
        $totalPd = 0;
        foreach ($definicion['subescalas'] ?? [] as $sub) {
            $pd = 0;
            foreach ($sub['items'] as $n) {
                if (isset($respuestas[$n]) && (string) $respuestas[$n] === (string) ($clave[$n] ?? '')) {
                    $pd++;
                }
            }
            $totalPd += $pd;
            $maximo = count($sub['items']);
            $escalas[] = [
                'codigo'         => (string) $sub['codigo'],
                'nombre'         => (string) $sub['nombre'],
                'grupo'          => 'subescala',
                'pd'             => $pd,
                'tb'             => $pd * $factor,
                'percentil'      => null,
                'maximo'         => $maximo * $factor,
                // Cuánto de esa área se afirma, para poder compararlas
                // entre sí aunque tengan distinto número de ítems.
                'porcentaje'     => $maximo > 0 ? (int) round($pd * 100 / $maximo) : 0,
                'interpretacion' => $invalida
                    ? 'No interpretable: la escala de validez quedó fuera de rango.'
                    : self::rotulo($definicion['cortes']['subescala'] ?? [],
                                   $maximo > 0 ? (int) round($pd * 100 / $maximo) : 0),
            ];
        }

        $total = $totalPd * $factor;
        $escalas[] = [
            'codigo'         => 'total',
            'nombre'         => (string) ($definicion['nombreEscala'] ?? 'Autoestima total'),
            'grupo'          => 'total',
            'pd'             => $totalPd,
            'tb'             => $total,
            'percentil'      => null,
            'maximo'         => (int) ($definicion['maximoTotal'] ?? ($totalPd * $factor)),
            'porcentaje'     => null,
            'interpretacion' => $invalida
                ? 'No interpretable: la escala de validez quedó fuera de rango.'
                : self::rotulo($definicion['cortes']['total'] ?? [], $total),
        ];

        $alertas = [];
        if ($invalida) {
            $alertas[] = ['item' => 0, 'respuesta' => null,
                          'texto' => (string) ($vDef['siSupera'] ?? 'Inventario no interpretable.')];
        }
        if ($sinResponder !== []) {
            $alertas[] = ['item' => 0, 'respuesta' => null,
                'texto' => 'Quedaron ' . count($sinResponder) . ' ítem(s) sin responder: '
                         . 'los puntajes salen más bajos de lo que corresponde.'];
        }

        return [
            'corregidoEn'   => date('c'),
            'nItems'        => (int) $definicion['nItems'],
            'contestados'   => $contestados,
            'sinResponder'  => $sinResponder,
            'completo'      => $sinResponder === [],
            'validez'       => $validez,
            'escalas'       => $escalas,
            'alertas'       => $alertas,
            'revisarAntes'  => ($definicion['revisarAntes'] ?? false) === true,
        ];
    }

    /**
     * Escalas que se suman directo, con ítems invertidos y baremo propio.
     *
     * Es la forma del STAIC: dos escalas de 20 ítems que se responden de
     * 1 a 3. En Ansiedad-Rasgo todos los ítems apuntan a la ansiedad y se
     * suman tal cual. En Ansiedad-Estado la mitad están redactados al
     * revés —en "Me siento seguro", responder *Mucho* significa MENOS
     * ansiedad— y esos se invierten antes de sumar.
     *
     * El baremo depende del sexo y del curso, así que el percentil solo
     * sale si esos dos datos están. Si faltan, se devuelve la puntuación
     * directa y se dice que falta el dato: una puntuación directa sin
     * percentil sigue sirviendo para comparar al niño consigo mismo en
     * dos momentos, que es la mitad de para lo que se usa esta prueba.
     *
     * Y una cosa más: el resultado lleva escrito qué ítems se invirtieron.
     * Una clave de corrección mal puesta no se nota mirando el puntaje —
     * se nota años después. Si está a la vista, se nota el primer día.
     */
    private static function corregirEscalasDirectas(
        array $definicion, array $respuestas, int $contestados, array $sinResponder
    ): array {
        $min = (int) ($definicion['valorMinimo'] ?? 1);
        $max = $min + count($definicion['opciones'] ?? []) - 1;
        $sexo  = strtoupper((string) ($definicion['_sexo'] ?? ''));
        $grupo = (string) ($definicion['_grupo'] ?? '');

        $escalas = [];
        $alertas = [];
        foreach ($definicion['escalas'] ?? [] as $e) {
            $inversos = array_flip($e['inversos'] ?? []);
            $pd = 0;
            $faltan = 0;
            foreach ($e['items'] as $n) {
                $r = $respuestas[$n] ?? null;
                if ($r === null || !is_numeric($r)) { $faltan++; continue; }
                $v = (int) $r;
                $pd += isset($inversos[$n]) ? ($min + $max - $v) : $v;
            }

            // Baremo: tabla de centiles por sexo y curso.
            $centil = null;
            $tabla = $e['baremos'][$grupo][$sexo] ?? null;
            if (is_array($tabla)) {
                foreach ($tabla as $fila) {
                    if ($pd >= (int) $fila['desde'] && $pd <= (int) $fila['hasta']) {
                        $centil = (int) $fila['centil'];
                        break;
                    }
                }
            }

            $escalas[] = [
                'codigo'         => (string) $e['codigo'],
                'nombre'         => (string) $e['nombre'],
                'grupo'          => 'escala',
                'pd'             => $pd,
                'tb'             => $pd,
                'percentil'      => $centil,
                'maximo'         => count($e['items']) * $max,
                'minimo'         => count($e['items']) * $min,
                'invertidos'     => array_values($e['inversos'] ?? []),
                'interpretacion' => $centil === null
                    ? ($tabla === null
                        ? 'Falta el sexo o el curso del paciente para poder dar el percentil.'
                        : 'Puntuación fuera de la tabla de baremos.')
                    : self::rotulo($definicion['cortes']['centil'] ?? [], $centil),
            ];

            if ($faltan > 0) {
                $alertas[] = ['item' => 0, 'respuesta' => null,
                    'texto' => sprintf('En %s quedaron %d ítem(s) sin responder. '
                        . 'El manual admite prorratear hasta dos; con más, el resultado '
                        . 'de esa escala no se debe usar.', $e['nombre'], $faltan)];
            }
        }

        return [
            'corregidoEn'   => date('c'),
            'nItems'        => (int) $definicion['nItems'],
            'contestados'   => $contestados,
            'sinResponder'  => $sinResponder,
            'completo'      => $sinResponder === [],
            'validez'       => [],
            'escalas'       => $escalas,
            'alertas'       => $alertas,
            'revisarAntes'  => ($definicion['revisarAntes'] ?? false) === true,
        ];
    }

    private static function corregirCotejo(
        array $definicion, array $respuestas, int $contestados, array $sinResponder
    ): array {
        $orden = [];
        $areas = [];
        foreach ($definicion['items'] as $it) {
            $area = (string) ($it['area'] ?? 'General');
            if (!isset($areas[$area])) {
                $orden[] = $area;
                $areas[$area] = ['total' => 0, 'logra' => 0, 'aunNo' => 0, 'noEval' => 0,
                                 'pendientes' => [], 'logrados' => [], 'sinVer' => []];
            }
            $areas[$area]['total']++;
            $r = $respuestas[$it['n']] ?? null;
            $fila = ['n' => $it['n'], 'texto' => $it['texto']];
            if ($r === '1') {
                $areas[$area]['logra']++;
                $areas[$area]['logrados'][] = $fila;
            } elseif ($r === '0') {
                $areas[$area]['aunNo']++;
                $areas[$area]['pendientes'][] = $fila;
            } else {
                $areas[$area]['noEval']++;
                $areas[$area]['sinVer'][] = $fila;
            }
        }

        $cortes = $definicion['cortes']['area'] ?? self::CORTES_COTEJO;

        $escalas = [];
        $detalle = [];
        $totalLogra = 0;
        $totalVisto = 0;
        foreach ($orden as $area) {
            $a = $areas[$area];
            $visto = $a['logra'] + $a['aunNo'];
            $pct = $visto === 0 ? null : (int) round($a['logra'] * 100 / $visto);
            $totalLogra += $a['logra'];
            $totalVisto += $visto;

            $escalas[] = [
                'codigo'         => self::codigoDeArea($area),
                'nombre'         => $area,
                'grupo'          => 'area',
                'pd'             => $a['logra'],
                'tb'             => $pct,
                'percentil'      => null,
                'maximo'         => $a['total'],
                'interpretacion' => $pct === null
                    ? 'No se pudo observar ningún ítem de esta área.'
                    : self::rotulo($cortes, (float) $pct),
            ];
            $detalle[] = ['area' => $area, 'porcentaje' => $pct] + $a;
        }

        $pctTotal = $totalVisto === 0 ? null : (int) round($totalLogra * 100 / $totalVisto);
        $escalas[] = [
            'codigo'         => 'total',
            'nombre'         => 'Total observado',
            'grupo'          => 'total',
            'pd'             => $totalLogra,
            'tb'             => $pctTotal,
            'percentil'      => null,
            'maximo'         => (int) $definicion['nItems'],
            'interpretacion' => $pctTotal === null ? null : self::rotulo($cortes, (float) $pctTotal),
        ];

        // Lo que sigue: lo que quedó en «aún no», que es exactamente lo que
        // hay que trabajar. El profesional ya no tiene que releer la hoja
        // entera para armar el plan.
        $porTrabajar = [];
        foreach ($detalle as $d) {
            foreach ($d['pendientes'] as $p) {
                $porTrabajar[] = ['area' => $d['area'], 'n' => $p['n'], 'texto' => $p['texto']];
            }
        }

        $noEvaluados = 0;
        foreach ($detalle as $d) {
            $noEvaluados += $d['noEval'];
        }

        return [
            'corregidoEn'   => date('c'),
            'tipo'          => 'cotejo',
            'nItems'        => (int) $definicion['nItems'],
            'contestados'   => $contestados,
            'sinResponder'  => $sinResponder,
            'completo'      => $sinResponder === [],
            'validez'       => [],
            'escalas'       => $escalas,
            'areas'         => $detalle,
            'porTrabajar'   => $porTrabajar,
            'noEvaluados'   => $noEvaluados,
            'observados'    => $totalVisto,
            'porcentaje'    => $pctTotal,
        ];
    }

    /**
     * Cómo se lee el porcentaje de logro de un área.
     *
     * Deliberadamente en palabras del trabajo y no en etiquetas
     * diagnósticas: una lista de cotejo dice qué hace el niño hoy, no lo
     * que el niño es.
     */
    private const CORTES_COTEJO = [
        ['hasta' => 39,  'texto' => 'Requiere apoyo en casi toda el área'],
        ['hasta' => 64,  'texto' => 'En proceso: la mitad del área por trabajar'],
        ['hasta' => 84,  'texto' => 'Avanzado, con algunos logros pendientes'],
        ['hasta' => 100, 'texto' => 'Logrado para su edad'],
    ];

    /** Un código corto y estable para el área, para las tablas del panel. */
    private static function codigoDeArea(string $area): string
    {
        $sin = iconv('UTF-8', 'ASCII//TRANSLIT', $area);
        $sin = preg_replace('/[^A-Za-z]/', '', (string) $sin) ?: 'AREA';
        return strtoupper(substr($sin, 0, 6));
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
