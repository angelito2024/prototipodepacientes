<?php
declare(strict_types=1);

namespace Centro;

use Centro\Repos\Asistencia;
use Centro\Repos\AuthConfig;
use Centro\Repos\CentroInfo;
use Centro\Repos\Cie10;
use Centro\Repos\Citas;
use Centro\Repos\Documento;
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
use Centro\Repos\PruebaAplicaciones;
use Centro\Repos\Pruebas;
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
        // Finanzas privadas: préstamos, fondos que organiza y juntas ajenas.
        'prestamos', 'recaudaciones', 'juntas',
        // Las categorías con que él clasifica sus ingresos y gastos.
        'categoriasPersonales',
        // El catálogo de diagnósticos: solo lectura, lo carga una migración.
        'cie10',
        // Pruebas psicológicas: el catálogo y cada aplicación a un paciente.
        // Van al final porque una aplicación necesita paciente y profesional.
        'pruebas', 'pruebaAplicaciones',
    ];

    /**
     * Qué permiso hace falta para cada sección: [leer, escribir].
     *
     * Los roles estaban definidos en la base desde el principio —Recepción
     * sin historia clínica, Profesional sin finanzas— pero no los miraba
     * nadie: cualquiera que entrara veía todo. Esta tabla es lo que les da
     * efecto.
     *
     * `null` significa que basta con haber entrado. Es el caso de los datos
     * del centro o del catálogo de diagnósticos: sin ellos la pantalla no
     * se puede ni dibujar, y no son secretos.
     */
    private const PERMISOS = [
        'patients'           => ['pacientes.ver',   'pacientes.editar'],
        'appointments'       => ['citas.ver',       'citas.editar'],
        'calendarEvents'     => ['citas.ver',       'citas.editar'],
        'roomUsage'          => ['citas.ver',       'citas.editar'],
        'talleres'           => ['citas.ver',       'citas.editar'],
        'attendanceLog'      => ['citas.ver',       'asistencia.registrar'],
        'payments'           => ['pagos.ver',       'pagos.registrar'],
        'expenses'           => ['gastos.ver',      'config.editar'],
        'professionals'      => ['pacientes.ver',   'profesionales.editar'],
        'practicantes'       => ['pacientes.ver',   'practicantes.editar'],
        'services'           => [null,              'config.editar'],
        'products'           => [null,              'config.editar'],
        'centerInfo'         => [null,              'config.editar'],
        'authConfig'         => [null,              'config.editar'],
        'cie10'              => [null,              null],
        // Las pruebas psicológicas son material clínico: van con la
        // historia, no con la agenda.
        'pruebas'            => ['historia.ver',    'config.editar'],
        'pruebaAplicaciones' => ['historia.ver',    'historia.editar'],
    ];

    /**
     * Finanzas privadas del dueño: préstamos, fondos y juntas.
     *
     * Van aparte porque se guardan como un documento único, sin columna de
     * usuario: lo que hay ahí es de una sola persona, y con solo entrar al
     * sistema cualquiera lo vería entero. Se exige el permiso de finanzas,
     * que únicamente tiene el administrador.
     *
     * (Las cuentas personales y sus categorías no están en esta lista
     * porque sí guardan a quién pertenece cada movimiento: ahí cada
     * usuario ve las suyas y no las de nadie más.)
     */
    private const FINANZAS_PRIVADAS = [
        'prestamos', 'recaudaciones', 'juntas', 'categoriasPersonales',
    ];

    /** Permiso que exige una sección, o null si basta con haber entrado. */
    public static function permiso(string $clave, bool $escribe): ?string
    {
        // Cada historia clínica va por su propia clave: historia_<paciente>.
        if (str_starts_with($clave, 'historia_')) {
            return $escribe ? 'historia.editar' : 'historia.ver';
        }
        if (in_array($clave, self::FINANZAS_PRIVADAS, true)) {
            return 'finanzas.reportes';
        }
        $par = self::PERMISOS[$clave] ?? null;
        if ($par === null) {
            // Una sección que no está en la tabla se trata como lo más
            // reservado que hay. Es preferible que algo deje de abrirse a
            // que algo se abra de más sin que nadie lo note.
            return $escribe ? 'config.editar' : 'config.editar';
        }
        return $escribe ? $par[1] : $par[0];
    }

    /** Secciones donde cada usuario ve solo sus propios movimientos. */
    public static function esDeCadaUsuario(string $clave): bool
    {
        return $clave === 'personalEntries' || $clave === 'personalConfig';
    }

    public static function para(string $clave): ?Repositorio
    {
        if (str_starts_with($clave, 'historia_')) {
            $uid = substr($clave, strlen('historia_'));
            return $uid === '' ? null : new Historia($uid);
        }

        // Colecciones privadas que se guardan como documento (ver Documento.php).
        if (in_array($clave, ['prestamos', 'recaudaciones', 'juntas', 'categoriasPersonales'], true)) {
            return new Documento($clave);
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
            'cie10'          => new Cie10(),
            'pruebas'        => new Pruebas(),
            'pruebaAplicaciones' => new PruebaAplicaciones(),
            default          => null,
        };
    }

    /** Clave con formato aceptable (evita inyectar cualquier cosa). */
    public static function valida(string $clave): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $clave) === 1;
    }
}
