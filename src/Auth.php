<?php
declare(strict_types=1);

namespace Centro;

/**
 * Autenticación por sesión contra la tabla `usuarios`.
 *
 * Reemplaza el authConfig del prototipo, que guardaba UN usuario y UNA
 * contraseña en texto plano dentro del propio almacenamiento y los
 * comparaba en JavaScript — es decir, cualquiera que abriera la consola
 * del navegador podía leerlos.
 */
final class Auth
{
    private const DURACION = 8 * 3600;          // 8 horas
    private const MAX_INTENTOS = 5;
    private const BLOQUEO_MINUTOS = 15;

    public static function iniciarSesionPhp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Por consola (migrar.php) no hay sesión: no hay usuario que
        // autenticar y session_start() solo produciría avisos.
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // 'secure' => true  <- activar cuando el sitio use HTTPS
        ]);
        session_start();
    }

    /** @return array{ok:bool, error?:string, usuario?:array} */
    public static function login(string $usuario, string $clave): array
    {
        self::iniciarSesionPhp();

        $fila = Database::uno(
            'SELECT id, usuario, password_hash, nombre_completo, activo,
                    intentos_fallidos, bloqueado_hasta
               FROM usuarios WHERE usuario = ? LIMIT 1',
            [$usuario]
        );

        // Se compara igual contra un hash falso aunque el usuario no exista,
        // para que el tiempo de respuesta no delate qué usuarios son válidos.
        $hash = $fila['password_hash'] ?? '$2y$12$invalidoinvalidoinvalidoinvalidoinvalidoinvalidoinvalidoinv';
        $coincide = password_verify($clave, $hash);

        if ($fila === null) {
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
        }
        if ((int) $fila['activo'] !== 1) {
            return ['ok' => false, 'error' => 'Este usuario está inhabilitado.'];
        }
        if ($fila['bloqueado_hasta'] !== null && strtotime((string) $fila['bloqueado_hasta']) > time()) {
            return [
                'ok' => false,
                'error' => 'Demasiados intentos fallidos. Vuelve a intentarlo en unos minutos.',
            ];
        }

        if (!$coincide) {
            $intentos = (int) $fila['intentos_fallidos'] + 1;
            $bloqueo  = $intentos >= self::MAX_INTENTOS
                ? date('Y-m-d H:i:s', time() + self::BLOQUEO_MINUTOS * 60)
                : null;
            Database::query(
                'UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id = ?',
                [$intentos, $bloqueo, $fila['id']]
            );
            self::auditar('LOGIN', null, ['usuario' => $usuario, 'resultado' => 'fallido']);
            return ['ok' => false, 'error' => 'Usuario o contraseña incorrectos.'];
        }

        // Rehash si el algoritmo por defecto cambió (o subió el coste).
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::query(
                'UPDATE usuarios SET password_hash = ? WHERE id = ?',
                [password_hash($clave, PASSWORD_DEFAULT), $fila['id']]
            );
        }

        Database::query(
            'UPDATE usuarios
                SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = NOW()
              WHERE id = ?',
            [$fila['id']]
        );

        session_regenerate_id(true);          // evita fijación de sesión
        $_SESSION['usuario_id'] = (int) $fila['id'];
        $_SESSION['expira']     = time() + self::DURACION;

        Database::query(
            'INSERT INTO sesiones (id, usuario_id, ip, user_agent, expira_en)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE expira_en = VALUES(expira_en)',
            [
                hash('sha256', session_id()),
                $fila['id'],
                Http::ipBinaria(),
                Http::userAgent(),
                date('Y-m-d H:i:s', $_SESSION['expira']),
            ]
        );

        self::auditar('LOGIN', (int) $fila['id'], ['usuario' => $usuario, 'resultado' => 'ok']);

        return ['ok' => true, 'usuario' => self::perfil((int) $fila['id'])];
    }

    public static function logout(): void
    {
        self::iniciarSesionPhp();
        $id = self::usuarioId();
        Database::query('DELETE FROM sesiones WHERE id = ?', [hash('sha256', session_id())]);
        if ($id !== null) {
            self::auditar('LOGOUT', $id, []);
        }
        $_SESSION = [];
        session_destroy();
    }

    public static function usuarioId(): ?int
    {
        self::iniciarSesionPhp();
        if (!isset($_SESSION['usuario_id'], $_SESSION['expira'])) {
            return null;
        }
        if ($_SESSION['expira'] < time()) {
            $_SESSION = [];
            return null;
        }
        return (int) $_SESSION['usuario_id'];
    }

    /** ¿Está activado el acceso con clave? (hay al menos un usuario activo) */
    public static function habilitado(): bool
    {
        return (int) Database::valor('SELECT COUNT(*) FROM usuarios WHERE activo = 1') > 0;
    }

    /**
     * A nombre de quién se guarda lo que es privado de una persona (hoy,
     * las cuentas personales).
     *
     * Con el acceso con clave activado es el usuario de la sesión. Sin él,
     * el panel no pregunta quién eres pero las cuentas personales igual
     * necesitan un dueño: se usa la cuenta de administrador. Sin esto, la
     * pestaña aceptaría los datos y no los guardaría en ningún sitio.
     */
    public static function duenioDeLoPersonal(): ?int
    {
        $id = self::usuarioId();
        if ($id !== null) {
            return $id;
        }
        $loginRequerido = (int) (Database::valor(
            'SELECT login_requerido FROM centro_config WHERE id = 1'
        ) ?? 0) === 1;
        if ($loginRequerido) {
            return null;      // hay pantalla de acceso: sin sesión, nadie
        }
        $admin = Database::valor(
            "SELECT u.id FROM usuarios u
               JOIN usuario_roles ur ON ur.usuario_id = u.id
               JOIN roles r          ON r.id = ur.rol_id
              WHERE u.activo = 1 AND r.clave = 'admin'
              ORDER BY u.id LIMIT 1"
        );
        return $admin === null ? null : (int) $admin;
    }

    /** Corta la ejecución con 401 si no hay sesión válida. */
    public static function exigir(): int
    {
        $id = self::usuarioId();
        if ($id === null) {
            Http::error('Sesión no iniciada o expirada.', 401);
        }
        return $id;
    }

    public static function perfil(int $usuarioId): array
    {
        $u = Database::uno(
            'SELECT id, usuario, nombre_completo, persona_id FROM usuarios WHERE id = ?',
            [$usuarioId]
        ) ?? [];
        $u['roles'] = array_column(Database::todos(
            'SELECT r.clave FROM usuario_roles ur JOIN roles r ON r.id = ur.rol_id WHERE ur.usuario_id = ?',
            [$usuarioId]
        ), 'clave');
        $u['permisos'] = array_column(Database::todos(
            'SELECT DISTINCT p.clave
               FROM usuario_roles ur
               JOIN rol_permisos rp ON rp.rol_id = ur.rol_id
               JOIN permisos p ON p.id = rp.permiso_id
              WHERE ur.usuario_id = ?',
            [$usuarioId]
        ), 'clave');
        return $u;
    }

    public static function puede(string $permiso): bool
    {
        $id = self::usuarioId();
        if ($id === null) {
            return false;
        }
        static $cache = [];
        $cache[$id] ??= self::perfil($id)['permisos'];
        return in_array($permiso, $cache[$id], true);
    }

    public static function auditar(string $accion, ?int $usuarioId, array $datos, string $tabla = 'usuarios'): void
    {
        try {
            Database::query(
                'INSERT INTO auditoria (tabla, registro_id, accion, usuario_id, datos_despues, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $tabla,
                    $usuarioId,
                    $accion,
                    $usuarioId,
                    json_encode($datos, JSON_UNESCAPED_UNICODE),
                    Http::ipBinaria(),
                    Http::userAgent(),
                ]
            );
        } catch (\Throwable) {
            // La auditoría nunca debe tumbar la operación principal.
        }
    }
}
