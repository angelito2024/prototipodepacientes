<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/** Colección 'professionals'. */
final class Profesionales extends Repositorio
{
    private const MODELOS = ['Comisión','Alquiler de espacio','Planilla','Honorarios'];
    private const MODOS   = ['Por paciente','Por dia','Mensual'];

    public function clave(): string
    {
        return 'professionals';
    }

    public function leer(): array
    {
        $horarios = Personas::leerHorarios('Profesional');
        $excep    = $this->leerExcepciones();

        $filas = Database::todos(
            'SELECT p.id, p.uid, p.nombre_completo, p.documento, p.telefono, p.email,
                    p.dia_cumple, p.activo,
                    pr.titulo, pr.carrera, pr.especialidad, pr.colegiatura,
                    pr.colegiatura_habil, pr.colegiatura_esp, pr.colegiatura_esp_habil,
                    pr.modelo_pago, pr.monto_centro_sesion, pr.alquiler_modo,
                    pr.alquiler_tarifa, pr.horario_texto, pr.saldo_por_pagar,
                    pr.al_dia, pr.ultima_liquidacion
               FROM profesionales pr
               JOIN personas p ON p.id = pr.persona_id
              WHERE p.eliminado_en IS NULL
              ORDER BY p.id'
        );

        $salida = [];
        foreach ($filas as $f) {
            $uid = (string) $f['uid'];
            $salida[] = [
                'id'                          => $uid,
                'name'                        => (string) $f['nombre_completo'],
                'dni'                         => (string) ($f['documento'] ?? ''),
                'phone'                       => (string) ($f['telefono'] ?? ''),
                'email'                       => (string) ($f['email'] ?? ''),
                'titulo'                      => (string) ($f['titulo'] ?? ''),
                'carrera'                     => (string) ($f['carrera'] ?? ''),
                'specialty'                   => (string) ($f['especialidad'] ?? ''),
                'colegiatura'                 => (string) ($f['colegiatura'] ?? ''),
                'colegiaturaHabilitada'       => (int) $f['colegiatura_habil'] === 1,
                'specColegiatura'             => (string) ($f['colegiatura_esp'] ?? ''),
                'specColegiaturaHabilitada'   => $f['colegiatura_esp_habil'] === null
                                                    ? null
                                                    : (int) $f['colegiatura_esp_habil'] === 1,
                'schedule'                    => (string) ($f['horario_texto'] ?? ''),
                'paymentModel'                => (string) $f['modelo_pago'],
                'centerShareAmount'           => (float) $f['monto_centro_sesion'],
                'roomRentalMode'              => (string) $f['alquiler_modo'],
                'roomRentalRate'              => (float) $f['alquiler_tarifa'],
                'birthday'                    => (string) ($f['dia_cumple'] ?? ''),
                'paid'                        => (int) $f['al_dia'] === 1,
                'amountToPay'                 => (float) $f['saldo_por_pagar'],
                'lastSettledDate'             => (string) ($f['ultima_liquidacion'] ?? ''),
                'weeklySchedule'              => $horarios[$uid] ?? null,
                'scheduleExceptions'          => $excep[(int) $f['id']] ?? [],
                'active'                      => (int) $f['activo'] === 1,
            ];
        }
        return $salida;
    }

    /** @return array<int,list<array>> */
    private function leerExcepciones(): array
    {
        $out = [];
        foreach (Database::todos(
            'SELECT id, persona_id, fecha, tipo, hora_inicio, hora_fin, nota
               FROM persona_horario_excepciones ORDER BY fecha'
        ) as $f) {
            $out[(int) $f['persona_id']][] = [
                'id'    => 'ex' . $f['id'],
                'date'  => (string) $f['fecha'],
                'type'  => (string) $f['tipo'],
                'start' => substr((string) ($f['hora_inicio'] ?? ''), 0, 5),
                'end'   => substr((string) ($f['hora_fin'] ?? ''), 0, 5),
                'note'  => (string) ($f['nota'] ?? ''),
            ];
        }
        return $out;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $uids = [];

        foreach ($valor as $item) {
            if (!is_array($item)) {
                continue;
            }
            $personaId = Personas::upsert($item);
            $uids[] = self::txt($item['id'] ?? '');

            $espHabil = $item['specColegiaturaHabilitada'] ?? null;

            Database::query(
                'INSERT INTO profesionales
                    (persona_id, titulo, carrera, especialidad, colegiatura, colegiatura_habil,
                     colegiatura_esp, colegiatura_esp_habil, modelo_pago, monto_centro_sesion,
                     alquiler_modo, alquiler_tarifa, horario_texto, saldo_por_pagar,
                     al_dia, ultima_liquidacion)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    titulo=VALUES(titulo), carrera=VALUES(carrera),
                    especialidad=VALUES(especialidad), colegiatura=VALUES(colegiatura),
                    colegiatura_habil=VALUES(colegiatura_habil),
                    colegiatura_esp=VALUES(colegiatura_esp),
                    colegiatura_esp_habil=VALUES(colegiatura_esp_habil),
                    modelo_pago=VALUES(modelo_pago),
                    monto_centro_sesion=VALUES(monto_centro_sesion),
                    alquiler_modo=VALUES(alquiler_modo),
                    alquiler_tarifa=VALUES(alquiler_tarifa),
                    horario_texto=VALUES(horario_texto),
                    saldo_por_pagar=VALUES(saldo_por_pagar),
                    al_dia=VALUES(al_dia),
                    ultima_liquidacion=VALUES(ultima_liquidacion)',
                [
                    $personaId,
                    self::nz($item['titulo'] ?? null),
                    self::nz($item['carrera'] ?? null),
                    self::nz($item['specialty'] ?? null),
                    self::nz($item['colegiatura'] ?? null),
                    self::bool($item['colegiaturaHabilitada'] ?? true),
                    self::nz($item['specColegiatura'] ?? null),
                    $espHabil === null ? null : self::bool($espHabil),
                    self::enum($item['paymentModel'] ?? null, self::MODELOS, 'Comisión'),
                    self::num($item['centerShareAmount'] ?? 0),
                    self::enum($item['roomRentalMode'] ?? null, self::MODOS, 'Por paciente'),
                    self::num($item['roomRentalRate'] ?? 0),
                    self::nz($item['schedule'] ?? null),
                    self::num($item['amountToPay'] ?? 0),
                    self::bool($item['paid'] ?? true),
                    self::fecha($item['lastSettledDate'] ?? null),
                ]
            );

            Personas::guardarHorario($personaId, 'Profesional', $item['weeklySchedule'] ?? null);
            $this->guardarExcepciones($personaId, $item['scheduleExceptions'] ?? []);
        }

        Personas::bajaFaltantes('profesionales', array_filter($uids));
        $this->subirVersion();
    }

    /**
     * (persona_id, fecha) ya es único, así que sirve de clave natural: se
     * hace upsert sobre ella y solo se borran las fechas que el panel dejó
     * de enviar. Así el id de cada excepción se mantiene estable.
     */
    private function guardarExcepciones(int $personaId, mixed $lista): void
    {
        $fechas = [];
        foreach ((array) $lista as $e) {
            if (!is_array($e)) {
                continue;
            }
            $fecha = self::fecha($e['date'] ?? null);
            // uq_excepcion es (persona_id, fecha): sin fecha o repetida, se omite.
            if ($fecha === null || isset($fechas[$fecha])) {
                continue;
            }
            $fechas[$fecha] = true;

            $tipo = self::enum($e['type'] ?? null, ['Libre','Especial'], 'Libre');
            $ini  = self::hora($e['start'] ?? null);
            $fin  = self::hora($e['end'] ?? null);
            // chk_excepcion_horas: si es 'Especial' necesita un rango válido.
            if ($tipo === 'Especial' && ($ini === null || $fin === null || $fin <= $ini)) {
                $tipo = 'Libre';
                $ini = $fin = null;
            }
            Database::query(
                'INSERT INTO persona_horario_excepciones
                    (persona_id, fecha, tipo, hora_inicio, hora_fin, nota)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    tipo=VALUES(tipo), hora_inicio=VALUES(hora_inicio),
                    hora_fin=VALUES(hora_fin), nota=VALUES(nota)',
                [$personaId, $fecha, $tipo, $ini, $fin, self::nz($e['note'] ?? null)]
            );
        }

        $sql = 'DELETE FROM persona_horario_excepciones WHERE persona_id = ?';
        $par = [$personaId];
        if ($fechas !== []) {
            $sql .= ' AND fecha NOT IN (' . implode(',', array_fill(0, count($fechas), '?')) . ')';
            $par = [...$par, ...array_keys($fechas)];
        }
        Database::query($sql, $par);
    }
}
