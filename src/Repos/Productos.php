<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/** Colección 'products' (pestaña Ideas). */
final class Productos extends Repositorio
{
    public function clave(): string
    {
        return 'products';
    }

    public function leer(): array
    {
        $salida = [];
        foreach (Database::todos(
            'SELECT uid, nombre, costo, precio, unidades_mes
               FROM productos WHERE activo = 1 ORDER BY id'
        ) as $f) {
            $salida[] = [
                'id'           => (string) $f['uid'],
                'name'         => (string) $f['nombre'],
                'cost'         => (float) $f['costo'],
                'price'        => (float) $f['precio'],
                'monthlyUnits' => (int) $f['unidades_mes'],
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
            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;
            Database::query(
                'INSERT INTO productos (uid, nombre, costo, precio, unidades_mes, activo)
                 VALUES (?,?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE
                    nombre=VALUES(nombre), costo=VALUES(costo), precio=VALUES(precio),
                    unidades_mes=VALUES(unidades_mes), activo=1',
                [
                    $uid,
                    self::txt($item['name']),
                    self::num($item['cost'] ?? 0),
                    self::num($item['price'] ?? 0),
                    self::ent($item['monthlyUnits'] ?? 0),
                ]
            );
        }

        $sql = 'UPDATE productos SET activo = 0 WHERE activo = 1';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }
}
