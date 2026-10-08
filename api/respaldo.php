<?php
declare(strict_types=1);

/**
 * En qué estado están las copias de la base.
 *
 *   GET ?accion=estado   -> cuándo fue la última, cuántas hay, si salieron
 *
 * Por qué hace falta: el respaldo lo hace una tarea de Windows todas las
 * noches, y cuando no corre —la computadora apagada, el portátil en
 * batería, un error— no pasa nada visible. El registro queda en un .txt
 * que nadie abre. Así es como se llega a nueve días sin copia creyendo
 * que todo va bien.
 *
 * Esto devuelve SOLO datos del archivo: nombre, fecha y tamaño. El
 * contenido de una copia son historias clínicas y no sale de acá por
 * ningún lado.
 */

require __DIR__ . '/../src/autoload.php';

use Centro\Auth;
use Centro\Http;

Auth::exigir();
if (!Auth::puede('backup.exportar')) {
    Http::error('No tienes permiso para ver el estado de las copias.', 403);
}

/* Dónde están las copias, y cuáles son "las mías".
 *
 * Hay dos paneles —el del centro y el de las finanzas personales— pero un
 * solo respaldo, que vive en la carpeta del centro y copia las dos bases.
 * El panel personal buscaba una carpeta `respaldo` dentro de la suya, no
 * la encontraba y decía "Sin copias de la base" teniendo su copia hecha.
 * Eso es peor que no avisar: una alarma que miente se aprende a ignorar.
 */
$raiz    = dirname(__DIR__);
$carpeta = is_dir($raiz . '/respaldo')
    ? $raiz . '/respaldo'
    : dirname($raiz) . '/prototipodepacientes/respaldo';
$copias  = $carpeta . '/copias';

// Cada panel informa de SU copia: la del centro empieza por "magusa_", la
// personal por "misfinanzas_".
$esPersonal = !is_dir($raiz . '/respaldo');
$patron     = $esPersonal ? 'misfinanzas_*.sql' : 'magusa_*.sql';

/* Lo que dejó escrito el último respaldo.
 *
 * El panel corre sobre Apache, con otro usuario de Windows: no alcanza la
 * carpeta de OneDrive, así que mirar ahí si la copia salió no funciona
 * (devolvía "ninguna copia ha salido" teniéndolas todas). El script del
 * respaldo sí la alcanza, y deja su parte en copias/estado.json. */
$parte = [];
$estadoJson = $copias . '/estado.json';
if (is_file($estadoJson)) {
    $parte = json_decode((string) file_get_contents($estadoJson), true) ?: [];
}

/** Días transcurridos desde una fecha 'Y-m-d H:i' del parte. */
$diasDesde = static function (?string $cuando): ?int {
    if ($cuando === null || $cuando === '') {
        return null;
    }
    $t = strtotime($cuando);
    return $t === false ? null : (int) floor((time() - $t) / 86400);
};

/** La más reciente de una carpeta, con su fecha y su peso. */
$ultima = static function (string $dir, string $patron): ?array {
    if (!is_dir($dir)) {
        return null;
    }
    $archivos = glob(rtrim($dir, '/\\') . '/' . $patron) ?: [];
    if ($archivos === []) {
        return null;
    }
    usort($archivos, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $f = $archivos[0];
    return [
        'archivo' => basename($f),
        'cuando'  => date('Y-m-d H:i', (int) filemtime($f)),
        'dias'    => (int) floor((time() - (int) filemtime($f)) / 86400),
        'mb'      => round(filesize($f) / 1048576, 2),
        'cuantas' => count($archivos),
    ];
};

// Las últimas líneas del registro que no son un "OK": son las que
// explican por qué faltó una copia.
$fallas = [];
$registro = $copias . '/registro.txt';
if (is_file($registro)) {
    $lineas = array_slice(file($registro, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -60);
    foreach ($lineas as $l) {
        if (stripos($l, 'ERROR') !== false || stripos($l, 'AVISO') !== false) {
            $fallas[] = $l;
        }
    }
    $fallas = array_slice($fallas, -5);
}

// La local se mira en el disco, que es la verdad: si el archivo está, está.
// La de afuera solo la sabe el script, porque el panel no la alcanza.
$fuera = null;
if ($esPersonal) {
    // El parte guarda la copia personal aparte, con un sí/no de si salió.
    $p = $parte['personal'] ?? null;
    if (!empty($p['cuando']) && !empty($p['fuera'])) {
        $fuera = [
            'archivo' => (string) ($p['archivo'] ?? '') . '.cifrado',
            'cuando'  => (string) $p['cuando'],
            'dias'    => $diasDesde((string) $p['cuando']),
            'mb'      => (float) ($p['mb'] ?? 0),
            'cuantas' => 0,
            'donde'   => 'OneDrive',
        ];
    }
} elseif (!empty($parte['fuera']['cuando'])) {
    $fuera = [
        'archivo' => (string) ($parte['fuera']['archivo'] ?? ''),
        'cuando'  => (string) $parte['fuera']['cuando'],
        'dias'    => $diasDesde((string) $parte['fuera']['cuando']),
        'mb'      => (float) ($parte['fuera']['mb'] ?? 0),
        'cuantas' => (int) ($parte['fuera']['cuantas'] ?? 0),
        'donde'   => (string) ($parte['fuera']['donde'] ?? 'la nube'),
    ];
}

Http::ok([
    'local'      => $ultima($copias, $patron),
    'fuera'      => $fuera,
    'corrioEn'   => $parte['corrioEn'] ?? null,
    'problema'   => $parte['problema'] ?? null,
    // Sin clave no se cifra, y sin cifrar la copia no sale de la PC.
    'tieneClave' => is_file($carpeta . '/clave.txt')
                    && trim((string) file_get_contents($carpeta . '/clave.txt')) !== '',
    'fallas'     => $fallas,
]);
