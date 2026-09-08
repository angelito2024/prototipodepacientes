<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'personalEntries' (pestaña Cuentas personales).
 *
 * Es el dinero propio del gerente, no el del centro: no entra en Finanzas,
 * ni en v_resultado_mensual, ni en ningún reporte. Por eso vive en sus
 * propias tablas y no en `gastos`.
 *
 * Dos diferencias con lo que guardaba el panel:
 *
 *  · Es privado por usuario. En el panel era una colección global del
 *    navegador: cualquiera que abriera la pestaña la veía, y salía entera
 *    en el JSON de la copia de seguridad.
 *  · Los meses pagados son filas de `personal_pagos`, no un array
 *    `pagados[]` con un objeto `fechasPago{}` en paralelo. Con dos
 *    estructuras que hay que mantener en el mismo orden, basta con que una
 *    se actualice y la otra no para que un mes quede pagado sin fecha.
 */
final class Personales extends Repositorio
{
    private const TIPOS  = ['ingreso', 'gasto'];
    private const CLASES = ['fijo', 'variable'];

    public function clave(): string
    {
        return 'personalEntries';
    }

    public function leer(): array
    {
        $usuarioId = Auth::duenioDeLoPersonal();
        if ($usuarioId === null) {
            return [];
        }

        $pagos = $this->leerPagos($usuarioId);

        $salida = [];
        foreach (Database::todos(
            'SELECT id, uid, tipo, clase, nombre, categoria, monto,
                    dia_vencimiento, fecha, notas, creado_en
               FROM personal_movimientos
              WHERE usuario_id = ?
              ORDER BY id',
            [$usuarioId]
        ) as $f) {
            $id = (int) $f['id'];
            $salida[] = [
                'id'         => (string) $f['uid'],
                'tipo'       => (string) $f['tipo'],
                'clase'      => (string) $f['clase'],
                'name'       => (string) $f['nombre'],
                'category'   => (string) ($f['categoria'] ?? ''),
                'amount'     => (float) $f['monto'],
                'dueDay'     => (int) ($f['dia_vencimiento'] ?? 1),
                'date'       => (string) ($f['fecha'] ?? ''),
                'notes'      => (string) ($f['notas'] ?? ''),
                'pagados'    => $pagos[$id]['meses'] ?? [],
                'fechasPago' => $pagos[$id]['fechas'] ?? new \stdClass(),
                'createdAt'  => substr((string) $f['creado_en'], 0, 10),
            ];
        }
        return $salida;
    }

    /** @return array<int,array{meses:list<string>,fechas:array<string,string>}> */
    private function leerPagos(int $usuarioId): array
    {
        $out = [];
        foreach (Database::todos(
            'SELECT p.movimiento_id, p.periodo, p.fecha_pago
               FROM personal_pagos p
               JOIN personal_movimientos m ON m.id = p.movimiento_id
              WHERE m.usuario_id = ?
              ORDER BY p.periodo',
            [$usuarioId]
        ) as $f) {
            $mid = (int) $f['movimiento_id'];
            $out[$mid] ??= ['meses' => [], 'fechas' => []];
            $out[$mid]['meses'][] = (string) $f['periodo'];
            if ($f['fecha_pago'] !== null) {
                $out[$mid]['fechas'][(string) $f['periodo']] = (string) $f['fecha_pago'];
            }
        }
        return $out;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $usuarioId = Auth::duenioDeLoPersonal();
        if ($usuarioId === null) {
            return;                      // sin dueño no hay dónde guardarlas
        }

        $uids = [];
        foreach ($valor as $item) {
            if (!is_array($item) || self::nz($item['name'] ?? null) === null) {
                continue;
            }
            $monto = self::num($item['amount'] ?? 0);
            if ($monto <= 0) {
                continue;                // chk_personal_monto
            }
            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;

            $clase = self::enum($item['clase'] ?? null, self::CLASES, 'fijo');
            $dia   = self::ent($item['dueDay'] ?? 0);
            if ($dia < 1 || $dia > 31) {
                $dia = 1;
            }

            Database::query(
                'INSERT INTO personal_movimientos
                    (uid, usuario_id, tipo, clase, nombre, categoria, monto,
                     dia_vencimiento, fecha, notas)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    tipo=VALUES(tipo), clase=VALUES(clase), nombre=VALUES(nombre),
                    categoria=VALUES(categoria), monto=VALUES(monto),
                    dia_vencimiento=VALUES(dia_vencimiento), fecha=VALUES(fecha),
                    notas=VALUES(notas)',
                [
                    $uid,
                    $usuarioId,
                    self::enum($item['tipo'] ?? null, self::TIPOS, 'gasto'),
                    $clase,
                    self::txt($item['name']),
                    self::nz($item['category'] ?? null),
                    $monto,
                    // chk_personal_clase: el fijo lleva vencimiento y no fecha;
                    // el variable, al revés.
                    $clase === 'fijo' ? $dia : null,
                    $clase === 'fijo' ? null : self::fecha($item['date'] ?? null),
                    self::nz($item['notes'] ?? null),
                ]
            );

            $movimientoId = (int) Database::valor(
                'SELECT id FROM personal_movimientos WHERE uid = ? AND usuario_id = ?',
                [$uid, $usuarioId]
            );
            $this->guardarPagos($movimientoId, $item);
        }

        // Aquí sí se borra: son las cuentas de casa, no un dato clínico ni
        // del centro. Solo se tocan las del usuario de la sesión.
        $sql = 'DELETE FROM personal_movimientos WHERE usuario_id = ?';
        $par = [$usuarioId];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = [...$par, ...$uids];
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }

    /** Los meses marcados como pagados, con la fecha real de cada pago. */
    private function guardarPagos(int $movimientoId, array $item): void
    {
        $fechas  = (array) ($item['fechasPago'] ?? []);
        $meses   = [];

        foreach ((array) ($item['pagados'] ?? []) as $periodo) {
            $p = self::txt($periodo);
            if (preg_match('/^\d{4}-\d{2}$/', $p) !== 1) {
                continue;
            }
            $meses[] = $p;
            Database::query(
                'INSERT INTO personal_pagos (movimiento_id, periodo, fecha_pago)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE fecha_pago = VALUES(fecha_pago)',
                [$movimientoId, $p, self::fecha($fechas[$p] ?? null)]
            );
        }

        $sql = 'DELETE FROM personal_pagos WHERE movimiento_id = ?';
        $par = [$movimientoId];
        if ($meses !== []) {
            $sql .= ' AND periodo NOT IN (' . implode(',', array_fill(0, count($meses), '?')) . ')';
            $par = [...$par, ...$meses];
        }
        Database::query($sql, $par);
    }
}
