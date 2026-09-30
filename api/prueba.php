<?php
declare(strict_types=1);

/**
 * Puerta de entrada del paciente para responder una prueba.
 *
 *   GET  ?accion=abrir&t=<clave>     -> datos de la prueba y lo ya respondido
 *   POST ?accion=avance&t=<clave>    <- {respuestas:{1:"V",...}}  guarda y sigue
 *   POST ?accion=terminar&t=<clave>  <- {respuestas:{...}, segundos:N}
 *
 * Esta es la única parte del sistema a la que se entra sin sesión: el
 * paciente no tiene cuenta. La clave del enlace hace de llave, y por eso:
 *
 *  · no se devuelve jamás la clave de corrección ni ningún puntaje;
 *  · terminar corrige en el servidor, nunca en el navegador;
 *  · si falta una sola respuesta, no se cierra el protocolo;
 *  · el enlace vence y deja de servir cuando la prueba está terminada.
 */

require __DIR__ . '/../src/autoload.php';

use Centro\Http;
use Centro\Repos\PruebaAplicaciones;
use Centro\Repos\Pruebas;

$accion = (string) ($_GET['accion'] ?? 'abrir');
$token  = (string) ($_GET['t'] ?? '');

try {
    $a = PruebaAplicaciones::porToken($token);
    if ($a === null) {
        // El mismo mensaje para clave mal formada y para clave inexistente:
        // así no se puede ir probando a ver cuál existe.
        Http::error('Este enlace no es válido. Pídele al centro uno nuevo.', 404);
    }

    $motivo = PruebaAplicaciones::motivoParaNoResponder($a);
    $id = (int) $a['id'];

    switch ($accion) {
        case 'abrir':
            PruebaAplicaciones::anotarAcceso($id, 'abrir');
            if ($motivo !== null) {
                Http::json(['ok' => false, 'cerrada' => true, 'error' => $motivo], 200);
            }
            $def = Pruebas::definicion((string) $a['prueba_codigo']);
            if ($def === null) {
                Http::error('La prueba no está disponible. Avísale al centro.', 500);
            }
            $resp = $a['respuestas'] === null ? [] : json_decode((string) $a['respuestas'], true);
            // El área de cada ítem solo se manda en las pautas de
            // observación, que las marca el profesional. En una prueba que
            // responde el paciente, saber a qué escala apunta cada frase es
            // parte de la clave y no sale de acá.
            $esCotejo = ($def['tipo'] ?? '') === 'cotejo';
            $notas = $a['notas'] === null ? [] : json_decode((string) $a['notas'], true);
            Http::ok([
                'paciente'   => $a['nombre_completo'],
                'prueba'     => [
                    'nombre'   => $a['prueba_nombre'],
                    'siglas'   => $a['siglas'],
                    'nItems'   => (int) $a['n_items'],
                    'minutos'  => $a['minutos_aprox'] === null ? null : (int) $a['minutos_aprox'],
                    'tipo'     => $def['tipo'] ?? 'vf',
                    'areas'    => $esCotejo ? ($def['areas'] ?? []) : null,
                    'opciones' => $def['opciones'],
                    // Solo el enunciado. La clave se queda en el servidor.
                    'items'    => array_map(
                        static fn(array $i): array => $esCotejo
                            ? ['n' => $i['n'], 'texto' => $i['texto'], 'area' => $i['area'] ?? '']
                            : ['n' => $i['n'], 'texto' => $i['texto']],
                        $def['items']
                    ),
                ],
                'respuestas' => is_array($resp) ? $resp : [],
                'notas'      => $esCotejo && is_array($notas) ? $notas : [],
            ]);

        case 'avance':
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            if ($motivo !== null) {
                Http::error($motivo, 409);
            }
            $c = Http::cuerpo();
            $n = PruebaAplicaciones::guardarAvance(
                $id,
                (array) ($c['respuestas'] ?? []),
                isset($c['notas']) ? (array) $c['notas'] : null
            );
            Http::ok(['respondidos' => $n]);

        case 'terminar':
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            if ($motivo !== null) {
                Http::error($motivo, 409);
            }
            $c = Http::cuerpo();
            try {
                PruebaAplicaciones::terminar(
                    $id,
                    (array) ($c['respuestas'] ?? []),
                    isset($c['segundos']) ? (int) $c['segundos'] : null,
                    isset($c['notas']) ? (array) $c['notas'] : null
                );
            } catch (RuntimeException $e) {
                // Falta algo: se le dice al paciente, sin cerrar nada.
                Http::error($e->getMessage(), 422);
            }
            // Al paciente no se le devuelve su perfil: el resultado de una
            // prueba se entrega en consulta, con el profesional delante.
            Http::ok(['mensaje' => 'Respuestas enviadas. Gracias.']);

        default:
            Http::error('Acción desconocida.', 404);
    }
} catch (Throwable $e) {
    error_log('[api/prueba] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::error('Hubo un problema al guardar. Vuelve a intentarlo en un momento.', 500);
}
