<?php
declare(strict_types=1);

/**
 * Vuelve a corregir los protocolos ya respondidos de una prueba.
 *
 *   php pruebas/recorregir.php ice_baron
 *
 * Hace falta cuando una prueba se cargó sin poder puntuarse —el ICE BarOn
 * se puede aplicar antes de tener la clave de ítems inversos— y después se
 * completa: los pacientes que ya la respondieron no tienen que volver a
 * hacerla, se recalculan sus resultados con lo que contestaron.
 *
 * No toca las respuestas: solo recalcula. Lo que el paciente contestó es
 * intocable, es el registro de lo que dijo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script se ejecuta desde la línea de comandos.\n");
}

require __DIR__ . '/../src/autoload.php';

use Centro\Database;
use Centro\Pruebas\Corrector;
use Centro\Repos\Pruebas;

$codigo = $argv[1] ?? '';
if ($codigo === '') {
    exit("Uso: php pruebas/recorregir.php <codigo>\n");
}

$def = Pruebas::definicion($codigo);
if ($def === null) {
    fwrite(STDERR, "No tengo cargada la prueba «$codigo».\n");
    exit(1);
}
if (($def['correccionPendiente'] ?? false) === true) {
    fwrite(STDERR,
        "La prueba «$codigo» sigue sin su clave de corrección.\n"
      . "Cárgala primero con pruebas/cargar.php y vuelve a correr esto.\n");
    exit(1);
}

$filas = Database::todos(
    "SELECT a.id, a.uid, a.respuestas, per.nombre_completo
       FROM prueba_aplicacion a
       JOIN pruebas p    ON p.id = a.prueba_id
       JOIN personas per ON per.id = a.paciente_id
      WHERE p.codigo = ? AND a.estado = 'terminada'
      ORDER BY a.terminada_en",
    [$codigo]
);

if ($filas === []) {
    echo "No hay protocolos terminados de «$codigo»: no hay nada que recalcular.\n";
    exit(0);
}

echo "Protocolos terminados: " . count($filas) . "\n\n";
$hechos = 0;
foreach ($filas as $f) {
    $respuestas = json_decode((string) $f['respuestas'], true);
    if (!is_array($respuestas) || $respuestas === []) {
        printf("  %-32s sin respuestas guardadas, se salta\n", mb_substr($f['nombre_completo'], 0, 32));
        continue;
    }
    $r = Corrector::corregir($def, $respuestas);
    Database::query(
        'UPDATE prueba_aplicacion SET resultado = ? WHERE id = ?',
        [json_encode($r, JSON_UNESCAPED_UNICODE), (int) $f['id']]
    );
    $hechos++;
    printf("  %-32s corregido: %d escalas\n",
        mb_substr($f['nombre_completo'], 0, 32), count($r['escalas']));
}

echo "\n$hechos protocolo(s) recalculado(s). Las respuestas no se tocaron.\n";
