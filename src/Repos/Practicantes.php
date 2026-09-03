<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Archivos;
use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/** Colección 'practicantes'. */
final class Practicantes extends Repositorio
{
    public function clave(): string
    {
        return 'practicantes';
    }

    public function leer(): array
    {
        $horarios   = Personas::leerHorarios('Practicante');
        $actividades = $this->leerActividades();

        $filas = Database::todos(
            'SELECT p.id, p.uid, p.nombre_completo, p.documento, p.telefono, p.email,
                    p.dia_cumple, p.activo,
                    pt.fecha_inicio, pt.fecha_fin, pt.horas_meta
               FROM practicantes pt
               JOIN personas p ON p.id = pt.persona_id
              WHERE p.eliminado_en IS NULL
              ORDER BY p.id'
        );

        $salida = [];
        foreach ($filas as $f) {
            $uid = (string) $f['uid'];
            $salida[] = [
                'id'             => $uid,
                'name'           => (string) $f['nombre_completo'],
                'dni'            => (string) ($f['documento'] ?? ''),
                'phone'          => (string) ($f['telefono'] ?? ''),
                'email'          => (string) ($f['email'] ?? ''),
                'startDate'      => (string) ($f['fecha_inicio'] ?? ''),
                'endDate'        => (string) ($f['fecha_fin'] ?? ''),
                'hoursGoal'      => (int) $f['horas_meta'],
                'weeklySchedule' => $horarios[$uid] ?? null,
                'activities'     => $actividades[(int) $f['id']] ?? [],
                'birthday'       => (string) ($f['dia_cumple'] ?? ''),
                'active'         => (int) $f['activo'] === 1,
            ];
        }
        return $salida;
    }

    /** @return array<int,list<array>> */
    private function leerActividades(): array
    {
        $out = [];
        $filas = Database::todos(
            'SELECT a.id, a.practicante_id, a.descripcion, a.fecha, a.turno, a.hora,
                    a.lugar, a.estado,
                    ar.uuid, ar.nombre_original, ar.mime, ar.tamano_bytes,
                    ar.es_enlace, ar.url_externa
               FROM practicante_actividades a
               LEFT JOIN archivos ar ON ar.id = a.archivo_id AND ar.eliminado_en IS NULL
              ORDER BY a.id'
        );
        foreach ($filas as $f) {
            $archivo = $f['uuid'] === null ? null : Archivos::aJson([
                'uuid'            => $f['uuid'],
                'nombre_original' => $f['nombre_original'],
                'mime'            => $f['mime'],
                'tamano_bytes'    => $f['tamano_bytes'],
                'es_enlace'       => $f['es_enlace'],
                'url_externa'     => $f['url_externa'],
            ]);
            $out[(int) $f['practicante_id']][] = [
                'id'          => 'ac' . $f['id'],
                'description' => (string) $f['descripcion'],
                'date'        => (string) ($f['fecha'] ?? ''),
                'turno'       => (string) ($f['turno'] ?? ''),
                'hora'        => substr((string) ($f['hora'] ?? ''), 0, 5),
                'lugar'       => (string) ($f['lugar'] ?? ''),
                'status'      => (string) $f['estado'],
                'file'        => $archivo,
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

            Database::query(
                'INSERT INTO practicantes (persona_id, fecha_inicio, fecha_fin, horas_meta)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    fecha_inicio = VALUES(fecha_inicio),
                    fecha_fin    = VALUES(fecha_fin),
                    horas_meta   = VALUES(horas_meta)',
                [
                    $personaId,
                    self::fecha($item['startDate'] ?? null),
                    self::fecha($item['endDate'] ?? null),
                    self::ent($item['hoursGoal'] ?? 0),
                ]
            );

            Personas::guardarHorario($personaId, 'Practicante', $item['weeklySchedule'] ?? null);
            $this->guardarActividades($personaId, $item['activities'] ?? []);
        }

        Personas::bajaFaltantes('practicantes', array_filter($uids));
        $this->subirVersion();
    }

    private function guardarActividades(int $practicanteId, mixed $lista): void
    {
        if (!is_array($lista)) {
            return;
        }
        $usuarioId = Auth::usuarioId();
        $conservar = [];

        foreach ($lista as $a) {
            if (!is_array($a) || self::nz($a['description'] ?? null) === null) {
                continue;
            }
            // El adjunto llega como data-URL la primera vez; después ya viene
            // como referencia y no se vuelve a escribir en disco.
            $archivoId = Archivos::resolver($a['file'] ?? null, $usuarioId);

            $idExistente = null;
            $marcado = self::txt($a['id'] ?? '');
            if (str_starts_with($marcado, 'ac')) {
                $idExistente = (int) substr($marcado, 2);
            }

            $params = [
                $practicanteId,
                self::txt($a['description']),
                self::fecha($a['date'] ?? null),
                self::enum($a['turno'] ?? null, ['Mañana','Tarde','Noche'], 'Mañana'),
                self::hora($a['hora'] ?? null),
                self::nz($a['lugar'] ?? null),
                self::enum($a['status'] ?? null, ['Pendiente','Completada','Observada'], 'Pendiente'),
                $archivoId,
            ];

            // 'turno' es opcional en el panel: si venía vacío, se deja NULL.
            if (self::nz($a['turno'] ?? null) === null) {
                $params[3] = null;
            }

            if ($idExistente !== null && Database::valor(
                'SELECT id FROM practicante_actividades WHERE id = ? AND practicante_id = ?',
                [$idExistente, $practicanteId]
            ) !== null) {
                Database::query(
                    'UPDATE practicante_actividades
                        SET practicante_id=?, descripcion=?, fecha=?, turno=?, hora=?,
                            lugar=?, estado=?, archivo_id=?
                      WHERE id=?',
                    [...$params, $idExistente]
                );
                $conservar[] = $idExistente;
            } else {
                Database::query(
                    'INSERT INTO practicante_actividades
                        (practicante_id, descripcion, fecha, turno, hora, lugar, estado, archivo_id)
                     VALUES (?,?,?,?,?,?,?,?)',
                    $params
                );
                $conservar[] = Database::ultimoId();
            }
        }

        $sql = 'DELETE FROM practicante_actividades WHERE practicante_id = ?';
        $par = [$practicanteId];
        if ($conservar !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($conservar), '?')) . ')';
            $par = [...$par, ...$conservar];
        }
        Database::query($sql, $par);
    }
}
