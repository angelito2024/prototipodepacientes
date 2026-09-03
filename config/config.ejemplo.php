<?php
/**
 * Plantilla de configuración. Copiar a config/config.php y ajustar.
 * config/config.php está en .gitignore: NO se versiona.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'puerto'  => 3306,
        'nombre'  => 'centro_psicologico',
        // Nunca usar root en la aplicación. El usuario app_centro se crea
        // en db/03_datos_base.sql con permisos mínimos (sin DROP ni ALTER).
        'usuario' => 'app_centro',
        'clave'   => 'CambiaEstaClave_2026',
        'charset' => 'utf8mb4',
    ],

    // Carpeta donde se guardan los adjuntos. Debe quedar FUERA de www/
    // en producción para que nadie pueda descargarlos por URL directa.
    'storage' => __DIR__ . '/../storage',

    // Tamaño máximo por adjunto (bytes).
    'max_adjunto' => 8 * 1024 * 1024,

    'zona_horaria' => 'America/Lima',

    // true en desarrollo: muestra el detalle de los errores SQL.
    // false en producción: solo registra en log y devuelve un mensaje genérico.
    'debug' => true,
];
