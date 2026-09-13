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

    /**
     * A quién se le cobra la tarifa. Sin esto, la ficha del paciente
     * ofrecía charlas, alquiler del consultorio y comisiones del
     * profesional junto a las sesiones de terapia.
     */
    private const AMBITOS = ['paciente', 'grupal', 'interno'];

    public function clave(): string
    {
        return 'services';
    }

    public function leer(): array
    {
        $salida = [];
        foreach (Database::todos(
            'SELECT uid, nombre, es_paquete, sesiones_paquete, precio,
                    precio_texto, unidad, unidad_texto, descripcion, ambito
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
                'ambito'          => (string) $f['ambito'],
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
                     unidad, unidad_texto, descripcion, ambito, activo)
                 VALUES (?,?,?,?,?,?,?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE
                    nombre=VALUES(nombre), es_paquete=VALUES(es_paquete),
                    sesiones_paquete=VALUES(sesiones_paquete), precio=VALUES(precio),
                    precio_texto=VALUES(precio_texto), unidad=VALUES(unidad),
                    unidad_texto=VALUES(unidad_texto), descripcion=VALUES(descripcion),
                    ambito=VALUES(ambito), activo=1',
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
                    // Las tarifas creadas antes de que existiera el ámbito
                    // llegan sin él: se deduce del nombre, igual que hace el
                    // panel, y queda guardado para poder corregirlo a mano.
                    self::enum(
                        $item['ambito'] ?? null,
                        self::AMBITOS,
                        self::ambitoDeducido(self::txt($item['name']), $esPaquete === 1)
                    ),
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
        // Se aceptan separadores de miles: "S/ 1,250.00" y "S/ 1250" dan lo
        // mismo. Sin esto, "1,250.00" se leía como 1.25.
        if (preg_match('/(\d[\d.,]*)/', $texto, $m) === 1) {
            $n = $m[1];
            // El último separador con 1-2 decimales detrás es el decimal;
            // cualquier otro punto o coma es separador de miles.
            if (preg_match('/^(.*)([.,])(\d{1,2})$/', $n, $d) === 1) {
                $n = str_replace([',', '.'], '', $d[1]) . '.' . $d[3];
            } else {
                $n = str_replace([',', '.'], '', $n);
            }
            return $n === '' ? null : (float) $n;
        }
        return null;   // "A definir", "Variable", ...
    }

    /** Misma regla que ambitoDeTarifa() del panel, para no discrepar. */
    private static function ambitoDeducido(string $nombre, bool $esPaquete): string
    {
        if ($esPaquete) {
            return 'paciente';
        }
        return match (true) {
            preg_match('/alquiler|comisi[oó]n/i', $nombre) === 1 => 'interno',
            preg_match('/charla|capacitaci|organizacional|empresa|colegio|taller/i', $nombre) === 1 => 'grupal',
            default => 'paciente',
        };
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
