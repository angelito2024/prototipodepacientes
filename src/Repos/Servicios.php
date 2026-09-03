<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/** Colección 'services' (pestaña Tarifas). */
final class Servicios extends Repositorio
{
    private const UNIDADES = [
        'Por sesión','Por paquete','Mensual','Por taller',
        'Por paciente atendido','Por evento','Otro',
    ];

    public function clave(): string
    {
        return 'services';
    }

    public function leer(): array
    {
        $salida = [];
        foreach (Database::todos(
            'SELECT uid, nombre, es_paquete, sesiones_paquete, precio,
                    precio_texto, unidad, unidad_texto, descripcion
               FROM servicios WHERE activo = 1 ORDER BY id'
        ) as $f) {
            $esPaquete = (int) $f['es_paquete'] === 1;
            $salida[] = [
                'id'              => (string) $f['uid'],
                'name'            => (string) $f['nombre'],
                'isPackage'       => $esPaquete,
                'packageSessions' => $esPaquete ? (int) ($f['sesiones_paquete'] ?? 0) : null,
                'packageTotal'    => $esPaquete ? (float) ($f['precio'] ?? 0) : null,
                // Si hay texto original se devuelve tal cual; si no, se
                // reconstruye desde el importe.
                'price'           => (string) ($f['precio_texto']
                                     ?? ($f['precio'] !== null ? 'S/ ' . number_format((float) $f['precio'], 2) : '')),
                'unit'            => (string) ($f['unidad_texto'] ?? $f['unidad']),
                'notes'           => (string) ($f['descripcion'] ?? ''),
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

            $esPaquete   = self::bool($item['isPackage'] ?? false);
            $precioTexto = self::nz($item['price'] ?? null);
            $unidadTexto = self::nz($item['unit'] ?? null);

            $precio = $esPaquete === 1
                ? self::num($item['packageTotal'] ?? 0)
                : self::precioDeTexto($precioTexto);

            Database::query(
                'INSERT INTO servicios
                    (uid, nombre, es_paquete, sesiones_paquete, precio, precio_texto,
                     unidad, unidad_texto, descripcion, activo)
                 VALUES (?,?,?,?,?,?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE
                    nombre=VALUES(nombre), es_paquete=VALUES(es_paquete),
                    sesiones_paquete=VALUES(sesiones_paquete), precio=VALUES(precio),
                    precio_texto=VALUES(precio_texto), unidad=VALUES(unidad),
                    unidad_texto=VALUES(unidad_texto), descripcion=VALUES(descripcion),
                    activo=1',
                [
                    $uid,
                    self::txt($item['name']),
                    $esPaquete,
                    // chk_servicio_paquete exige sesiones > 0 cuando es paquete.
                    $esPaquete === 1 ? max(1, self::ent($item['packageSessions'] ?? 0)) : null,
                    $precio,
                    $precioTexto,
                    self::unidadEnum($unidadTexto, $esPaquete === 1),
                    $unidadTexto,
                    self::nz($item['notes'] ?? null),
                ]
            );
        }

        // Los servicios se desactivan en vez de borrarse: pueden estar
        // referenciados por paquetes y citas ya registrados.
        $sql = 'UPDATE servicios SET activo = 0 WHERE activo = 1';
        $par = [];
        if ($uids !== []) {
            $sql .= ' AND (uid IS NULL OR uid NOT IN (' . implode(',', array_fill(0, count($uids), '?')) . '))';
            $par = $uids;
        }
        Database::query($sql, $par);
        $this->subirVersion();
    }

    /** Extrae el primer importe de un texto libre: "S/100" -> 100.00 */
    private static function precioDeTexto(?string $texto): ?float
    {
        if ($texto === null) {
            return null;
        }
        if (preg_match('/(\d+(?:[.,]\d{1,2})?)/', $texto, $m) === 1) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return null;   // "A definir", "Variable", ...
    }

    private static function unidadEnum(?string $texto, bool $esPaquete): string
    {
        if ($esPaquete) {
            return 'Por paquete';
        }
        $t = mb_strtolower($texto ?? '');
        return match (true) {
            str_contains($t, 'mensual')          => 'Mensual',
            str_contains($t, 'taller')           => 'Por taller',
            str_contains($t, 'paciente atendido') => 'Por paciente atendido',
            str_contains($t, 'sesión'), str_contains($t, 'sesion') => 'Por sesión',
            str_contains($t, 'evento')           => 'Por evento',
            $t === ''                            => 'Por sesión',
            default                              => 'Otro',
        };
    }
}
