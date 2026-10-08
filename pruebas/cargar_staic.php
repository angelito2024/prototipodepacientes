<?php
declare(strict_types=1);

/**
 * Carga el STAIC (ansiedad estado/rasgo en niños).
 *
 *   php pruebas/cargar_staic.php
 *   php pruebas/cargar_staic.php --base=prueba_staic   (para verificar)
 *
 * El contenido sale de `pruebas/contenido/staic.php`, que NO va al
 * repositorio: el STAIC es un instrumento que TEA Ediciones vende.
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
    if (str_starts_with($a, '--base=')) { $base = substr($a, 7); }
}
if ($base !== null) {
    $p = (new ReflectionClass(Database::class))->getProperty('config');
    $p->setAccessible(true);
    $cfg = require __DIR__ . '/../config/config.php';
    $cfg['db']['nombre'] = $base;
    $p->setValue(null, $cfg);
    echo "Base de pruebas: $base\n";
}

$archivo = __DIR__ . '/contenido/staic.php';
if (!is_file($archivo)) {
    exit("No encuentro $archivo.\nEs el contenido de la prueba; no viaja en el repositorio.\n");
}
/** @var array $def */
$def = require $archivo;

/* Comprobaciones antes de guardar. Una escala mal armada no se nota
   mirando el puntaje: se nota años después, en el informe de un niño. */
$problemas = [];
$enEscala = [];
foreach ($def['escalas'] as $e) {
    foreach ($e['items'] as $n) {
        if (isset($enEscala[$n])) { $problemas[] = "El ítem $n está en dos escalas."; }
        $enEscala[$n] = $e['codigo'];
    }
    foreach ($e['inversos'] as $n) {
        if (!in_array($n, $e['items'], true)) {
            $problemas[] = "El ítem invertido $n no pertenece a la escala {$e['codigo']}.";
        }
    }
    $min = count($e['items']) * (int) $def['valorMinimo'];
    $max = count($e['items']) * count($def['opciones']);
    foreach ($e['baremos'] as $grupo => $porSexo) {
        foreach ($porSexo as $sexo => $tabla) {
            foreach ($tabla as $f) {
                if ($f['desde'] < $min || $f['hasta'] > $max) {
                    $problemas[] = sprintf('Baremo %s/%s/%s: el tramo %d-%d se sale de %d-%d.',
                        $e['codigo'], $grupo, $sexo, $f['desde'], $f['hasta'], $min, $max);
                }
            }
        }
    }
}
for ($n = 1; $n <= (int) $def['nItems']; $n++) {
    if (!isset($enEscala[$n])) { $problemas[] = "El ítem $n no está en ninguna escala."; }
}
if (count($def['items']) !== (int) $def['nItems']) {
    $problemas[] = sprintf('Hay %d ítems escritos y la ficha dice %d.',
        count($def['items']), $def['nItems']);
}

if ($problemas !== []) {
    echo "NO se cargó nada:\n";
    foreach ($problemas as $p) { echo "  · $p\n"; }
    exit(1);
}

$ficha = [
    'descripcion' => $def['descripcion'] ?? null,
    'edadMinima'  => $def['edadMinima'] ?? null,
    'edadMaxima'  => $def['edadMaxima'] ?? null,
    'minutos'     => $def['minutos'] ?? null,
];
foreach (['descripcion', 'edadMinima', 'edadMaxima', 'minutos'] as $k) { unset($def[$k]); }

Pruebas::instalar($def, $ficha);

printf("Cargada: %s (%s)\n", $def['nombre'], $def['siglas']);
foreach ($def['escalas'] as $e) {
    printf("  %-24s %2d ítems · %2d invertidos · baremos %s\n",
        $e['nombre'], count($e['items']), count($e['inversos']),
        implode('/', array_keys($e['baremos'])));
}
echo "\nOJO: queda marcada para revisar antes de usarla. Hay que confirmar\n";
echo "contra el Ejemplar impreso la redacción de los ítems 4 y 16 de la\n";
echo "primera parte, que en el Word del centro vienen con erratas.\n";
