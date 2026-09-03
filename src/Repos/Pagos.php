<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'payments'.
 *
 * El panel mezcla en una sola lista los pagos de pacientes y los de
 * colegios. El alquiler de consultorio, que también es un ingreso, vive en
 * otra colección (roomUsage). Aquí ambos van a `pagos` y `usos_consultorio`
 * respectivamente, con la categoría explícita.
 */
final class Pagos extends Repositorio
{
    /** paymentType del panel -> ENUM tipo_pago. */
    private const TIPOS = [
        'sesion_completa'  => 'Sesión completa',
        'adelanto_sesion'  => 'Adelanto de sesión',
        'paquete_completo' => 'Paquete completo',
        'adelanto_paquete' => 'Adelanto de paquete',
        'saldo_paquete'    => 'Saldo pendiente',
        'colegio'          => 'Colegio',
        'sesion'           => 'Sesión completa',
        'paquete'          => 'Paquete completo',
        'otro'             => 'Otro',
    ];

    public function clave(): string
    {
        return 'payments';
    }

    public function leer(): array
    {
        $inverso = array_flip(self::TIPOS);
        $salida = [];
        foreach (Database::todos(
            'SELECT pg.uid, pg.fecha, pg.hora, pg.monto, pg.concepto, pg.tipo_pago,
                    mp.nombre AS metodo, pac.uid AS paciente_uid
               FROM pagos pg
               LEFT JOIN personas pac    ON pac.id = pg.paciente_id
               LEFT JOIN metodos_pago mp ON mp.id = pg.metodo_pago_id
              WHERE pg.anulado_en IS NULL
              ORDER BY pg.fecha, pg.id'
        ) as $f) {
            $salida[] = [
                'id'          => (string) $f['uid'],
                'patientId'   => (string) ($f['paciente_uid'] ?? ''),
                'date'        => (string) $f['fecha'],
                'time'        => substr((string) ($f['hora'] ?? ''), 0, 5),
                'amount'      => (float) $f['monto'],
                'concept'     => (string) ($f['concepto'] ?? ''),
                'method'      => (string) ($f['metodo'] ?? 'Efectivo'),
                'paymentType' => $inverso[(string) $f['tipo_pago']] ?? 'otro',
            ];
        }
        return $salida;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $pacientes = self::mapaPersonas('pacientes');
        $metodos   = $this->metodos();
        $paquetes  = $this->paquetesActivos();
        $usuarioId = Auth::usuarioId();
        $uids = [];

        foreach ($valor as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pacUid = self::txt($item['patientId'] ?? '');
            $monto  = self::num($item['amount'] ?? 0);
            $fecha  = self::fecha($item['date'] ?? null);

            // chk_pago_monto exige monto > 0; chk_pago_destino exige paciente
            // para las categorías Paciente y Colegio.
            if ($monto <= 0 || $fecha === null || !isset($pacientes[$pacUid])) {
                continue;
            }

            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;
            $pacienteId = $pacientes[$pacUid];
            $tipoPanel  = self::txt($item['paymentType'] ?? 'otro');

            Database::query(
                'INSERT INTO pagos
                    (uid, categoria, paciente_id, paquete_id, tipo_pago, monto,
                     metodo_pago_id, concepto, fecha, hora, registrado_por, anulado_en)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)
                 ON DUPLICATE KEY UPDATE
                    categoria=VALUES(categoria), paciente_id=VALUES(paciente_id),
                    paquete_id=VALUES(paquete_id), tipo_pago=VALUES(tipo_pago),
                    monto=VALUES(monto), metodo_pago_id=VALUES(metodo_pago_id),
                    concepto=VALUES(concepto), fecha=VALUES(fecha), hora=VALUES(hora),
                    anulado_en=NULL',
                [
                    $uid,
                    $tipoPanel === 'colegio' ? 'Colegio' : 'Paciente',
                    $pacienteId,
                    $paquetes[$pacienteId] ?? null,
                    self::TIPOS[$tipoPanel] ?? 'Otro',
                    $monto,
                    $metodos[self::txt($item['method'] ?? '')] ?? null,
                    self::nz($item['concept'] ?? null),
                    $fecha,
                    self::hora($item['time'] ?? null),
                    $usuarioId,
                ]
            );
        }

        // Un pago eliminado en el panel se ANULA: queda el rastro contable de
        // que existió y de cuándo se dio de baja.
        $sql = 'UPDATE pagos SET anulado_en = NOW(), anulado_por = ?
                 WHERE anulado_en IS NULL AND categoria IN (\'Paciente\',\'Colegio\')';
        $par = [$usuarioId];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = [...$par, ...$uids];
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }

    private function metodos(): array
    {
        $mapa = [];
        foreach (Database::todos('SELECT id, nombre FROM metodos_pago') as $m) {
            $mapa[(string) $m['nombre']] = (int) $m['id'];
        }
        return $mapa;
    }

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
}
