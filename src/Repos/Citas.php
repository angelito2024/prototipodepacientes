<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/** Colección 'appointments'. */
final class Citas extends Repositorio
{
    private const ESTADOS   = ['Programada','Confirmada','Completada','Cancelada','Reprogramada','No asistió'];
    private const MODALIDADES = ['Presencial','Virtual','Domicilio'];
    /** Qué fue la sesión. 'Devolución' es la entrega de resultados. */
    private const TIPOS_SESION = ['Consulta','Evaluacion','Terapia','Seguimiento',
                                  'Devolucion','Taller','Otro'];

    /** Si venía por primera vez, si sigue, o si volvió tras mucho tiempo.
     *  Cualquier otra cosa entra como NULL y el panel lo deduce solo. */
    private const TIPOS_PACIENTE = ['Primera vez', 'Continuador', 'Reingreso'];

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
            "SELECT c.uid, c.codigo_atencion, c.inicio, c.duracion_min, c.modalidad, c.estado,
                    c.enlace, c.direccion, c.referencia,
                    c.recordatorio_enviado, c.cuenta_sesion, c.tipo_sesion,
                    c.tipo_paciente,
                    pac.uid AS paciente_uid, pro.uid AS profesional_uid,
                    pro.id AS profesional_persona_id,
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

        // Quién más estuvo en cada sesión. Una consulta para todas: con una
        // por cita, una agenda de un año haría cientos de viajes a la base.
        $participantes = [];
        foreach (Database::todos(
            'SELECT c.uid AS cita, per.uid AS paciente
               FROM cita_participantes cp
               JOIN citas c    ON c.id = cp.cita_id
               JOIN personas per ON per.id = cp.paciente_id
              WHERE c.eliminado_en IS NULL'
        ) as $f) {
            $participantes[(string) $f['cita']][] = (string) $f['paciente'];
        }

        // El historial de cambios de fecha, en una sola consulta.
        $reprogramaciones = [];
        foreach (Database::todos(
            'SELECT c.uid AS cita, r.fecha_anterior, r.fecha_nueva, r.modalidad_anterior,
                    r.sala_anterior, r.a_pedido_de, r.motivo, r.registrado_en
               FROM cita_reprogramaciones r
               JOIN citas c ON c.id = r.cita_id
              WHERE c.eliminado_en IS NULL
              ORDER BY r.registrado_en, r.id'
        ) as $f) {
            $ant = (string) $f['fecha_anterior'];
            $nue = (string) $f['fecha_nueva'];
            $reprogramaciones[(string) $f['cita']][] = [
                'deFecha'   => substr($ant, 0, 10),
                'deHora'    => substr($ant, 11, 5),
                'aFecha'    => substr($nue, 0, 10),
                'aHora'     => substr($nue, 11, 5),
                'deModalidad' => (string) ($f['modalidad_anterior'] ?? ''),
                'deSala'    => (string) ($f['sala_anterior'] ?? ''),
                'aPedidoDe' => (string) $f['a_pedido_de'],
                'motivo'    => (string) ($f['motivo'] ?? ''),
                'cuando'    => (string) $f['registrado_en'],
            ];
        }

        // Quien no administra el centro ve SUS citas enteras y las de los
        // demás solo como una hora ocupada: sin nombre, sin teléfono, sin
        // nada. Esconderlas del todo sería peor — agendaría encima de un
        // colega y el paciente se encontraría con el consultorio tomado.
        $veTodo = Auth::veTodoElCentro();
        $miPersona = $veTodo ? null : Auth::personaId();

        $salida = [];
        foreach ($filas as $f) {
            $inicio = (string) $f['inicio'];

            if (!$veTodo) {
                $esMia = $miPersona !== null
                    && (int) $f['profesional_persona_id'] === $miPersona;
                if (!$esMia) {
                    // Lo mínimo para que la agenda no se pise: cuándo,
                    // cuánto dura y qué sala. De quién es, no se dice.
                    $salida[] = [
                        'id'             => (string) $f['uid'],
                        'ajena'          => true,
                        'patientId'      => '',
                        'professionalId' => '',
                        'participantes'  => [],
                        'reprogramaciones' => [],
                        'date'           => substr($inicio, 0, 10),
                        'time'           => substr($inicio, 11, 5),
                        'modality'       => (string) $f['modalidad'],
                        'room'           => (string) ($f['sala'] ?? ''),
                        'status'         => (string) $f['estado'],
                        'meetingLink'    => '', 'address' => '', 'reference' => '',
                        'reminderSent'   => true,
                        'countedSession' => false,
                        'tipoSesion'     => '', 'tipoPaciente' => '',
                        'codigoAtencion' => '',
                    ];
                    continue;
                }
            }
            $salida[] = [
                'id'             => (string) $f['uid'],
                // El código que se dicta por teléfono y se escribe en el
                // comprobante. Vacío mientras la atención no haya ocurrido.
                'codigoAtencion' => (string) ($f['codigo_atencion'] ?? ''),
                'patientId'      => (string) $f['paciente_uid'],
                // Los demás que vienen a ESTA misma sesión. La sesión sigue
                // siendo una: descuenta una del paquete, no una por cabeza.
                'participantes'  => $participantes[(string) $f['uid']] ?? [],
                // De dónde viene esta fecha: cada vez que se movió, con
                // quién lo pidió. La cita guarda la fecha vigente; esto,
                // todas las anteriores.
                'reprogramaciones' => $reprogramaciones[(string) $f['uid']] ?? [],
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
                // Qué fue esa sesión: la consulta inicial, una evaluación,
                // terapia… Sin esto la agenda de un paciente son nueve
                // renglones iguales y no se sabe cuál fue cuál.
                'tipoSesion'     => (string) $f['tipo_sesion'],
                // Vacío quiere decir «no se anotó»: ahí el panel lo deduce
                // de las citas que esa persona ya tuvo.
                'tipoPaciente'   => (string) ($f['tipo_paciente'] ?? ''),
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
            $estado = self::enum($item['status'] ?? null, self::ESTADOS, 'Programada');
            $uids[] = $uid;
            $pacienteId = $pacientes[$pacUid];

            Database::query(
                'INSERT INTO citas
                    (uid, paciente_id, profesional_id, paquete_id, inicio, duracion_min,
                     modalidad, consultorio_id, enlace, direccion, referencia, estado,
                     recordatorio_enviado, cuenta_sesion, tipo_sesion,
                     tipo_paciente, eliminado_en)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)
                 ON DUPLICATE KEY UPDATE
                    paciente_id=VALUES(paciente_id), profesional_id=VALUES(profesional_id),
                    paquete_id=VALUES(paquete_id), inicio=VALUES(inicio),
                    duracion_min=VALUES(duracion_min), modalidad=VALUES(modalidad),
                    consultorio_id=VALUES(consultorio_id), enlace=VALUES(enlace),
                    direccion=VALUES(direccion), referencia=VALUES(referencia),
                    estado=VALUES(estado),
                    recordatorio_enviado=VALUES(recordatorio_enviado),
                    cuenta_sesion=VALUES(cuenta_sesion), tipo_sesion=VALUES(tipo_sesion),
                    tipo_paciente=VALUES(tipo_paciente),
                    eliminado_en=NULL',
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
                    $estado,
                    self::bool($item['reminderSent'] ?? false),
                    self::bool($item['countedSession'] ?? false),
                    self::enum($item['tipoSesion'] ?? null, self::TIPOS_SESION, 'Terapia'),
                    in_array($item['tipoPaciente'] ?? '', self::TIPOS_PACIENTE, true)
                        ? $item['tipoPaciente'] : null,
                ]
            );

            // Ya ocurrió: le toca su código. Lo decide el servidor
            // mirando el estado que acaba de quedar guardado, no lo que
            // el panel diga traer en 'codigoAtencion'.
            if ($estado === 'Completada') {
                $this->asegurarCodigo($uid, (int) substr($fecha, 0, 4));
            }

            $this->guardarParticipantes($uid, $pacienteId, $item['participantes'] ?? [], $pacientes);
            $this->guardarReprogramaciones($uid, $item['reprogramaciones'] ?? []);
        }

        $this->bajaFaltantes($uids);
        Database::query('SET @centro_permitir_solape = 0');
        $this->subirVersion();
    }

    /**
     * Le pone su código a una atención que acaba de ocurrir.
     *
     * Tres cosas que no son negociables y por eso viven acá y no en el
     * panel:
     *
     *  · El número lo entrega el servidor. El panel manda la agenda
     *    entera en cada guardado; si calculara «el último + 1», dos
     *    pantallas abiertas sacarían el mismo número.
     *  · Se entrega UNA vez. Si la cita ya tiene código no se toca,
     *    aunque se reabra, se mueva de fecha o cambie de profesional:
     *    el código identifica la atención, no su estado de hoy.
     *  · No se recicla. Una atención anulada se queda con el suyo; el
     *    comprobante que salió con ese número sigue existiendo.
     *
     * El año es el de la ATENCIÓN, no el de hoy: una sesión de 2025
     * cargada ahora es A-2025-…, porque el correlativo se lee como la
     * historia del centro.
     */
    private function asegurarCodigo(string $uid, int $anio): void
    {
        $ya = Database::valor(
            'SELECT codigo_atencion FROM citas WHERE uid = ?', [$uid]
        );
        if ($ya !== null && $ya !== '') {
            return;
        }

        $codigo = sprintf('A-%04d-%05d', $anio, self::correlativo('atencion', (string) $anio));

        // La condición del WHERE es la red: si entre la consulta de arriba
        // y este UPDATE otra pasada le puso código, esto no pisa nada.
        Database::query(
            'UPDATE citas SET codigo_atencion = ?
              WHERE uid = ? AND codigo_atencion IS NULL',
            [$codigo, $uid]
        );
    }

    /**
     * El siguiente número de una serie, sin que dos pedidos simultáneos
     * reciban el mismo.
     *
     * Un SELECT MAX + 1 se rompe apenas haya dos personas trabajando a la
     * vez —y eso es exactamente lo que viene con caja y admisión—. Este
     * INSERT ... ON DUPLICATE KEY UPDATE es una sola operación atómica:
     * MySQL serializa los pedidos por la clave de la fila y LAST_INSERT_ID
     * devuelve, a cada conexión, el valor que le tocó a ella.
     *
     * Sirve igual para los recibos y para las ventas: por eso lleva ámbito.
     */
    private static function correlativo(string $ambito, string $periodo): int
    {
        Database::query(
            'INSERT INTO correlativos (ambito, periodo, ultimo)
                  VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)',
            [$ambito, $periodo]
        );
        return (int) Database::valor('SELECT LAST_INSERT_ID()');
    }
    /**
     * Quién más vino a esta misma sesión.
     *
     * Se reescribe entera cada vez, como el resto de la conciliación: el
     * panel manda la lista completa y lo que ya no está se quita. El
     * paciente principal nunca entra acá —ya es el dueño de la cita—, y
     * ponerlo dos veces haría que la sesión pareciera de dos personas
     * cuando es de una.
     *
     * @param array<string,int> $pacientes uid => persona_id
     */
    private function guardarParticipantes(
        string $citaUid, int $pacienteId, mixed $lista, array $pacientes
    ): void {
        $citaId = Database::valor('SELECT id FROM citas WHERE uid = ?', [$citaUid]);
        if ($citaId === null) {
            return;
        }
        $citaId = (int) $citaId;

        $ids = [];
        foreach (is_array($lista) ? $lista : [] as $uid) {
            $u = self::txt($uid);
            if ($u === '' || !isset($pacientes[$u])) {
                continue;
            }
            if ($pacientes[$u] === $pacienteId) {
                continue;
            }
            $ids[$pacientes[$u]] = true;
        }
        $ids = array_keys($ids);

        if ($ids === []) {
            Database::query('DELETE FROM cita_participantes WHERE cita_id = ?', [$citaId]);
            return;
        }
        Database::query(
            'DELETE FROM cita_participantes WHERE cita_id = ? AND paciente_id NOT IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$citaId, ...$ids]
        );
        foreach ($ids as $pid) {
            Database::query(
                'INSERT IGNORE INTO cita_participantes (cita_id, paciente_id) VALUES (?,?)',
                [$citaId, $pid]
            );
        }
    }

    /**
     * El historial de cambios de fecha de una cita.
     *
     * A diferencia del resto de la conciliación, acá no se borra nada que
     * ya esté: un cambio de fecha ocurrió, y el registro de que ocurrió no
     * se deshace reescribiendo la agenda. Solo se agregan los que el panel
     * traiga y todavía no estén.
     */
    private function guardarReprogramaciones(string $citaUid, mixed $lista): void
    {
        if (!is_array($lista) || $lista === []) {
            return;
        }
        $citaId = Database::valor('SELECT id FROM citas WHERE uid = ?', [$citaUid]);
        if ($citaId === null) {
            return;
        }
        $citaId = (int) $citaId;

        $yaEstan = [];
        foreach (Database::todos(
            'SELECT fecha_anterior, fecha_nueva FROM cita_reprogramaciones WHERE cita_id = ?',
            [$citaId]
        ) as $f) {
            $yaEstan[(string) $f['fecha_anterior'] . '|' . (string) $f['fecha_nueva']] = true;
        }

        foreach ($lista as $r) {
            if (!is_array($r)) {
                continue;
            }
            $de = self::fecha($r['deFecha'] ?? null);
            $a  = self::fecha($r['aFecha'] ?? null);
            if ($de === null || $a === null) {
                continue;
            }
            $antes  = $de . ' ' . (self::hora($r['deHora'] ?? null) ?? '00:00') . ':00';
            $nueva  = $a  . ' ' . (self::hora($r['aHora'] ?? null) ?? '00:00') . ':00';
            if (isset($yaEstan[$antes . '|' . $nueva])) {
                continue;
            }
            $yaEstan[$antes . '|' . $nueva] = true;

            Database::query(
                'INSERT INTO cita_reprogramaciones
                    (cita_id, fecha_anterior, fecha_nueva, modalidad_anterior, sala_anterior,
                     a_pedido_de, motivo, registrado_por)
                 VALUES (?,?,?,?,?,?,?,?)',
                [
                    $citaId, $antes, $nueva,
                    self::nz($r['deModalidad'] ?? null) !== null
                        ? self::enum($r['deModalidad'], self::MODALIDADES, 'Presencial') : null,
                    self::nz($r['deSala'] ?? null),
                    self::enum($r['aPedidoDe'] ?? null,
                        ['paciente', 'centro', 'profesional', 'otro'], 'paciente'),
                    self::nz($r['motivo'] ?? null),
                    Auth::usuarioId(),
                ]
            );
        }
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
        self::noVaciarTodo('citas', $uids,
            (int) Database::valor('SELECT COUNT(*) FROM citas WHERE eliminado_en IS NULL'));

        $sql = 'UPDATE citas SET eliminado_en = NOW() WHERE eliminado_en IS NULL';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
    }
}
