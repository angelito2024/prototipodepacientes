<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'talleres' (pestaña Talleres y charlas).
 *
 * Un taller no es un paciente: no abre historia clínica ni paquete, y su
 * dinero entra por otra vía. Por eso vive en sus propias tablas
 * (`talleres` + `taller_sesiones` + `taller_participantes`) y no como un
 * tipo raro de paciente.
 *
 * Dos cosas que el panel guardaba dentro del propio taller y aquí son
 * filas:
 *  · Las fechas. Un programa con colegio son cuatro sábados con horarios
 *    distintos; en el panel solo cabía uno, y los demás vivían dentro de
 *    un array que se reescribía entero.
 *  · Los inscritos. Su lista es la fuente de lo cobrado en el modo
 *    'participante', así que tiene que poder consultarse y sumarse sin
 *    cargar el taller completo.
 *
 * El campo `date` / `startTime` / `endTime` que el panel lee suelto se
 * devuelve derivado de la primera sesión: el panel los usa para ordenar y
 * para el cuadro de "próximos", y no hace falta que los guarde dos veces.
 */
final class Talleres extends Repositorio
{
    private const TIPOS = ['Taller', 'Charla', 'Programa organizacional', 'Programa con colegio'];
    private const MODOS = ['participante', 'grupal'];
    private const MODALIDADES = ['Presencial', 'Virtual', 'Mixto'];
    private const ESTADOS = ['Planificado', 'En curso', 'Realizado', 'Cancelado'];

    public function clave(): string
    {
        return 'talleres';
    }

    public function leer(): array
    {
        $sesiones     = $this->leerSesiones();
        $participantes = $this->leerParticipantes();

        $salida = [];
        foreach (Database::todos(
            'SELECT t.id, t.uid, t.nombre, t.tipo, t.modo_cobro, t.cliente_nombre,
                    t.cliente_contacto, t.monto_acordado, t.monto_cobrado, t.precio_persona,
                    t.cupo, t.modalidad, t.lugar, t.enlace, t.estado, t.notas,
                    t.fecha_inicio, t.creado_en,
                    pro.uid AS profesional_uid
               FROM talleres t
               LEFT JOIN personas pro ON pro.id = t.profesional_id
              WHERE t.eliminado_en IS NULL
              ORDER BY t.fecha_inicio, t.id'
        ) as $f) {
            $id   = (int) $f['id'];
            $ses  = $sesiones[$id] ?? [];
            $prim = $ses[0] ?? ['date' => '', 'start' => '', 'end' => ''];

            $salida[] = [
                'id'             => (string) $f['uid'],
                'name'           => (string) $f['nombre'],
                'serviceType'    => (string) $f['tipo'],
                'billingMode'    => (string) $f['modo_cobro'],
                'clientName'     => (string) ($f['cliente_nombre'] ?? ''),
                'clientContact'  => (string) ($f['cliente_contacto'] ?? ''),
                'agreedAmount'   => (float) ($f['monto_acordado'] ?? 0),
                'paidAmount'     => (float) $f['monto_cobrado'],
                'professionalId' => (string) ($f['profesional_uid'] ?? ''),
                'sessions'       => $ses,
                // Sueltos, para el orden y el cuadro de "próximos".
                'date'           => $prim['date'],
                'startTime'      => $prim['start'],
                'endTime'        => $prim['end'],
                'modality'       => (string) $f['modalidad'],
                'place'          => (string) ($f['lugar'] ?? ''),
                'meetingLink'    => (string) ($f['enlace'] ?? ''),
                'capacity'       => (int) ($f['cupo'] ?? 0),
                'pricePerPerson' => (float) ($f['precio_persona'] ?? 0),
                'status'         => (string) $f['estado'],
                'notes'          => (string) ($f['notas'] ?? ''),
                'participantes'  => $participantes[$id] ?? [],
                'createdAt'      => substr((string) $f['creado_en'], 0, 10),
            ];
        }
        return $salida;
    }

    /** @return array<int,list<array{id:string,date:string,start:string,end:string}>> */
    private function leerSesiones(): array
    {
        $out = [];
        foreach (Database::todos(
            'SELECT taller_id, id, uid, fecha, hora_inicio, hora_fin
               FROM taller_sesiones ORDER BY taller_id, fecha, hora_inicio'
        ) as $f) {
            $out[(int) $f['taller_id']][] = [
                'id'    => (string) ($f['uid'] ?? ('ts' . $f['id'])),
                'date'  => (string) $f['fecha'],
                'start' => substr((string) ($f['hora_inicio'] ?? ''), 0, 5),
                'end'   => substr((string) ($f['hora_fin'] ?? ''), 0, 5),
            ];
        }
        return $out;
    }

    /** @return array<int,list<array<string,mixed>>> */
    private function leerParticipantes(): array
    {
        $out = [];
        foreach (Database::todos(
            'SELECT taller_id, id, uid, nombre, documento, sexo, telefono, email,
                    procedencia, monto, pagado, asistio, diploma_entregado, inscrito_el
               FROM taller_participantes ORDER BY taller_id, id'
        ) as $f) {
            $out[(int) $f['taller_id']][] = [
                'id'                => (string) ($f['uid'] ?? ('tp' . $f['id'])),
                'name'              => (string) $f['nombre'],
                'dni'               => (string) ($f['documento'] ?? ''),
                'sex'               => Personas::sexoTexto($f['sexo'] ?? null),
                'phone'             => (string) ($f['telefono'] ?? ''),
                'email'             => (string) ($f['email'] ?? ''),
                'origin'            => (string) ($f['procedencia'] ?? ''),
                'amount'            => (float) $f['monto'],
                'paid'              => (int) $f['pagado'] === 1,
                'attended'          => (int) $f['asistio'] === 1,
                'diplomaEntregado'  => (int) $f['diploma_entregado'] === 1,
                'inscritoEl'        => (string) ($f['inscrito_el'] ?? ''),
            ];
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
            if (!is_array($item) || self::nz($item['name'] ?? null) === null) {
                continue;
            }
            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;

            $modo = self::enum($item['billingMode'] ?? null, self::MODOS, 'participante');
            $grupal = $modo === 'grupal';

            Database::query(
                'INSERT INTO talleres
                    (uid, nombre, tipo, modo_cobro, cliente_nombre, cliente_contacto,
                     monto_acordado, monto_cobrado, precio_persona, cupo, profesional_id,
                     modalidad, lugar, enlace, estado, notas)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    nombre=VALUES(nombre), tipo=VALUES(tipo), modo_cobro=VALUES(modo_cobro),
                    cliente_nombre=VALUES(cliente_nombre), cliente_contacto=VALUES(cliente_contacto),
                    monto_acordado=VALUES(monto_acordado), monto_cobrado=VALUES(monto_cobrado),
                    precio_persona=VALUES(precio_persona), cupo=VALUES(cupo),
                    profesional_id=VALUES(profesional_id), modalidad=VALUES(modalidad),
                    lugar=VALUES(lugar), enlace=VALUES(enlace), estado=VALUES(estado),
                    notas=VALUES(notas), eliminado_en=NULL',
                [
                    $uid,
                    self::txt($item['name']),
                    self::enum($item['serviceType'] ?? null, self::TIPOS, 'Taller'),
                    $modo,
                    // Lo del contratante solo se guarda en el modo grupal: en el
                    // otro no existe ese cliente y dejarlo escrito confunde.
                    $grupal ? self::nz($item['clientName'] ?? null) : null,
                    $grupal ? self::nz($item['clientContact'] ?? null) : null,
                    $grupal ? self::num($item['agreedAmount'] ?? 0) : null,
                    $grupal ? self::num($item['paidAmount'] ?? 0) : 0,
                    $grupal ? null : self::num($item['pricePerPerson'] ?? 0),
                    self::ent($item['capacity'] ?? 0) ?: null,
                    $profesionales[self::txt($item['professionalId'] ?? '')] ?? null,
                    self::enum($item['modality'] ?? null, self::MODALIDADES, 'Presencial'),
                    self::nz($item['place'] ?? null),
                    self::nz($item['meetingLink'] ?? null),
                    self::enum($item['status'] ?? null, self::ESTADOS, 'Planificado'),
                    self::nz($item['notes'] ?? null),
                ]
            );

            $tallerId = (int) Database::valor('SELECT id FROM talleres WHERE uid = ?', [$uid]);
            $this->guardarSesiones($tallerId, $item);
            $this->guardarParticipantes($tallerId, $item, $grupal);
        }

        // Borrado lógico: detrás de un taller dictado hay dinero cobrado y
        // diplomas entregados. Sus fechas e inscritos se conservan.
        $sql = 'UPDATE talleres SET eliminado_en = NOW() WHERE eliminado_en IS NULL';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }

    /**
     * Las fechas se reconcilian por (fecha, hora): así una fecha que no
     * cambió conserva su fila y su id, y el trigger que mantiene
     * `fecha_inicio` no se dispara sin motivo en cada guardado.
     */
    private function guardarSesiones(int $tallerId, array $item): void
    {
        $conservar = [];
        foreach ((array) ($item['sessions'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $fecha = self::fecha($s['date'] ?? null);
            if ($fecha === null) {
                continue;                     // sin fecha no es una sesión
            }
            $inicio = self::hora($s['start'] ?? null);
            $fin    = self::hora($s['end'] ?? null);
            if ($fin !== null && $inicio !== null && $fin <= $inicio) {
                $fin = null;                  // chk_tsesion_horas
            }

            Database::query(
                'INSERT INTO taller_sesiones (uid, taller_id, fecha, hora_inicio, hora_fin)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE hora_fin = VALUES(hora_fin), uid = COALESCE(uid, VALUES(uid))',
                [self::nz($s['id'] ?? null), $tallerId, $fecha, $inicio, $fin]
            );
            $conservar[] = (int) Database::valor(
                'SELECT id FROM taller_sesiones
                  WHERE taller_id = ? AND fecha = ? AND hora_inicio <=> ?',
                [$tallerId, $fecha, $inicio]
            );
        }

        $sql = 'DELETE FROM taller_sesiones WHERE taller_id = ?';
        $par = [$tallerId];
        if ($conservar !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($conservar), '?')) . ')';
            $par = [...$par, ...$conservar];
        }
        Database::query($sql, $par);
    }

    /**
     * En modo grupal nadie paga: el monto y la marca de pagado se fuerzan
     * a cero para que la lista no sume nada. Es la misma regla que aplica
     * el panel al ocultar la columna de monto.
     */
    private function guardarParticipantes(int $tallerId, array $item, bool $grupal): void
    {
        $uids = [];
        foreach ((array) ($item['participantes'] ?? []) as $p) {
            if (!is_array($p) || self::nz($p['name'] ?? null) === null) {
                continue;
            }
            $uid = self::txt($p['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;

            $monto  = $grupal ? 0.0 : self::num($p['amount'] ?? 0);
            $pagado = $grupal ? 0 : self::bool($p['paid'] ?? false);
            // trg_tpart_monto_ins: marcado como pagado exige importe.
            if ($pagado === 1 && $monto <= 0) {
                $pagado = 0;
            }

            Database::query(
                'INSERT INTO taller_participantes
                    (uid, taller_id, persona_id, nombre, documento, sexo, telefono, email,
                     procedencia, monto, pagado, asistio, diploma_entregado, inscrito_el)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    taller_id=VALUES(taller_id), persona_id=VALUES(persona_id),
                    nombre=VALUES(nombre), documento=VALUES(documento), sexo=VALUES(sexo),
                    telefono=VALUES(telefono), email=VALUES(email),
                    procedencia=VALUES(procedencia), monto=VALUES(monto),
                    pagado=VALUES(pagado), asistio=VALUES(asistio),
                    diploma_entregado=VALUES(diploma_entregado), inscrito_el=VALUES(inscrito_el)',
                [
                    $uid,
                    $tallerId,
                    // Si la persona ya está en el centro se enlaza con su ficha:
                    // el buscador por DNI la encuentra sin gastar consulta.
                    self::personaPorDocumento($p['dni'] ?? null),
                    self::txt($p['name']),
                    Personas::soloDocumento($p['dni'] ?? null),
                    Personas::sexoEnum($p['sex'] ?? null),
                    self::nz($p['phone'] ?? null),
                    self::nz($p['email'] ?? null),
                    self::nz($p['origin'] ?? null),
                    $monto,
                    $pagado,
                    self::bool($p['attended'] ?? false),
                    self::bool($p['diplomaEntregado'] ?? false),
                    self::fecha($p['inscritoEl'] ?? null),
                ]
            );
        }

        // Aquí sí se borra de verdad: quitar a alguien de la lista es
        // corregir una inscripción, no dar de baja un dato clínico.
        $sql = 'DELETE FROM taller_participantes WHERE taller_id = ?';
        $par = [$tallerId];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = [...$par, ...$uids];
        }
        Database::query($sql, $par);
    }

    /** Persona ya registrada con ese documento, si la hay. */
    private static function personaPorDocumento(mixed $documento): ?int
    {
        $doc = Personas::soloDocumento($documento);
        if ($doc === null) {
            return null;
        }
        $id = Database::valor('SELECT id FROM personas WHERE documento = ? LIMIT 1', [$doc]);
        return $id === null ? null : (int) $id;
    }
}
