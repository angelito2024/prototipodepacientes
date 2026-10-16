<?php
declare(strict_types=1);

/**
 * Puerta de entrada del paciente para responder una prueba.
 *
 *   GET  ?accion=abrir&t=<clave>     -> la portada: de quién es el enlace
 *   POST ?accion=entrar&t=<clave>    <- {documento} o {confirmo:true}
 *                                    -> recién acá salen las preguntas
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
 *
 * Por qué dos pasos, y no uno como antes. El enlace llega por WhatsApp, y
 * un WhatsApp se reenvía: al grupo de la familia, al teléfono equivocado,
 * al hermano. Si al abrirlo salen las preguntas de una, el que lo abrió
 * contesta, y esas respuestas entran a la historia clínica de otra
 * persona. Un protocolo respondido por alguien más es idéntico a uno
 * legítimo —mismos ítems, mismo tiempo, mismo perfil— y es el único error
 * de este sistema que no deja rastro. El paso de en medio es para eso.
 *
 * Lo que el paso de en medio NO es: no es seguridad. El secreto es la
 * clave del enlace, que son 64 caracteres al azar. El documento del
 * paciente está en su carné, en su ficha y en la lista de asistencia; no
 * guarda nada de nadie que ya tenga el enlace. Sirve contra el error, no
 * contra el malintencionado, y conviene no confundirse.
 */

require __DIR__ . '/../src/autoload.php';

use Centro\Http;
use Centro\Marca;
use Centro\Repos\PruebaAplicaciones;
use Centro\Repos\Pruebas;

const INTENTOS_DE_DOCUMENTO = 5;

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

    // Se pide el documento solo si el centro lo tiene prendido Y el
    // paciente lo tiene cargado en su ficha. Sin ficha completa no se le
    // puede exigir: quedaría encerrado afuera de su propia prueba.
    $tieneDocumento = preg_replace('/\D+/', '', (string) ($a['documento'] ?? '')) !== '';
    $pideDocumento  = Marca::pideDocumento() && $tieneDocumento;

    switch ($accion) {
        case 'abrir':
            PruebaAplicaciones::anotarAcceso($id, 'abrir');
            $portada = Marca::portada(PruebaAplicaciones::aperturas($id));
            if ($motivo !== null) {
                Http::json(['ok' => false, 'cerrada' => true, 'error' => $motivo,
                            'marca' => $portada], 200);
            }
            Http::ok([
                'marca' => $portada,
                // El nombre recortado: la familia reconoce a su hijo, y
                // quien recibió el enlace por error no se entera de a
                // quién se atiende acá.
                'paciente'      => Marca::nombreCorto($a),
                'prueba'        => [
                    'nombre'  => $a['prueba_nombre'],
                    'siglas'  => $a['siglas'],
                    'nItems'  => (int) $a['n_items'],
                    'minutos' => $a['minutos_aprox'] === null ? null : (int) $a['minutos_aprox'],
                ],
                'pideDocumento' => $pideDocumento,
                'empezada'      => $a['estado'] === 'en_curso',
            ]);

        case 'entrar':
            if (Http::metodo() !== 'POST') {
                Http::error('Método no permitido.', 405);
            }
            if ($motivo !== null) {
                Http::error($motivo, 409);
            }
            $c = Http::cuerpo();

            if ($pideDocumento) {
                if (PruebaAplicaciones::intentosFallidos($id) >= INTENTOS_DE_DOCUMENTO) {
                    Http::error('Se intentó varias veces con un documento que no coincide. '
                        . 'Espera un cuarto de hora o escríbele al centro.', 429);
                }
                $esc = (string) ($c['documento'] ?? '');
                if ($esc === '') {
                    Http::error('Escribe el número de documento para continuar.', 422);
                }
                if (PruebaAplicaciones::documentoCoincide($a, $esc) !== true) {
                    PruebaAplicaciones::anotarAcceso($id, 'documento_mal');
                    $quedan = INTENTOS_DE_DOCUMENTO - PruebaAplicaciones::intentosFallidos($id);
                    Http::error('Ese número no coincide con el del paciente. '
                        . ($quedan > 0
                            ? "Te quedan $quedan intentos."
                            : 'Escríbele al centro.'), 422);
                }
                PruebaAplicaciones::anotarAcceso($id, 'documento_ok');
            } elseif (($c['confirmo'] ?? false) !== true) {
                // Sin documento cargado queda la confirmación a mano. No
                // es un candado —cualquiera puede decir que sí— pero
                // obliga a leer de quién es el enlace antes de contestar,
                // que es donde se corta el error.
                Http::error('Confirma de quién es este cuestionario para continuar.', 422);
            } else {
                PruebaAplicaciones::anotarAcceso($id, 'confirmo_identidad');
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
                // Acá sí va el nombre completo: ya se confirmó quién es.
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
            // La frase de despedida es otra que la de la portada: acá sí
            // cabe algo de aliento, porque ya terminó de contestar y no
            // hay respuesta que se pueda inclinar.
            Http::ok([
                'mensaje' => 'Respuestas enviadas. Gracias.',
                'frase'   => Marca::frase(PruebaAplicaciones::aperturas($id) + 1),
            ]);

        default:
            Http::error('Acción desconocida.', 404);
    }
} catch (Throwable $e) {
    error_log('[api/prueba] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::error('Hubo un problema al guardar. Vuelve a intentarlo en un momento.', 500);
}
