<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Database;
use Centro\Repositorio as R;

/**
 * Alta y actualización de la parte común de una persona.
 *
 * Los tres subtipos (paciente, profesional, practicante) comparten los
 * mismos datos base. Este helper los resuelve una sola vez y devuelve el
 * persona_id que usan los repositorios de cada subtipo.
 */
final class Personas
{
    /** Días de la semana del panel -> numeración ISO (1 = lunes). */
    public const DIAS = [
        'Lunes' => 1, 'Martes' => 2, 'Miercoles' => 3, 'Jueves' => 4,
        'Viernes' => 5, 'Sabado' => 6, 'Domingo' => 7,
    ];

    /** @return array<int,string> ISO -> clave del panel */
    public static function diasInverso(): array
    {
        return array_flip(self::DIAS);
    }

    /**
     * Inserta o actualiza una persona a partir de un elemento del panel.
     *
     * Si el documento ya existe en otra ficha, se REUTILIZA esa persona en
     * vez de fallar: es exactamente el caso que motiva el supertipo (un
     * practicante que pasa a profesional, un profesional que además se
     * atiende como paciente).
     */
    public static function upsert(array $item): int
    {
        $uid       = R::txt($item['id'] ?? '') ?: R::nuevoUid();
        $documento = self::soloDocumento($item['dni'] ?? null);

        $id = Database::valor('SELECT id FROM personas WHERE uid = ?', [$uid]);
        if ($id === null && $documento !== null) {
            $id = Database::valor(
                'SELECT id FROM personas WHERE documento = ? AND tipo_documento = ?',
                [$documento, self::tipoDocumento($documento)]
            );
        }

        $campos = [
            'documento'      => $documento,
            'tipo_documento' => self::tipoDocumento($documento),
            'nombres'        => R::txt($item['name'] ?? '') ?: 'Sin nombre',
            'dia_cumple'     => R::diaMes($item['birthday'] ?? null),
            'telefono'       => R::nz($item['phone'] ?? null),
            'email'          => R::nz($item['email'] ?? null),
            'notas'          => R::nz($item['notes'] ?? null),
            'activo'         => array_key_exists('active', $item) ? R::bool($item['active']) : 1,
            'eliminado_en'   => null,
        ];

        if ($id !== null) {
            $id = (int) $id;
            $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($campos)));
            Database::query(
                "UPDATE personas SET {$set}, uid = COALESCE(uid, ?) WHERE id = ?",
                [...array_values($campos), $uid, $id]
            );
            return $id;
        }

        $campos['uid'] = $uid;
        $cols = implode(', ', array_keys($campos));
        $ph   = implode(', ', array_fill(0, count($campos), '?'));
        Database::query("INSERT INTO personas ({$cols}) VALUES ({$ph})", array_values($campos));
        return Database::ultimoId();
    }

    /**
     * El prototipo permite escribir cualquier cosa en el campo DNI. Solo se
     * guarda como documento lo que parece uno; el resto se descarta para no
     * chocar contra el UNIQUE con basura repetida ('-', 'no tiene', ...).
     */
    private static function soloDocumento(mixed $v): ?string
    {
        $s = R::nz($v);
        if ($s === null) {
            return null;
        }
        $s = preg_replace('/\s+/', '', $s) ?? '';
        return preg_match('/^[0-9A-Za-z]{6,20}$/', $s) === 1 ? strtoupper($s) : null;
    }

    private static function tipoDocumento(?string $doc): string
    {
        if ($doc === null) {
            return 'SIN';
        }
        if (preg_match('/^\d{8}$/', $doc) === 1) {
            return 'DNI';
        }
        if (preg_match('/^\d{11}$/', $doc) === 1) {
            return 'RUC';
        }
        return 'CE';
    }

    /** Da de baja lógica a las personas de un subtipo que ya no vienen. */
    public static function bajaFaltantes(string $subtipo, array $uidsVigentes): void
    {
        $todas = R::mapaPersonas($subtipo);
        $sobran = array_diff_key($todas, array_flip($uidsVigentes));
        foreach ($sobran as $id) {
            // Nunca DELETE: la historia clínica, los pagos y las citas de esa
            // persona deben sobrevivir. Se marca como eliminada y deja de
            // aparecer en las lecturas.
            Database::query(
                'UPDATE personas SET eliminado_en = NOW() WHERE id = ? AND eliminado_en IS NULL',
                [$id]
            );
        }
    }

    /** Guarda el horario semanal del panel como filas de persona_horarios. */
    public static function guardarHorario(int $personaId, string $rol, mixed $weeklySchedule): void
    {
        Database::query(
            'DELETE FROM persona_horarios WHERE persona_id = ? AND rol = ?',
            [$personaId, $rol]
        );
        if (!is_array($weeklySchedule)) {
            return;
        }
        foreach (self::DIAS as $clave => $iso) {
            $d = $weeklySchedule[$clave] ?? null;
            if (!is_array($d)) {
                continue;
            }
            $ini = R::hora($d['start'] ?? null) ?? '09:00';
            $fin = R::hora($d['end'] ?? null) ?? '18:00';
            if ($fin <= $ini) {
                continue;                       // chk_horario_rango
            }
            Database::query(
                'INSERT INTO persona_horarios (persona_id, rol, dia_semana, hora_inicio, hora_fin, activo)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$personaId, $rol, $iso, $ini, $fin, R::bool($d['active'] ?? false)]
            );
        }
    }

    /** Reconstruye el objeto weeklySchedule que espera el panel. */
    public static function leerHorarios(string $rol): array
    {
        $inverso = self::diasInverso();
        $out = [];
        $filas = Database::todos(
            'SELECT p.uid, h.dia_semana, h.hora_inicio, h.hora_fin, h.activo
               FROM persona_horarios h
               JOIN personas p ON p.id = h.persona_id
              WHERE h.rol = ?',
            [$rol]
        );
        foreach ($filas as $f) {
            $uid = (string) $f['uid'];
            $out[$uid] ??= [];
            $out[$uid][$inverso[(int) $f['dia_semana']]] = [
                'active' => (int) $f['activo'] === 1,
                'start'  => substr((string) $f['hora_inicio'], 0, 5),
                'end'    => substr((string) $f['hora_fin'], 0, 5),
            ];
        }
        return $out;
    }
}
