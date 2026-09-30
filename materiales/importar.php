<?php
declare(strict_types=1);

/**
 * Trae una carpeta de material de trabajo a la biblioteca del sistema.
 *
 *   php materiales/importar.php "C:/ruta/al/pack"
 *   php materiales/importar.php "C:/ruta/al/pack" --coleccion="Niños con autismo (TEA)"
 *   php materiales/importar.php "C:/ruta/al/pack" --probar     (no escribe nada)
 *
 * Qué hace:
 *   · recorre la carpeta y sus subcarpetas;
 *   · copia cada archivo al almacenamiento del sistema (una sola vez: si el
 *     mismo PDF está en dos carpetas, ocupa espacio una vez);
 *   · le pone un título legible a partir del nombre del archivo;
 *   · clasifica de dónde salió y qué se puede hacer con él.
 *
 * Esto último es lo importante y por eso no se adivina solo. El material
 * ajeno se puede usar en consulta; repartirlo o venderlo es otra cosa. El
 * importador marca lo que reconoce y deja el resto como «solo en sesión»,
 * que es lo prudente: de ahí en adelante lo decide una persona desde el
 * panel, material por material.
 *
 * Los archivos NO van al repositorio: quedan en `storage/materiales/`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script se ejecuta desde la consola, no desde el navegador.\n");
}

require __DIR__ . '/../src/autoload.php';

use Centro\Archivos;
use Centro\Database;
use Centro\Repos\Materiales;

$args    = array_slice($argv, 1);
$carpeta = null;
$coleccion = null;
$probar  = false;
$base    = null;      // base de datos distinta, para probar sin tocar la real
$almacen = null;      // carpeta de archivos distinta, por lo mismo

foreach ($args as $a) {
    if ($a === '--probar' || $a === '--dry-run') {
        $probar = true;
    } elseif (str_starts_with($a, '--coleccion=')) {
        $coleccion = substr($a, strlen('--coleccion='));
    } elseif (str_starts_with($a, '--base=')) {
        $base = substr($a, strlen('--base='));
    } elseif (str_starts_with($a, '--almacen=')) {
        $almacen = substr($a, strlen('--almacen='));
    } elseif (!str_starts_with($a, '--')) {
        $carpeta = $a;
    }
}

// Se comprueba contra una base desechable, nunca contra la del centro.
if ($base !== null || $almacen !== null) {
    $cfg = Database::config();
    if ($base !== null) {
        $cfg['db']['nombre'] = $base;
    }
    if ($almacen !== null) {
        $cfg['storage'] = $almacen;
    }
    $prop = (new ReflectionClass(Database::class))->getProperty('config');
    $prop->setAccessible(true);
    $prop->setValue(null, $cfg);
    echo "Base: {$cfg['db']['nombre']}\n";
}

if ($carpeta === null || !is_dir($carpeta)) {
    exit("Uso: php materiales/importar.php \"C:/ruta/a/la/carpeta\" [--coleccion=\"Nombre\"] [--probar]\n");
}

$carpeta   = rtrim(str_replace('\\', '/', $carpeta), '/');
$coleccion ??= nombreLegible(basename($carpeta));

echo "Colección: $coleccion\n";
echo "Carpeta:   $carpeta\n";
echo $probar ? "Modo prueba: no se escribe nada.\n\n" : "\n";

/* -------------------------------------------------------------------
 *  Cómo se clasifica
 *
 *  Cada regla mira el nombre de la subcarpeta o del archivo. La primera
 *  que coincide manda. Lo que no coincide con ninguna queda como «solo
 *  en sesión»: se usa en consulta y no se reparte hasta que alguien lo
 *  revise.
 * ----------------------------------------------------------------- */
$reglas = [
    // Escalas y protocolos: material con derechos, del profesional y de
    // nadie más. El C.A.R.S. es una escala comercial; repartir el
    // protocolo, además de ser una infracción, arruina la prueba para
    // quien después la tenga que responder.
    [
        'busca'    => ['c.a.r.s', 'cars', 'test', 'registro de paciente'],
        'licencia' => 'reservado',
        'destino'  => 'profesional',
        'origen'   => '',
        'nota'     => 'Escala de evaluación: solo para uso del profesional.',
    ],
    // Los videos pesan cien megas y más: no son para mandar por WhatsApp,
    // son para poner en la sesión.
    [
        'extension' => ['mp4', 'webm', 'mov', 'avi'],
        'licencia'  => 'solo_sesion',
        'destino'   => 'profesional',
        'nota'      => 'Video de formación: se ve en sesión o en capacitación del equipo.',
    ],
    // Actividad interactiva: se corre en la computadora del consultorio.
    [
        'extension' => ['ppsx', 'pptx', 'ppt'],
        'licencia'  => 'solo_sesion',
        'destino'   => 'paciente',
        'nota'      => 'Actividad interactiva para trabajar en la sesión.',
    ],
    // Pictogramas y secuencias: están hechos para imprimirse y pegarse en
    // la casa. Es exactamente el material que la familia se lleva.
    [
        'busca'    => ['pictograma', 'rutina', 'secuencia'],
        'licencia' => 'libre',
        'destino'  => 'familia',
        'nota'     => 'Para imprimir y usar en casa.',
    ],
    [
        'busca'    => ['autismodiario'],
        'licencia' => 'libre',
        'destino'  => 'familia',
        'origen'   => 'autismodiario.org',
    ],
    [
        'busca'    => ['primera preocupación', 'primera preocupacion'],
        'licencia' => 'libre',
        'destino'  => 'familia',
        'origen'   => 'Autism Speaks — guía de distribución gratuita',
    ],
    [
        'busca'    => ['manual para padres', 'guia de información', 'guia de informacion',
                       'padres y maestros', 'manual-de-actividades'],
        'licencia' => 'libre',
        'destino'  => 'familia',
        'nota'     => 'Guía de orientación para la familia.',
    ],
    [
        'busca'    => ['alumnado', 'apoyos a las personas', 'docente', 'estudiantes'],
        'licencia' => 'libre',
        'destino'  => 'docente',
        'nota'     => 'Orientaciones para el colegio.',
    ],
    [
        'busca'    => ['tarjeta', 'vocabulario', 'oracion', 'oración', 'vocales',
                       'cuento', 'ventura', 'señalar', 'senalar', 'partes del cuerpo',
                       'funciones comunicativas'],
        'licencia' => 'libre',
        'destino'  => 'familia',
        'nota'     => 'Ejercicios para practicar en casa entre sesión y sesión.',
    ],
    [
        'busca'    => ['app', 'plataforma'],
        'licencia' => 'libre',
        'destino'  => 'familia',
        'nota'     => 'Listado de aplicaciones recomendadas.',
    ],
];

/* --- Recorrido ----------------------------------------------------- */
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$archivos = [];
foreach ($iter as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $ext = strtolower($f->getExtension());
    if ($ext === '' || in_array($ext, ['db', 'ini', 'lnk', 'tmp'], true)) {
        continue;
    }
    $archivos[] = str_replace('\\', '/', $f->getPathname());
}
sort($archivos);

if ($archivos === []) {
    exit("No encontré archivos en esa carpeta.\n");
}

$nuevos = 0;
$repetidos = 0;
$porLicencia = [];
$orden = 0;

foreach ($archivos as $ruta) {
    $relativa = ltrim(substr($ruta, strlen($carpeta)), '/');
    $partes   = explode('/', $relativa);
    $archivo  = array_pop($partes);
    $seccion  = $partes === [] ? null : implode(' — ', array_map('nombreLegible', $partes));
    $base     = pathinfo($archivo, PATHINFO_FILENAME);
    $titulo   = nombreLegible($base);

    // Hay carpetas donde los archivos se llaman "a.jpg", "b.jpg"... El
    // nombre no dice nada; lo que ubica la hoja es la carpeta y la letra.
    if (mb_strlen($titulo, 'UTF-8') <= 2 && $seccion !== null) {
        $ultima = trim((string) (array_slice(explode(' — ', $seccion), -1)[0] ?? ''));
        $titulo = $ultima . ' — hoja ' . mb_strtoupper($base, 'UTF-8');
    }

    $clase = clasificar($relativa, $archivo, $reglas);
    $porLicencia[$clase['licencia']] = ($porLicencia[$clase['licencia']] ?? 0) + 1;

    printf(
        "%-11s %-13s %s\n",
        $clase['licencia'],
        $clase['destino'],
        ($seccion !== null ? "$seccion · " : '') . $titulo
    );

    if ($probar) {
        continue;
    }

    Database::transaccion(static function () use (
        $ruta, $archivo, $titulo, $seccion, $coleccion, $clase, &$nuevos, &$repetidos, &$orden
    ): void {
        $archivoId = Archivos::importarDesdeDisco($ruta, $archivo);

        // El mismo archivo ya registrado como material: no se duplica.
        $existe = Database::valor(
            'SELECT id FROM materiales WHERE archivo_id = ? LIMIT 1', [$archivoId]
        );
        if ($existe !== null) {
            $repetidos++;
            return;
        }

        Materiales::registrar([
            'titulo'      => $titulo,
            'coleccion'   => $coleccion,
            'seccion'     => $seccion,
            'descripcion' => $clase['nota'],
            'formato'     => Materiales::formatoPorNombre($archivo),
            'destino'     => $clase['destino'],
            'licencia'    => $clase['licencia'],
            'origen'      => $clase['origen'],
            'orden'       => ++$orden,
        ], $archivoId);
        $nuevos++;
    });
}

echo "\n";
if ($probar) {
    echo count($archivos) . " archivos listos para importar. No se escribió nada.\n";
} else {
    echo "$nuevos materiales nuevos";
    if ($repetidos > 0) {
        echo ", $repetidos ya estaban";
    }
    echo ".\n";
}
ksort($porLicencia);
foreach ($porLicencia as $lic => $n) {
    echo "  $lic: $n\n";
}
echo "\nRevisa la clasificación en el panel, en Materiales. Lo que quedó como\n"
   . "«solo en sesión» no se le puede mandar a nadie hasta que lo cambies.\n";

/* ------------------------------------------------------------------ */

/** Nombre de archivo o carpeta -> algo que se pueda leer en la pantalla. */
function nombreLegible(string $s): string
{
    // '1. Actividades para...' / '2- Actividades-para-alumnado' / '1.1- App...'
    $s = preg_replace('/^\s*\d+(\.\d+)*\s*[.\-–]\s*/u', '', $s) ?? $s;
    // Los emojis con que se marcan las carpetas en el escritorio no tienen
    // por qué salir en una lista del sistema.
    $s = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $s) ?? $s;
    $s = str_replace(['_', '-'], ' ', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    // Quita el "(1)" que deja Windows al bajar dos veces el mismo archivo.
    $s = trim(preg_replace('/\s*\(\d+\)\s*$/', '', $s) ?? $s);
    if ($s === '') {
        return 'Material';
    }
    // TODO EN MAYÚSCULAS se lee mal en una lista larga.
    if (mb_strtoupper($s, 'UTF-8') === $s) {
        $s = mb_strtolower($s, 'UTF-8');
    }
    return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}

/** La primera regla que coincide manda; si ninguna, queda «solo en sesión». */
function clasificar(string $relativa, string $archivo, array $reglas): array
{
    $texto = mb_strtolower($relativa, 'UTF-8');
    $ext   = strtolower((string) pathinfo($archivo, PATHINFO_EXTENSION));

    foreach ($reglas as $r) {
        $coincide = false;
        foreach ($r['busca'] ?? [] as $aguja) {
            if (str_contains($texto, mb_strtolower($aguja, 'UTF-8'))) {
                $coincide = true;
                break;
            }
        }
        if (!$coincide && in_array($ext, $r['extension'] ?? [], true)) {
            $coincide = true;
        }
        if ($coincide) {
            return [
                'licencia' => $r['licencia'],
                'destino'  => $r['destino'],
                'origen'   => $r['origen'] ?? '',
                'nota'     => $r['nota'] ?? '',
            ];
        }
    }
    return [
        'licencia' => 'solo_sesion',
        'destino'  => 'profesional',
        'origen'   => '',
        'nota'     => 'Sin clasificar: revisa de dónde salió antes de entregarlo.',
    ];
}
