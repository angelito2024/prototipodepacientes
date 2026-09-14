<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'centerInfo' (objeto, no lista).
 *
 * El token de apiperu.dev NO se devuelve al navegador: vive en la tabla
 * `integraciones` y lo usa el proxy del servidor (api/dni.php). En el
 * prototipo viaja dentro del JSON del panel, así que cualquiera con acceso
 * a la pantalla podía leerlo desde la consola.
 */
final class CentroInfo extends Repositorio
{
    public function clave(): string
    {
        return 'centerInfo';
    }

    public function leer(): array
    {
        $c = Database::uno('SELECT * FROM centro_config WHERE id = 1') ?? [];
        return [
            'name'                => (string) ($c['razon_social'] ?? 'Centro Psicológico'),
            'address'             => (string) ($c['direccion'] ?? ''),
            'phone'               => (string) ($c['telefono'] ?? ''),
            'ruc'                 => (string) ($c['ruc'] ?? ''),
            'slogan'              => (string) ($c['lema'] ?? ''),
            // Quién responde por el centro y por los datos de los pacientes.
            // Va en el consentimiento informado, que debe identificarlo con
            // nombre y documento (Ley 29733 y su Reglamento).
            'titular_nombre'      => (string) ($c['titular_nombre'] ?? ''),
            'titular_documento'   => (string) ($c['titular_documento'] ?? ''),
            'titular_cargo'       => (string) ($c['titular_cargo'] ?? ''),
            'titular_colegiatura' => (string) ($c['titular_colegiatura'] ?? ''),
            'paymentMethods'      => (string) ($c['medios_pago'] ?? ''),
            // Salas fijas de videollamada: se guardan una vez y se reúsan en
            // cada cita virtual, sin tener que crearlas de nuevo cada vez.
            'zoomSala'            => (string) ($c['sala_zoom'] ?? ''),
            'meetSala'            => (string) ($c['sala_meet'] ?? ''),
            'reminderMinutes'     => (int) ($c['minutos_recordatorio'] ?? 10),
            'sessionMinutes'      => (int) ($c['minutos_sesion'] ?? 60),
            'rescheduleHours'     => (int) ($c['horas_reprogramacion'] ?? 24),
            'latePenaltyPercent'  => (float) ($c['porcentaje_penalidad'] ?? 10),
            'includePolicy'       => (int) ($c['incluir_politica'] ?? 1) === 1,
            // Se informa si hay token configurado, pero nunca su valor.
            'apiPeruToken'        => '',
            'apiPeruConfigurado'  => Database::valor(
                "SELECT COUNT(*) FROM integraciones WHERE clave = 'apiperu_token' AND valor IS NOT NULL"
            ) > 0,
        ];
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }

        Database::query(
            'INSERT INTO centro_config
                (id, razon_social, direccion, telefono, ruc, lema, medios_pago,
                 titular_nombre, titular_documento, titular_cargo, titular_colegiatura,
                 sala_zoom, sala_meet,
                 minutos_recordatorio, minutos_sesion, horas_reprogramacion,
                 porcentaje_penalidad, incluir_politica)
             VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                razon_social=VALUES(razon_social), direccion=VALUES(direccion),
                telefono=VALUES(telefono), ruc=VALUES(ruc), lema=VALUES(lema),
                medios_pago=VALUES(medios_pago),
                titular_nombre=VALUES(titular_nombre),
                titular_documento=VALUES(titular_documento),
                titular_cargo=VALUES(titular_cargo),
                titular_colegiatura=VALUES(titular_colegiatura),
                sala_zoom=VALUES(sala_zoom), sala_meet=VALUES(sala_meet),
                minutos_recordatorio=VALUES(minutos_recordatorio),
                minutos_sesion=VALUES(minutos_sesion),
                horas_reprogramacion=VALUES(horas_reprogramacion),
                porcentaje_penalidad=VALUES(porcentaje_penalidad),
                incluir_politica=VALUES(incluir_politica)',
            [
                self::txt($valor['name'] ?? '') ?: 'Centro Psicológico',
                self::nz($valor['address'] ?? null),
                self::nz($valor['phone'] ?? null),
                self::nz($valor['ruc'] ?? null),
                self::nz($valor['slogan'] ?? null),
                self::nz($valor['paymentMethods'] ?? null),
                self::nz($valor['titular_nombre'] ?? null),
                self::nz($valor['titular_documento'] ?? null),
                self::nz($valor['titular_cargo'] ?? null),
                self::nz($valor['titular_colegiatura'] ?? null),
                self::nz($valor['zoomSala'] ?? null),
                self::nz($valor['meetSala'] ?? null),
                self::ent($valor['reminderMinutes'] ?? 10) ?: 10,
                // Duración de la sesión: es el rango con el que se comparan
                // los cruces de agenda.
                self::ent($valor['sessionMinutes'] ?? 60) ?: 60,
                self::ent($valor['rescheduleHours'] ?? 24) ?: 24,
                self::num($valor['latePenaltyPercent'] ?? 10),
                self::bool($valor['includePolicy'] ?? true),
            ]
        );

        // Solo se escribe el token si el panel manda uno nuevo; una cadena
        // vacía significa "no lo toques", no "bórralo".
        $token = self::nz($valor['apiPeruToken'] ?? null);
        if ($token !== null) {
            Database::query(
                'INSERT INTO integraciones (clave, valor, descripcion)
                 VALUES (\'apiperu_token\', ?, \'Token de apiperu.dev para consulta de DNI\')
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                [$token]
            );
        }

        $this->subirVersion();
    }
}
