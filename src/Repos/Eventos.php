<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/** Colección 'calendarEvents': fechas del centro, guardadas como 'MM-DD'. */
final class Eventos extends Repositorio
{
    public function clave(): string
    {
        return 'calendarEvents';
    }

    public function leer(): array
    {
        $salida = [];
        foreach (Database::todos(
            'SELECT uid, nombre, dia_mes FROM eventos_calendario ORDER BY dia_mes'
        ) as $f) {
            $salida[] = [
                'id'   => (string) $f['uid'],
                'name' => (string) $f['nombre'],
                'date' => (string) ($f['dia_mes'] ?? ''),
            ];
        }
        return $salida;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $uids = [];
        foreach ($valor as $item) {
            if (!is_array($item) || self::nz($item['name'] ?? null) === null) {
                continue;
            }
            $diaMes = self::diaMes($item['date'] ?? null);
            if ($diaMes === null) {          // chk_evento_fecha
                continue;
            }
            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;

            Database::query(
                'INSERT INTO eventos_calendario (uid, nombre, fecha, dia_mes, anual, tipo)
                 VALUES (?,?,NULL,?,1,\'Festividad\')
                 ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), dia_mes=VALUES(dia_mes)',
                [$uid, self::txt($item['name']), $diaMes]
            );
        }

        $sql = 'DELETE FROM eventos_calendario WHERE 1=1';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }
}
