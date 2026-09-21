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
    private const DIAS_VIGENCIA = 30;

    public function clave(): string
    {
        return 'pruebaAplicaciones';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            "SELECT a.uid, a.estado, a.asignada_en, a.abierta_en, a.terminada_en, a.expira_en,
                    a.n_respondidos, a.resultado, a.observaciones, a.segundos_total,
                    p.codigo AS prueba, p.siglas, p.nombre AS prueba_nombre, p.n_items,
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
        $dias  = $diasVigencia ?? self::DIAS_VIGENCIA;

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

    /** Vuelve a generar la clave de un enlace que se perdió. */
    public static function regenerarToken(string $uid): string
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
        $token = bin2hex(random_bytes(32));
        Database::query(
            'UPDATE prueba_aplicacion SET token_hash = ? WHERE id = ?',
            [hash('sha256', $token), (int) $fila['id']]
        );
        Auth::auditar('REGENERAR_ENLACE_PRUEBA', Auth::usuarioId(), ['uid' => $uid], 'prueba_aplicacion');
        return $token;
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
    public static function guardarAvance(int $aplicacionId, array $respuestas): int
    {
        $limpias = self::limpiar($respuestas);
        Database::query(
            "UPDATE prueba_aplicacion
                SET respuestas = ?, n_respondidos = ?,
                    estado = IF(estado = 'pendiente', 'en_curso', estado),
                    abierta_en = COALESCE(abierta_en, NOW())
              WHERE id = ? AND estado IN ('pendiente','en_curso')",
            [json_encode($limpias, JSON_UNESCAPED_UNICODE), count($limpias), $aplicacionId]
        );
        return count($limpias);
    }

    /**
     * Cierra el protocolo y lo corrige.
     *
     * Este es el candado que el Excel no tenía: si falta aunque sea una
     * respuesta, no se cierra. Un MCMI-IV con 23 ítems en blanco igual
     * arroja perfil, y ese perfil no se puede usar.
     */
    public static function terminar(int $aplicacionId, array $respuestas, ?int $segundos = null): array
    {
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

        $limpias = self::limpiar($respuestas);
        $faltan  = [];
        for ($i = 1; $i <= (int) $def['nItems']; $i++) {
            if (!isset($limpias[$i])) {
                $faltan[] = $i;
            }
        }
        if ($faltan !== []) {
            throw new RuntimeException(
                'Faltan ' . count($faltan) . ' respuesta(s). Una prueba incompleta no se puede '
                . 'corregir: los puntajes saldrían más bajos de lo real.',
            );
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

    /** @return array<int,string> solo ítems válidos y respuestas conocidas */
    private static function limpiar(array $respuestas): array
    {
        $out = [];
        foreach ($respuestas as $item => $r) {
            $n = (int) $item;
            $v = is_string($r) ? strtoupper(trim($r)) : '';
            if ($n > 0 && $n <= 999 && ($v === 'V' || $v === 'F')) {
                $out[$n] = $v;
            }
        }
        ksort($out);
        return $out;
    }

    /** Las respuestas crudas, para el informe del profesional. */
    public static function respuestasDe(string $uid): array
    {
        $json = Database::valor('SELECT respuestas FROM prueba_aplicacion WHERE uid = ?', [$uid]);
        $r = $json === null ? [] : json_decode((string) $json, true);
        return is_array($r) ? $r : [];
    }
}
