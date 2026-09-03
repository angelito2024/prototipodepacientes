<?php
declare(strict_types=1);

/**
 * Descarga de un adjunto por su uuid.
 *
 * Los archivos viven fuera de la raíz web y solo se sirven a través de este
 * endpoint: así se puede exigir sesión antes de entregar un consentimiento
 * informado o el protocolo de una prueba psicológica, cosa imposible si
 * estuvieran en una carpeta pública.
 */

require __DIR__ . '/../src/autoload.php';

use Centro\Archivos;
use Centro\Auth;
use Centro\Database;
use Centro\Http;

try {
    $loginRequerido = (int) (Database::valor('SELECT login_requerido FROM centro_config WHERE id = 1') ?? 0) === 1;
    if ($loginRequerido) {
        Auth::exigir();
    }

    $uuid = (string) ($_GET['id'] ?? '');
    if (preg_match('/^[0-9a-f-]{36}$/', $uuid) !== 1) {
        Http::error('Identificador de archivo inválido.', 400);
    }

    $a = Database::uno(
        'SELECT * FROM archivos WHERE uuid = ? AND eliminado_en IS NULL',
        [$uuid]
    );
    if ($a === null) {
        Http::error('Archivo no encontrado.', 404);
    }

    if ((int) $a['es_enlace'] === 1) {
        header('Location: ' . $a['url_externa'], true, 302);
        exit;
    }

    $ruta = Archivos::rutaAbsoluta($a);
    if (!is_file($ruta)) {
        Http::error('El archivo ya no está en el almacenamiento: ' . $a['ruta_relativa'], 410);
    }

    Auth::auditar('SELECT', Auth::usuarioId(), ['archivo' => $uuid], 'archivos');

    header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($ruta));
    header('Content-Disposition: inline; filename="'
        . str_replace('"', '', (string) $a['nombre_original']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($ruta);
} catch (Throwable $e) {
    error_log('[archivo] ' . $e->getMessage());
    Http::error('No se pudo entregar el archivo.', 500);
}
