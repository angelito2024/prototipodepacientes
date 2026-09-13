<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/**
 * Colecciones que se guardan como documento JSON: 'prestamos',
 * 'recaudaciones' y 'juntas'.
 *
 * Son finanzas privadas de Luis, no del centro. Nada del resto del sistema
 * las referencia, son pequeñas, se leen enteras y su forma cambia seguido
 * (un fondo nuevo, otra clase de junta). Normalizarlas costaría migrar
 * tablas cada vez sin ganar integridad referencial.
 *
 * Lo que sí necesitan —persistir de verdad, entrar en los respaldos y tener
 * control de versión para que dos pestañas no se pisen— lo dan igual.
 */
final class Documento extends Repositorio
{
    public function __construct(private readonly string $clave)
    {
    }

    public function clave(): string
    {
        return $this->clave;
    }

    public function leer(): mixed
    {
        $json = Database::valor(
            'SELECT contenido FROM coleccion_json WHERE clave = ?',
            [$this->clave]
        );
        if ($json === null) {
            return [];
        }
        $valor = json_decode((string) $json, true);
        return is_array($valor) ? $valor : [];
    }

    public function guardar(mixed $valor): void
    {
        // Solo listas y objetos: un escalar suelto sería un error del cliente
        // y dejaría la colección inservible.
        if (!is_array($valor)) {
            return;
        }
        Database::query(
            'INSERT INTO coleccion_json (clave, contenido, actualizado_por)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                contenido = VALUES(contenido),
                actualizado_por = VALUES(actualizado_por)',
            [
                $this->clave,
                json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                Auth::usuarioId(),
            ]
        );
        $this->subirVersion();
    }
}
