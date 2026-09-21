<?php
declare(strict_types=1);

namespace Centro\Pruebas;

use RuntimeException;

/**
 * Saca del Excel del ICE de BarOn (EQ-i) lo necesario para aplicarlo y
 * corregirlo: los 133 ítems, qué ítems suman en cada subescala y los
 * baremos peruanos.
 *
 * Se corrige distinto del MCMI-IV. Aquí el paciente responde de 1 a 5, cada
 * subescala suma sus ítems, y ese bruto se lleva a un cociente emocional
 * con la media y la desviación de la muestra peruana:
 *
 *     CE = ((bruto − media) / desviación) × 15 + 100
 *
 * El 100 es el promedio de la población, y cada 15 puntos es una desviación.
 *
 * --------------------------------------------------------------------
 *  LO QUE EL EXCEL NO TIENE: los ítems inversos
 * --------------------------------------------------------------------
 * Hay frases redactadas al revés ("Me resulta difícil disfrutar de la
 * vida"): en esas, responder 5 significa lo contrario que en las demás, y
 * antes de sumar hay que dar vuelta la respuesta (1↔5, 2↔4, 3 queda igual).
 *
 * En el Excel esa vuelta se hacía en una columna que quedó vacía, así que
 * la lista de cuáles son NO está en el archivo y no se puede deducir de él.
 * Sin esa lista la corrección da puntajes equivocados, así que mientras
 * `ITEMS_INVERSOS` esté vacío esta clase se niega a entregar la prueba.
 * La lista sale del manual del instrumento; una vez puesta aquí, la prueba
 * queda operativa.
 */
final class IceBaron
{
    private const N_ITEMS = 133;
    private const MIN_RESPUESTA = 1;
    private const MAX_RESPUESTA = 5;

    /**
     * Ítems que se puntúan al revés, según el manual.
     * Mientras esté vacío, la prueba no se puede cargar (ver extraer()).
     *
     * @var list<int>
     */
    private const ITEMS_INVERSOS = [];

    /** Fila de ENTRADA que suma cada escala => [código, nombre, grupo]. */
    private const ESCALAS = [
        162 => ['CM', 'Comprensión emocional de sí mismo', 'intrapersonal'],
        163 => ['SE', 'Seguridad (asertividad)',           'intrapersonal'],
        164 => ['AE', 'Autoestima',                        'intrapersonal'],
        165 => ['AR', 'Autorrealización',                  'intrapersonal'],
        166 => ['IN', 'Independencia',                     'intrapersonal'],
        167 => ['RI', 'Relaciones interpersonales',        'interpersonal'],
        175 => ['RS', 'Responsabilidad social',            'interpersonal'],
        171 => ['EM', 'Empatía',                           'interpersonal'],
        168 => ['SP', 'Solución de problemas',             'adaptabilidad'],
        169 => ['PR', 'Prueba de la realidad',             'adaptabilidad'],
        172 => ['FL', 'Flexibilidad',                      'adaptabilidad'],
        173 => ['TT', 'Tolerancia a la tensión',           'manejo_tension'],
        174 => ['CI', 'Control de impulsos',               'manejo_tension'],
        170 => ['OP', 'Optimismo',                         'animo_general'],
        176 => ['FE', 'Felicidad',                         'animo_general'],
        177 => ['IMP','Impresión positiva',                'validez'],
        178 => ['IMN','Impresión negativa',                'validez'],
    ];

    /** Los cinco componentes y el total, con las subescalas que los forman. */
    private const COMPONENTES = [
        'intrapersonal'  => ['CE Intrapersonal',          ['CM','SE','AE','AR','IN']],
        'interpersonal'  => ['CE Interpersonal',          ['RI','RS','EM']],
        'adaptabilidad'  => ['CE Adaptabilidad',          ['SP','PR','FL']],
        'manejo_tension' => ['CE Manejo de la tensión',   ['TT','CI']],
        'animo_general'  => ['CE Estado de ánimo general',['OP','FE']],
    ];

    /** Nombre del baremo en la hoja PROCESO => código de la escala. */
    private const BAREMOS = [
        'Comprensión de Sí Mismo' => 'CM',  'Seguridad (Asertividad)' => 'SE',
        'Autocoestima' => 'AE',              'Autorealización' => 'AR',
        'Independencia' => 'IN',             'Relaciones Interpersonales' => 'RI',
        'Solución de Problemas' => 'SP',     'Prueba de la Realidad' => 'PR',
        'Optimismo' => 'OP',                 'Empatía' => 'EM',
        'Flexibilidad' => 'FL',              'Responsabilidad Social' => 'RS',
        'Tolerancia a la Tensión (Estrés)' => 'TT', 'Control de Impulsos' => 'CI',
        'Felicidad' => 'FE',                 'Impresión Positiva' => 'IMP',
        'Impresión Negativa' => 'IMN',       'Intrapersonal' => 'intrapersonal',
        'Interpersonal' => 'interpersonal',  'Adaptabilidad' => 'adaptabilidad',
        'Manejo de Tensión (Estrés)' => 'manejo_tension',
        'Estado de Ánimo General' => 'animo_general',
        'TOTAL CE' => 'total',
    ];

    public static function extraer(string $rutaExcel, array $itemsInversos = []): array
    {
        $inversos = $itemsInversos !== [] ? $itemsInversos : self::ITEMS_INVERSOS;
        if ($inversos === []) {
            throw new RuntimeException(
                "Falta la lista de ítems inversos del ICE BarOn.\n"
                . "  El Excel no la trae: la columna donde se daba vuelta la respuesta\n"
                . "  quedó vacía, así que no se puede deducir del archivo.\n"
                . "  Sin ella los puntajes salen equivocados y la prueba no se carga.\n"
                . "  Sale del manual del instrumento; pásala y queda operativa."
            );
        }
        foreach ($inversos as $i) {
            if (!is_int($i) || $i < 1 || $i > self::N_ITEMS) {
                throw new RuntimeException(
                    "El ítem inverso «{$i}» no existe: van del 1 al " . self::N_ITEMS . '.'
                );
            }
        }

        $x = new LectorExcel($rutaExcel);
        $hojas = $x->hojas();
        foreach (['ENTRADA', 'PROCESO'] as $n) {
            if (empty($hojas[$n])) {
                throw new RuntimeException("Al Excel le falta la hoja «$n».");
            }
        }
        $ent = $x->celdas($hojas['ENTRADA']);
        $pro = $x->celdas($hojas['PROCESO']);

        [$items, $filaDe, $itemDe] = self::items($ent);
        $baremos = self::baremos($pro);

        return [
            'codigo'   => 'ice_baron',
            'nombre'   => 'Inventario de Cociente Emocional de BarOn',
            'siglas'   => 'ICE BarOn',
            'autor'    => 'Reuven BarOn · adaptación peruana',
            'nItems'   => self::N_ITEMS,
            'tipo'     => 'likert',
            'opciones' => [
                ['valor' => '1', 'etiqueta' => 'Rara vez o nunca'],
                ['valor' => '2', 'etiqueta' => 'Pocas veces'],
                ['valor' => '3', 'etiqueta' => 'Algunas veces'],
                ['valor' => '4', 'etiqueta' => 'Muchas veces'],
                ['valor' => '5', 'etiqueta' => 'Muy frecuentemente o siempre'],
            ],
            'items'        => $items,
            'inversos'     => array_values($inversos),
            'escalas'      => self::escalas($ent, $itemDe),
            'componentes'  => self::componentes($ent, $itemDe),
            'baremos'      => $baremos,
            'cortes'       => self::cortes(),
        ];
    }

    /** Los 133 enunciados, y de paso el mapa fila↔ítem que usa el Excel. */
    private static function items(array $ent): array
    {
        $items = [];
        $filaDe = [];
        $itemDe = [];
        for ($f = 1; $f <= 160; $f++) {
            $a = $ent[$f]['A']['v'] ?? null;
            if ($a === null || !is_numeric($a)) {
                continue;
            }
            $n = (int) $a;
            $texto = trim((string) ($ent[$f]['B']['v'] ?? ''));
            // Algunos enunciados siguen en la fila de abajo, sin número.
            $sigue = trim((string) ($ent[$f + 1]['B']['v'] ?? ''));
            if ($sigue !== '' && ($ent[$f + 1]['A']['v'] ?? null) === null) {
                $texto .= ' ' . $sigue;
            }
            if ($texto === '') {
                throw new RuntimeException("El Excel no tiene texto para el ítem $n.");
            }
            $items[] = ['n' => $n, 'texto' => $texto];
            $filaDe[$n] = $f;
            $itemDe[$f] = $n;
        }
        if (count($items) !== self::N_ITEMS) {
            throw new RuntimeException(
                'Esperaba ' . self::N_ITEMS . ' ítems y encontré ' . count($items) . '.'
            );
        }
        return [$items, $filaDe, $itemDe];
    }

    /**
     * Qué ítems suma cada subescala. Está escrito en las fórmulas de la
     * columna I de ENTRADA, por ejemplo  =SUM(H20+H22+H37+…)  donde cada
     * H apunta a la fila de un ítem.
     */
    private static function escalas(array $ent, array $itemDe): array
    {
        $out = [];
        foreach (self::ESCALAS as $fila => [$cod, $nombre, $grupo]) {
            $f = (string) ($ent[$fila]['I']['f'] ?? '');
            if ($f === '') {
                throw new RuntimeException("El Excel no trae la fórmula de la escala $cod.");
            }
            preg_match_all('/H(\d+)/', $f, $m);
            $items = [];
            foreach ($m[1] as $filaItem) {
                $n = $itemDe[(int) $filaItem] ?? null;
                if ($n === null) {
                    throw new RuntimeException("La escala $cod apunta a la fila $filaItem, que no es un ítem.");
                }
                $items[] = $n;
            }
            if ($items === []) {
                throw new RuntimeException("No pude leer los ítems de la escala $cod.");
            }
            sort($items);
            $out[] = ['codigo' => $cod, 'nombre' => $nombre, 'grupo' => $grupo, 'items' => $items];
        }
        return $out;
    }

    /**
     * Los cinco componentes y el total.
     *
     * Ojo con las restas: hay ítems que pertenecen a dos subescalas a la
     * vez, y al sumar los totales se contarían dos veces. El Excel los
     * descuenta y aquí se hace lo mismo, leyendo de sus fórmulas cuáles son.
     */
    private static function componentes(array $ent, array $itemDe): array
    {
        $leerRestas = static function (string $formula, array $itemDe, string $prefijo): array {
            // "-(H24+H34+…)" en ENTRADA, "-(ENTRADA!J39+…)" en PROCESO
            if (!preg_match('/-\s*\((.+)\)\s*$/', $formula, $m)) {
                return [];
            }
            preg_match_all('/' . $prefijo . '(\d+)/', $m[1], $mm);
            $r = [];
            foreach ($mm[1] as $fila) {
                $n = $itemDe[(int) $fila] ?? null;
                if ($n !== null) {
                    $r[] = $n;
                }
            }
            sort($r);
            return $r;
        };

        $out = [];
        foreach (self::COMPONENTES as $clave => [$nombre, $subescalas]) {
            $out[] = ['codigo' => $clave, 'nombre' => $nombre, 'subescalas' => $subescalas,
                      'descontar' => []];
        }
        // Interpersonal descuenta ítems compartidos (PROCESO!D13).
        // Sus referencias son a ENTRADA!J<fila>, con la fila del ítem.
        // El total general descuenta los suyos (ENTRADA!I179).
        $total = [
            'codigo' => 'total', 'nombre' => 'Cociente emocional total',
            'subescalas' => ['CM','SE','AE','AR','IN','RI','RS','EM','SP','PR','FL','TT','CI','OP','FE'],
            'descontar' => $leerRestas((string) ($ent[179]['I']['f'] ?? ''), $itemDe, 'H'),
        ];
        $out[] = $total;
        return $out;
    }

    /** Media y desviación de la muestra peruana, de la hoja PROCESO. */
    private static function baremos(array $pro): array
    {
        $out = [];
        for ($f = 7; $f <= 40; $f++) {
            $nombre = $pro[$f]['L']['v'] ?? null;
            $media  = $pro[$f]['M']['v'] ?? null;
            $ds     = $pro[$f]['N']['v'] ?? null;
            if ($nombre === null || $media === null || $ds === null) {
                continue;
            }
            $cod = self::BAREMOS[trim((string) $nombre)] ?? null;
            if ($cod === null || (float) $ds <= 0) {
                continue;   // "I-CE" es un título, y el índice de inconsistencia no se usa aquí
            }
            $out[$cod] = ['media' => (float) $media, 'ds' => (float) $ds];
        }
        // Todas las subescalas y componentes necesitan su baremo: sin él no
        // hay cociente, y entregar una escala a medias sería peor que nada.
        foreach (self::ESCALAS as [$cod]) {
            if (!isset($out[$cod])) {
                throw new RuntimeException("Falta el baremo de la escala $cod.");
            }
        }
        foreach (array_keys(self::COMPONENTES) as $cod) {
            if (!isset($out[$cod])) {
                throw new RuntimeException("Falta el baremo del componente $cod.");
            }
        }
        if (!isset($out['total'])) {
            throw new RuntimeException('Falta el baremo del cociente emocional total.');
        }
        return $out;
    }

    /** Las categorías con que se lee un cociente emocional. */
    private static function cortes(): array
    {
        return [
            'ce' => [
                ['hasta' => 69,  'texto' => 'Marcadamente baja: capacidad emocional muy por debajo del promedio'],
                ['hasta' => 79,  'texto' => 'Muy baja: capacidad emocional extremadamente por mejorar'],
                ['hasta' => 89,  'texto' => 'Baja: capacidad emocional por debajo del promedio'],
                ['hasta' => 109, 'texto' => 'Promedio: capacidad emocional adecuada'],
                ['hasta' => 119, 'texto' => 'Alta: capacidad emocional bien desarrollada'],
                ['hasta' => 129, 'texto' => 'Muy alta: capacidad emocional muy desarrollada'],
                ['hasta' => 999, 'texto' => 'Marcadamente alta: capacidad emocional inusualmente desarrollada'],
            ],
        ];
    }

    public static function minRespuesta(): int { return self::MIN_RESPUESTA; }
    public static function maxRespuesta(): int { return self::MAX_RESPUESTA; }
}
