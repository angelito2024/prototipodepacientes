<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;
use RuntimeException;

/**
 * Colección 'usuarios': quién entra al sistema y con qué rol.
 *
 * Hasta ahora había una sola cuenta y todo el equipo trabajaba con ella.
 * Dos consecuencias: los roles no servían de nada —no había a quién
 * asignárselos— y el registro de auditoría decía siempre lo mismo, así que
 * no permitía saber quién hizo qué.
 *
 * Reglas que se sostienen desde aquí:
 *
 *  · la contraseña nunca sale de la base, ni siquiera con hash;
 *  · nadie puede desactivarse ni quitarse el rol a sí mismo, porque el
 *    sistema se quedaría sin quién lo administre;
 *  · tiene que quedar siempre al menos un administrador activo;
 *  · las cuentas no se borran, se desactivan: borrarlas dejaría la
 *    auditoría apuntando a alguien que ya no existe.
 */
final class Usuarios extends Repositorio
{
    private const CLAVE_MINIMA = 8;

    public function clave(): string
    {
        return 'usuarios';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            'SELECT u.id, u.usuario, u.nombre_completo, u.email, u.activo,
                    u.ultimo_acceso, u.intentos_fallidos, u.bloqueado_hasta,
                    GROUP_CONCAT(r.clave ORDER BY r.clave) AS roles
               FROM usuarios u
          LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
          LEFT JOIN roles r          ON r.id = ur.rol_id
           GROUP BY u.id
           ORDER BY u.activo DESC, u.usuario'
        );
        $yo = Auth::usuarioId();
        return array_map(static function (array $f) use ($yo): array {
            return [
                'id'        => (int) $f['id'],
                'usuario'   => $f['usuario'],
                'nombre'    => $f['nombre_completo'],
                'email'     => $f['email'],
                'activo'    => (int) $f['activo'] === 1,
                'roles'     => $f['roles'] === null ? [] : explode(',', (string) $f['roles']),
                'ultimoAcceso' => $f['ultimo_acceso'],
                'bloqueadoHasta' => $f['bloqueado_hasta'],
                'soyYo'     => (int) $f['id'] === $yo,
                // La contraseña no viaja nunca, ni siquiera su hash.
            ];
        }, $filas);
    }

    /** Los roles disponibles, para armar el desplegable. */
    public static function roles(): array
    {
        return array_map(static fn(array $r): array => [
            'clave'  => $r['clave'],
            'nombre' => $r['nombre'],
            'descripcion' => $r['descripcion'],
        ], Database::todos('SELECT clave, nombre, descripcion FROM roles ORDER BY id'));
    }

    /** Desde el panel solo se cambia el estado y el rol, nunca la clave. */
    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        foreach ($valor as $u) {
            if (!is_array($u) || empty($u['id'])) {
                continue;
            }
            self::actualizar((int) $u['id'], $u);
        }
        $this->subirVersion();
    }

    // ------------------------------------------------------------------

    public static function crear(array $datos): int
    {
        $usuario = strtolower(trim((string) ($datos['usuario'] ?? '')));
        $nombre  = trim((string) ($datos['nombre'] ?? ''));
        $clave   = (string) ($datos['clave'] ?? '');
        $rol     = (string) ($datos['rol'] ?? '');

        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
            throw new RuntimeException(
                'El nombre de usuario debe tener entre 3 y 40 caracteres: letras, '
                . 'números, punto, guion o guion bajo. Sin espacios ni tildes.'
            );
        }
        if ($nombre === '') {
            throw new RuntimeException('Escribe el nombre completo de la persona.');
        }
        if (mb_strlen($clave) < self::CLAVE_MINIMA) {
            throw new RuntimeException(
                'La contraseña debe tener al menos ' . self::CLAVE_MINIMA . ' caracteres.'
            );
        }
        if (Database::valor('SELECT 1 FROM usuarios WHERE usuario = ?', [$usuario]) !== null) {
            throw new RuntimeException("Ya existe un usuario «{$usuario}».");
        }
        $rolId = Database::valor('SELECT id FROM roles WHERE clave = ?', [$rol]);
        if ($rolId === null) {
            throw new RuntimeException('Elige un rol para esta persona.');
        }

        Database::query(
            'INSERT INTO usuarios (usuario, password_hash, nombre_completo, email, activo)
             VALUES (?,?,?,?,1)',
            [$usuario, password_hash($clave, PASSWORD_DEFAULT), $nombre,
             self::nz($datos['email'] ?? null)]
        );
        $id = (int) Database::valor('SELECT id FROM usuarios WHERE usuario = ?', [$usuario]);
        Database::query('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (?,?)', [$id, (int) $rolId]);

        Auth::auditar('CREAR_USUARIO', Auth::usuarioId(),
            ['usuario' => $usuario, 'rol' => $rol], 'usuarios');
        return $id;
    }

    public static function actualizar(int $id, array $datos): void
    {
        $yo = Auth::usuarioId();
        $fila = Database::uno('SELECT usuario, activo FROM usuarios WHERE id = ?', [$id]);
        if ($fila === null) {
            throw new RuntimeException('Ese usuario ya no existe.');
        }

        if (array_key_exists('activo', $datos)) {
            $activo = $datos['activo'] ? 1 : 0;
            if ($activo === 0) {
                if ($id === $yo) {
                    throw new RuntimeException(
                        'No puedes desactivar tu propia cuenta: te quedarías fuera del sistema.'
                    );
                }
                self::exigirOtroAdministrador($id);
            }
            Database::query('UPDATE usuarios SET activo = ? WHERE id = ?', [$activo, $id]);
            // Desactivar sin desbloquear deja a alguien fuera dos veces.
            if ($activo === 1) {
                Database::query(
                    'UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?',
                    [$id]
                );
            }
        }

        if (!empty($datos['nombre'])) {
            Database::query('UPDATE usuarios SET nombre_completo = ? WHERE id = ?',
                [trim((string) $datos['nombre']), $id]);
        }
        if (array_key_exists('email', $datos)) {
            Database::query('UPDATE usuarios SET email = ? WHERE id = ?',
                [self::nz($datos['email']), $id]);
        }

        if (!empty($datos['rol'])) {
            $rolId = Database::valor('SELECT id FROM roles WHERE clave = ?', [(string) $datos['rol']]);
            if ($rolId === null) {
                throw new RuntimeException('Ese rol no existe.');
            }
            if ($id === $yo && $datos['rol'] !== 'admin') {
                throw new RuntimeException(
                    'No puedes quitarte a ti mismo el rol de administrador. '
                    . 'Pídeselo a otro administrador.'
                );
            }
            if ($datos['rol'] !== 'admin') {
                self::exigirOtroAdministrador($id);
            }
            Database::query('DELETE FROM usuario_roles WHERE usuario_id = ?', [$id]);
            Database::query('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (?,?)',
                [$id, (int) $rolId]);
        }

        Auth::auditar('EDITAR_USUARIO', $yo,
            ['usuario' => $fila['usuario'], 'cambios' => array_keys($datos)], 'usuarios');
    }

    /** Le pone una contraseña nueva a alguien que olvidó la suya. */
    public static function restablecerClave(int $id, string $claveNueva): string
    {
        if (mb_strlen($claveNueva) < self::CLAVE_MINIMA) {
            throw new RuntimeException(
                'La contraseña debe tener al menos ' . self::CLAVE_MINIMA . ' caracteres.'
            );
        }
        $usuario = Database::valor('SELECT usuario FROM usuarios WHERE id = ?', [$id]);
        if ($usuario === null) {
            throw new RuntimeException('Ese usuario ya no existe.');
        }
        Database::query(
            'UPDATE usuarios SET password_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL
              WHERE id = ?',
            [password_hash($claveNueva, PASSWORD_DEFAULT), $id]
        );
        Auth::auditar('RESTABLECER_CLAVE', Auth::usuarioId(),
            ['usuario' => $usuario], 'usuarios');
        return (string) $usuario;
    }

    /**
     * Impide dejar al sistema sin administrador activo. Sin esta
     * comprobación, bastaría un clic para que nadie pudiera volver a
     * crear usuarios ni cambiar la configuración.
     */
    private static function exigirOtroAdministrador(int $excepto): void
    {
        $otros = (int) Database::valor(
            "SELECT COUNT(*) FROM usuarios u
               JOIN usuario_roles ur ON ur.usuario_id = u.id
               JOIN roles r ON r.id = ur.rol_id
              WHERE r.clave = 'admin' AND u.activo = 1 AND u.id <> ?",
            [$excepto]
        );
        if ($otros === 0) {
            throw new RuntimeException(
                'Es el único administrador activo. Si lo quitas, nadie podría '
                . 'volver a administrar el sistema. Nombra antes a otro.'
            );
        }
    }
}
