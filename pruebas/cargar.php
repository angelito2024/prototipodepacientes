<?php
declare(strict_types=1);

/**
 * Carga una prueba psicológica en la base, leyéndola del Excel con que se
 * venía aplicando.
 *
 *   php pruebas/cargar.php mcmi4 "MILLON.xlsm"
 *
 * Se ejecuta una sola vez por prueba (o de nuevo si el Excel se corrige).
 * Del Excel solo se toma la prueba: los ítems, la clave de corrección y los
 * baremos. Las respuestas del paciente que hubiera en el archivo NO se
 * importan; para eso está la aplicación desde el sistema.
 *
 * Antes de guardar, corrige con la clave recién leída el protocolo que el
 * Excel trae marcado y compara los resultados contra los que calculó el
 * propio Excel. Si algo no coincide, no guarda nada: es preferible no tener
 * la prueba a tenerla corrigiendo mal.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script se ejecuta desde la línea de comandos.\n");
}

require __DIR__ . '/../src/autoload.php';

use Centro\Pruebas\Corrector;
use Centro\Pruebas\IceBaron;
use Centro\Pruebas\LectorExcel;
use Centro\Pruebas\Mcmi4;
use Centro\Repos\Pruebas;

$codigo  = $argv[1] ?? '';
$archivo = $argv[2] ?? '';
if ($codigo === '' || $archivo === '') {
    exit("Uso: php pruebas/cargar.php <codigo> <archivo.xlsx>\n"
       . "Códigos disponibles: mcmi4, ice_baron\n\n"
       . "Los .xls antiguos hay que guardarlos antes como .xlsx desde Excel.\n");
}

/** Fichas: lo que el panel muestra para elegir la prueba. */
$FICHAS = [
    'mcmi4' => [
        'descripcion' => 'Inventario de personalidad y síndromes clínicos para adultos en '
                       . 'evaluación o tratamiento psicológico. 25 escalas más 5 índices de validez.',
        'edadMinima'  => 18,
        'edadMaxima'  => null,
        'minutos'     => 30,
    ],
    'ice_baron' => [
        'descripcion' => 'Inventario de inteligencia emocional. 15 subescalas agrupadas en '
                       . 'cinco componentes, con baremos peruanos.',
        'edadMinima'  => 16,
        'edadMaxima'  => null,
        'minutos'     => 40,
    ],
];

try {
    $def = match ($codigo) {
        'mcmi4' => Mcmi4::extraer($archivo),
        // Los ítems inversos se pasan como tercer argumento, separados por
        // comas: el Excel no los trae (ver IceBaron.php).
        'ice_baron' => IceBaron::extraer($archivo, array_values(array_filter(array_map(
            static fn(string $s): int => (int) trim($s),
            explode(',', (string) ($argv[3] ?? ''))
        )))),
        default => throw new RuntimeException("No sé leer la prueba «$codigo»."),
    };

    echo "Leído de «{$archivo}»:\n";
    echo "  " . $def['nombre'] . " (" . $def['siglas'] . ")\n";
    echo "  ítems ................ " . count($def['items']) . "\n";
    echo "  escalas .............. " . count($def['escalas']) . "\n";
    if (isset($def['validez'])) {
        echo "  índices de validez ... " . count($def['validez']) . "\n";
    }
    if (isset($def['componentes'])) {
        echo "  componentes .......... " . count($def['componentes']) . "\n";
    }
    if (($def['correccionPendiente'] ?? false) === true) {
        echo "\n  ATENCIÓN: se carga para APLICARLA, no para puntuarla.\n"
           . "  Falta la lista de ítems inversos, así que no se calculan puntajes.\n"
           . "  El paciente la puede responder y sus respuestas quedan guardadas en\n"
           . "  su historia. Cuando tengas la lista, vuelve a cargarla pasándola al\n"
           . "  final del comando y luego corre:\n"
           . "      php pruebas/recorregir.php {$def['codigo']}\n"
           . "  y los protocolos ya respondidos se puntúan solos.\n";
    }

    // --- Comprobación contra el propio Excel --------------------------
    $x  = new LectorExcel($archivo);
    $hojas = $x->hojas();
    $comprobado = false;
    if (isset($hojas['APLICACION'], $hojas['CORRECCION'])) {
        $ap  = $x->celdas($hojas['APLICACION']);
        $cor = $x->celdas($hojas['CORRECCION']);
        $respuestas = [];
        for ($i = 1; $i <= (int) $def['nItems']; $i++) {
            $f  = 12 + $i;
            $v  = ($ap[$f]['C']['v'] ?? null) !== null;
            $fa = ($ap[$f]['D']['v'] ?? null) !== null;
            if ($v && !$fa)      $respuestas[$i] = 'V';
            elseif ($fa && !$v)  $respuestas[$i] = 'F';
        }
        if (count($respuestas) > 20) {   // hay un protocolo marcado que sirva de patrón
            $r = Corrector::corregir($def, $respuestas);
            $filaDe = ['1'=>21,'2A'=>22,'2B'=>23,'3'=>24,'4A'=>25,'4B'=>26,'5'=>27,'6A'=>28,
                       '6B'=>29,'7'=>30,'8A'=>31,'8B'=>32,'S'=>34,'C'=>35,'P'=>36,'A'=>38,
                       'H'=>39,'N'=>40,'D'=>41,'B'=>42,'T'=>43,'R'=>44,'SS'=>46,'CC'=>47,'PP'=>48];
            $malas = [];
            foreach ($r['escalas'] as $e) {
                $f = $filaDe[$e['codigo']] ?? null;
                if ($f === null) continue;
                $xPd = (int) ($cor[$f]['C']['v'] ?? -1);
                $xTb = (int) ($cor[$f]['D']['v'] ?? -1);
                if ($e['pd'] !== $xPd || (int) $e['tb'] !== $xTb) {
                    $malas[] = sprintf('%s (PD %d≠%d, TB %d≠%d)',
                        $e['codigo'], $e['pd'], $xPd, (int) $e['tb'], $xTb);
                }
            }
            if ($malas !== []) {
                throw new RuntimeException(
                    "La corrección no reproduce al Excel. No se guarda nada.\n  "
                    . implode("\n  ", $malas)
                );
            }
            echo "  comprobación ......... las " . count($r['escalas'])
               . " escalas dan lo mismo que el Excel\n";
            $comprobado = true;
        }
    }
    if (!$comprobado) {
        echo "  comprobación ......... el Excel no trae un protocolo marcado; no se pudo contrastar\n";
    }

    $id = Pruebas::instalar($def, $FICHAS[$codigo] ?? []);
    echo "\nGuardada en la base con el código «{$def['codigo']}» (id $id).\n";
    echo "Los ítems y la clave quedan solo en la base: no van al repositorio.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nNo se cargó la prueba: " . $e->getMessage() . "\n");
    exit(1);
}
