<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'cie10': el catálogo de diagnósticos.
 *
 * Existe porque el buscador de la historia clínica tenía su propia lista
 * escrita dentro del panel, y esa lista no era la misma que la del
 * catálogo de la base. Las consecuencias eran dos, y ninguna se veía:
 *
 *  · el panel ofrecía códigos que la base no reconocía, y al guardar el
 *    diagnóstico se quedaba sin código —la descripción sobrevivía, el
 *    CIE-10 no—, que es justo el dato que pide un informe formal;
 *  · los códigos que sí estaban en la base pero no en esa lista eran
 *    invisibles: no había forma de elegirlos.
 *
 * Con una sola fuente eso no puede volver a pasar. El catálogo es de
 * lectura: se carga desde `db/14_cie10_salud_mental.sql`, no se edita
 * desde la pantalla.
 */
final class Cie10 extends Repositorio
{
    public function clave(): string
    {
        return 'cie10';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            'SELECT codigo, descripcion, capitulo
               FROM cie10_catalogo
              WHERE vigente = 1
              ORDER BY codigo'
        );
        // Nombres cortos: esta lista viaja entera al navegador y se recorre
        // en cada tecla del buscador.
        return array_map(static fn(array $f): array => [
            'code' => $f['codigo'],
            'desc' => $f['descripcion'],
            'cap'  => $f['capitulo'],
        ], $filas);
    }

    /** El catálogo no se edita desde el panel. */
    public function guardar(mixed $valor): void
    {
    }
}
