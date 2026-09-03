<?php
declare(strict_types=1);

/**
 * Consulta de DNI contra apiperu.dev, hecha desde el servidor.
 *
 * El prototipo llamaba a apiperu.dev desde el navegador con el token
 * incrustado en el JSON del panel, así que el token viajaba a cada pantalla
 * y era visible en la consola y en la pestaña de red. Aquí el token no sale
 * nunca del servidor.
 */

require __DIR__ . '/../src/autoload.php';

use Centro\Auth;
use Centro\Database;
use Centro\Http;

try {
    $loginRequerido = (int) (Database::valor('SELECT login_requerido FROM centro_config WHERE id = 1') ?? 0) === 1;
    if ($loginRequerido) {
        Auth::exigir();
    }
    if (Http::metodo() !== 'POST') {
        Http::error('Método no permitido.', 405);
    }

    $dni = preg_replace('/\D/', '', (string) (Http::cuerpo()['dni'] ?? ''));
    if (strlen((string) $dni) !== 8) {
        Http::error('Ingresa un DNI de 8 dígitos.', 400);
    }

    $token = Database::valor("SELECT valor FROM integraciones WHERE clave = 'apiperu_token'");
    if ($token === null || $token === '') {
        Http::error(
            'Falta configurar el token de apiperu.dev en "Datos del centro". Es gratis: regístrate en apiperu.dev.',
            409
        );
    }

    $ch = curl_init('https://apiperu.dev/api/dni');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS     => json_encode(['dni' => $dni]),
    ]);
    $respuesta = curl_exec($ch);
    $error     = curl_error($ch);
    curl_close($ch);

    if ($respuesta === false) {
        Http::error('No se pudo consultar apiperu.dev: ' . $error, 502);
    }

    $datos = json_decode((string) $respuesta, true);
    if (!is_array($datos) || empty($datos['success']) || empty($datos['data'])) {
        Http::error('No se encontró información para ese DNI.', 404);
    }

    $d = $datos['data'];
    $nombre = trim(implode(' ', array_filter([
        $d['nombres'] ?? null,
        $d['apellido_paterno'] ?? null,
        $d['apellido_materno'] ?? null,
    ])));

    Http::ok([
        'nombre'           => $nombre !== '' ? $nombre : ($d['nombre_completo'] ?? ''),
        'nombres'          => $d['nombres'] ?? '',
        'apellidoPaterno'  => $d['apellido_paterno'] ?? '',
        'apellidoMaterno'  => $d['apellido_materno'] ?? '',
    ]);
} catch (Throwable $e) {
    error_log('[dni] ' . $e->getMessage());
    Http::error('Error consultando el DNI.', 500);
}
