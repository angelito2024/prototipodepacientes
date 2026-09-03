<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/** Colección 'expenses'. */
final class Gastos extends Repositorio
{
    public function clave(): string
    {
        return 'expenses';
    }

    public function leer(): array
    {
        $salida = [];
        foreach (Database::todos(
            'SELECT g.uid, g.nombre, g.monto, g.fecha_pago, g.es_recurrente,
                    c.nombre AS categoria
               FROM gastos g
               LEFT JOIN categorias_gasto c ON c.id = g.categoria_id
              WHERE g.anulado_en IS NULL
              ORDER BY g.fecha_pago, g.id'
        ) as $f) {
            $salida[] = [
                'id'          => (string) $f['uid'],
                'name'        => (string) $f['nombre'],
                'amount'      => (float) $f['monto'],
                'category'    => (string) ($f['categoria'] ?? 'Otro'),
                'paymentDate' => (string) $f['fecha_pago'],
                'recurring'   => (int) $f['es_recurrente'] === 1,
            ];
        }
        return $salida;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $categorias = $this->categorias();
        $usuarioId  = Auth::usuarioId();
        $uids = [];

        foreach ($valor as $item) {
            if (!is_array($item) || self::nz($item['name'] ?? null) === null) {
                continue;
            }
            $monto = self::num($item['amount'] ?? 0);
            if ($monto <= 0) {          // chk_gasto_monto
                continue;
            }
            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;

            $fecha = self::fecha($item['paymentDate'] ?? null) ?? date('Y-m-d');
            $cat   = self::txt($item['category'] ?? 'Otro');

            Database::query(
                'INSERT INTO gastos
                    (uid, nombre, categoria_id, monto, fecha_pago, periodo,
                     es_recurrente, registrado_por, anulado_en)
                 VALUES (?,?,?,?,?,?,?,?,NULL)
                 ON DUPLICATE KEY UPDATE
                    nombre=VALUES(nombre), categoria_id=VALUES(categoria_id),
                    monto=VALUES(monto), fecha_pago=VALUES(fecha_pago),
                    periodo=VALUES(periodo), es_recurrente=VALUES(es_recurrente),
                    anulado_en=NULL',
                [
                    $uid,
                    self::txt($item['name']),
                    $categorias[$cat] ?? $this->crearCategoria($cat),
                    $monto,
                    $fecha,
                    substr($fecha, 0, 7),
                    self::bool($item['recurring'] ?? false),
                    $usuarioId,
                ]
            );
        }

        $sql = 'UPDATE gastos SET anulado_en = NOW() WHERE anulado_en IS NULL';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }

    private function categorias(): array
    {
        $mapa = [];
        foreach (Database::todos('SELECT id, nombre FROM categorias_gasto') as $c) {
            $mapa[(string) $c['nombre']] = (int) $c['id'];
        }
        return $mapa;
    }

    /** Categoría nueva escrita por el usuario: se agrega al catálogo. */
    private function crearCategoria(string $nombre): ?int
    {
        if ($nombre === '') {
            return null;
        }
        Database::query(
            'INSERT INTO categorias_gasto (nombre) VALUES (?)
             ON DUPLICATE KEY UPDATE activo = 1',
            [mb_substr($nombre, 0, 80)]
        );
        $id = Database::valor('SELECT id FROM categorias_gasto WHERE nombre = ?', [mb_substr($nombre, 0, 80)]);
        return $id === null ? null : (int) $id;
    }
}
