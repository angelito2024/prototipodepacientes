<?php
declare(strict_types=1);

/**
 * Carga las pautas de observación que marca el profesional.
 *
 *   php pruebas/cargar_pautas.php
 *   php pruebas/cargar_pautas.php --base=prueba_pruebas     (para verificar)
 *
 * De dónde salen: `pruebas/contenido/pautas_desarrollo.php`, que tiene las
 * trece hojas de evaluación pedagógica por edades transcritas de las hojas
 * escaneadas del centro. Ese archivo no va al repositorio: el programa que
 * las corrige sí se versiona, el texto de los ítems vive en la base.
 *
 * Se puede correr todas las veces que haga falta: si una hoja ya está, se
 * actualiza. Las evaluaciones ya hechas no se tocan.
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
        $base = substr($a, strlen('--base='));
    }
}
if ($base !== null) {
    $cfg = Database::config();
    $cfg['db']['nombre'] = $base;
    $p = (new ReflectionClass(Database::class))->getProperty('config');
    $p->setAccessible(true);
    $p->setValue(null, $cfg);
    echo "Base: $base\n";
}

$archivo = __DIR__ . '/contenido/pautas_desarrollo.php';
if (!is_file($archivo)) {
    exit("No encuentro $archivo.\n"
       . "Es el archivo con el texto de las hojas; no viaja en el repositorio.\n");
}

/** @var array<int,array> $hojas */
$hojas = require $archivo;

$n = 0;
$items = 0;
foreach ($hojas as $def) {
    $ficha = $def['ficha'] ?? [];
    unset($def['ficha']);
    Pruebas::instalar($def, $ficha);
    printf(
        "  %-14s %-10s %2d ítems   %s\n",
        $def['codigo'], $def['siglas'], $def['nItems'], implode(' · ', $def['areas'])
    );
    $n++;
    $items += (int) $def['nItems'];
}

echo "\n$n hojas cargadas, $items ítems.\n";
echo "Se marcan desde el panel, en Pruebas psicológicas: el sistema elige la\n";
echo "hoja que le toca al niño por su fecha de nacimiento.\n";
