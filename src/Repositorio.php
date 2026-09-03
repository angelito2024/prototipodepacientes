<?php
declare(strict_types=1);

namespace Centro;

/**
 * Base de los mapeadores entre el formato JSON que consume index.html y
 * el modelo relacional.
 *
 * El panel guarda con setColl('patients', DB.patients): envía el ARRAY
 * COMPLETO, no el elemento que cambió. Para no reescribir los ~40 puntos
 * de llamada del prototipo, la API acepta ese array y lo concilia contra
 * las tablas: inserta lo nuevo, actualiza lo cambiado y da de baja lo que
 * ya no viene. El `uid` (el id base36 del panel) es la bisagra.
 */
abstract class Repositorio
{
    /** Clave de colección que usa el panel ('patients', 'appointments', ...). */
    abstract public function clave(): string;

    /** Modelo relacional -> forma JSON que espera index.html. */
    abstract public function leer(): mixed;

    /** Forma JSON de index.html -> modelo relacional. */
    abstract public function guardar(mixed $valor): void;

    // ------------------------------------------------------------------
    // Utilidades compartidas
    // ------------------------------------------------------------------

    /** Cadena vacía -> NULL (para columnas UNIQUE y FK opcionales). */
    public static function nz(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    public static function txt(mixed $v, string $porDefecto = ''): string
    {
        return $v === null ? $porDefecto : trim((string) $v);
    }

    public static function num(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    public static function ent(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    public static function bool(mixed $v): int
    {
        return ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
    }

    /** Fecha 'AAAA-MM-DD' válida, o NULL. */
    public static function fecha(mixed $v): ?string
    {
        $s = self::nz($v);
        if ($s === null) {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : null;
    }

    /** 'MM-DD' válido, o NULL. El panel guarda los cumpleaños así. */
    public static function diaMes(mixed $v): ?string
    {
        $s = self::nz($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^\d{4}-(\d{2}-\d{2})$/', $s, $m) === 1) {
            return $m[1];
        }
        return preg_match('/^\d{2}-\d{2}$/', $s) === 1 ? $s : null;
    }

    /** Hora 'HH:MM[:SS]' válida, o NULL. */
    public static function hora(mixed $v): ?string
    {
        $s = self::nz($v);
        if ($s === null) {
            return null;
        }
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $s) === 1 ? $s : null;
    }

    /**
     * Valor permitido por un ENUM; si no está en la lista, devuelve el
     * primero. Evita que un dato antiguo rompa el INSERT en modo estricto.
     */
    public static function enum(mixed $v, array $permitidos, ?string $porDefecto = null): string
    {
        $s = self::txt($v);
        if (in_array($s, $permitidos, true)) {
            return $s;
        }
        return $porDefecto ?? $permitidos[0];
    }

    /** @return array<string,int> uid => id */
    public static function mapaUid(string $tabla): array
    {
        $mapa = [];
        foreach (Database::todos("SELECT uid, id FROM {$tabla} WHERE uid IS NOT NULL") as $f) {
            $mapa[(string) $f['uid']] = (int) $f['id'];
        }
        return $mapa;
    }

    /** @return array<string,int> uid de persona => id, para un subtipo dado. */
    public static function mapaPersonas(?string $subtipo = null): array
    {
        $sql = 'SELECT p.uid, p.id FROM personas p';
        if ($subtipo !== null) {
            $sql .= " JOIN {$subtipo} s ON s.persona_id = p.id";
        }
        $sql .= ' WHERE p.uid IS NOT NULL';
        $mapa = [];
        foreach (Database::todos($sql) as $f) {
            $mapa[(string) $f['uid']] = (int) $f['id'];
        }
        return $mapa;
    }

    /** Genera un uid con el mismo formato que uid() del panel. */
    public static function nuevoUid(): string
    {
        return base_convert((string) (int) (microtime(true) * 1000), 10, 36)
            . substr(bin2hex(random_bytes(4)), 0, 5);
    }

    /** Sube la versión de la colección (bloqueo optimista). */
    protected function subirVersion(): int
    {
        Database::query(
            'INSERT INTO coleccion_version (clave, version, actualizado_por)
             VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE version = version + 1, actualizado_por = VALUES(actualizado_por)',
            [$this->clave(), Auth::usuarioId()]
        );
        return (int) Database::valor(
            'SELECT version FROM coleccion_version WHERE clave = ?',
            [$this->clave()]
        );
    }

    public function version(): int
    {
        $v = Database::valor('SELECT version FROM coleccion_version WHERE clave = ?', [$this->clave()]);
        return $v === null ? 0 : (int) $v;
    }
}
