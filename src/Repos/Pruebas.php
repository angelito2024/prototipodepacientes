<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'pruebas': el catálogo de pruebas psicológicas que el centro
 * puede aplicar.
 *
 * Al panel le llega solo la ficha (nombre, cuántos ítems, a qué edad se
 * aplica). La definición completa —las preguntas, la clave de corrección y
 * los baremos— se queda en el servidor: si viajara al navegador, cualquiera
 * con la consola abierta tendría la plantilla de corrección del test, y un
 * paciente podría ver qué respuesta puntúa en qué escala.
 */
final class Pruebas extends Repositorio
{
    public function clave(): string
    {
        return 'pruebas';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            'SELECT codigo, nombre, siglas, autor, descripcion, edad_minima, edad_maxima,
                    minutos_aprox, n_items, activa
               FROM pruebas
              ORDER BY nombre'
        );
        return array_map(static fn(array $f): array => [
            'codigo'      => $f['codigo'],
            'nombre'      => $f['nombre'],
            'siglas'      => $f['siglas'],
            'autor'       => $f['autor'],
            'descripcion' => $f['descripcion'],
            'edadMinima'  => $f['edad_minima'] === null ? null : (int) $f['edad_minima'],
            'edadMaxima'  => $f['edad_maxima'] === null ? null : (int) $f['edad_maxima'],
            'minutos'     => $f['minutos_aprox'] === null ? null : (int) $f['minutos_aprox'],
            'nItems'      => (int) $f['n_items'],
            'activa'      => (int) $f['activa'] === 1,
        ], $filas);
    }

    /**
     * El catálogo no se edita desde el panel: una prueba se carga desde su
     * Excel con `pruebas/cargar.php`, que es donde está la clave. Lo único
     * que se puede cambiar aquí es si sigue disponible o no.
     */
    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        foreach ($valor as $p) {
            if (!is_array($p) || !isset($p['codigo'])) {
                continue;
            }
            Database::query(
                'UPDATE pruebas SET activa = ? WHERE codigo = ?',
                [isset($p['activa']) && $p['activa'] ? 1 : 0, (string) $p['codigo']]
            );
        }
        $this->subirVersion();
    }

    /** La definición completa, solo para el servidor. */
    public static function definicion(string $codigo): ?array
    {
        $json = Database::valor('SELECT definicion FROM pruebas WHERE codigo = ?', [$codigo]);
        if ($json === null) {
            return null;
        }
        $d = json_decode((string) $json, true);
        return is_array($d) ? $d : null;
    }

    public static function id(string $codigo): ?int
    {
        $id = Database::valor('SELECT id FROM pruebas WHERE codigo = ?', [$codigo]);
        return $id === null ? null : (int) $id;
    }

    /** Guarda (o actualiza) una prueba completa. La usa el cargador. */
    public static function instalar(array $def, array $ficha = []): int
    {
        Database::query(
            'INSERT INTO pruebas (codigo, nombre, siglas, autor, descripcion,
                                  edad_minima, edad_maxima, minutos_aprox, n_items, definicion)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                nombre = VALUES(nombre), siglas = VALUES(siglas), autor = VALUES(autor),
                descripcion = VALUES(descripcion), edad_minima = VALUES(edad_minima),
                edad_maxima = VALUES(edad_maxima), minutos_aprox = VALUES(minutos_aprox),
                n_items = VALUES(n_items), definicion = VALUES(definicion)',
            [
                $def['codigo'],
                $def['nombre'],
                $def['siglas'] ?? null,
                $def['autor'] ?? null,
                $ficha['descripcion'] ?? null,
                $ficha['edadMinima'] ?? null,
                $ficha['edadMaxima'] ?? null,
                $ficha['minutos'] ?? null,
                (int) $def['nItems'],
                json_encode($def, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        Auth::auditar('CARGAR_PRUEBA', null, ['codigo' => $def['codigo']], 'pruebas');
        return (int) Database::valor('SELECT id FROM pruebas WHERE codigo = ?', [$def['codigo']]);
    }
}
