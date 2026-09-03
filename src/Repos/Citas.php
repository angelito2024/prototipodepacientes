<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/** Colección 'appointments'. */
final class Citas extends Repositorio
{
    private const ESTADOS   = ['Programada','Confirmada','Completada','Cancelada','Reprogramada','No asistió'];
    private const MODALIDADES = ['Presencial','Virtual','Domicilio'];

    public function clave(): string
    {
        return 'appointments';
    }

    /** Etiqueta que muestra el panel ("Consultorio 1 (Adultos)") -> id. */
    private function consultoriosPorEtiqueta(): array
    {
        $mapa = [];
        foreach (Database::todos('SELECT id, nombre, descripcion FROM consultorios') as $c) {
            $etiqueta = $c['descripcion'] !== null && $c['descripcion'] !== ''
                ? sprintf('%s (%s)', $c['nombre'], $c['descripcion'])
                : (string) $c['nombre'];
            $mapa[$etiqueta] = (int) $c['id'];
            $mapa[(string) $c['nombre']] = (int) $c['id'];   // tolera la forma corta
        }
        return $mapa;
    }

    public function leer(): array
    {
        $filas = Database::todos(
            "SELECT c.uid, c.inicio, c.duracion_min, c.modalidad, c.estado,
                    c.enlace, c.direccion, c.referencia,
                    c.recordatorio_enviado, c.cuenta_sesion,
                    pac.uid AS paciente_uid, pro.uid AS profesional_uid,
                    CASE WHEN co.descripcion IS NULL OR co.descripcion = ''
                         THEN co.nombre
                         ELSE CONCAT(co.nombre, ' (', co.descripcion, ')') END AS sala
               FROM citas c
               JOIN personas pac ON pac.id = c.paciente_id
               JOIN personas pro ON pro.id = c.profesional_id
               LEFT JOIN consultorios co ON co.id = c.consultorio_id
              WHERE c.eliminado_en IS NULL
              ORDER BY c.inicio"
        );

        $salida = [];
        foreach ($filas as $f) {
            $inicio = (string) $f['inicio'];
            $salida[] = [
                'id'             => (string) $f['uid'],
                'patientId'      => (string) $f['paciente_uid'],
                'professionalId' => (string) $f['profesional_uid'],
                'date'           => substr($inicio, 0, 10),
                'time'           => substr($inicio, 11, 5),
                'modality'       => (string) $f['modalidad'],
                'room'           => (string) ($f['sala'] ?? ''),
                'status'         => (string) $f['estado'],
                'meetingLink'    => (string) ($f['enlace'] ?? ''),
                'address'        => (string) ($f['direccion'] ?? ''),
                'reference'      => (string) ($f['referencia'] ?? ''),
                'reminderSent'   => (int) $f['recordatorio_enviado'] === 1,
                'countedSession' => (int) $f['cuenta_sesion'] === 1,
            ];
        }
        return $salida;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }

        // El panel ya avisa de los cruces y permite forzarlos tras confirmar,
        // y los datos históricos pueden traerlos. La vista v_citas_solapadas
        // muestra los que hayan quedado.
        Database::query('SET @centro_permitir_solape = 1');

        $pacientes    = self::mapaPersonas('pacientes');
        $profesionales = self::mapaPersonas('profesionales');
        $salas        = $this->consultoriosPorEtiqueta();
        $paquetes     = $this->paquetesActivos();
        $uids = [];

        foreach ($valor as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pacUid  = self::txt($item['patientId'] ?? '');
            $profUid = self::txt($item['professionalId'] ?? '');
            $fecha   = self::fecha($item['date'] ?? null);
            $hora    = self::hora($item['time'] ?? null);

            // Sin paciente, profesional o fecha no hay cita que guardar:
            // las FK son obligatorias.
            if (!isset($pacientes[$pacUid], $profesionales[$profUid]) || $fecha === null) {
                continue;
            }

            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;
            $pacienteId = $pacientes[$pacUid];

            Database::query(
                'INSERT INTO citas
                    (uid, paciente_id, profesional_id, paquete_id, inicio, duracion_min,
                     modalidad, consultorio_id, enlace, direccion, referencia, estado,
                     recordatorio_enviado, cuenta_sesion, eliminado_en)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)
                 ON DUPLICATE KEY UPDATE
                    paciente_id=VALUES(paciente_id), profesional_id=VALUES(profesional_id),
                    paquete_id=VALUES(paquete_id), inicio=VALUES(inicio),
                    duracion_min=VALUES(duracion_min), modalidad=VALUES(modalidad),
                    consultorio_id=VALUES(consultorio_id), enlace=VALUES(enlace),
                    direccion=VALUES(direccion), referencia=VALUES(referencia),
                    estado=VALUES(estado),
                    recordatorio_enviado=VALUES(recordatorio_enviado),
                    cuenta_sesion=VALUES(cuenta_sesion), eliminado_en=NULL',
                [
                    $uid,
                    $pacienteId,
                    $profesionales[$profUid],
                    $paquetes[$pacienteId] ?? null,
                    $fecha . ' ' . ($hora ?? '00:00') . ':00',
                    60,
                    self::enum($item['modality'] ?? null, self::MODALIDADES, 'Presencial'),
                    $salas[self::txt($item['room'] ?? '')] ?? null,
                    self::nz($item['meetingLink'] ?? null),
                    self::nz($item['address'] ?? null),
                    self::nz($item['reference'] ?? null),
                    self::enum($item['status'] ?? null, self::ESTADOS, 'Programada'),
                    self::bool($item['reminderSent'] ?? false),
                    self::bool($item['countedSession'] ?? false),
                ]
            );
        }

        $this->bajaFaltantes($uids);
        Database::query('SET @centro_permitir_solape = 0');
        $this->subirVersion();
    }

    /** @return array<int,int> paciente_id => paquete activo */
    private function paquetesActivos(): array
    {
        $mapa = [];
        foreach (Database::todos(
            "SELECT paciente_id, id FROM paciente_paquetes WHERE estado = 'Activo'"
        ) as $f) {
            $mapa[(int) $f['paciente_id']] = (int) $f['id'];
        }
        return $mapa;
    }

    /** Las citas borradas en el panel se marcan, no se destruyen. */
    private function bajaFaltantes(array $uids): void
    {
        $sql = 'UPDATE citas SET eliminado_en = NOW() WHERE eliminado_en IS NULL';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
    }
}
