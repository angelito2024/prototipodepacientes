<?php
declare(strict_types=1);

/**
 * IMPORTADOR: respaldo JSON del panel  ->  base de datos.
 *
 * Entrada: el archivo que genera "Descargar copia de seguridad"
 * (exportBackupJSON) o, si el panel nunca se exportó, el volcado de
 * localStorage (ver --localstorage más abajo).
 *
 * Uso por consola:
 *   php migrar.php backup-centro-psicologico-2026-09-02.json
 *   php migrar.php backup.json --simular     (no escribe: solo informa)
 *   php migrar.php backup.json --vaciar      (borra los datos previos)
 *
 * También funciona por navegador:
 *   http://localhost/prototipodepacientes/migrar.php?archivo=backup.json
 *
 * Es reejecutable: la conciliación va por `uid`, así que volver a importar
 * el mismo respaldo actualiza en vez de duplicar.
 */

require __DIR__ . '/src/autoload.php';

use Centro\Colecciones;
use Centro\Database;

$esCli = PHP_SAPI === 'cli';
if (!$esCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function decir(string $m): void
{
    echo $m, PHP_EOL;
    if (PHP_SAPI !== 'cli') {
        flush();
    }
}

// ---------------------------------------------------------------------
// 1. Argumentos
// ---------------------------------------------------------------------
if ($esCli) {
    $archivo  = $argv[1] ?? null;
    $simular  = in_array('--simular', $argv, true);
    $vaciar   = in_array('--vaciar', $argv, true);
} else {
    $archivo  = isset($_GET['archivo']) ? basename((string) $_GET['archivo']) : null;
    $simular  = isset($_GET['simular']);
    $vaciar   = isset($_GET['vaciar']);
}

if ($archivo === null) {
    decir('Falta el archivo de respaldo.');
    decir('');
    decir('  php migrar.php backup-centro-psicologico-AAAA-MM-DD.json [--simular] [--vaciar]');
    decir('');
    decir('El respaldo se genera con el botón "Descargar copia de seguridad" del panel.');
    decir('Si el panel nunca se exportó, abre index.html en el navegador donde están');
    decir('los datos, abre la consola (F12) y ejecuta:');
    decir('');
    decir('  copy(JSON.stringify(Object.fromEntries(Object.entries(localStorage)');
    decir('    .filter(([k])=>k.startsWith("centroPsicologico_"))');
    decir('    .map(([k,v])=>[k.replace("centroPsicologico_",""), JSON.parse(v)]))))');
    decir('');
    decir('Pega el resultado en un archivo .json y pásalo a este script.');
    exit(1);
}

$ruta = is_file($archivo) ? $archivo : __DIR__ . '/' . $archivo;
if (!is_file($ruta)) {
    decir("No se encontró el archivo: {$ruta}");
    exit(1);
}

$json = json_decode((string) file_get_contents($ruta), true);
if (!is_array($json)) {
    decir('El archivo no contiene JSON válido.');
    exit(1);
}

decir('== IMPORTACIÓN ==');
decir('Archivo : ' . $ruta);
decir('Fecha   : ' . (string) ($json['_fecha'] ?? 'sin marca de tiempo'));
decir('Base    : ' . (string) Database::valor('SELECT DATABASE()'));
decir('Modo    : ' . ($simular ? 'SIMULACIÓN (no se escribe nada)' : 'ESCRITURA'));
decir('');

// ---------------------------------------------------------------------
// 2. Inventario de lo que trae el respaldo
// ---------------------------------------------------------------------
$historias = is_array($json['historias'] ?? null) ? $json['historias'] : [];
foreach (Colecciones::ORDEN as $clave) {
    if (!array_key_exists($clave, $json)) {
        continue;
    }
    $v = $json[$clave];
    decir(sprintf('  %-16s %s', $clave, is_array($v) && array_is_list($v) ? count($v) . ' registros' : 'objeto'));
}
decir(sprintf('  %-16s %d pacientes', 'historias', count($historias)));
decir('');

if ($simular) {
    decir('Simulación terminada. Vuelve a ejecutar sin --simular para escribir.');
    exit(0);
}

// ---------------------------------------------------------------------
// 3. Vaciado opcional
// ---------------------------------------------------------------------
if ($vaciar) {
    decir('Vaciando datos previos…');
    Database::query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'hc_adjuntos','hc_evoluciones','hc_diagnosticos','hc_pruebas','hc_episodios',
        'informes','historias_clinicas','asistencia_registros','cita_historial','citas',
        'pagos','usos_consultorio','liquidaciones_profesional','paciente_paquetes',
        'paciente_acompanantes','persona_horario_excepciones','persona_horarios',
        'practicante_actividades','pacientes','profesionales','practicantes',
        'taller_participantes','taller_sesiones','talleres',
        'personal_pagos','personal_movimientos','personas',
        'eventos_calendario','productos','archivos',
    ] as $t) {
        Database::query("DELETE FROM {$t}");
    }
    Database::query("DELETE FROM gastos WHERE uid IS NULL OR uid NOT LIKE 'seed%'");
    Database::query("DELETE FROM servicios WHERE uid IS NULL OR uid NOT LIKE 'seed%'");
    Database::query('SET FOREIGN_KEY_CHECKS = 1');
    decir('  hecho.');
    decir('');
}

// ---------------------------------------------------------------------
// 4. Importación, en orden de dependencias
// ---------------------------------------------------------------------
// Los datos históricos pueden traer citas cruzadas: el panel avisa del
// choque pero deja guardarlo igual. Se permiten aquí y luego se informa
// de los que quedaron, para revisarlos con calma.
Database::query('SET @centro_permitir_solape = 1');

// Las cuentas personales son privadas de una persona, así que necesitan un
// dueño. Por consola no hay sesión iniciada: si el centro tiene activado el
// acceso con clave, no hay a nombre de quién guardarlas y se avisa en vez
// de dejar que se pierdan sin decir nada.
$avisoPersonal = null;
if (array_key_exists('personalEntries', $json) && \Centro\Auth::duenioDeLoPersonal() === null) {
    $avisoPersonal = count((array) $json['personalEntries']);
}

$errores = 0;
foreach (Colecciones::ORDEN as $clave) {
    if (!array_key_exists($clave, $json)) {
        continue;
    }
    // authConfig no se importa: las contraseñas del prototipo están en texto
    // plano y no deben pasar tal cual. Se crean usuarios con hash aparte.
    if ($clave === 'authConfig') {
        decir('  authConfig       omitido a propósito (ver nota al final)');
        continue;
    }

    $repo = Colecciones::para($clave);
    if ($repo === null) {
        continue;
    }
    try {
        $t0 = microtime(true);
        Database::transaccion(static fn() => $repo->guardar($json[$clave]));
        decir(sprintf('  %-16s importado (%.2fs)', $clave, microtime(true) - $t0));
    } catch (Throwable $e) {
        $errores++;
        decir(sprintf('  %-16s ERROR: %s', $clave, $e->getMessage()));
    }
}

decir('');
decir('Historias clínicas:');
$okHist = 0;
foreach ($historias as $pacienteUid => $historia) {
    $repo = Colecciones::para('historia_' . $pacienteUid);
    if ($repo === null) {
        continue;
    }
    try {
        Database::transaccion(static fn() => $repo->guardar($historia));
        $okHist++;
    } catch (Throwable $e) {
        $errores++;
        decir(sprintf('  %-20s ERROR: %s', $pacienteUid, $e->getMessage()));
    }
}
decir(sprintf('  %d de %d importadas', $okHist, count($historias)));

Database::query('SET @centro_permitir_solape = 0');

// ---------------------------------------------------------------------
// 5. Resumen y avisos
// ---------------------------------------------------------------------
decir('');
decir('== RESULTADO ==');
foreach ([
    'personas','pacientes','profesionales','practicantes','citas','pagos',
    'usos_consultorio','gastos','historias_clinicas','hc_episodios',
    'hc_evoluciones','hc_diagnosticos','hc_adjuntos','archivos','asistencia_registros',
    'talleres','taller_sesiones','taller_participantes','personal_movimientos',
] as $t) {
    decir(sprintf('  %-22s %s', $t, Database::valor("SELECT COUNT(*) FROM {$t}")));
}

decir('');
decir('== REVISAR ==');

$sinFecha = Database::valor(
    'SELECT COUNT(*) FROM personas WHERE dia_cumple IS NOT NULL AND fecha_nacimiento IS NULL'
);
if ((int) $sinFecha > 0) {
    decir("  · {$sinFecha} personas tienen día y mes de cumpleaños pero no el año:");
    decir('    el panel descartaba el año al guardar. Las alertas de cumpleaños');
    decir('    funcionan igual; la edad no se puede calcular hasta completarlo.');
}

$solapes = Database::todos('SELECT * FROM v_citas_solapadas');
if ($solapes !== []) {
    decir('  · ' . count($solapes) . ' citas quedaron cruzadas (mismo consultorio o');
    decir('    profesional a la misma hora). Se importaron igual porque el panel');
    decir('    permitía forzarlas. Revísalas con:  SELECT * FROM v_citas_solapadas;');
    foreach (array_slice($solapes, 0, 5) as $s) {
        decir(sprintf('      %s vs %s (%s): %s / %s',
            $s['inicio_a'], $s['inicio_b'], $s['motivo'], $s['paciente_a'], $s['paciente_b']));
    }
}

$sinPrecio = Database::valor(
    'SELECT COUNT(*) FROM servicios WHERE activo = 1 AND precio IS NULL'
);
if ((int) $sinPrecio > 0) {
    decir("  · {$sinPrecio} servicios no tienen precio numérico (\"A definir\",");
    decir('    "Variable"…). El texto original se conservó en precio_texto, pero');
    decir('    no entran en los reportes de finanzas hasta ponerles un importe.');
}

$descuadre = Database::todos(
    'SELECT pq.id, per.nombre_completo, pq.sesiones_usadas,
            COUNT(c.id) AS sesiones_citas
       FROM paciente_paquetes pq
       JOIN personas per ON per.id = pq.paciente_id
       LEFT JOIN citas c ON c.paquete_id = pq.id AND c.estado = \'Completada\' AND c.eliminado_en IS NULL
      WHERE pq.estado = \'Activo\'
      GROUP BY pq.id, per.nombre_completo, pq.sesiones_usadas
     HAVING pq.sesiones_usadas <> COUNT(c.id)'
);
if ($descuadre !== []) {
    decir('  · ' . count($descuadre) . ' paquetes donde el contador de sesiones del panel no');
    decir('    coincide con las citas completadas registradas:');
    foreach (array_slice($descuadre, 0, 5) as $d) {
        decir(sprintf('      %s: contador=%s citas=%s',
            $d['nombre_completo'], $d['sesiones_usadas'], $d['sesiones_citas']));
    }
}

if ($avisoPersonal !== null) {
    decir("  · Las {$avisoPersonal} cuentas personales NO se importaron: son privadas de");
    decir('    una persona y el centro tiene activado el acceso con clave, así que por');
    decir('    consola no hay a nombre de quién guardarlas. Impórtalas entrando al panel');
    decir('    con tu usuario y usando "Restaurar copia de seguridad".');
}

$sinAmbito = Database::valor(
    "SELECT COUNT(*) FROM servicios WHERE activo = 1 AND ambito = 'paciente'
      AND nombre REGEXP 'harla|aller|olegio|mpresa|lquiler|omisi'"
);
if ((int) $sinAmbito > 0) {
    decir("  · {$sinAmbito} tarifas quedaron como \"de paciente\" pero su nombre sugiere otra");
    decir('    cosa (taller, colegio, alquiler…). El ámbito se dedujo del nombre;');
    decir('    revísalo en Tarifas para que no aparezcan en la ficha del paciente.');
}

$pinPlano = Database::valor('SELECT COUNT(*) FROM personal_config WHERE pin_hash IS NOT NULL');
if ((int) $pinPlano > 0) {
    decir('  · La clave de cuentas personales se importó con hash. A partir de ahora no');
    decir('    viaja al navegador ni sale en la copia de seguridad, como sí ocurría antes.');
}

decir('  · Las contraseñas del panel no se importaron: estaban en texto plano.');
decir('    Entra con el usuario "admin" (clave temporal Magusa2026*) y crea las');
decir('    cuentas reales desde "Datos del centro".');

decir('');
decir($errores === 0 ? 'Importación completada sin errores.' : "Importación terminada con {$errores} errores.");
exit($errores === 0 ? 0 : 1);
