<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;
use RuntimeException;

/**
 * Colección 'materialEntregas': cada vez que se le deja material a alguien.
 *
 * Lo que se quiere saber cuando se manda una tarea para la casa no es si se
 * envió —eso se sabe—, sino si la familia la abrió. Por eso cada entrega
 * lleva su propio enlace con clave, queda anotado cuándo se abrió por
 * primera vez, y cuántas veces se bajó cada archivo.
 *
 * El enlace vence, igual que el de una prueba: material clínico no debe
 * quedar colgado en una dirección pública para siempre.
 */
final class MaterialEntregas extends Repositorio
{
    private const DIAS_VIGENCIA = 30;
    private const DIAS_MINIMO   = 1;
    private const DIAS_MAXIMO   = 365;

    public function clave(): string
    {
        return 'materialEntregas';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            "SELECT e.uid, e.titulo, e.mensaje, e.via, e.entregada_en, e.expira_en,
                    e.abierta_en, e.n_aperturas, e.anulada_en,
                    pac.uid AS pacienteId, pac.nombre_completo AS pacienteNombre,
                    pro.uid AS profesionalId
               FROM material_entrega e
               JOIN personas pac  ON pac.id = e.paciente_id
          LEFT JOIN personas pro  ON pro.id = e.profesional_id
              ORDER BY e.entregada_en DESC"
        );
        if ($filas === []) {
            return [];
        }

        // Qué llevaba cada entrega, en una sola consulta.
        $items = Database::todos(
            'SELECT e.uid AS entrega, m.uid AS material, m.titulo, m.formato,
                    i.descargas, i.ultima_descarga
               FROM material_entrega_item i
               JOIN material_entrega e ON e.id = i.entrega_id
               JOIN materiales m       ON m.id = i.material_id
              ORDER BY m.titulo'
        );
        $porEntrega = [];
        foreach ($items as $i) {
            $porEntrega[$i['entrega']][] = [
                'material'       => $i['material'],
                'titulo'         => $i['titulo'],
                'formato'        => $i['formato'],
                'descargas'      => (int) $i['descargas'],
                'ultimaDescarga' => $i['ultima_descarga'],
            ];
        }

        return array_map(static function (array $f) use ($porEntrega): array {
            return [
                'id'             => $f['uid'],
                'titulo'         => $f['titulo'],
                'mensaje'        => $f['mensaje'],
                'via'            => $f['via'],
                'patientId'      => $f['pacienteId'],
                'pacienteNombre' => $f['pacienteNombre'],
                'professionalId' => $f['profesionalId'],
                'entregadaEn'    => $f['entregada_en'],
                'expiraEn'       => $f['expira_en'],
                'abiertaEn'      => $f['abierta_en'],
                'aperturas'      => (int) $f['n_aperturas'],
                'anuladaEn'      => $f['anulada_en'],
                'items'          => $porEntrega[$f['uid']] ?? [],
            ];
        }, $filas);
    }

    /** Desde el panel solo se corrige la indicación o se anula la entrega. */
    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        foreach ($valor as $e) {
            if (!is_array($e) || empty($e['id'])) {
                continue;
            }
            if (!empty($e['anular'])) {
                Database::query(
                    'UPDATE material_entrega SET anulada_en = NOW() WHERE uid = ? AND anulada_en IS NULL',
                    [(string) $e['id']]
                );
                continue;
            }
            Database::query(
                'UPDATE material_entrega SET mensaje = ?, titulo = ? WHERE uid = ?',
                [
                    self::nz($e['mensaje'] ?? null),
                    self::nz($e['titulo'] ?? null),
                    (string) $e['id'],
                ]
            );
        }
        $this->subirVersion();
    }

    // ------------------------------------------------------------------
    //  Entregar
    // ------------------------------------------------------------------

    /**
     * Le deja material a un paciente y devuelve la clave del enlace.
     *
     * La clave se devuelve UNA sola vez: después queda solo su hash. Si se
     * pierde, hay que generar el enlace de nuevo.
     *
     * @param string[] $materialUids
     * @return array{id:string, token:string, dias:int, entregados:int, omitidos:string[]}
     */
    public static function entregar(
        string $pacienteUid,
        array $materialUids,
        ?string $profesionalUid = null,
        ?int $diasVigencia = null,
        string $titulo = '',
        string $mensaje = '',
        string $via = 'enlace'
    ): array {
        $pacienteId = Database::valor(
            'SELECT p.id FROM personas p JOIN pacientes pa ON pa.persona_id = p.id WHERE p.uid = ?',
            [$pacienteUid]
        );
        if ($pacienteId === null) {
            throw new RuntimeException('Ese paciente no está en la base.');
        }
        if ($materialUids === []) {
            throw new RuntimeException('No elegiste ningún material.');
        }

        // Qué se puede mandar y qué no. Se resuelve acá, en el servidor:
        // el panel ya lo filtra, pero eso solo esconde el botón.
        $elegidos = [];
        $omitidos = [];
        foreach ($materialUids as $uid) {
            $m = Materiales::porUid((string) $uid);
            if ($m === null) {
                continue;
            }
            if (Materiales::entregable($m)) {
                $elegidos[] = (int) $m['id'];
            } else {
                $omitidos[] = (string) $m['titulo'];
            }
        }
        if ($elegidos === []) {
            throw new RuntimeException(
                'Ninguno de esos materiales se puede entregar. Los marcados «solo en sesión» o '
                . '«reservado» se usan en consulta y no se reparten.'
            );
        }

        $profesionalId = null;
        if ($profesionalUid !== null && $profesionalUid !== '') {
            $profesionalId = Database::valor(
                'SELECT p.id FROM personas p JOIN profesionales pr ON pr.persona_id = p.id WHERE p.uid = ?',
                [$profesionalUid]
            );
        }

        $token = bin2hex(random_bytes(32));
        $uid   = 'me_' . bin2hex(random_bytes(8));
        $dias  = max(self::DIAS_MINIMO, min(self::DIAS_MAXIMO, $diasVigencia ?? self::DIAS_VIGENCIA));

        Database::query(
            'INSERT INTO material_entrega
                (uid, paciente_id, profesional_id, token_hash, titulo, mensaje, via, expira_en, creada_por)
             VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ? DAY),?)',
            [
                $uid, (int) $pacienteId,
                $profesionalId === null ? null : (int) $profesionalId,
                hash('sha256', $token),
                mb_substr(trim($titulo) ?: 'Material para trabajar en casa', 0, 200),
                trim($mensaje) ?: null,
                self::enum($via, ['enlace', 'whatsapp', 'correo', 'en_consulta', 'impreso'], 'enlace'),
                $dias, Auth::usuarioId(),
            ]
        );
        $entregaId = Database::ultimoId();

        foreach ($elegidos as $materialId) {
            Database::query(
                'INSERT IGNORE INTO material_entrega_item (entrega_id, material_id) VALUES (?,?)',
                [$entregaId, $materialId]
            );
        }

        Auth::auditar('ENTREGAR_MATERIAL', Auth::usuarioId(),
            ['paciente' => $pacienteUid, 'materiales' => count($elegidos)], 'material_entrega');

        return [
            'id'         => $uid,
            'token'      => $token,
            'dias'       => $dias,
            'entregados' => count($elegidos),
            'omitidos'   => $omitidos,
        ];
    }

    /** Vuelve a generar el enlace de una entrega, renovando el plazo. */
    public static function regenerarToken(string $uid, ?int $diasVigencia = null): array
    {
        $fila = Database::uno('SELECT id, anulada_en FROM material_entrega WHERE uid = ?', [$uid]);
        if ($fila === null) {
            throw new RuntimeException('No encuentro esa entrega.');
        }
        if ($fila['anulada_en'] !== null) {
            throw new RuntimeException('Esa entrega está anulada. Vuelve a entregar el material si hace falta.');
        }
        $dias  = max(self::DIAS_MINIMO, min(self::DIAS_MAXIMO, $diasVigencia ?? self::DIAS_VIGENCIA));
        $token = bin2hex(random_bytes(32));
        Database::query(
            'UPDATE material_entrega
                SET token_hash = ?, expira_en = DATE_ADD(NOW(), INTERVAL ? DAY)
              WHERE id = ?',
            [hash('sha256', $token), $dias, (int) $fila['id']]
        );
        Auth::auditar('REGENERAR_ENLACE_MATERIAL', Auth::usuarioId(), ['uid' => $uid], 'material_entrega');
        return ['token' => $token, 'dias' => $dias];
    }

    // ------------------------------------------------------------------
    //  Lo que usa la familia
    // ------------------------------------------------------------------

    public static function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Database::uno(
            'SELECT e.*, per.nombre_completo, pro.nombre_completo AS profesional
               FROM material_entrega e
               JOIN personas per ON per.id = e.paciente_id
          LEFT JOIN personas pro ON pro.id = e.profesional_id
              WHERE e.token_hash = ?',
            [hash('sha256', $token)]
        );
    }

    /** null si el enlace sirve; si no, el motivo en palabras de la familia. */
    public static function motivoParaNoAbrir(array $e): ?string
    {
        if ($e['anulada_en'] !== null) {
            return 'Este material ya no está disponible. Consúltalo con el centro.';
        }
        if ($e['expira_en'] !== null && strtotime((string) $e['expira_en']) < time()) {
            return 'Este enlace ya venció. Pídele al centro que te lo envíe de nuevo.';
        }
        return null;
    }

    /** Lo que ve la familia al abrir el enlace. */
    public static function contenido(int $entregaId): array
    {
        $filas = Database::todos(
            'SELECT m.uid, m.titulo, m.descripcion, m.formato, m.destino,
                    a.nombre_original, a.tamano_bytes, a.mime
               FROM material_entrega_item i
               JOIN materiales m ON m.id = i.material_id
          LEFT JOIN archivos a   ON a.id = m.archivo_id
              WHERE i.entrega_id = ?
              ORDER BY m.seccion, m.orden, m.titulo',
            [$entregaId]
        );
        return array_map(static fn(array $f): array => [
            'id'      => $f['uid'],
            'titulo'  => $f['titulo'],
            'detalle' => $f['descripcion'],
            'formato' => $f['formato'],
            'archivo' => $f['nombre_original'],
            'peso'    => $f['tamano_bytes'] === null ? null : (int) $f['tamano_bytes'],
            'mime'    => $f['mime'],
        ], $filas);
    }

    /** El archivo concreto que pide la familia, ya comprobado que le toca. */
    public static function archivoDe(int $entregaId, string $materialUid): ?array
    {
        return Database::uno(
            'SELECT a.*, m.id AS material_id
               FROM material_entrega_item i
               JOIN materiales m ON m.id = i.material_id
               JOIN archivos a   ON a.id = m.archivo_id
              WHERE i.entrega_id = ? AND m.uid = ? AND m.activo = 1',
            [$entregaId, $materialUid]
        );
    }

    public static function anotarApertura(int $entregaId): void
    {
        Database::query(
            'UPDATE material_entrega
                SET n_aperturas = n_aperturas + 1,
                    abierta_en = COALESCE(abierta_en, NOW())
              WHERE id = ?',
            [$entregaId]
        );
    }

    public static function anotarDescarga(int $entregaId, int $materialId): void
    {
        Database::query(
            'UPDATE material_entrega_item
                SET descargas = descargas + 1, ultima_descarga = NOW()
              WHERE entrega_id = ? AND material_id = ?',
            [$entregaId, $materialId]
        );
    }

    public static function anotarAcceso(int $entregaId, string $accion, ?int $materialId = null): void
    {
        Database::query(
            'INSERT INTO material_acceso (entrega_id, material_id, accion, ip, agente)
             VALUES (?,?,?,?,?)',
            [
                $entregaId, $materialId, $accion,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    }
}
