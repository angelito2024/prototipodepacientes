<?php
declare(strict_types=1);

/**
 * Autocarga PSR-4 mínima para el namespace Centro\ -> src/
 * Sin Composer: el proyecto no tiene dependencias externas.
 */
spl_autoload_register(static function (string $clase): void {
    $prefijo = 'Centro\\';
    if (!str_starts_with($clase, $prefijo)) {
        return;
    }
    $relativa = substr($clase, strlen($prefijo));
    $ruta = __DIR__ . '/' . str_replace('\\', '/', $relativa) . '.php';
    if (is_file($ruta)) {
        require $ruta;
    }
});
