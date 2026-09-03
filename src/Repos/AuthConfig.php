<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'authConfig'.
 *
 * En el prototipo esta colección guardaba {enabled, username, password} con
 * la clave EN TEXTO PLANO, y attemptLogin() la comparaba en JavaScript:
 * cualquiera que abriera la consola del navegador la leía.
 *
 * Aquí la lectura nunca devuelve una contraseña, y al guardar se crea o
 * actualiza un usuario real con hash. El inicio de sesión ocurre en el
 * servidor (api/index.php?accion=login).
 */
final class AuthConfig extends Repositorio
{
    public function clave(): string
    {
        return 'authConfig';
    }

    public function leer(): array
    {
        $requerido = (int) (Database::valor('SELECT login_requerido FROM centro_config WHERE id = 1') ?? 0);
        $usuario   = (string) (Database::valor(
            'SELECT u.usuario FROM usuarios u
               JOIN usuario_roles ur ON ur.usuario_id = u.id
               JOIN roles r ON r.id = ur.rol_id
              WHERE u.activo = 1 AND r.clave = \'admin\'
              ORDER BY u.id LIMIT 1'
        ) ?? '');

        return [
            'enabled'  => $requerido === 1,
            'username' => $usuario,
            'password' => '',        // nunca sale del servidor
        ];
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $activar = self::bool($valor['enabled'] ?? false);
        $usuario = self::nz($valor['username'] ?? null);
        $clave   = (string) ($valor['password'] ?? '');

        Database::query('UPDATE centro_config SET login_requerido = ? WHERE id = 1', [$activar]);

        // Solo se toca la contraseña si el panel manda una nueva. Al leer,
        // 'password' siempre viene vacío, así que un guardado normal de
        // "Datos del centro" no la borra.
        if ($usuario !== null && $clave !== '') {
            $existente = Database::valor('SELECT id FROM usuarios WHERE usuario = ?', [$usuario]);
            if ($existente !== null) {
                Database::query(
                    'UPDATE usuarios SET password_hash = ?, activo = 1,
                            intentos_fallidos = 0, bloqueado_hasta = NULL
                      WHERE id = ?',
                    [password_hash($clave, PASSWORD_DEFAULT), $existente]
                );
            } else {
                Database::query(
                    'INSERT INTO usuarios (usuario, password_hash, nombre_completo)
                     VALUES (?, ?, ?)',
                    [$usuario, password_hash($clave, PASSWORD_DEFAULT), $usuario]
                );
                $nuevo = Database::ultimoId();
                Database::query(
                    'INSERT IGNORE INTO usuario_roles (usuario_id, rol_id)
                     SELECT ?, id FROM roles WHERE clave = \'admin\'',
                    [$nuevo]
                );
            }
            Auth::auditar('UPDATE', Auth::usuarioId(), ['accion' => 'cambio de credenciales', 'usuario' => $usuario]);
        }

        $this->subirVersion();
    }
}
