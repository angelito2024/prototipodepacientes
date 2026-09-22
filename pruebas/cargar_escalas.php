<?php
declare(strict_types=1);

/**
 * Carga las escalas de tamizaje de uso libre.
 *
 *   php pruebas/cargar_escalas.php            (todas)
 *   php pruebas/cargar_escalas.php phq9 gad7  (solo esas)
 *
 * Se pueden volver a cargar en cualquier momento: si el profesional
 * corrige un enunciado en pruebas/escalas_libres.php, este comando lo
 * actualiza sin tocar los protocolos ya respondidos. Después de cambiar
 * enunciados o cortes conviene correr `pruebas/recorregir.php <codigo>`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script se ejecuta desde la línea de comandos.\n");
}

require __DIR__ . '/../src/autoload.php';

use Centro\Pruebas\Corrector;
use Centro\Repos\Pruebas;

$escalas = require __DIR__ . '/escalas_libres.php';
$pedidas = array_slice($argv, 1);
if ($pedidas !== []) {
    $escalas = array_intersect_key($escalas, array_flip($pedidas));
    if ($escalas === []) {
        exit("No conozco esas escalas. Disponibles: "
           . implode(', ', array_keys(require __DIR__ . '/escalas_libres.php')) . "\n");
    }
}

$cargadas = 0;
foreach ($escalas as $clave => $e) {
    // Los ítems vienen como [n, texto] para que el archivo se lea; aquí
    // toman la forma que espera el corrector.
    $items = array_map(
        static fn(array $i): array => ['n' => $i[0], 'texto' => $i[1]],
        $e['items']
    );
    if (count($items) !== (int) $e['nItems']) {
        fwrite(STDERR, "  {$clave}: dice tener {$e['nItems']} ítems y tiene "
             . count($items) . ". No se carga.\n");
        continue;
    }

    $def = $e;
    $def['items'] = $items;
    unset($def['ficha']);

    /* Se corrigen dos protocolos extremos antes de guardar: si la
       definición está mal armada, se ve acá y no cuando un paciente ya
       respondió.

       Para llegar al extremo hay que responder al revés en los ítems
       inversos. Contestar todo igual en una escala con la mitad de ítems
       invertidos da el punto medio, no el máximo: con eso no se comprueba
       nada. */
    $min = (int) $e['valorMinimo'];
    $max = $min + count($e['opciones']) - 1;
    $inversos = array_flip($e['inversos'] ?? []);
    $protocolo = static function (bool $alto) use ($items, $inversos, $min, $max): array {
        $r = [];
        foreach ($items as $i) {
            $directo = $alto ? $max : $min;
            $r[$i['n']] = (string) (isset($inversos[$i['n']])
                ? ($min + $max - $directo) : $directo);
        }
        return $r;
    };

    $rAlto = Corrector::corregir($def, $protocolo(true));
    $rBajo = Corrector::corregir($def, $protocolo(false));
    $errores = [];
    foreach ([['alto', $rAlto], ['bajo', $rBajo]] as [$cual, $r]) {
        if (count($r['escalas']) !== 1) {
            $errores[] = "el puntaje $cual no salió";
        } elseif ($r['escalas'][0]['interpretacion'] === null) {
            $errores[] = "el puntaje $cual ({$r['escalas'][0]['pd']}) cae fuera de los cortes";
        }
    }
    $teorico = count($items) * $max;
    if ($errores === [] && (int) $rAlto['escalas'][0]['pd'] !== $teorico) {
        $errores[] = "el máximo dio {$rAlto['escalas'][0]['pd']} y debería ser $teorico";
    }
    if ($errores !== []) {
        fwrite(STDERR, "  {$clave}: " . implode('; ', $errores) . ". No se carga.\n");
        continue;
    }

    Pruebas::instalar($def, $e['ficha'] ?? []);
    $cargadas++;
    printf("  %-11s %-44s %2d ítems · rango %d-%d\n",
        $e['siglas'], $e['nombre'], count($items),
        $rBajo['escalas'][0]['pd'], $rAlto['escalas'][0]['pd']);
    printf("              mínimo: %s\n", $rBajo['escalas'][0]['interpretacion']);
    printf("              máximo: %s\n", $rAlto['escalas'][0]['interpretacion']);
    if (($e['itemsCriticos'] ?? []) !== []) {
        foreach ($e['itemsCriticos'] as $c) {
            echo "              ítem {$c['item']} se revisa aparte del total\n";
        }
    }
}

echo "\n{$cargadas} escala(s) cargada(s).\n";
if ($cargadas > 0) {
    echo "\nQuedan marcadas para revisión: el panel avisa hasta que un\n"
       . "profesional confirme los enunciados contra el protocolo oficial.\n"
       . "Para PHQ-9 y GAD-7 la fuente es phqscreeners.com.\n";
}
