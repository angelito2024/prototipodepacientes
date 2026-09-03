<?php
declare(strict_types=1);

namespace Centro;

use RuntimeException;

/**
 * Adjuntos: el binario va al disco, la base guarda solo metadatos.
 *
 * El prototipo guarda cada archivo como data-URL base64 DENTRO del JSON
 * de la historia clínica. Eso infla el dato un 33 %, obliga a releer todos
 * los adjuntos en cada renderizado y hace que un PDF de 4 MB se reescriba
 * completo cada vez que se agrega una nota de evolución.
 */
final class Archivos
{
    /** Guarda un data-URL como archivo y devuelve el id de `archivos`. */
    public static function desdeDataUrl(
        string $dataUrl,
        string $nombre,
        ?string $mime = null,
        ?int $usuarioId = null
    ): int {
        if (!preg_match('#^data:([^;,]+)?(;base64)?,(.*)$#s', $dataUrl, $m)) {
            throw new RuntimeException('El adjunto no es un data-URL válido.');
        }
        $mime ??= $m[1] ?: 'application/octet-stream';
        $crudo = $m[2] === ';base64'
            ? base64_decode($m[3], true)
            : rawurldecode($m[3]);

        if ($crudo === false) {
            throw new RuntimeException('No se pudo decodificar el adjunto.');
        }

        return self::guardarBinario($crudo, $nombre, $mime, $usuarioId);
    }

    public static function guardarBinario(
        string $contenido,
        string $nombre,
        string $mime,
        ?int $usuarioId = null
    ): int {
        $max = (int) (Database::config()['max_adjunto'] ?? 8 * 1024 * 1024);
        if (strlen($contenido) > $max) {
            throw new RuntimeException(
                sprintf('El archivo supera el máximo permitido (%d MB).', intdiv($max, 1048576))
            );
        }

        $sha = hash('sha256', $contenido);

        // Deduplicación: el mismo consentimiento subido a diez fichas ocupa
        // espacio una sola vez.
        $existente = Database::valor(
            'SELECT id FROM archivos WHERE sha256 = ? AND eliminado_en IS NULL LIMIT 1',
            [$sha]
        );
        if ($existente !== null) {
            return (int) $existente;
        }

        $uuid = self::uuid();
        $ext  = self::extension($nombre, $mime);
        $rel  = date('Y/m') . '/' . $uuid . $ext;
        $abs  = self::raiz() . '/' . $rel;

        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            throw new RuntimeException('No se pudo crear la carpeta de almacenamiento: ' . dirname($abs));
        }
        if (file_put_contents($abs, $contenido) === false) {
            throw new RuntimeException('No se pudo escribir el archivo en ' . $abs);
        }

        Database::query(
            'INSERT INTO archivos (uuid, nombre_original, mime, tamano_bytes, sha256, ruta_relativa, es_enlace, subido_por)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)',
            [$uuid, mb_substr($nombre, 0, 255), $mime, strlen($contenido), $sha, $rel, $usuarioId]
        );
        return Database::ultimoId();
    }

    /** Registra un enlace externo (Google Drive y similares). */
    public static function desdeEnlace(string $nombre, string $url, ?int $usuarioId = null): int
    {
        Database::query(
            'INSERT INTO archivos (uuid, nombre_original, es_enlace, url_externa, subido_por)
             VALUES (?, ?, 1, ?, ?)',
            [self::uuid(), mb_substr($nombre, 0, 255), mb_substr($url, 0, 1000), $usuarioId]
        );
        return Database::ultimoId();
    }

    /**
     * Representación que consume el panel. Ya NO lleva el base64: lleva la
     * URL del endpoint de descarga, así la lista de adjuntos pesa bytes en
     * vez de megabytes.
     */
    public static function aJson(?array $fila): ?array
    {
        if ($fila === null) {
            return null;
        }
        $esEnlace = (int) $fila['es_enlace'] === 1;
        return [
            'id'     => (string) $fila['uuid'],
            'name'   => (string) $fila['nombre_original'],
            'type'   => $fila['mime'] !== null ? (string) $fila['mime'] : '',
            'size'   => $fila['tamano_bytes'] !== null ? (int) $fila['tamano_bytes'] : null,
            'isLink' => $esEnlace,
            'url'    => $esEnlace
                ? (string) $fila['url_externa']
                : 'api/archivo.php?id=' . rawurlencode((string) $fila['uuid']),
        ];
    }

    /**
     * Resuelve un adjunto que llega del panel: puede venir como data-URL
     * (recién subido), como enlace externo, o ya como referencia a un
     * archivo existente. Devuelve el id de `archivos` o null.
     */
    public static function resolver(mixed $file, ?int $usuarioId = null): ?int
    {
        if (!is_array($file)) {
            return null;
        }

        // Ya existe: viene con el uuid que devolvió una lectura anterior.
        $uuid = Repositorio::nz($file['id'] ?? null);
        if ($uuid !== null) {
            $id = Database::valor('SELECT id FROM archivos WHERE uuid = ?', [$uuid]);
            if ($id !== null) {
                return (int) $id;
            }
        }

        $nombre = Repositorio::txt($file['name'] ?? '') ?: 'Adjunto';

        if (!empty($file['isLink']) && Repositorio::nz($file['url'] ?? null) !== null) {
            return self::desdeEnlace($nombre, (string) $file['url'], $usuarioId);
        }

        $dataUrl = Repositorio::nz($file['dataUrl'] ?? null);
        if ($dataUrl !== null && str_starts_with($dataUrl, 'data:')) {
            return self::desdeDataUrl($dataUrl, $nombre, Repositorio::nz($file['type'] ?? null), $usuarioId);
        }

        return null;
    }

    public static function rutaAbsoluta(array $fila): string
    {
        return self::raiz() . '/' . $fila['ruta_relativa'];
    }

    public static function raiz(): string
    {
        $raiz = Database::config()['storage'] ?? __DIR__ . '/../storage';
        return rtrim(str_replace('\\', '/', (string) $raiz), '/');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function extension(string $nombre, string $mime): string
    {
        $ext = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));
        if ($ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext) === 1) {
            return '.' . $ext;
        }
        return match ($mime) {
            'application/pdf' => '.pdf',
            'image/png'       => '.png',
            'image/jpeg'      => '.jpg',
            'image/webp'      => '.webp',
            default           => '.bin',
        };
    }
}
