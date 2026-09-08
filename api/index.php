<?php
declare(strict_types=1);

/**
 * API del panel.
 *
 *   GET    ?accion=coleccion&key=patients      -> {ok, version, value}
 *   PUT    ?accion=coleccion&key=patients      <- {version, value}
 *   POST   ?accion=login                       <- {usuario, clave}
 *   POST   ?accion=logout
 *   GET    ?accion=sesion                      -> estado de la sesión
 *   POST   ?accion=personal_pin                <- {pin} (cuentas personales)
 *   GET    ?accion=salud                       -> diagnóstico de conexión
 *
 * El panel guarda enviando el array completo de la colección. Para evitar
 * que dos personas trabajando a la vez se pisen los cambios, el PUT exige
 * la versión que se leyó: si no coincide, responde 409 y el panel recarga.
 */

require __DIR__ . '/../src/autoload.php';

use Centro\Auth;
use Centro\Colecciones;
use Centro\Database;
use Centro\Http;

$accion = (string) ($_GET['accion'] ?? 'coleccion');

try {
    switch ($accion) {
        case 'salud':
            Http::ok([
                'servidor' => Database::valor('SELECT VERSION()'),
                'base'     => Database::valor('SELECT DATABASE()'),
                'usuario'  => Database::valor('SELECT CURRENT_USER()'),
                'tablas'   => (int) Database::valor(
                    "SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
                ),
            ]);
            // no break: Http::ok() termina la ejecución

        case 'login':
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            $c = Http::cuerpo();
            $r = Auth::login((string) ($c['usuario'] ?? ''), (string) ($c['clave'] ?? ''));
            if (!$r['ok']) {
                Http::error($r['error'], 401);
            }
            Http::ok(['usuario' => $r['usuario']]);

        case 'logout':
            Auth::logout();
            Http::ok();

        case 'sesion':
            $id = Auth::usuarioId();
            Http::ok([
                'autenticado'     => $id !== null,
                'loginRequerido'  => (int) (Database::valor(
                    'SELECT login_requerido FROM centro_config WHERE id = 1'
                ) ?? 0) === 1,
                'usuario'         => $id === null ? null : Auth::perfil($id),
            ]);

        case 'personal_pin':
            // La clave de las cuentas personales se comprueba aquí, contra
            // el hash. En el panel se comparaba en JavaScript contra el
            // valor en claro, que viajaba al navegador y salía también en
            // el JSON de la copia de seguridad.
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            if ((int) (Database::valor('SELECT login_requerido FROM centro_config WHERE id = 1') ?? 0) === 1) {
                Auth::exigir();
            }
            $c = Http::cuerpo();
            if (!\Centro\Repos\PersonalConfig::verificar((string) ($c['pin'] ?? ''))) {
                Http::error('Clave incorrecta.', 401);
            }
            Http::ok();

        case 'coleccion':
            manejarColeccion();

        default:
            Http::error('Acción desconocida: ' . $accion, 404);
    }
} catch (Throwable $e) {
    $debug = (bool) (Database::config()['debug'] ?? false);
    error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::error(
        $debug ? $e->getMessage() : 'Error interno del servidor. Revisa el log de PHP.',
        500,
        $debug ? ['detalle' => $e->getFile() . ':' . $e->getLine()] : []
    );
}

function manejarColeccion(): never
{
    $clave = (string) ($_GET['key'] ?? '');
    if (!Colecciones::valida($clave)) {
        Http::error('Clave de colección inválida.', 400);
    }
    $repo = Colecciones::para($clave);
    if ($repo === null) {
        Http::error('Colección desconocida: ' . $clave, 404);
    }

    // Si el centro activó el acceso con clave, todo exige sesión salvo lo
    // mínimo que la pantalla de login necesita para dibujarse.
    $loginRequerido = (int) (Database::valor('SELECT login_requerido FROM centro_config WHERE id = 1') ?? 0) === 1;
    $publicas = ['authConfig', 'centerInfo'];
    if ($loginRequerido && !in_array($clave, $publicas, true)) {
        Auth::exigir();
    }

    if (Http::metodo() === 'GET') {
        Http::ok(['version' => $repo->version(), 'value' => $repo->leer()]);
    }

    if (Http::metodo() !== 'PUT' && Http::metodo() !== 'POST') {
        Http::error('Método no permitido.', 405);
    }

    if ($loginRequerido) {
        Auth::exigir();
    }

    $cuerpo = Http::cuerpo();
    if (!array_key_exists('value', $cuerpo)) {
        Http::error('Falta el campo "value".', 400);
    }

    $actual  = $repo->version();
    $enviada = isset($cuerpo['version']) ? (int) $cuerpo['version'] : null;

    // Bloqueo optimista: solo se comprueba si el cliente dice qué versión
    // leyó. La historia clínica se identifica por paciente y no comparte
    // contador, así que no lo aplica.
    if ($enviada !== null && $actual > 0 && $enviada !== $actual) {
        Http::error(
            'Otra persona guardó cambios en esta sección mientras trabajabas. '
            . 'Se recargarán los datos actualizados para no perder su trabajo.',
            409,
            ['version' => $actual, 'value' => $repo->leer()]
        );
    }

    Database::transaccion(static function () use ($repo, $cuerpo): void {
        $repo->guardar($cuerpo['value']);
    });

    Http::ok(['version' => $repo->version()]);
}
