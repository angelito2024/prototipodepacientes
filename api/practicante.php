<?php
declare(strict_types=1);

/**
 * La puerta por la que un practicante llena su propia ficha.
 *
 *   GET  ?accion=abrir&t=<clave>      -> si la invitación sirve, y de parte de quién
 *   POST ?accion=registrar&t=<clave>  -> guarda sus datos y cierra la invitación
 *
 * Como el de materiales y el de pruebas, es de los pocos sitios a los que
 * se entra sin sesión: un practicante que todavía no está en el sistema no
 * puede tener cuenta en el sistema. La clave del enlace hace de llave.
 *
 * Lo que este archivo NO hace, a propósito:
 *
 *  · no devuelve nada del centro salvo el nombre y el mensaje de la
 *    invitación: quien tenga el enlace no ve pacientes, ni agenda, ni la
 *    lista de practicantes;
 *  · no crea ninguna cuenta ni da ningún permiso. Registrarse deja una
 *    ficha para que la vea Luis, nada más;
 *  · no deja registrar dos veces con el mismo enlace.
 */

require __DIR__ . '/../src/autoload.php';

use Centro\Database;
use Centro\Http;
use Centro\Repos\InvitacionesPracticante;

$accion = (string) ($_GET['accion'] ?? 'abrir');
$token  = (string) ($_GET['t'] ?? '');

try {
    $inv = InvitacionesPracticante::porToken($token);
    if ($inv === null) {
        // El mismo mensaje para clave mal formada y para clave inexistente:
        // así no se puede ir probando a ver cuál existe.
        Http::error('Este enlace no es válido. Pídele al centro uno nuevo.', 404);
    }

    $id     = (int) $inv['id'];
    $motivo = InvitacionesPracticante::motivoParaNoAbrir($inv);

    switch ($accion) {
        case 'abrir':
            InvitacionesPracticante::anotarApertura($id);
            if ($motivo !== null) {
                Http::json(['ok' => false, 'cerrada' => true, 'error' => $motivo], 200);
            }
            Http::ok([
                'centro'  => (string) (Database::valor(
                    'SELECT COALESCE(NULLIF(nombre_comercial, ""), razon_social)
                       FROM centro_config WHERE id = 1'
                ) ?? 'Centro Psicológico'),
                'mensaje' => (string) ($inv['mensaje'] ?? ''),
                'expira'  => $inv['expira_en'],
            ]);
            // no break: Http::ok termina

        case 'registrar':
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            if ($motivo !== null) {
                Http::error($motivo, 409);
            }
            try {
                $r = InvitacionesPracticante::registrar($id, Http::cuerpo());
            } catch (RuntimeException $e) {
                Http::error($e->getMessage(), 400);
            }
            Http::ok(['nombre' => $r['nombre']]);

        default:
            Http::error('Acción desconocida.', 400);
    }
} catch (Throwable $e) {
    error_log('practicante.php: ' . $e->getMessage());
    Http::error('No se pudo completar la operación. Avísale al centro.', 500);
}
