<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'personalConfig' (objeto, no lista): la clave que protege la
 * pestaña de cuentas personales.
 *
 * Se guarda el hash, nunca la clave. En el panel el PIN viajaba en texto
 * plano al navegador —bastaba abrir la consola para leerlo— y salía
 * también en el JSON de la copia de seguridad, que es un archivo de texto.
 * Aquí `leer()` informa solo de si hay clave puesta; la comprobación la
 * hace el servidor en `verificar()`.
 */
final class PersonalConfig extends Repositorio
{
    public function clave(): string
    {
        return 'personalConfig';
    }

    public function leer(): array
    {
        $usuarioId = Auth::duenioDeLoPersonal();
        if ($usuarioId === null) {
            return ['pinEnabled' => false, 'pin' => ''];
        }
        $f = Database::uno(
            'SELECT pin_activo, pin_hash FROM personal_config WHERE usuario_id = ?',
            [$usuarioId]
        );
        return [
            'pinEnabled' => $f !== null && (int) $f['pin_activo'] === 1 && $f['pin_hash'] !== null,
            // El valor no se devuelve nunca. El panel lo comprueba contra
            // el servidor (accion=personal_pin), no en JavaScript.
            'pin'        => '',
        ];
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $usuarioId = Auth::duenioDeLoPersonal();
        if ($usuarioId === null) {
            return;
        }

        $activo = self::bool($valor['pinEnabled'] ?? false);
        $pin    = self::nz($valor['pin'] ?? null);

        if ($activo === 0) {
            // Desactivar borra el hash: una clave que ya no protege nada no
            // tiene por qué seguir guardada.
            Database::query(
                'INSERT INTO personal_config (usuario_id, pin_activo, pin_hash)
                 VALUES (?,0,NULL)
                 ON DUPLICATE KEY UPDATE pin_activo = 0, pin_hash = NULL',
                [$usuarioId]
            );
            $this->subirVersion();
            return;
        }

        if ($pin === null) {
            // Activada pero sin clave nueva: el panel ya no recibe el valor,
            // así que un guardado con el campo vacío conserva la que hay.
            Database::query(
                'UPDATE personal_config SET pin_activo = 1
                  WHERE usuario_id = ? AND pin_hash IS NOT NULL',
                [$usuarioId]
            );
            $this->subirVersion();
            return;
        }

        Database::query(
            'INSERT INTO personal_config (usuario_id, pin_activo, pin_hash)
             VALUES (?,1,?)
             ON DUPLICATE KEY UPDATE pin_activo = 1, pin_hash = VALUES(pin_hash)',
            [$usuarioId, password_hash($pin, PASSWORD_DEFAULT)]
        );
        $this->subirVersion();
    }

    /** ¿Es esta la clave de quien tiene abiertas sus cuentas personales? */
    public static function verificar(string $pin): bool
    {
        $usuarioId = Auth::duenioDeLoPersonal();
        if ($usuarioId === null) {
            return false;
        }
        $hash = Database::valor(
            'SELECT pin_hash FROM personal_config WHERE usuario_id = ? AND pin_activo = 1',
            [$usuarioId]
        );
        return is_string($hash) && password_verify($pin, $hash);
    }
}
