<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;
use RuntimeException;

/**
 * Colección 'practicanteInvitaciones': el enlace con el que un practicante
 * llena su propia ficha.
 *
 * Es el mismo mecanismo del material que se le deja a una familia —clave
 * larga, hash en la base, vencimiento, anotar si se abrió—, pero al revés:
 * acá el que está afuera no recibe información, la entrega.
 *
 * Por eso hay una regla más que en los materiales: la invitación sirve UNA
 * vez. Apenas se registra queda usada y el enlace deja de abrir. Si no, el
 * mismo enlace reenviado al grupo de WhatsApp crearía una ficha por cada
 * persona que lo toque.
 *
 * Registrarse tampoco da acceso al sistema: crea la ficha del practicante
 * y nada más. La cuenta con usuario y clave la da Luis aparte.
 */
final class InvitacionesPracticante extends Repositorio
{
    private const DIAS_VIGENCIA = 7;
    private const DIAS_MINIMO   = 1;
    private const DIAS_MAXIMO   = 90;

    public function clave(): string
    {
        return 'practicanteInvitaciones';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            'SELECT i.uid, i.referencia, i.telefono, i.email, i.mensaje,
                    i.creada_en, i.expira_en, i.abierta_en, i.n_aperturas,
                    i.usada_en, i.anulada_en,
                    p.uid AS persona_uid, p.nombre_completo
               FROM practicante_invitacion i
          LEFT JOIN personas p ON p.id = i.persona_id
              ORDER BY i.id DESC'
        );

        return array_map(static function (array $f): array {
            return [
                'id'           => (string) $f['uid'],
                'referencia'   => (string) ($f['referencia'] ?? ''),
                'telefono'     => (string) ($f['telefono'] ?? ''),
                'email'        => (string) ($f['email'] ?? ''),
                'mensaje'      => (string) ($f['mensaje'] ?? ''),
                'creadaEn'     => (string) $f['creada_en'],
                'expiraEn'     => $f['expira_en'],
                'abiertaEn'    => $f['abierta_en'],
                'aperturas'    => (int) $f['n_aperturas'],
                'usadaEn'      => $f['usada_en'],
                'anuladaEn'    => $f['anulada_en'],
                'practicanteId'=> $f['persona_uid'],
                'practicante'  => (string) ($f['nombre_completo'] ?? ''),
                'estado'       => self::estado($f),
            ];
        }, $filas);
    }

    /** En qué quedó la invitación, dicho como lo diría Luis. */
    private static function estado(array $f): string
    {
        if ($f['anulada_en'] !== null) {
            return 'anulada';
        }
        if ($f['usada_en'] !== null) {
            return 'registrado';
        }
        if ($f['expira_en'] !== null && strtotime((string) $f['expira_en']) < time()) {
            return 'vencida';
        }
        return $f['abierta_en'] !== null ? 'abierta' : 'enviada';
    }

    /**
     * La colección es de solo lectura desde el panel: una invitación no se
     * edita a mano, se crea con `invitar()` y se apaga con `anular()`.
     */
    public function guardar(mixed $valor): void
    {
        // Nada. El panel no escribe acá.
    }

    // ------------------------------------------------------------------
    //  Lo que hace Luis
    // ------------------------------------------------------------------

    /**
     * Genera la invitación y devuelve la clave del enlace.
     *
     * La clave se devuelve UNA sola vez: después queda solo su hash, igual
     * que la de un material. Si se pierde, se genera otra invitación.
     *
     * @return array{id:string, token:string, dias:int}
     */
    public static function invitar(array $datos): array
    {
        $dias = (int) ($datos['dias'] ?? self::DIAS_VIGENCIA);
        $dias = max(self::DIAS_MINIMO, min(self::DIAS_MAXIMO, $dias));

        $token = bin2hex(random_bytes(32));
        $uid   = 'pi_' . bin2hex(random_bytes(8));

        Database::query(
            'INSERT INTO practicante_invitacion
                (uid, token_hash, referencia, telefono, email, mensaje,
                 expira_en, creada_por)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)',
            [
                $uid,
                hash('sha256', $token),
                self::texto($datos['referencia'] ?? null, 180),
                self::texto($datos['telefono'] ?? null, 30),
                self::texto($datos['email'] ?? null, 150),
                self::texto($datos['mensaje'] ?? null, 2000),
                $dias,
                Auth::usuarioId(),
            ]
        );

        Auth::auditar('INVITACION_PRACTICANTE', Auth::usuarioId(),
            ['uid' => $uid, 'dias' => $dias], 'practicantes');

        return ['id' => $uid, 'token' => $token, 'dias' => $dias];
    }

    /** Apaga una invitación que todavía no se usó. */
    public static function anular(string $uid): void
    {
        $fila = Database::uno(
            'SELECT id, usada_en FROM practicante_invitacion WHERE uid = ?', [$uid]
        );
        if ($fila === null) {
            throw new RuntimeException('Esa invitación no existe.');
        }
        if ($fila['usada_en'] !== null) {
            throw new RuntimeException(
                'Esa invitación ya se usó: el practicante está registrado. '
              . 'Anularla no borraría su ficha.'
            );
        }
        Database::query(
            'UPDATE practicante_invitacion SET anulada_en = NOW() WHERE id = ?',
            [(int) $fila['id']]
        );
        Auth::auditar('INVITACION_ANULADA', Auth::usuarioId(), ['uid' => $uid], 'practicantes');
    }

    // ------------------------------------------------------------------
    //  Lo que usa el practicante
    // ------------------------------------------------------------------

    public static function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Database::uno(
            'SELECT * FROM practicante_invitacion WHERE token_hash = ?',
            [hash('sha256', $token)]
        );
    }

    /** null si el enlace sirve; si no, el motivo en palabras del practicante. */
    public static function motivoParaNoAbrir(array $i): ?string
    {
        if ($i['anulada_en'] !== null) {
            return 'Esta invitación ya no está activa. Pídele otra al centro.';
        }
        if ($i['usada_en'] !== null) {
            return 'Esta invitación ya se usó: tus datos quedaron registrados. '
                 . 'Si necesitas corregir algo, avísale al centro.';
        }
        if ($i['expira_en'] !== null && strtotime((string) $i['expira_en']) < time()) {
            return 'Esta invitación venció. Pídele al centro que te envíe una nueva.';
        }
        return null;
    }

    public static function anotarApertura(int $id): void
    {
        Database::query(
            'UPDATE practicante_invitacion
                SET n_aperturas = n_aperturas + 1,
                    abierta_en  = COALESCE(abierta_en, NOW())
              WHERE id = ?',
            [$id]
        );
    }

    /**
     * Guarda lo que llenó el practicante y cierra la invitación.
     *
     * Todo o nada: si algo falla a mitad, no queda ni la persona a medio
     * crear ni la invitación marcada como usada.
     *
     * @return array{nombre:string}
     */
    public static function registrar(int $invitacionId, array $d): array
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if ($nombre === '') {
            throw new RuntimeException('Escribe tu nombre completo.');
        }
        $dni = preg_replace('/\D/', '', (string) ($d['dni'] ?? '')) ?? '';
        if ($dni !== '' && strlen($dni) !== 8) {
            throw new RuntimeException('El DNI tiene ocho números. Revísalo.');
        }
        $email = trim((string) ($d['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ese correo no parece válido. Revísalo.');
        }

        // Que no entre dos veces la misma persona por dos invitaciones.
        if ($dni !== '') {
            $ya = Database::uno(
                'SELECT p.nombre_completo
                   FROM personas p JOIN practicantes pt ON pt.persona_id = p.id
                  WHERE p.documento = ? AND p.eliminado_en IS NULL LIMIT 1',
                [$dni]
            );
            if ($ya !== null) {
                throw new RuntimeException(
                    'Ese DNI ya está registrado como practicante del centro. '
                  . 'Avísale al centro si necesitas corregir algo.'
                );
            }
        }

        Database::transaccion(static function () use ($invitacionId, $d, $nombre, $dni, $email): void {
            // La invitación se vuelve a leer y se bloquea dentro de la
            // transacción: si el enlace se abrió en dos teléfonos a la vez,
            // solo uno de los dos llega a registrar.
            $inv = Database::uno(
                'SELECT id, usada_en, anulada_en, expira_en
                   FROM practicante_invitacion WHERE id = ? FOR UPDATE',
                [$invitacionId]
            );
            if ($inv === null || self::motivoParaNoAbrir($inv) !== null) {
                throw new RuntimeException('Esta invitación ya no se puede usar.');
            }

            $partes = preg_split('/\s+/', $nombre) ?: [];
            $nombres = array_shift($partes) ?? $nombre;
            $paterno = array_shift($partes);
            $materno = $partes === [] ? null : implode(' ', $partes);

            Database::query(
                'INSERT INTO personas
                    (uid, tipo_documento, documento, nombres, apellido_paterno,
                     apellido_materno, fecha_nacimiento, dia_cumple, telefono, email, activo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [
                    'per_' . bin2hex(random_bytes(8)),
                    $dni === '' ? 'SIN' : 'DNI',
                    $dni === '' ? null : $dni,
                    $nombres,
                    $paterno,
                    $materno,
                    self::fecha($d['nacimiento'] ?? null),
                    self::diaMes($d['nacimiento'] ?? null),
                    self::texto($d['telefono'] ?? null, 30),
                    $email === '' ? null : $email,
                ]
            );
            $personaId = (int) Database::ultimoId();

            Database::query(
                'INSERT INTO practicantes
                    (persona_id, universidad, fecha_inicio, fecha_fin, horas_meta)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $personaId,
                    self::texto($d['universidad'] ?? null, 150),
                    self::fecha($d['inicio'] ?? null),
                    self::fecha($d['fin'] ?? null),
                    max(0, min(65535, (int) ($d['horasMeta'] ?? 0))),
                ]
            );

            self::guardarHorario($personaId, $d['horario'] ?? null);

            Database::query(
                'UPDATE practicante_invitacion
                    SET usada_en = NOW(), persona_id = ?
                  WHERE id = ?',
                [$personaId, $invitacionId]
            );
        });

        Auth::auditar('PRACTICANTE_SE_REGISTRO', null,
            ['invitacion' => $invitacionId, 'nombre' => $nombre], 'practicantes');

        return ['nombre' => $nombre];
    }

    /**
     * El cronograma de la semana, con el mismo formato que usa el panel.
     * Lo que manda el practicante se limpia acá: un día sin marcar no
     * guarda horas, y una hora al revés (sale antes de entrar) se descarta.
     */
    private static function guardarHorario(int $personaId, mixed $horario): void
    {
        if (!is_array($horario)) {
            return;
        }
        foreach (Personas::DIAS as $nombre => $n) {
            $d = $horario[$nombre] ?? null;
            if (!is_array($d) || empty($d['asiste'])) {
                continue;
            }
            $desde = self::hora($d['desde'] ?? null);
            $hasta = self::hora($d['hasta'] ?? null);
            if ($desde === null || $hasta === null || $hasta <= $desde) {
                continue;
            }
            Database::query(
                'INSERT INTO persona_horarios (persona_id, rol, dia_semana, hora_inicio, hora_fin)
                 VALUES (?, ?, ?, ?, ?)',
                [$personaId, 'Practicante', $n, $desde, $hasta]
            );
        }
    }

    // ------------------------------------------------------------------
    //  Limpieza de lo que llega de afuera
    // ------------------------------------------------------------------

    /** Como nz(), pero recortando a lo que aguanta la columna. */
    private static function texto(mixed $v, int $max): ?string
    {
        $s = self::nz($v);
        return $s === null ? null : mb_substr($s, 0, $max);
    }
}
