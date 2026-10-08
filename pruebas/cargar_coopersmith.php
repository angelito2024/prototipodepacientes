<?php
declare(strict_types=1);

/**
 * Carga el Inventario de Autoestima de Coopersmith, forma escolar.
 *
 *   php pruebas/cargar_coopersmith.php
 *   php pruebas/cargar_coopersmith.php --base=prueba_pruebas   (para verificar)
 *
 * De dónde sale: `pruebas/contenido/coopersmith_escolar.php`, transcrito
 * de los documentos del propio centro. Ese archivo NO va al repositorio:
 * el texto de los ítems, la clave y los baremos de una prueba publicada
 * tienen derechos de autor. El programa que la corrige sí se versiona.
 *
 * Se puede correr todas las veces que haga falta: si la prueba ya está,
 * se actualiza. Las aplicaciones ya hechas a pacientes no se tocan.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script se ejecuta desde la consola, no desde el navegador.\n");
}

require __DIR__ . '/../src/autoload.php';

use Centro\Database;
use Centro\Repos\Pruebas;

$base = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--base=')) {
        $base = substr($a, 7);
    }
}
if ($base !== null) {
    $p = (new ReflectionClass(Database::class))->getProperty('config');
    $p->setAccessible(true);
    $cfg = require __DIR__ . '/../config/config.php';
    $cfg['db']['nombre'] = $base;
    $p->setValue(null, $cfg);
    echo "Base de pruebas: $base\n";
}

$archivo = __DIR__ . '/contenido/coopersmith_escolar.php';
if (!is_file($archivo)) {
    exit("No encuentro $archivo.\n"
       . "Es el archivo con los ítems y la clave; no viaja en el repositorio.\n");
}

/** @var array $def */
$def = require $archivo;

/* Antes de guardar nada, comprobar que la clave cierra. Una clave con un
   ítem de más o de menos no se nota al mirarla: se nota meses después,
   cuando a un chico le sale un puntaje que no es el suyo. */
$problemas = [];
$enSub = [];
foreach ($def['subescalas'] as $s) {
    foreach ($s['items'] as $n) {
        if (isset($enSub[$n])) {
            $problemas[] = "El ítem $n está en dos subescalas.";
        }
        $enSub[$n] = $s['codigo'];
    }
}
foreach ($def['validez']['items'] as $n) {
    if (isset($enSub[$n])) {
        $problemas[] = "El ítem $n está en una subescala y además en la de validez.";
    }
    $enSub[$n] = 'validez';
}
for ($n = 1; $n <= (int) $def['nItems']; $n++) {
    if (!isset($enSub[$n]))        { $problemas[] = "El ítem $n no está en ninguna escala."; }
    if (!isset($def['clave'][$n])) { $problemas[] = "El ítem $n no tiene clave."; }
}
if (count($def['items']) !== (int) $def['nItems']) {
    $problemas[] = sprintf('Hay %d ítems escritos y la ficha dice %d.',
        count($def['items']), $def['nItems']);
}
$puntuables = 0;
foreach ($def['subescalas'] as $s) {
    $puntuables += count($s['items']);
}
$maxCalculado = $puntuables * (int) $def['factor'];
if ($maxCalculado !== (int) $def['maximoTotal']) {
    $problemas[] = sprintf('El máximo sale %d y la ficha dice %d.',
        $maxCalculado, $def['maximoTotal']);
}

if ($problemas !== []) {
    echo "NO se cargó nada. La definición tiene problemas:\n";
    foreach ($problemas as $p) {
        echo "  · $p\n";
    }
    exit(1);
}

$ficha = [
    'descripcion' => $def['descripcion'] ?? null,
    'edadMinima'  => $def['edadMinima'] ?? null,
    'edadMaxima'  => $def['edadMaxima'] ?? null,
    'minutos'     => $def['minutos'] ?? null,
];
foreach (['descripcion', 'edadMinima', 'edadMaxima', 'minutos'] as $k) {
    unset($def[$k]);
}

Pruebas::instalar($def, $ficha);

printf("Cargada: %s (%s)\n", $def['nombre'], $def['siglas']);
printf("  %d ítems · %d puntuables · máximo %d\n",
    $def['nItems'], $puntuables, $def['maximoTotal']);
foreach ($def['subescalas'] as $s) {
    printf("  %-18s %2d ítems\n", $s['nombre'], count($s['items']));
}
printf("  %-18s %2d ítems (invalida si pasa de %d)\n",
    $def['validez']['nombre'], count($def['validez']['items']), $def['validez']['maximo']);
echo "\nSe asigna desde el panel, en Pruebas psicológicas.\n";
