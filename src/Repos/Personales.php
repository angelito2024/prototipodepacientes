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
 *  · Los pagos son filas de `personal_pagos`, cada una con su fecha y su
 *    importe. Un mes deja de ser "pagado sí o no": se puede ir pagando por
 *    partes, y lo que falta es el monto menos lo abonado.
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
                'abonos'     => $pagos[$id] ?? [],
                'createdAt'  => substr((string) $f['creado_en'], 0, 10),
            ];
        }
        return $salida;
    }

    /** @return array<int,list<array{id:string,periodo:string,fecha:string,monto:float}>> */
    private function leerPagos(int $usuarioId): array
    {
        $out = [];
        foreach (Database::todos(
            'SELECT p.id, p.uid, p.movimiento_id, p.periodo, p.fecha_pago, p.monto
               FROM personal_pagos p
               JOIN personal_movimientos m ON m.id = p.movimiento_id
              WHERE m.usuario_id = ?
              ORDER BY p.periodo, p.fecha_pago, p.id',
            [$usuarioId]
        ) as $f) {
            $out[(int) $f['movimiento_id']][] = [
                'id'      => (string) ($f['uid'] ?? ('pp' . $f['id'])),
                'periodo' => (string) $f['periodo'],
                'fecha'   => (string) ($f['fecha_pago'] ?? ''),
                'monto'   => (float) $f['monto'],
            ];
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
            $this->guardarPagos($movimientoId, $item, $monto);
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

    /**
     * Cada abono, con su fecha y su importe.
     *
     * Un mes puede llevar varios: se paga 300 el día 5 y 600 el día 20. Lo
     * que falta sale de restar la suma al monto del movimiento, así que no
     * hay ningún campo "pagado" que mantener al día por separado.
     */
    private function guardarPagos(int $movimientoId, array $item, float $montoMovimiento): void
    {
        $uids = [];
        foreach (self::abonosDe($item, $montoMovimiento) as $a) {
            $uids[] = $a['uid'];
            Database::query(
                'INSERT INTO personal_pagos (uid, movimiento_id, periodo, fecha_pago, monto)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    movimiento_id = VALUES(movimiento_id), periodo = VALUES(periodo),
                    fecha_pago = VALUES(fecha_pago), monto = VALUES(monto)',
                [$a['uid'], $movimientoId, $a['periodo'], $a['fecha'], $a['monto']]
            );
        }

        $sql = 'DELETE FROM personal_pagos WHERE movimiento_id = ?';
        $par = [$movimientoId];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = [...$par, ...$uids];
        }
        Database::query($sql, $par);
    }

    /**
     * Los abonos que trae el elemento, normalizados.
     *
     * Acepta también la forma anterior —`pagados[]` con `fechasPago{}`—,
     * que es la que traen los respaldos hechos antes de que se pudiera
     * pagar por partes: cada mes marcado era un pago por el monto completo.
     *
     * @return list<array{uid:string,periodo:string,fecha:?string,monto:float}>
     */
    private static function abonosDe(array $item, float $montoMovimiento): array
    {
        $salida = [];

        if (is_array($item['abonos'] ?? null)) {
            foreach ($item['abonos'] as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $periodo = self::txt($a['periodo'] ?? '');
                $monto   = self::num($a['monto'] ?? 0);
                if (preg_match('/^\d{4}-\d{2}$/', $periodo) !== 1 || $monto <= 0) {
                    continue;                       // chk_ppago_monto
                }
                $salida[] = [
                    'uid'     => self::txt($a['id'] ?? '') ?: self::nuevoUid(),
                    'periodo' => $periodo,
                    'fecha'   => self::fecha($a['fecha'] ?? null),
                    'monto'   => $monto,
                ];
            }
            return $salida;
        }

        $fechas = (array) ($item['fechasPago'] ?? []);
        foreach ((array) ($item['pagados'] ?? []) as $periodo) {
            $p = self::txt($periodo);
            if (preg_match('/^\d{4}-\d{2}$/', $p) !== 1 || $montoMovimiento <= 0) {
                continue;
            }
            $salida[] = [
                'uid'     => self::nuevoUid(),
                'periodo' => $p,
                'fecha'   => self::fecha($fechas[$p] ?? null),
                'monto'   => $montoMovimiento,
            ];
        }

        // Un gasto suelto de un respaldo antiguo no tenía estado: el panel de
        // entonces los sumaba a todos como ya gastados. Se decide por su fecha:
        //
        //  · fecha pasada  -> se da por pagado. Es el mercado, el transporte,
        //    una salida: cosas anotadas después de hacerlas. Marcarlas
        //    pendientes llenaría la pantalla de deudas falsas.
        //  · fecha futura  -> se deja PENDIENTE. Nadie anota con fecha del mes
        //    que viene algo que ya pagó; eso es una deuda con vencimiento,
        //    justo lo que ahora se puede registrar.
        $fecha = self::fecha($item['date'] ?? null);
        $yaPasó = $fecha !== null && $fecha <= date('Y-m-d');
        if ($salida === [] && self::txt($item['clase'] ?? '') !== 'fijo'
            && $yaPasó && $montoMovimiento > 0) {
            $salida[] = [
                'uid'     => self::nuevoUid(),
                'periodo' => substr($fecha, 0, 7),
                'fecha'   => $fecha,
                'monto'   => $montoMovimiento,
            ];
        }
        return $salida;
    }
}
