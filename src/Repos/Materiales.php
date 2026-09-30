<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Archivos;
use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;
use RuntimeException;

/**
 * Colección 'materiales': la biblioteca de trabajo del centro.
 *
 * Pictogramas, manuales para padres, tarjetas de lenguaje, videos. Lo que
 * hasta ahora vivía en carpetas del escritorio de una sola computadora.
 *
 * Desde el panel se puede corregir el título, la descripción, para quién es
 * y qué se puede hacer con él. El archivo en sí no se cambia desde aquí:
 * entra por `materiales/importar.php` y se va dando de baja el material.
 */
final class Materiales extends Repositorio
{
    public const LICENCIAS = ['propio', 'libre', 'solo_sesion', 'reservado'];
    public const DESTINOS  = ['paciente', 'familia', 'docente', 'profesional'];
    public const FORMATOS  = ['pdf', 'video', 'imagen', 'presentacion', 'hoja', 'audio', 'enlace', 'otro'];

    public function clave(): string
    {
        return 'materiales';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            'SELECT m.*, a.uuid AS archivo_uuid, a.nombre_original, a.mime,
                    a.tamano_bytes, a.es_enlace, a.url_externa,
                    (SELECT COUNT(*) FROM material_entrega_item i WHERE i.material_id = m.id) AS veces
               FROM materiales m
          LEFT JOIN archivos a ON a.id = m.archivo_id
              ORDER BY m.coleccion, m.seccion, m.orden, m.titulo'
        );
        return array_map(static function (array $f): array {
            $esEnlace = (int) ($f['es_enlace'] ?? 0) === 1;
            return [
                'id'            => $f['uid'],
                'titulo'        => $f['titulo'],
                'coleccion'     => $f['coleccion'],
                'seccion'       => $f['seccion'],
                'descripcion'   => $f['descripcion'],
                'formato'       => $f['formato'],
                'edadMin'       => $f['edad_min'] === null ? null : (int) $f['edad_min'],
                'edadMax'       => $f['edad_max'] === null ? null : (int) $f['edad_max'],
                'destino'       => $f['destino'],
                'licencia'      => $f['licencia'],
                'origen'        => $f['origen'],
                'activo'        => (int) $f['activo'] === 1,
                'peso'          => $f['tamano_bytes'] === null ? null : (int) $f['tamano_bytes'],
                'archivo'       => $f['archivo_uuid'],
                'nombreArchivo' => $f['nombre_original'],
                // Para abrirlo en consulta desde el panel. Esta dirección
                // exige sesión: no es la que se le manda al paciente.
                'url'           => $f['archivo_uuid'] === null
                    ? null
                    : ($esEnlace
                        ? (string) $f['url_externa']
                        : 'api/archivo.php?id=' . rawurlencode((string) $f['archivo_uuid'])),
                'veces'         => (int) $f['veces'],
            ];
        }, $filas);
    }

    /**
     * Lo que se puede corregir desde la pantalla. Deliberadamente no se
     * puede cambiar el archivo: si un material pudiera apuntar a otro PDF
     * sin dejar rastro, lo que dice una entrega vieja dejaría de ser lo que
     * la familia recibió.
     */
    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        foreach ($valor as $m) {
            if (!is_array($m) || empty($m['id'])) {
                continue;
            }
            Database::query(
                'UPDATE materiales
                    SET titulo = ?, coleccion = ?, seccion = ?, descripcion = ?,
                        edad_min = ?, edad_max = ?, destino = ?, licencia = ?,
                        origen = ?, activo = ?
                  WHERE uid = ?',
                [
                    mb_substr(self::txt($m['titulo'] ?? '', 'Material'), 0, 200),
                    mb_substr(self::txt($m['coleccion'] ?? '', 'General'), 0, 120),
                    self::nz($m['seccion'] ?? null),
                    self::nz($m['descripcion'] ?? null),
                    self::nz($m['edadMin'] ?? null),
                    self::nz($m['edadMax'] ?? null),
                    self::enum($m['destino'] ?? '', self::DESTINOS, 'familia'),
                    self::enum($m['licencia'] ?? '', self::LICENCIAS, 'solo_sesion'),
                    self::nz($m['origen'] ?? null),
                    self::bool($m['activo'] ?? true),
                    (string) $m['id'],
                ]
            );
        }
        $this->subirVersion();
    }

    // ------------------------------------------------------------------
    //  Alta
    // ------------------------------------------------------------------

    /** Registra un material nuevo a partir de un archivo ya guardado. */
    public static function registrar(array $d, ?int $archivoId): string
    {
        $uid = 'mt_' . bin2hex(random_bytes(8));
        Database::query(
            'INSERT INTO materiales
                (uid, titulo, coleccion, seccion, descripcion, archivo_id, formato,
                 edad_min, edad_max, destino, licencia, origen, orden)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $uid,
                mb_substr(self::txt($d['titulo'] ?? '', 'Material'), 0, 200),
                mb_substr(self::txt($d['coleccion'] ?? '', 'General'), 0, 120),
                self::nz($d['seccion'] ?? null),
                self::nz($d['descripcion'] ?? null),
                $archivoId,
                self::enum($d['formato'] ?? '', self::FORMATOS, 'otro'),
                self::nz($d['edadMin'] ?? null),
                self::nz($d['edadMax'] ?? null),
                self::enum($d['destino'] ?? '', self::DESTINOS, 'familia'),
                self::enum($d['licencia'] ?? '', self::LICENCIAS, 'solo_sesion'),
                self::nz($d['origen'] ?? null),
                self::ent($d['orden'] ?? 0),
            ]
        );
        return $uid;
    }

    /** Formato deducido del nombre del archivo. */
    public static function formatoPorNombre(string $nombre): string
    {
        return match (strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION))) {
            'pdf'                               => 'pdf',
            'mp4', 'webm', 'mov', 'avi'         => 'video',
            'jpg', 'jpeg', 'png', 'gif', 'webp' => 'imagen',
            'ppsx', 'pptx', 'ppt'               => 'presentacion',
            'xlsx', 'xls', 'csv'                => 'hoja',
            'mp3', 'wav', 'ogg', 'm4a'          => 'audio',
            default                             => 'otro',
        };
    }

    public static function porUid(string $uid): ?array
    {
        return Database::uno('SELECT * FROM materiales WHERE uid = ?', [$uid]);
    }

    /**
     * Los que se le pueden mandar a una familia.
     *
     * Un material marcado 'solo_sesion' o 'reservado' se usa en consulta y
     * no sale de ahí. Esta comprobación está en el servidor a propósito: si
     * viviera solo en el panel, bastaría con pedir la dirección a mano para
     * saltársela.
     */
    public static function entregable(array $fila): bool
    {
        return in_array($fila['licencia'], ['propio', 'libre'], true)
            && (int) $fila['activo'] === 1
            && $fila['archivo_id'] !== null;
    }

    /** Da de baja el material y borra el archivo del disco. */
    public static function eliminar(string $uid): void
    {
        $m = self::porUid($uid);
        if ($m === null) {
            throw new RuntimeException('No encuentro ese material.');
        }
        $usado = (int) Database::valor(
            'SELECT COUNT(*) FROM material_entrega_item WHERE material_id = ?',
            [(int) $m['id']]
        );
        if ($usado > 0) {
            throw new RuntimeException(
                'Ese material ya se le entregó a alguien, así que no se borra: quedaría una '
                . 'entrega apuntando a algo que ya no existe. Desactívalo y deja de ofrecerlo.'
            );
        }

        $archivoId = $m['archivo_id'] === null ? null : (int) $m['archivo_id'];
        Database::query('DELETE FROM materiales WHERE id = ?', [(int) $m['id']]);

        if ($archivoId !== null) {
            $otros = (int) Database::valor(
                'SELECT COUNT(*) FROM materiales WHERE archivo_id = ?', [$archivoId]
            );
            $a = Database::uno('SELECT * FROM archivos WHERE id = ?', [$archivoId]);
            if ($a !== null && $otros === 0 && (int) $a['es_enlace'] === 0) {
                $ruta = Archivos::rutaAbsoluta($a);
                if (is_file($ruta)) {
                    @unlink($ruta);
                }
                Database::query('UPDATE archivos SET eliminado_en = NOW() WHERE id = ?', [$archivoId]);
            }
        }
        Auth::auditar('ELIMINAR_MATERIAL', Auth::usuarioId(), ['uid' => $uid], 'materiales');
    }
}
