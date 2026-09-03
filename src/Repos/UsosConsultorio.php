<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/** Colección 'roomUsage': alquiler de consultorio a profesionales externos. */
final class UsosConsultorio extends Repositorio
{
    private const MODOS = ['Por paciente','Por dia','Mensual'];

    public function clave(): string
    {
        return 'roomUsage';
    }

    public function leer(): array
    {
        $salida = [];
        foreach (Database::todos(
            'SELECT u.uid, u.fecha, u.periodo, u.modo, u.monto, u.nota, u.cobrado,
                    pro.uid AS profesional_uid
               FROM usos_consultorio u
               JOIN personas pro ON pro.id = u.profesional_id
              ORDER BY u.fecha, u.id'
        ) as $f) {
            $salida[] = [
                'id'             => (string) $f['uid'],
                'professionalId' => (string) $f['profesional_uid'],
                'date'           => (string) $f['fecha'],
                'month'          => (string) ($f['periodo'] ?? ''),
                'mode'           => (string) $f['modo'],
                'amount'         => (float) $f['monto'],
                'note'           => (string) ($f['nota'] ?? ''),
                'paid'           => (int) $f['cobrado'] === 1,
            ];
        }
        return $salida;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $profesionales = self::mapaPersonas('profesionales');
        $uids = [];
        $mensualesVistos = [];

        foreach ($valor as $item) {
            if (!is_array($item)) {
                continue;
            }
            $profUid = self::txt($item['professionalId'] ?? '');
            $fecha   = self::fecha($item['date'] ?? null);
            if (!isset($profesionales[$profUid]) || $fecha === null) {
                continue;
            }

            $modo    = self::enum($item['mode'] ?? null, self::MODOS, 'Por paciente');
            $periodo = null;
            if ($modo === 'Mensual') {
                $periodo = self::txt($item['month'] ?? '') ?: substr($fecha, 0, 7);
                // uq_uso_mensual impide dos mensualidades del mismo mes para el
                // mismo profesional. El panel solo avisa con un confirm().
                $llave = $profesionales[$profUid] . '|' . $periodo;
                if (isset($mensualesVistos[$llave])) {
                    continue;
                }
                $mensualesVistos[$llave] = true;
            }

            $uid = self::txt($item['id'] ?? '') ?: self::nuevoUid();
            $uids[] = $uid;

            Database::query(
                'INSERT INTO usos_consultorio
                    (uid, profesional_id, modo, fecha, periodo, monto, nota, cobrado)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    profesional_id=VALUES(profesional_id), modo=VALUES(modo),
                    fecha=VALUES(fecha), periodo=VALUES(periodo), monto=VALUES(monto),
                    nota=VALUES(nota), cobrado=VALUES(cobrado)',
                [
                    $uid,
                    $profesionales[$profUid],
                    $modo,
                    $fecha,
                    $periodo,
                    self::num($item['amount'] ?? 0),
                    self::nz($item['note'] ?? null),
                    self::bool($item['paid'] ?? false),
                ]
            );
        }

        $sql = 'DELETE FROM usos_consultorio WHERE pago_id IS NULL';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }
}
