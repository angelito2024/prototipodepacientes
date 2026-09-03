<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'attendanceLog'.
 *
 * El panel identifica a la persona con el par (personType, personId) y
 * además duplica su nombre en cada registro. Gracias al supertipo
 * `personas`, aquí persona_id es una clave foránea real y el nombre se
 * obtiene por JOIN: si alguien corrige un nombre, se corrige en todas
 * partes a la vez.
 */
final class Asistencia extends Repositorio
{
    private const TIPOS = [
        'Puntual','Tardanza','Falta','Falta justificada',
        'Permiso de salud','Permiso familiar','Otro permiso',
    ];
    private const ROLES = ['Paciente','Profesional','Practicante'];

    public function clave(): string
    {
        return 'attendanceLog';
    }

    public function leer(): array
    {
        $salida = [];
        foreach (Database::todos(
            'SELECT a.uid, a.rol, a.fecha, a.tipo, a.nota,
                    p.uid AS persona_uid, p.nombre_completo
               FROM asistencia_registros a
               JOIN personas p ON p.id = a.persona_id
              ORDER BY a.fecha, a.id'
        ) as $f) {
            $salida[] = [
                'id'         => (string) $f['uid'],
                'personType' => (string) $f['rol'],
                'personId'   => (string) $f['persona_uid'],
                'personName' => (string) $f['nombre_completo'],
                'date'       => (string) $f['fecha'],
                'type'       => (string) $f['tipo'],
                'note'       => (string) ($f['nota'] ?? ''),
            ];
        }
        return $salida;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $personas  = self::mapaPersonas();
        $usuarioId = Auth::usuarioId();
        $uids = [];
        $vistos = [];

        foreach ($valor as $item) {
            if (!is_array($item)) {
                continue;
            }
            $personaUid = self::txt($item['personId'] ?? '');
            $fecha      = self::fecha($item['date'] ?? null);
            if (!isset($personas[$personaUid]) || $fecha === null) {
                continue;
            }

            $rol  = self::enum($item['personType'] ?? null, self::ROLES, 'Paciente');
            $tipo = self::enum($item['type'] ?? null, self::TIPOS, 'Puntual');

            // uq_asistencia_dia es (persona, rol, fecha, tipo): el panel
            // permite marcar dos veces lo mismo el mismo día; aquí se ignora
            // el duplicado en lugar de romper el guardado completo.
            $llave = $personas[$personaUid] . "|$rol|$fecha|$tipo";
            if (isset($vistos[$llave])) {
                continue;
            }
            $vistos[$llave] = true;

            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;

            Database::query(
                'INSERT INTO asistencia_registros
                    (uid, persona_id, rol, fecha, tipo, nota, registrado_por)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    nota = VALUES(nota)',
                [$uid, $personas[$personaUid], $rol, $fecha, $tipo, self::nz($item['note'] ?? null), $usuarioId]
            );
        }

        $sql = 'DELETE FROM asistencia_registros WHERE 1=1';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }
}
