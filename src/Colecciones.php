<?php
declare(strict_types=1);

namespace Centro;

use Centro\Repos\Asistencia;
use Centro\Repos\AuthConfig;
use Centro\Repos\CentroInfo;
use Centro\Repos\Citas;
use Centro\Repos\Eventos;
use Centro\Repos\Gastos;
use Centro\Repos\Historia;
use Centro\Repos\Pacientes;
use Centro\Repos\Pagos;
use Centro\Repos\PersonalConfig;
use Centro\Repos\Personales;
use Centro\Repos\Practicantes;
use Centro\Repos\Productos;
use Centro\Repos\Profesionales;
use Centro\Repos\Servicios;
use Centro\Repos\Talleres;
use Centro\Repos\UsosConsultorio;

/** Traduce la clave de colección que usa el panel al repositorio adecuado. */
final class Colecciones
{
    /**
     * Orden de escritura. Importa: las citas necesitan que existan los
     * pacientes y profesionales, los pagos necesitan los paquetes, etc.
     */
    public const ORDEN = [
        'centerInfo', 'authConfig', 'services', 'professionals', 'practicantes',
        'patients', 'appointments', 'payments', 'roomUsage', 'expenses',
        'attendanceLog', 'calendarEvents', 'products',
        // Los talleres necesitan al profesional que los dicta. Las cuentas
        // personales no dependen de nada del centro: van al final.
        'talleres', 'personalConfig', 'personalEntries',
    ];

    public static function para(string $clave): ?Repositorio
    {
        if (str_starts_with($clave, 'historia_')) {
            $uid = substr($clave, strlen('historia_'));
            return $uid === '' ? null : new Historia($uid);
        }

        return match ($clave) {
            'patients'       => new Pacientes(),
            'appointments'   => new Citas(),
            'professionals'  => new Profesionales(),
            'practicantes'   => new Practicantes(),
            'services'       => new Servicios(),
            'payments'       => new Pagos(),
            'expenses'       => new Gastos(),
            'calendarEvents' => new Eventos(),
            'products'       => new Productos(),
            'centerInfo'     => new CentroInfo(),
            'roomUsage'      => new UsosConsultorio(),
            'attendanceLog'  => new Asistencia(),
            'authConfig'     => new AuthConfig(),
            'talleres'       => new Talleres(),
            'personalEntries' => new Personales(),
            'personalConfig' => new PersonalConfig(),
            default          => null,
        };
    }

    /** Clave con formato aceptable (evita inyectar cualquier cosa). */
    public static function valida(string $clave): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $clave) === 1;
    }
}
