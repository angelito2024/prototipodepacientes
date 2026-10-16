<?php
declare(strict_types=1);

/**
 * Puerta de entrada de la familia al material que le dejó el profesional.
 *
 *   GET  ?accion=abrir&t=<clave>          -> la portada: para quién es la tarea
 *   POST ?accion=entrar&t=<clave>         <- {documento} o {confirmo:true}
 *                                         -> recién acá sale qué se le dejó
 *   GET  ?accion=bajar&t=<clave>&m=<id>   -> el archivo
 *
 * Igual que en las pruebas, es una de las pocas partes a las que se entra
 * sin sesión: los padres de un paciente no tienen cuenta en el sistema ni
 * deberían tenerla. La clave del enlace hace de llave, y por eso:
 *
 *  · solo se entrega el material que va en ESA entrega, nunca la
 *    biblioteca entera;
 *  · el enlace vence, y una entrega anulada deja de abrir;
 *  · queda anotado cuándo se abrió y qué se bajó, que es justamente lo
 *    que el profesional quiere saber de una tarea para la casa.
 */

require __DIR__ . '/../src/autoload.php';

use Centro\Archivos;
use Centro\Http;
use Centro\Marca;
use Centro\Repos\MaterialEntregas;

const INTENTOS_DE_DOCUMENTO = 5;

$accion = (string) ($_GET['accion'] ?? 'abrir');
$token  = (string) ($_GET['t'] ?? '');

try {
    $e = MaterialEntregas::porToken($token);
    if ($e === null) {
        // El mismo mensaje para clave mal formada y para clave inexistente:
        // así no se puede ir probando a ver cuál existe.
        Http::error('Este enlace no es válido. Pídele al centro uno nuevo.', 404);
    }

    $id     = (int) $e['id'];
    $motivo = MaterialEntregas::motivoParaNoAbrir($e);

    // Se pide el documento solo si el centro lo tiene prendido Y el
    // paciente lo tiene cargado en su ficha: sin ficha completa, exigirlo
    // dejaría a la familia sin poder abrir la tarea de su hijo.
    $tieneDocumento = preg_replace('/\D+/', '', (string) ($e['documento'] ?? '')) !== '';
    $pideDocumento  = Marca::pideDocumento() && $tieneDocumento;

    switch ($accion) {
        case 'abrir':
            MaterialEntregas::anotarAcceso($id, 'abrir');
            MaterialEntregas::anotarApertura($id);
            $portada = Marca::portada((int) $e['n_aperturas'] + 1);
            if ($motivo !== null) {
                Http::json(['ok' => false, 'cerrada' => true, 'error' => $motivo,
                            'marca' => $portada], 200);
            }
            Http::ok([
                'marca' => $portada,
                // El nombre recortado: la familia reconoce a su hijo, y
                // quien recibió el enlace por error no se entera de a
                // quién se atiende en el centro.
                'paciente'      => Marca::nombreCorto($e),
                'profesional'   => $e['profesional'],
                'titulo'        => $e['titulo'],
                'expira'        => $e['expira_en'],
                'cuantos'       => count(MaterialEntregas::contenido($id)),
                'pideDocumento' => $pideDocumento,
            ]);

        case 'entrar':
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            if ($motivo !== null) {
                Http::error($motivo, 410);
            }
            $c = Http::cuerpo();

            if ($pideDocumento) {
                if (MaterialEntregas::intentosFallidos($id) >= INTENTOS_DE_DOCUMENTO) {
                    Http::error('Se intentó varias veces con un documento que no coincide. '
                        . 'Espera un cuarto de hora o escríbele al centro.', 429);
                }
                $esc = (string) ($c['documento'] ?? '');
                if ($esc === '') {
                    Http::error('Escribe el número de documento para continuar.', 422);
                }
                if (MaterialEntregas::documentoCoincide($e, $esc) !== true) {
                    MaterialEntregas::anotarAcceso($id, 'documento_mal');
                    $quedan = INTENTOS_DE_DOCUMENTO - MaterialEntregas::intentosFallidos($id);
                    Http::error('Ese número no coincide con el del paciente. '
                        . ($quedan > 0 ? "Te quedan $quedan intentos." : 'Escríbele al centro.'), 422);
                }
                MaterialEntregas::anotarAcceso($id, 'documento_ok');
            } elseif (($c['confirmo'] ?? false) !== true) {
                Http::error('Confirma para quién es esta tarea para continuar.', 422);
            } else {
                MaterialEntregas::anotarAcceso($id, 'confirmo_identidad');
            }

            Http::ok([
                // Acá sí va el nombre completo: ya se confirmó quién es.
                'paciente'    => $e['nombre_completo'],
                'profesional' => $e['profesional'],
                'titulo'      => $e['titulo'],
                'mensaje'     => $e['mensaje'],
                'expira'      => $e['expira_en'],
                'materiales'  => MaterialEntregas::contenido($id),
            ]);

        case 'bajar':
            if ($motivo !== null) {
                Http::error($motivo, 410);
            }
            $mUid = (string) ($_GET['m'] ?? '');
            $a = MaterialEntregas::archivoDe($id, $mUid);
            if ($a === null) {
                Http::error('Ese material no forma parte de lo que se te envió.', 404);
            }
            if ((int) $a['es_enlace'] === 1) {
                MaterialEntregas::anotarAcceso($id, 'descargar', (int) $a['material_id']);
                MaterialEntregas::anotarDescarga($id, (int) $a['material_id']);
                header('Location: ' . $a['url_externa'], true, 302);
                exit;
            }
            $ruta = Archivos::rutaAbsoluta($a);
            if (!is_file($ruta)) {
                Http::error('El archivo ya no está disponible. Avísale al centro.', 410);
            }
            MaterialEntregas::anotarAcceso($id, 'descargar', (int) $a['material_id']);
            MaterialEntregas::anotarDescarga($id, (int) $a['material_id']);
            entregarArchivo($ruta, (string) ($a['mime'] ?: 'application/octet-stream'),
                (string) $a['nombre_original']);

        default:
            Http::error('Acción desconocida: ' . $accion, 404);
    }
} catch (Throwable $t) {
    error_log('[material] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
    Http::error('No se pudo abrir el material.', 500);
}

/**
 * Entrega el archivo por partes.
 *
 * Los videos del pack pesan más de cien megas. Sin esto el celular tiene
 * que bajarlo entero antes de ver el primer segundo, y no puede adelantar:
 * el navegador pide un trozo (`Range`) y hay que saber dárselo.
 */
function entregarArchivo(string $ruta, string $mime, string $nombre): never
{
    $total = (int) filesize($ruta);
    $inicio = 0;
    $fin    = $total - 1;
    $parcial = false;

    $rango = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($rango !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rango, $m) === 1) {
        if ($m[1] === '' && $m[2] !== '') {
            // "bytes=-500": los últimos 500 bytes.
            $inicio = max(0, $total - (int) $m[2]);
            $fin    = $total - 1;
        } else {
            $inicio = (int) $m[1];
            $fin    = $m[2] === '' ? $total - 1 : (int) $m[2];
        }
        if ($inicio > $fin || $inicio >= $total) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header("Content-Range: bytes */$total");
            exit;
        }
        $fin = min($fin, $total - 1);
        $parcial = true;
    }

    $largo = $fin - $inicio + 1;

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $largo);
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $nombre) . '"');
    header('X-Content-Type-Options: nosniff');
    // Privado: es material que se le dejó a una familia concreta, no algo
    // que deba quedar guardado en un proxy del camino.
    header('Cache-Control: private, max-age=600');
    if ($parcial) {
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $inicio-$fin/$total");
    }

    $f = fopen($ruta, 'rb');
    if ($f === false) {
        exit;
    }
    fseek($f, $inicio);
    $porLeer = $largo;
    while ($porLeer > 0 && !feof($f)) {
        $trozo = fread($f, (int) min(262144, $porLeer));
        if ($trozo === false) {
            break;
        }
        echo $trozo;
        $porLeer -= strlen($trozo);
        flush();
    }
    fclose($f);
    exit;
}
