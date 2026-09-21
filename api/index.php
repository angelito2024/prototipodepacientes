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

        case 'credenciales':
            // Cambiar el propio usuario y/o clave, ya con la sesión abierta.
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            Auth::exigir();
            $c = Http::cuerpo();
            $r = Auth::cambiarCredenciales(
                (string) ($c['claveActual'] ?? ''),
                (string) ($c['usuario'] ?? ''),
                (string) ($c['claveNueva'] ?? '')
            );
            if (!$r['ok']) {
                Http::error($r['error'], 400);
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
            // Quitar la clave olvidada. Solo puede hacerlo quien ya entró al
            // panel con su usuario y contraseña: esa es la puerta de verdad,
            // esta es una segunda tranca. Sin esto, olvidar la clave deja a
            // alguien fuera de sus propias cuentas para siempre.
            if (($c['quitar'] ?? false) === true) {
                Auth::exigir();
                if (!\Centro\Repos\PersonalConfig::quitarPin()) {
                    Http::error('No se pudo quitar la clave.', 400);
                }
                Http::ok(['quitada' => true]);
            }
            if (!\Centro\Repos\PersonalConfig::verificar((string) ($c['pin'] ?? ''))) {
                Http::error('Clave incorrecta.', 401);
            }
            Http::ok();

        case 'crear_usuario':
            // Dar de alta a alguien del equipo. La contraseña llega una vez
            // y se guarda con hash: nunca se puede volver a leer, solo
            // restablecer.
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            Auth::exigir();
            if (!Auth::puede('usuarios.editar')) {
                Auth::auditar('PERMISO_DENEGADO', Auth::usuarioId(),
                    ['accion' => 'crear_usuario'], 'usuarios');
                Http::error('Solo un administrador puede crear usuarios.', 403);
            }
            try {
                $id = \Centro\Repos\Usuarios::crear(Http::cuerpo());
            } catch (RuntimeException $e) {
                Http::error($e->getMessage(), 400);
            }
            Http::ok(['id' => $id]);

        case 'restablecer_clave':
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            Auth::exigir();
            if (!Auth::puede('usuarios.editar')) {
                Auth::auditar('PERMISO_DENEGADO', Auth::usuarioId(),
                    ['accion' => 'restablecer_clave'], 'usuarios');
                Http::error('Solo un administrador puede restablecer contraseñas.', 403);
            }
            $c = Http::cuerpo();
            try {
                $usuario = \Centro\Repos\Usuarios::restablecerClave(
                    (int) ($c['id'] ?? 0), (string) ($c['clave'] ?? '')
                );
            } catch (RuntimeException $e) {
                Http::error($e->getMessage(), 400);
            }
            Http::ok(['usuario' => $usuario]);

        case 'roles':
            Auth::exigir();
            Http::ok(['roles' => \Centro\Repos\Usuarios::roles()]);

        case 'asignar_prueba':
            // Le asigna una prueba a un paciente y devuelve el enlace para
            // mandárselo. La clave del enlace se muestra una sola vez: después
            // queda guardada con hash y ya no se puede volver a leer.
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            Auth::exigir();
            $c = Http::cuerpo();
            try {
                $r = \Centro\Repos\PruebaAplicaciones::asignar(
                    (string) ($c['prueba'] ?? ''),
                    (string) ($c['paciente'] ?? ''),
                    isset($c['profesional']) ? (string) $c['profesional'] : null,
                    isset($c['dias']) ? (int) $c['dias'] : null
                );
            } catch (RuntimeException $e) {
                Http::error($e->getMessage(), 400);
            }
            // La dirección de esta PC dentro de la red. Hace falta porque el
            // panel arma el enlace con la dirección que tiene en la barra, y
            // en el centro esa es "localhost": en el celular del paciente
            // localhost es su propio teléfono y el enlace no abre.
            $r['ipLocal'] = ipDeLaRed();
            Http::ok($r);

        case 'enlace_prueba':
            // Vuelve a generar el enlace de una prueba que sigue pendiente.
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            Auth::exigir();
            $c = Http::cuerpo();
            try {
                $r = \Centro\Repos\PruebaAplicaciones::regenerarToken(
                    (string) ($c['id'] ?? ''),
                    isset($c['dias']) ? (int) $c['dias'] : null
                );
            } catch (RuntimeException $e) {
                Http::error($e->getMessage(), 400);
            }
            Http::ok($r + ['ipLocal' => ipDeLaRed()]);

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

/**
 * Corta la petición si quien entró no tiene permiso para esta sección.
 *
 * Los intentos rechazados quedan anotados: si alguien anda probando lo que
 * no le toca, se ve en el registro de auditoría.
 */
function comprobarPermiso(string $clave, bool $escribe): void
{
    // Las cuentas personales guardan a quién pertenece cada movimiento, así
    // que cada usuario ve las suyas y solo las suyas: eso lo resuelve el
    // repositorio al consultar, no hace falta un permiso aparte.
    if (Colecciones::esDeCadaUsuario($clave)) {
        return;
    }

    $permiso = Colecciones::permiso($clave, $escribe);
    if ($permiso === null || Auth::puede($permiso)) {
        return;
    }

    Auth::auditar('PERMISO_DENEGADO', Auth::usuarioId(),
        ['seccion' => $clave, 'permiso' => $permiso, 'escribe' => $escribe], 'usuarios');
    Http::error(
        $escribe
            ? 'Tu usuario no puede modificar esta sección. Pídeselo a quien administra el sistema.'
            : 'Tu usuario no tiene acceso a esta sección.',
        403
    );
}

/**
 * La IP de esta computadora dentro de la red local, o null si no se puede
 * averiguar. Sirve para proponerle al psicólogo una dirección que el celular
 * del paciente sí pueda abrir mientras el sistema no esté en internet.
 */
function ipDeLaRed(): ?string
{
    $ip = gethostbyname(gethostname());
    // gethostbyname devuelve el nombre tal cual si no resuelve.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return null;
    }
    return str_starts_with($ip, '127.') ? null : $ip;
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
    // Estas dos se pueden LEER sin sesión: la pantalla de acceso no se
    // puede dibujar sin ellas. Escribirlas es otra cosa, y sí pide permiso.
    $publicasParaLeer = ['authConfig', 'centerInfo'];

    $escribe = Http::metodo() !== 'GET';

    if (Http::metodo() !== 'GET' && Http::metodo() !== 'PUT' && Http::metodo() !== 'POST') {
        Http::error('Método no permitido.', 405);
    }

    if ($loginRequerido && ($escribe || !in_array($clave, $publicasParaLeer, true))) {
        Auth::exigir();
    }

    // El permiso se comprueba aquí, del lado del servidor. Esconder un
    // botón en la pantalla no protege nada: quien sepa pedir la dirección
    // igual recibe los datos.
    if ($loginRequerido && ($escribe || !in_array($clave, $publicasParaLeer, true))) {
        comprobarPermiso($clave, $escribe);
    }

    if (!$escribe) {
        Http::ok(['version' => $repo->version(), 'value' => $repo->leer()]);
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
