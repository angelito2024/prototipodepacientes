<?php
declare(strict_types=1);

namespace Centro\Pruebas;

use RuntimeException;

/**
 * Saca del Excel del MCMI-IV todo lo que hace falta para aplicar la prueba
 * y corregirla: los 195 ítems, la clave de cada escala y las tablas de
 * baremos.
 *
 * La clave no está escrita en ninguna celda: vive dentro de las fórmulas de
 * la hoja APLICACION. Por ejemplo, la escala Esquizoide se calcula así:
 *
 *   =(K17*(C18+C27+C55+...) + C29+C36+D42+...)
 *
 * donde K17 es el peso (2), la columna C es "respondió Verdadero", la D es
 * "respondió Falso", y la fila menos 12 es el número de ítem. Lo que va
 * multiplicado por el peso son los ítems prototípicos, que valen 2 puntos;
 * el resto vale 1. Eso es exactamente lo que se lee abajo.
 *
 * Esto corre una sola vez, al cargar la prueba. Después el sistema trabaja
 * con la definición guardada y no vuelve a tocar el Excel.
 */
final class Mcmi4
{
    /** Fila de la hoja APLICACION donde empieza el ítem 1. */
    private const FILA_ITEM_1 = 13;
    private const N_ITEMS     = 195;

    /** Fila de APLICACION que calcula cada escala => [código, nombre, grupo]. */
    private const ESCALAS = [
        17 => ['1',  'Esquizoide',              'personalidad'],
        18 => ['2A', 'Evitativo',               'personalidad'],
        19 => ['2B', 'Melancólico',             'personalidad'],
        20 => ['3',  'Dependiente',             'personalidad'],
        21 => ['4A', 'Histriónico',             'personalidad'],
        22 => ['4B', 'Tempestuoso',             'personalidad'],
        23 => ['5',  'Narcisista',              'personalidad'],
        24 => ['6A', 'Antisocial',              'personalidad'],
        25 => ['6B', 'Sádico',                  'personalidad'],
        26 => ['7',  'Compulsivo',              'personalidad'],
        27 => ['8A', 'Negativista',             'personalidad'],
        28 => ['8B', 'Masoquista',              'personalidad'],
        29 => ['S',  'Esquizotípico',           'patologia'],
        30 => ['C',  'Límite',                  'patologia'],
        31 => ['P',  'Paranoide',               'patologia'],
        35 => ['A',  'Ansiedad Generalizada',   'sindrome'],
        36 => ['H',  'Síntomas Somáticos',      'sindrome'],
        37 => ['N',  'Espectro Bipolar',        'sindrome'],
        38 => ['D',  'Depresión Persistente',   'sindrome'],
        39 => ['B',  'Consumo de alcohol',      'sindrome'],
        40 => ['T',  'Consumo de Drogas',       'sindrome'],
        41 => ['R',  'Estrés Postraumático',    'sindrome'],
        42 => ['SS', 'Espectro Esquizofrénico', 'grave'],
        43 => ['CC', 'Depresión Mayor',         'grave'],
        44 => ['PP', 'Delirante',               'grave'],
    ];

    /** Columna de la tabla PUNT-DIRECTA donde está la tasa base de cada escala. */
    private const COL_PD_TB = [
        '1'=>'D','2A'=>'E','2B'=>'F','3'=>'G','4A'=>'H','4B'=>'I','5'=>'J','6A'=>'K',
        '6B'=>'L','7'=>'M','8A'=>'N','8B'=>'O','S'=>'P','C'=>'Q','P'=>'R','A'=>'S',
        'H'=>'T','N'=>'U','D'=>'V','B'=>'W','T'=>'X','R'=>'Y','SS'=>'Z','CC'=>'AA','PP'=>'AB',
    ];
    /** Lo mismo en la tabla PERCENTIL2, que va de tasa base a percentil. */
    private const COL_TB_PCT = [
        '1'=>'B','2A'=>'C','2B'=>'D','3'=>'E','4A'=>'F','4B'=>'G','5'=>'H','6A'=>'I',
        '6B'=>'J','7'=>'K','8A'=>'L','8B'=>'M','S'=>'N','C'=>'O','P'=>'P','A'=>'Q',
        'H'=>'R','N'=>'S','D'=>'T','B'=>'U','T'=>'V','R'=>'W','SS'=>'X','CC'=>'Y','PP'=>'Z',
    ];

    public static function extraer(string $rutaExcel): array
    {
        $x = new LectorExcel($rutaExcel);
        $hojas = $x->hojas();
        foreach (['APLICACION', 'PUNT-DIRECTA', 'PERCENTIL2', 'RESULTADOS'] as $n) {
            if (empty($hojas[$n])) {
                throw new RuntimeException("Al Excel le falta la hoja «$n».");
            }
        }
        $ap  = $x->celdas($hojas['APLICACION']);
        $tpd = $x->celdas($hojas['PUNT-DIRECTA']);
        $tpc = $x->celdas($hojas['PERCENTIL2']);
        $res = $x->celdas($hojas['RESULTADOS']);

        return [
            'codigo'     => 'mcmi4',
            'nombre'     => 'Inventario Clínico Multiaxial de Millon IV',
            'siglas'     => 'MCMI-IV',
            'autor'      => 'Theodore Millon',
            'nItems'     => self::N_ITEMS,
            'opciones'   => [
                ['valor' => 'V', 'etiqueta' => 'Verdadero'],
                ['valor' => 'F', 'etiqueta' => 'Falso'],
            ],
            'items'      => self::items($ap),
            'escalas'    => self::escalas($ap),
            'validez'    => self::validez($ap, $res),
            'baremos'    => [
                'pdATb'  => self::tabla($tpd, 'B', self::COL_PD_TB, 4, 33),
                'tbAPct' => self::tabla($tpc, 'A', self::COL_TB_PCT, 4, 119),
            ],
            'cortes'     => self::cortes(),
        ];
    }

    private static function items(array $ap): array
    {
        $items = [];
        for ($i = 1; $i <= self::N_ITEMS; $i++) {
            $texto = trim((string) ($ap[self::FILA_ITEM_1 + $i - 1]['B']['v'] ?? ''));
            if ($texto === '') {
                throw new RuntimeException("El Excel no tiene texto para el ítem $i.");
            }
            $items[] = ['n' => $i, 'texto' => $texto];
        }
        return $items;
    }

    /**
     * Traduce una fórmula de Excel a la lista de ítems que puntúan.
     * Devuelve los prototípicos (los que van multiplicados por el peso)
     * separados de los demás.
     */
    private static function clave(string $formula): array
    {
        $resto = $formula;
        $proto = '';
        if (preg_match('/K\d+\s*\*\s*\(([^()]*)\)/', $resto, $m)) {
            $proto = $m[1];
            $resto = str_replace($m[0], '', $resto);
        }
        $leer = static function (string $s): array {
            preg_match_all('/([CD])(\d+)/', $s, $mm, PREG_SET_ORDER);
            $r = [];
            foreach ($mm as $x) {
                $r[] = [
                    'item' => (int) $x[2] - (self::FILA_ITEM_1 - 1),
                    'resp' => $x[1] === 'C' ? 'V' : 'F',
                ];
            }
            return $r;
        };
        return ['prototipicos' => $leer($proto), 'secundarios' => $leer($resto)];
    }

    private static function escalas(array $ap): array
    {
        $out = [];
        foreach (self::ESCALAS as $fila => [$cod, $nombre, $grupo]) {
            $f = (string) ($ap[$fila]['E']['f'] ?? '');
            if ($f === '') {
                throw new RuntimeException("El Excel no trae la fórmula de la escala $cod.");
            }
            $c = self::clave($f);
            if ($c['prototipicos'] === []) {
                throw new RuntimeException("No pude leer la clave de la escala $cod.");
            }
            $out[] = [
                'codigo' => $cod, 'nombre' => $nombre, 'grupo' => $grupo, 'peso' => 2,
            ] + $c;
        }
        return $out;
    }

    private static function validez(array $ap, array $res): array
    {
        $soloSec = static fn(string $f): array => self::clave($f)['secundarios'];

        // Pares de frases equivalentes. Si el paciente contesta distinto a
        // las dos, algo no cuadra: o no está leyendo, o responde al azar.
        // En el Excel cada par ocupa dos columnas (una cuenta los "V" y la
        // de al lado los "F"); basta leer la impar.
        $pares = [];
        foreach ([49, 53, 57] as $fila) {
            foreach (['E','G','I','K','M','O','Q','S','U'] as $col) {
                $f = $ap[$fila][$col]['f'] ?? null;
                if ($f === null || !preg_match('/^([CD])(\d+)\+([CD])(\d+)$/', $f, $m)) {
                    continue;
                }
                $pares[] = [
                    ['item' => (int) $m[2] - (self::FILA_ITEM_1 - 1), 'resp' => $m[1] === 'C' ? 'V' : 'F'],
                    ['item' => (int) $m[4] - (self::FILA_ITEM_1 - 1), 'resp' => $m[3] === 'C' ? 'V' : 'F'],
                ];
            }
        }

        // Tasa base de Sinceridad, Deseabilidad y Devaluación: hoja RESULTADOS.
        $tabla = static function (array $res, string $col, int $hasta): array {
            $t = [];
            for ($f = 3; $f <= $hasta; $f++) {
                $pd = $res[$f]['A']['v'] ?? null;
                $tb = $res[$f][$col]['v'] ?? null;
                if ($pd === null || $tb === null) {
                    continue;
                }
                $t[(int) $pd] = (int) $tb;
            }
            return $t;
        };

        return [
            'V' => ['nombre' => 'Invalidez',          'items' => $soloSec((string) ($ap[46]['E']['f'] ?? ''))],
            'X' => ['nombre' => 'Sinceridad',         'items' => $soloSec((string) ($ap[63]['F']['f'] ?? '')),
                    'tb' => $tabla($res, 'B', 117)],
            'Y' => ['nombre' => 'Deseabilidad Social','items' => $soloSec((string) ($ap[64]['F']['f'] ?? '')),
                    'tb' => $tabla($res, 'C', 27)],
            'Z' => ['nombre' => 'Devaluación',        'items' => $soloSec((string) ($ap[65]['F']['f'] ?? '')),
                    'tb' => $tabla($res, 'D', 33)],
            'W' => ['nombre' => 'Inconsistencia',     'pares' => $pares],
        ];
    }

    private static function tabla(array $hoja, string $colEntrada, array $cols, int $desde, int $hasta): array
    {
        $out = [];
        foreach ($cols as $cod => $col) {
            $t = [];
            for ($f = $desde; $f <= $hasta; $f++) {
                $e = $hoja[$f][$colEntrada]['v'] ?? null;
                $s = $hoja[$f][$col]['v'] ?? null;
                if ($e === null || $s === null) {
                    continue;
                }
                $t[(int) $e] = (int) $s;
            }
            if ($t === []) {
                throw new RuntimeException("La tabla de baremos de la escala $cod salió vacía.");
            }
            $out[$cod] = $t;
        }
        return $out;
    }

    /** Los mismos cortes que usa el Excel para poner el rótulo. */
    private static function cortes(): array
    {
        return [
            'personalidad' => [
                ['hasta' => 59, 'texto' => 'Funcional'],
                ['hasta' => 74, 'texto' => 'Estilo de personalidad menos funcional'],
                ['hasta' => 84, 'texto' => 'Tipo de personalidad clínicamente significativa'],
                ['hasta' => 999,'texto' => 'Trastorno de la personalidad'],
            ],
            'sindrome' => [
                ['hasta' => 74, 'texto' => 'Sin presencia del síndrome clínico'],
                ['hasta' => 84, 'texto' => 'Síndrome presente'],
                ['hasta' => 999,'texto' => 'Síndrome prominente'],
            ],
            'validez' => [
                'V' => [['hasta' => 0, 'texto' => 'Válido'], ['hasta' => 999, 'texto' => 'Cuestionable']],
                'W' => [['hasta' => 8, 'texto' => 'Aceptable'], ['hasta' => 19, 'texto' => 'Cuestionable'],
                        ['hasta' => 999, 'texto' => 'Prueba inválida']],
                'X' => [['hasta' => 6, 'texto' => 'Inválido'],
                        ['hasta' => 20, 'texto' => 'Posible minimización de los síntomas'],
                        ['hasta' => 60, 'texto' => 'Aceptable'],
                        ['hasta' => 114,'texto' => 'Posible exageración de los síntomas'],
                        ['hasta' => 999,'texto' => 'Inválido']],
                'Y' => [['hasta' => 74, 'texto' => 'Aceptable'],
                        ['hasta' => 999,'texto' => 'Tendencia a mostrar una imagen de sí misma positiva']],
                'Z' => [['hasta' => 74, 'texto' => 'Aceptable'],
                        ['hasta' => 999,'texto' => 'Tendencia a menospreciarse; manifiesta problemas emocionales y personales']],
            ],
        ];
    }
}
