<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Pruebas\Corrector;
use Centro\Repositorio;
use RuntimeException;

/**
 * Colección 'pruebaAplicaciones': cada vez que se le toma una prueba a un
 * paciente.
 *
 * El paciente responde desde un enlace con una clave larga, sin usuario ni
 * contraseña —no tiene cuenta en el sistema ni debería tenerla—. Por eso:
 *
 *  · la clave se guarda con hash, igual que una contraseña: si alguien se
 *    lleva la base, no puede abrir los enlaces pendientes;
 *  · el enlace vence;
 *  · una vez terminada la prueba el enlace deja de servir, para que nadie
 *    vuelva sobre un protocolo ya corregido;
 *  · queda anotado desde qué equipo y a qué hora se abrió.
 *
 * La corrección la hace el servidor. El navegador del paciente nunca recibe
 * la clave del test ni los puntajes.
 */
final class PruebaAplicaciones extends Repositorio
{
    /** Días que dura el enlace si no se dice otra cosa. */
    private const DIAS_VIGENCIA = 7;
    /**
     * Límites del plazo. Menos de un día daría un enlace que nace vencido;
     * más de un año es demasiado para una dirección que abre sin pedir
     * contraseña. El panel ya los aplica, pero la comprobación que vale es
     * esta: lo que llega del navegador no se cree.
     */
    private const DIAS_MINIMO = 1;
    private const DIAS_MAXIMO = 365;

    public function clave(): string
    {
        return 'pruebaAplicaciones';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            "SELECT a.uid, a.estado, a.asignada_en, a.abierta_en, a.terminada_en, a.expira_en,
                    a.n_respondidos, a.resultado, a.observaciones, a.segundos_total, a.notas,
                    p.codigo AS prueba, p.siglas, p.nombre AS prueba_nombre, p.n_items,
                    p.aplicador, p.familia, p.edad_meses_min, p.edad_meses_max,
                    pac.uid AS pacienteId, pac.nombre_completo AS pacienteNombre,
                    pro.uid AS profesionalId
               FROM prueba_aplicacion a
               JOIN pruebas p     ON p.id = a.prueba_id
               JOIN personas pac  ON pac.id = a.paciente_id
          LEFT JOIN personas pro  ON pro.id = a.profesional_id
              ORDER BY a.asignada_en DESC"
        );
        return array_map(static function (array $f): array {
            return [
                'id'            => $f['uid'],
                'prueba'        => $f['prueba'],
                'siglas'        => $f['siglas'],
                'pruebaNombre'  => $f['prueba_nombre'],
                'nItems'        => (int) $f['n_items'],
                'patientId'     => $f['pacienteId'],
                'pacienteNombre'=> $f['pacienteNombre'],
                'professionalId'=> $f['profesionalId'],
                'estado'        => $f['estado'],
                'asignadaEn'    => $f['asignada_en'],
                'abiertaEn'     => $f['abierta_en'],
                'terminadaEn'   => $f['terminada_en'],
                'expiraEn'      => $f['expira_en'],
                'respondidos'   => (int) $f['n_respondidos'],
                'minutos'       => $f['segundos_total'] === null ? null : (int) round(((int) $f['segundos_total']) / 60),
                'resultado'     => $f['resultado'] === null ? null : json_decode((string) $f['resultado'], true),
                'observaciones' => $f['observaciones'],
                'notas'         => $f['notas'] === null ? [] : json_decode((string) $f['notas'], true),
                'aplicador'     => (string) ($f['aplicador'] ?? 'paciente'),
                'familia'       => $f['familia'],
                'mesesMin'      => $f['edad_meses_min'] === null ? null : (int) $f['edad_meses_min'],
                'mesesMax'      => $f['edad_meses_max'] === null ? null : (int) $f['edad_meses_max'],
            ];
        }, $filas);
    }

    /**
     * Desde el panel solo se cambian las observaciones o se anula una
     * aplicación. Las respuestas y el resultado no se tocan a mano: si un
     * protocolo se pudiera editar desde la pantalla, el informe dejaría de
     * ser lo que el paciente contestó.
     */
    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        foreach ($valor as $a) {
            if (!is_array($a) || empty($a['id'])) {
                continue;
            }
            $estado = in_array($a['estado'] ?? '', ['anulada'], true) ? 'anulada' : null;
            if ($estado !== null) {
                Database::query(
                    "UPDATE prueba_aplicacion SET estado = 'anulada', observaciones = ?
                      WHERE uid = ? AND estado <> 'terminada'",
                    [$a['observaciones'] ?? null, (string) $a['id']]
                );
            } else {
                Database::query(
                    'UPDATE prueba_aplicacion SET observaciones = ? WHERE uid = ?',
                    [$a['observaciones'] ?? null, (string) $a['id']]
                );
            }
        }
        $this->subirVersion();
    }

    // ------------------------------------------------------------------
    //  Asignar
    // ------------------------------------------------------------------

    /**
     * Le asigna una prueba a un paciente y devuelve la clave del enlace.
     * La clave se devuelve UNA sola vez, aquí: después queda solo su hash,
     * así que si se pierde hay que generar el enlace de nuevo.
     */
    public static function asignar(
        string $codigoPrueba,
        string $pacienteUid,
        ?string $profesionalUid = null,
        ?int $diasVigencia = null
    ): array {
        $pruebaId = Pruebas::id($codigoPrueba);
        if ($pruebaId === null) {
            throw new RuntimeException("No tengo cargada la prueba «$codigoPrueba».");
        }
        $pacienteId = Database::valor(
            'SELECT p.id FROM personas p JOIN pacientes pa ON pa.persona_id = p.id WHERE p.uid = ?',
            [$pacienteUid]
        );
        if ($pacienteId === null) {
            throw new RuntimeException('Ese paciente no está en la base.');
        }
        $profesionalId = null;
        if ($profesionalUid !== null && $profesionalUid !== '') {
            $profesionalId = Database::valor(
                'SELECT p.id FROM personas p JOIN profesionales pr ON pr.persona_id = p.id WHERE p.uid = ?',
                [$profesionalUid]
            );
        }

        // 32 bytes de azar: no se adivina ni probando.
        $token = bin2hex(random_bytes(32));
        $uid   = 'pa_' . bin2hex(random_bytes(8));
        $dias  = max(self::DIAS_MINIMO, min(self::DIAS_MAXIMO, $diasVigencia ?? self::DIAS_VIGENCIA));

        Database::query(
            'INSERT INTO prueba_aplicacion
                (uid, prueba_id, paciente_id, profesional_id, token_hash, expira_en, creada_por)
             VALUES (?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ? DAY),?)',
            [$uid, $pruebaId, (int) $pacienteId, $profesionalId === null ? null : (int) $profesionalId,
             hash('sha256', $token), $dias, Auth::usuarioId()]
        );
        Auth::auditar('ASIGNAR_PRUEBA', Auth::usuarioId(),
            ['prueba' => $codigoPrueba, 'paciente' => $pacienteUid], 'prueba_aplicacion');

        return ['id' => $uid, 'token' => $token, 'dias' => $dias];
    }

    /**
     * Vuelve a generar la clave de un enlace que se perdió o venció.
     *
     * Con el plazo se renueva también la fecha: si no, regenerar el enlace
     * de una prueba vencida devolvía otro igual de vencido, que tampoco
     * abría. Lo ya respondido no se toca.
     *
     * @return array{token:string, dias:int}
     */
    public static function regenerarToken(string $uid, ?int $diasVigencia = null): array
    {
        $fila = Database::uno(
            'SELECT id, estado FROM prueba_aplicacion WHERE uid = ?', [$uid]
        );
        if ($fila === null) {
            throw new RuntimeException('No encuentro esa aplicación.');
        }
        if ($fila['estado'] === 'terminada') {
            throw new RuntimeException('Esa prueba ya está terminada: no se puede volver a abrir.');
        }
        if ($fila['estado'] === 'anulada') {
            throw new RuntimeException('Esa prueba está anulada. Asígnala de nuevo si hace falta.');
        }
        $dias  = max(self::DIAS_MINIMO, min(self::DIAS_MAXIMO, $diasVigencia ?? self::DIAS_VIGENCIA));
        $token = bin2hex(random_bytes(32));
        Database::query(
            'UPDATE prueba_aplicacion
                SET token_hash = ?, expira_en = DATE_ADD(NOW(), INTERVAL ? DAY)
              WHERE id = ?',
            [hash('sha256', $token), $dias, (int) $fila['id']]
        );
        Auth::auditar('REGENERAR_ENLACE_PRUEBA', Auth::usuarioId(),
            ['uid' => $uid, 'dias' => $dias], 'prueba_aplicacion');
        return ['token' => $token, 'dias' => $dias];
    }

    // ------------------------------------------------------------------
    //  Lo que usa el paciente
    // ------------------------------------------------------------------

    /** Busca la aplicación por la clave del enlace. */
    public static function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Database::uno(
            'SELECT a.*, p.codigo AS prueba_codigo, p.nombre AS prueba_nombre,
                    p.siglas, p.n_items, p.minutos_aprox,
                    per.nombre_completo
               FROM prueba_aplicacion a
               JOIN pruebas p    ON p.id = a.prueba_id
               JOIN personas per ON per.id = a.paciente_id
              WHERE a.token_hash = ?',
            [hash('sha256', $token)]
        );
    }

    /** ¿Se puede responder ahora mismo? Devuelve null si sí, o el motivo. */
    public static function motivoParaNoResponder(array $a): ?string
    {
        return match (true) {
            $a['estado'] === 'terminada' =>
                'Esta prueba ya fue enviada y está corregida. Si necesitas cambiar algo, '
                . 'comunícate con el centro.',
            $a['estado'] === 'anulada' =>
                'Este enlace fue anulado por el centro. Pide uno nuevo.',
            $a['expira_en'] !== null && strtotime((string) $a['expira_en']) < time() =>
                'Este enlace venció. Escríbele al centro para que te envíe uno nuevo.',
            default => null,
        };
    }

    public static function anotarAcceso(int $aplicacionId, string $accion): void
    {
        Database::query(
            'INSERT INTO prueba_acceso (aplicacion_id, accion, ip, agente) VALUES (?,?,?,?)',
            [$aplicacionId, $accion, $_SERVER['REMOTE_ADDR'] ?? null,
             mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null]
        );
    }

    /** Guarda el avance. No corrige ni cierra nada. */
    public static function guardarAvance(int $aplicacionId, array $respuestas, ?array $notas = null): int
    {
        $def     = self::definicionDe($aplicacionId);
        $limpias = self::limpiar($respuestas, $def);
        Database::query(
            "UPDATE prueba_aplicacion
                SET respuestas = ?, n_respondidos = ?,
                    estado = IF(estado = 'pendiente', 'en_curso', estado),
                    abierta_en = COALESCE(abierta_en, NOW())
              WHERE id = ? AND estado IN ('pendiente','en_curso')",
            [json_encode($limpias, JSON_UNESCAPED_UNICODE), count($limpias), $aplicacionId]
        );
        if ($notas !== null) {
            self::guardarNotas($aplicacionId, $notas, $def);
        }
        return count($limpias);
    }

    /**
     * Lo que el profesional anota ítem por ítem mientras observa.
     *
     * En la hoja de papel había un solo renglón de OBSERVACIONES al final,
     * y ahí no entra «lo logró, pero solo con apoyo y a la tercera vez»,
     * que es justo lo que sirve en la sesión siguiente.
     */
    public static function guardarNotas(int $aplicacionId, array $notas, ?array $definicion = null): void
    {
        $nItems = (int) (($definicion ?? self::definicionDe($aplicacionId))['nItems'] ?? 0);
        $out = [];
        foreach ($notas as $item => $texto) {
            $n = (int) $item;
            if ($n < 1 || ($nItems > 0 && $n > $nItems) || !is_scalar($texto)) {
                continue;
            }
            $t = trim((string) $texto);
            if ($t !== '') {
                $out[$n] = mb_substr($t, 0, 500);
            }
        }
        ksort($out);
        Database::query(
            'UPDATE prueba_aplicacion SET notas = ? WHERE id = ?',
            [$out === [] ? null : json_encode($out, JSON_UNESCAPED_UNICODE), $aplicacionId]
        );
    }

    /** @return array<int,string> */
    public static function notasDe(string $uid): array
    {
        $json = Database::valor('SELECT notas FROM prueba_aplicacion WHERE uid = ?', [$uid]);
        $n = $json === null ? [] : json_decode((string) $json, true);
        return is_array($n) ? $n : [];
    }

    /**
     * Cierra el protocolo y lo corrige.
     *
     * Este es el candado que el Excel no tenía: si falta aunque sea una
     * respuesta, no se cierra. Un MCMI-IV con 23 ítems en blanco igual
     * arroja perfil, y ese perfil no se puede usar.
     */
    public static function terminar(
        int $aplicacionId, array $respuestas, ?int $segundos = null, ?array $notas = null
    ): array {
        $a = Database::uno(
            'SELECT a.*, p.codigo AS prueba_codigo FROM prueba_aplicacion a
               JOIN pruebas p ON p.id = a.prueba_id WHERE a.id = ?',
            [$aplicacionId]
        );
        if ($a === null) {
            throw new RuntimeException('No encuentro esa aplicación.');
        }
        $def = Pruebas::definicion((string) $a['prueba_codigo']);
        if ($def === null) {
            throw new RuntimeException('No tengo la definición de esa prueba.');
        }

        $limpias = self::limpiar($respuestas, $def);
        $faltan  = [];
        for ($i = 1; $i <= (int) $def['nItems']; $i++) {
            if (!isset($limpias[$i])) {
                $faltan[] = $i;
            }
        }
        if ($faltan !== []) {
            // En una pauta de observación «no evaluado» también es una
            // respuesta, así que el mensaje tiene que decir otra cosa: no
            // falta contestar, falta decidir qué se pudo ver y qué no.
            throw new RuntimeException(
                ($def['tipo'] ?? '') === 'cotejo'
                    ? 'Quedan ' . count($faltan) . ' ítem(s) sin marcar. Si no se pudieron '
                      . 'observar, márcalos como «no evaluado»: dejarlos en blanco y dejarlos '
                      . 'en «aún no» no significan lo mismo.'
                    : 'Faltan ' . count($faltan) . ' respuesta(s). Una prueba incompleta no se puede '
                      . 'corregir: los puntajes saldrían más bajos de lo real.',
            );
        }

        if ($notas !== null) {
            self::guardarNotas($aplicacionId, $notas, $def);
        }
        $resultado = Corrector::corregir($def, $limpias);
        Database::query(
            "UPDATE prueba_aplicacion
                SET respuestas = ?, n_respondidos = ?, resultado = ?, estado = 'terminada',
                    terminada_en = NOW(), segundos_total = ?
              WHERE id = ?",
            [json_encode($limpias, JSON_UNESCAPED_UNICODE), count($limpias),
             json_encode($resultado, JSON_UNESCAPED_UNICODE), $segundos, $aplicacionId]
        );
        self::anotarAcceso($aplicacionId, 'terminar');
        return $resultado;
    }

    /**
     * Deja solo lo que la prueba acepta: ítems que existen y respuestas que
     * están entre sus opciones. Cada prueba tiene las suyas —el Millon es
     * Verdadero/Falso, el BarOn va del 1 al 5— así que se comprueban contra
     * la definición, no contra una lista fija.
     *
     * @return array<int,string>
     */
    private static function limpiar(array $respuestas, ?array $definicion = null): array
    {
        $validas = null;
        $nItems  = 999;
        if ($definicion !== null) {
            $validas = array_map(
                static fn(array $o): string => (string) $o['valor'],
                $definicion['opciones'] ?? []
            );
            $nItems = (int) ($definicion['nItems'] ?? 999);
        }

        $out = [];
        foreach ($respuestas as $item => $r) {
            $n = (int) $item;
            if ($n < 1 || $n > $nItems) {
                continue;
            }
            $v = is_scalar($r) ? trim((string) $r) : '';
            if ($v === '') {
                continue;
            }
            if ($validas === null || $validas === []) {
                // Sin definición a mano se mantiene el criterio antiguo.
                $v = strtoupper($v);
                if ($v === 'V' || $v === 'F') {
                    $out[$n] = $v;
                }
                continue;
            }
            // "v" y "V" son la misma respuesta; "3" y "3 " también.
            foreach ($validas as $ok) {
                if (strcasecmp($v, $ok) === 0) {
                    $out[$n] = $ok;
                    break;
                }
            }
        }
        ksort($out);
        return $out;
    }

    /** La definición de la prueba de una aplicación, o null si no está. */
    private static function definicionDe(int $aplicacionId): ?array
    {
        $codigo = Database::valor(
            'SELECT p.codigo FROM prueba_aplicacion a JOIN pruebas p ON p.id = a.prueba_id
              WHERE a.id = ?',
            [$aplicacionId]
        );
        return $codigo === null ? null : Pruebas::definicion((string) $codigo);
    }

    /** Las respuestas crudas, para el informe del profesional. */
    public static function respuestasDe(string $uid): array
    {
        $json = Database::valor('SELECT respuestas FROM prueba_aplicacion WHERE uid = ?', [$uid]);
        $r = $json === null ? [] : json_decode((string) $json, true);
        return is_array($r) ? $r : [];
    }
}
