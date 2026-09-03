<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'patients'.
 *
 * Un elemento del panel se reparte en cuatro tablas:
 *   personas              datos base (nombre, documento, contacto)
 *   pacientes             lo propio de su condición de paciente
 *   paciente_acompanantes acompañantes y apoderado
 *   paciente_paquetes     paquete vigente + historial de paquetes cerrados
 */
final class Pacientes extends Repositorio
{
    private const TIPOS = ['Individual','Pareja','Familia','Colegio','Organizacional','Evaluación','Taller','Charla'];
    private const MODALIDADES = ['Presencial','Virtual','Domicilio'];
    private const INFORMES = ['No aplica','Pendiente','En revisión','Entregado'];
    private const ESTADOS_PAGO = ['Pendiente','Parcial','Completo'];

    public function clave(): string
    {
        return 'patients';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            "SELECT p.id, p.uid, p.nombre_completo, p.documento, p.telefono, p.email,
                    p.dia_cumple, p.notas, p.activo,
                    pa.tipo_atencion, pa.modalidad_default, pa.es_menor,
                    pa.enlace_default, pa.direccion_default, pa.referencia_default,
                    pa.estado_informe, pa.fecha_limite_informe, pa.estado_pago_manual,
                    prof.uid AS profesional_uid
               FROM pacientes pa
               JOIN personas p    ON p.id = pa.persona_id
               LEFT JOIN personas prof ON prof.id = pa.profesional_id
              WHERE p.eliminado_en IS NULL
              ORDER BY p.id"
        );
        if ($filas === []) {
            return [];
        }

        $acomp   = $this->leerAcompanantes();
        $paquetes = $this->leerPaquetes();

        $salida = [];
        foreach ($filas as $f) {
            $pid = (int) $f['id'];
            $a   = $acomp[$pid] ?? ['apoderado' => null, 'lista' => []];
            $pk  = $paquetes[$pid] ?? ['activo' => null, 'historial' => []];
            $act = $pk['activo'];

            $salida[] = [
                'id'                 => (string) $f['uid'],
                'name'               => (string) $f['nombre_completo'],
                'dni'                => (string) ($f['documento'] ?? ''),
                'phone'              => (string) ($f['telefono'] ?? ''),
                'email'              => (string) ($f['email'] ?? ''),
                'type'               => (string) $f['tipo_atencion'],
                'modality'           => (string) $f['modalidad_default'],
                'professionalId'     => (string) ($f['profesional_uid'] ?? ''),
                'billingType'        => $act['billingType'] ?? 'Paquete',
                'packageTotal'       => $act['packageTotal'] ?? 0,
                'sessionsUsed'       => $act['sessionsUsed'] ?? 0,
                'billedSessions'     => $act['billedSessions'] ?? 0,
                'pricePerSession'    => $act['pricePerSession'] ?? 0,
                'packageStartDate'   => $act['packageStartDate'] ?? '',
                'paquetesHistorial'  => $pk['historial'],
                'paymentStatus'      => (string) $f['estado_pago_manual'],
                'reportStatus'       => (string) $f['estado_informe'],
                'reportDueDate'      => (string) ($f['fecha_limite_informe'] ?? ''),
                'birthday'           => (string) ($f['dia_cumple'] ?? ''),
                'isMinor'            => (int) $f['es_menor'] === 1,
                'guardianName'       => $a['apoderado']['name']  ?? '',
                'guardianPhone'      => $a['apoderado']['phone'] ?? '',
                'guardianEmail'      => $a['apoderado']['email'] ?? '',
                'guardianDni'        => $a['apoderado']['dni']   ?? '',
                'defaultMeetingLink' => (string) ($f['enlace_default'] ?? ''),
                'defaultAddress'     => (string) ($f['direccion_default'] ?? ''),
                'defaultReference'   => (string) ($f['referencia_default'] ?? ''),
                'companions'         => $a['lista'],
                'notes'              => (string) ($f['notas'] ?? ''),
                'active'             => (int) $f['activo'] === 1,
            ];
        }
        return $salida;
    }

    /** @return array<int,array{apoderado:?array,lista:list<array>}> */
    private function leerAcompanantes(): array
    {
        $out = [];
        $filas = Database::todos(
            'SELECT paciente_id, id, nombre, documento, telefono, email, es_apoderado
               FROM paciente_acompanantes ORDER BY id'
        );
        foreach ($filas as $f) {
            $pid = (int) $f['paciente_id'];
            $out[$pid] ??= ['apoderado' => null, 'lista' => []];
            $reg = [
                'id'    => 'ac' . $f['id'],
                'name'  => (string) $f['nombre'],
                'dni'   => (string) ($f['documento'] ?? ''),
                'phone' => (string) ($f['telefono'] ?? ''),
                'email' => (string) ($f['email'] ?? ''),
            ];
            if ((int) $f['es_apoderado'] === 1) {
                $out[$pid]['apoderado'] = $reg;
            } else {
                $out[$pid]['lista'][] = $reg;
            }
        }
        return $out;
    }

    /** @return array<int,array{activo:?array,historial:list<array>}> */
    private function leerPaquetes(): array
    {
        $out = [];
        $filas = Database::todos(
            'SELECT paciente_id, uid, tipo_facturacion, sesiones_totales, sesiones_usadas,
                    sesiones_facturadas, precio_sesion, fecha_inicio, fecha_cierre, estado
               FROM paciente_paquetes
              WHERE estado <> \'Anulado\'
              ORDER BY fecha_inicio, id'
        );
        foreach ($filas as $f) {
            $pid = (int) $f['paciente_id'];
            $out[$pid] ??= ['activo' => null, 'historial' => []];
            if ($f['estado'] === 'Activo') {
                $out[$pid]['activo'] = [
                    'billingType'      => (string) $f['tipo_facturacion'],
                    'packageTotal'     => (int) ($f['sesiones_totales'] ?? 0),
                    'sessionsUsed'     => (int) $f['sesiones_usadas'],
                    'billedSessions'   => (int) $f['sesiones_facturadas'],
                    'pricePerSession'  => (float) $f['precio_sesion'],
                    'packageStartDate' => (string) $f['fecha_inicio'],
                ];
            } else {
                $out[$pid]['historial'][] = [
                    'id'              => (string) ($f['uid'] ?? ''),
                    'packageTotal'    => (int) ($f['sesiones_totales'] ?? 0),
                    'sessionsUsed'    => (int) $f['sesiones_usadas'],
                    'pricePerSession' => (float) $f['precio_sesion'],
                    'cerradoEl'       => (string) ($f['fecha_cierre'] ?? ''),
                ];
            }
        }
        return $out;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $profesionales = self::mapaPersonas('profesionales');
        $uids = [];

        foreach ($valor as $item) {
            if (!is_array($item)) {
                continue;
            }
            $personaId = Personas::upsert($item);
            $uids[] = self::txt($item['id'] ?? '');

            $profUid = self::txt($item['professionalId'] ?? '');
            Database::query(
                'INSERT INTO pacientes
                    (persona_id, tipo_atencion, modalidad_default, profesional_id, es_menor,
                     enlace_default, direccion_default, referencia_default,
                     estado_informe, fecha_limite_informe, estado_pago_manual)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    tipo_atencion        = VALUES(tipo_atencion),
                    modalidad_default    = VALUES(modalidad_default),
                    profesional_id       = VALUES(profesional_id),
                    es_menor             = VALUES(es_menor),
                    enlace_default       = VALUES(enlace_default),
                    direccion_default    = VALUES(direccion_default),
                    referencia_default   = VALUES(referencia_default),
                    estado_informe       = VALUES(estado_informe),
                    fecha_limite_informe = VALUES(fecha_limite_informe),
                    estado_pago_manual   = VALUES(estado_pago_manual)',
                [
                    $personaId,
                    self::enum($item['type'] ?? null, self::TIPOS, 'Individual'),
                    self::enum($item['modality'] ?? null, self::MODALIDADES, 'Presencial'),
                    $profesionales[$profUid] ?? null,
                    self::bool($item['isMinor'] ?? false),
                    self::nz($item['defaultMeetingLink'] ?? null),
                    self::nz($item['defaultAddress'] ?? null),
                    self::nz($item['defaultReference'] ?? null),
                    self::enum($item['reportStatus'] ?? null, self::INFORMES, 'No aplica'),
                    self::fecha($item['reportDueDate'] ?? null),
                    self::enum($item['paymentStatus'] ?? null, self::ESTADOS_PAGO, 'Pendiente'),
                ]
            );

            $this->guardarAcompanantes($personaId, $item);
            $this->guardarPaquetes($personaId, $item);
        }

        Personas::bajaFaltantes('pacientes', array_filter($uids));
        $this->subirVersion();
    }

    /**
     * Se actualiza en vez de borrar y reinsertar: así el id de cada
     * acompañante es estable entre guardados y el AUTO_INCREMENT no crece
     * sin motivo cada vez que se toca la ficha.
     */
    private function guardarAcompanantes(int $pacienteId, array $item): void
    {
        $conservar = [];

        $apoderado = self::nz($item['guardianName'] ?? null);
        if ($apoderado !== null) {
            $idApoderado = Database::valor(
                'SELECT id FROM paciente_acompanantes WHERE paciente_id = ? AND es_apoderado = 1 LIMIT 1',
                [$pacienteId]
            );
            $conservar[] = $this->upsertAcompanante($pacienteId, [
                'id'    => $idApoderado === null ? null : 'ac' . $idApoderado,
                'name'  => $apoderado,
                'dni'   => $item['guardianDni'] ?? null,
                'phone' => $item['guardianPhone'] ?? null,
                'email' => $item['guardianEmail'] ?? null,
            ], true);
        }

        foreach ((array) ($item['companions'] ?? []) as $c) {
            if (!is_array($c) || self::nz($c['name'] ?? null) === null) {
                continue;
            }
            $conservar[] = $this->upsertAcompanante($pacienteId, $c, false);
        }

        $sql = 'DELETE FROM paciente_acompanantes WHERE paciente_id = ?';
        $par = [$pacienteId];
        if ($conservar !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($conservar), '?')) . ')';
            $par = [...$par, ...$conservar];
        }
        Database::query($sql, $par);
    }

    private function upsertAcompanante(int $pacienteId, array $c, bool $esApoderado): int
    {
        $params = [
            $pacienteId,
            self::txt($c['name']),
            self::nz($c['dni'] ?? null),
            self::nz($c['phone'] ?? null),
            self::nz($c['email'] ?? null),
            $esApoderado ? 1 : 0,
        ];

        $marcado = self::txt($c['id'] ?? '');
        if (str_starts_with($marcado, 'ac')) {
            $id = (int) substr($marcado, 2);
            $existe = Database::valor(
                'SELECT id FROM paciente_acompanantes WHERE id = ? AND paciente_id = ?',
                [$id, $pacienteId]
            );
            if ($existe !== null) {
                Database::query(
                    'UPDATE paciente_acompanantes
                        SET paciente_id=?, nombre=?, documento=?, telefono=?, email=?, es_apoderado=?
                      WHERE id=?',
                    [...$params, $id]
                );
                return $id;
            }
        }

        Database::query(
            'INSERT INTO paciente_acompanantes
                (paciente_id, nombre, documento, telefono, email, es_apoderado)
             VALUES (?,?,?,?,?,?)',
            $params
        );
        return Database::ultimoId();
    }

    /**
     * El panel lleva el paquete vigente en campos sueltos del paciente y los
     * anteriores en paquetesHistorial[]. Aquí todos son filas: primero se
     * cierran los históricos, después se deja uno solo en estado Activo
     * (uq_paquete_activo lo garantiza).
     */
    private function guardarPaquetes(int $pacienteId, array $item): void
    {
        $vistos = [];

        foreach ((array) ($item['paquetesHistorial'] ?? []) as $h) {
            if (!is_array($h)) {
                continue;
            }
            $uid = self::txt($h['id'] ?? '') ?: self::nuevoUid();
            $vistos[] = $uid;
            $total = self::ent($h['packageTotal'] ?? 0);
            Database::query(
                'INSERT INTO paciente_paquetes
                    (uid, paciente_id, tipo_facturacion, sesiones_totales, sesiones_usadas,
                     precio_sesion, fecha_inicio, fecha_cierre, estado)
                 VALUES (?,?,?,?,?,?,?,?,\'Cerrado\')
                 ON DUPLICATE KEY UPDATE
                    sesiones_totales = VALUES(sesiones_totales),
                    sesiones_usadas  = VALUES(sesiones_usadas),
                    precio_sesion    = VALUES(precio_sesion),
                    fecha_cierre     = VALUES(fecha_cierre),
                    estado           = \'Cerrado\'',
                [
                    $uid,
                    $pacienteId,
                    $total > 0 ? 'Paquete' : 'Individual',
                    $total > 0 ? $total : null,
                    self::ent($h['sessionsUsed'] ?? 0),
                    self::num($h['pricePerSession'] ?? 0),
                    self::fecha($h['cerradoEl'] ?? null) ?? date('Y-m-d'),
                    self::fecha($h['cerradoEl'] ?? null) ?? date('Y-m-d'),
                ]
            );
        }

        $tipo  = self::enum($item['billingType'] ?? null, ['Paquete','Individual'], 'Paquete');
        $total = self::ent($item['packageTotal'] ?? 0);

        $activoUid = Database::valor(
            "SELECT uid FROM paciente_paquetes WHERE paciente_id = ? AND estado = 'Activo'",
            [$pacienteId]
        );
        $activoUid ??= self::nuevoUid();
        $vistos[] = (string) $activoUid;

        Database::query(
            'INSERT INTO paciente_paquetes
                (uid, paciente_id, tipo_facturacion, sesiones_totales, sesiones_usadas,
                 sesiones_facturadas, precio_sesion, fecha_inicio, estado)
             VALUES (?,?,?,?,?,?,?,?,\'Activo\')
             ON DUPLICATE KEY UPDATE
                tipo_facturacion    = VALUES(tipo_facturacion),
                sesiones_totales    = VALUES(sesiones_totales),
                sesiones_usadas     = VALUES(sesiones_usadas),
                sesiones_facturadas = VALUES(sesiones_facturadas),
                precio_sesion       = VALUES(precio_sesion),
                fecha_inicio        = VALUES(fecha_inicio)',
            [
                $activoUid,
                $pacienteId,
                $tipo,
                $tipo === 'Individual' ? null : ($total > 0 ? $total : null),
                self::ent($item['sessionsUsed'] ?? 0),
                self::ent($item['billedSessions'] ?? 0),
                self::num($item['pricePerSession'] ?? 0),
                self::fecha($item['packageStartDate'] ?? null) ?? date('Y-m-d'),
            ]
        );

        // Paquetes que la app ya no reporta: se anulan, no se borran.
        $sobran = Database::todos(
            'SELECT id FROM paciente_paquetes WHERE paciente_id = ? AND estado <> \'Anulado\'',
            [$pacienteId]
        );
        $vigentes = Database::todos(
            'SELECT id FROM paciente_paquetes WHERE paciente_id = ? AND uid IN ('
            . implode(',', array_fill(0, max(1, count($vistos)), '?')) . ')',
            [$pacienteId, ...($vistos ?: [''])]
        );
        $idsVigentes = array_column($vigentes, 'id');
        foreach ($sobran as $s) {
            if (!in_array($s['id'], $idsVigentes, false)) {
                Database::query(
                    "UPDATE paciente_paquetes SET estado = 'Anulado' WHERE id = ?",
                    [$s['id']]
                );
            }
        }
    }
}
