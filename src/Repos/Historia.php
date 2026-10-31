<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Archivos;
use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;

/**
 * Colección 'historia_<uid del paciente>'.
 *
 * En el prototipo toda la historia clínica es UN blob JSON que se reescribe
 * completo cada vez que se agrega una nota de evolución o un adjunto. Aquí
 * se reparte en siete tablas, y el "episodio actual" no es un juego de
 * campos sueltos que se copian y se borran al archivarlo: es simplemente la
 * fila con fecha_cierre IS NULL.
 */
final class Historia extends Repositorio
{
    private const AFECTOS  = ['Eutímico','Ansioso','Depresivo','Irritable','Aplanado','Lábil'];
    private const TIPOS_DX = ['Presuntivo','Definitivo','Diferencial','Descartado'];
    private const CATEGORIAS = [
        'Consentimiento informado','Informe psicológico',
        'Resultado de prueba','Documento de identidad','Otro',
    ];
    private const ESTADOS_INFORME = ['Borrador','En revisión','Aprobado','Entregado'];

    public function __construct(private readonly string $pacienteUid)
    {
    }

    public function clave(): string
    {
        return 'historia_' . $this->pacienteUid;
    }

    /**
     * ¿Esta sesión puede abrir la historia de este paciente?
     *
     * El permiso `historia.ver` dice que puede abrir historias; esto dice
     * cuáles. Un profesional abre las de los pacientes que atiende y
     * ninguna más — y eso no es una preferencia del centro, es el deber
     * de confidencialidad: una historia clínica se lee cuando hace falta
     * para atender, no porque uno tenga la pantalla a mano.
     *
     * Va acá, en el repositorio, y no escondiendo un botón: quien sepa
     * pedir la dirección del paciente ajeno igual recibiría los datos.
     */
    private function puedoVerla(int $pacienteId): bool
    {
        if (Auth::veTodoElCentro()) {
            return true;
        }
        $mia = Auth::personaId();
        if ($mia === null) {
            return false;
        }
        return (int) (Database::valor(
            'SELECT COUNT(*) FROM pacientes WHERE persona_id = ? AND profesional_id = ?',
            [$pacienteId, $mia]
        ) ?? 0) > 0;
    }

    private function pacienteId(): ?int
    {
        $id = Database::valor(
            'SELECT p.id FROM personas p JOIN pacientes pa ON pa.persona_id = p.id WHERE p.uid = ?',
            [$this->pacienteUid]
        );
        return $id === null ? null : (int) $id;
    }

    private function historiaId(bool $crear = false): ?int
    {
        $pacienteId = $this->pacienteId();
        if ($pacienteId === null) {
            return null;
        }
        $id = Database::valor('SELECT id FROM historias_clinicas WHERE paciente_id = ?', [$pacienteId]);
        if ($id !== null) {
            return (int) $id;
        }
        if (!$crear) {
            return null;
        }
        Database::query('INSERT INTO historias_clinicas (paciente_id) VALUES (?)', [$pacienteId]);
        return Database::ultimoId();
    }

    public function leer(): mixed
    {
        // Antes que nada: ¿es un paciente de quien está preguntando?
        // Se corta acá y no escondiendo la pestaña, porque quien sepa
        // pedir la dirección del paciente ajeno igual recibiría todo.
        $pac = $this->pacienteId();
        if ($pac !== null && !$this->puedoVerla($pac)) {
            throw new \RuntimeException(
                'Esa historia clínica es de un paciente que no atiende usted. '
                . 'Si necesita verla, pídaselo a quien administra el centro.'
            );
        }

        $id = $this->historiaId();
        if ($id === null) {
            return null;     // el panel usará defaultHistoria()
        }

        $h = Database::uno(
            'SELECT hc.*, p.fecha_nacimiento
               FROM historias_clinicas hc
               JOIN personas p ON p.id = hc.paciente_id
              WHERE hc.id = ?',
            [$id]
        ) ?? [];

        $actual    = $this->episodioActual($id);
        $episodios = $this->episodiosCerrados($id);
        $informe   = Database::uno(
            'SELECT i.contenido, i.estado, i.fecha_firma, per.nombre_completo AS firmante
               FROM informes i
               LEFT JOIN personas per ON per.id = i.firmante_id
              WHERE i.historia_id = ? ORDER BY i.id DESC LIMIT 1',
            [$id]
        );

        $em = $actual['examenMental'] ?? [
            'orientacion' => '', 'apariencia' => '', 'afecto' => 'Eutímico',
            'juicio' => 'Conservado', 'riesgo' => 'Sin riesgo evidente',
        ];

        return [
            'patientId'               => $this->pacienteUid,
            'fechaNacimiento'         => (string) ($h['fecha_nacimiento'] ?? ''),
            'lugarNacimiento'         => (string) ($h['lugar_nacimiento'] ?? ''),
            'gradoInstruccion'        => (string) ($h['grado_instruccion'] ?? ''),
            'gradoInstruccionOtro'    => (string) ($h['grado_instruccion_otro'] ?? ''),
            'ocupacion'               => (string) ($h['ocupacion'] ?? ''),
            'conQuienVive'            => (string) ($h['con_quien_vive'] ?? ''),
            'informante'              => (string) ($h['informante'] ?? ''),
            'motivoConsulta'          => $actual['motivoConsulta'] ?? '',
            'anamnesis'               => $actual['anamnesis'] ?? '',
            'antecedentesPersonales'  => $actual['antecedentesPersonales'] ?? '',
            'antecedentesFamiliares'  => $actual['antecedentesFamiliares'] ?? '',
            'antecedentesSociales'    => $actual['antecedentesSociales'] ?? '',
            'examenMental'            => $em,
            'impresionDiagnostica'    => $actual['impresionDiagnostica'] ?? '',
            'planTratamiento'         => $actual['planTratamiento'] ?? '',
            'fechaInicioEpisodioActual' => $actual['fechaInicio'] ?? '',
            'pruebas'                 => $this->leerPruebas($id),
            'diagnosticos'            => $this->leerDiagnosticos($id),
            'evolucion'               => $this->leerEvoluciones($id),
            'attachments'             => $this->leerAdjuntos($id),
            'episodios'               => $episodios,
            'updatedAt'               => (string) ($h['actualizado_en'] ?? ''),
            'informeTexto'            => (string) ($informe['contenido'] ?? ''),
            'informeEstado'           => (string) ($informe['estado'] ?? 'Borrador'),
            'informeFirmante'         => (string) ($informe['firmante'] ?? ''),
            'informeFechaFirma'       => (string) ($informe['fecha_firma'] ?? ''),
        ];
    }

    private function mapaEpisodio(array $e): array
    {
        return [
            'id'                     => 'ep' . $e['id'],
            'fechaInicio'            => (string) $e['fecha_inicio'],
            'fechaCierre'            => (string) ($e['fecha_cierre'] ?? ''),
            'motivoConsulta'         => (string) ($e['motivo_consulta'] ?? ''),
            'anamnesis'              => (string) ($e['anamnesis'] ?? ''),
            'antecedentesPersonales' => (string) ($e['antecedentes_personales'] ?? ''),
            'antecedentesFamiliares' => (string) ($e['antecedentes_familiares'] ?? ''),
            'antecedentesSociales'   => (string) ($e['antecedentes_sociales'] ?? ''),
            'impresionDiagnostica'   => (string) ($e['impresion_diagnostica'] ?? ''),
            'planTratamiento'        => (string) ($e['plan_tratamiento'] ?? ''),
            'examenMental'           => [
                'orientacion' => (string) ($e['em_orientacion'] ?? ''),
                'apariencia'  => (string) ($e['em_apariencia'] ?? ''),
                'afecto'      => (string) ($e['em_afecto'] ?? 'Eutímico'),
                'juicio'      => (string) ($e['em_juicio'] ?? 'Conservado'),
                'riesgo'      => (string) ($e['em_riesgo'] ?? 'Sin riesgo evidente'),
            ],
        ];
    }

    private function episodioActual(int $historiaId): ?array
    {
        $e = Database::uno(
            'SELECT * FROM hc_episodios WHERE historia_id = ? AND fecha_cierre IS NULL LIMIT 1',
            [$historiaId]
        );
        return $e === null ? null : $this->mapaEpisodio($e);
    }

    private function episodiosCerrados(int $historiaId): array
    {
        return array_map(
            fn(array $e) => $this->mapaEpisodio($e),
            Database::todos(
                'SELECT * FROM hc_episodios
                  WHERE historia_id = ? AND fecha_cierre IS NOT NULL
                  ORDER BY fecha_cierre',
                [$historiaId]
            )
        );
    }

    private function leerPruebas(int $historiaId): array
    {
        return array_map(static fn(array $p) => [
            'id'        => 'pr' . $p['id'],
            'nombre'    => (string) $p['nombre'],
            'fecha'     => (string) ($p['fecha'] ?? ''),
            'resultado' => (string) ($p['resultado'] ?? ''),
        ], Database::todos(
            'SELECT id, nombre, fecha, resultado FROM hc_pruebas WHERE historia_id = ? ORDER BY id',
            [$historiaId]
        ));
    }

    private function leerDiagnosticos(int $historiaId): array
    {
        return array_map(static fn(array $d) => [
            'id'          => 'dx' . $d['id'],
            'codigo'      => (string) ($d['codigo_cie10'] ?? ''),
            'descripcion' => (string) $d['descripcion'],
            'tipo'        => (string) $d['tipo'],
            'fecha'       => (string) $d['fecha'],
        ], Database::todos(
            'SELECT id, codigo_cie10, descripcion, tipo, fecha
               FROM hc_diagnosticos WHERE historia_id = ? ORDER BY id',
            [$historiaId]
        ));
    }

    private function leerEvoluciones(int $historiaId): array
    {
        return array_map(static fn(array $e) => [
            'id'        => 'ev' . $e['id'],
            'fecha'     => (string) $e['fecha'],
            'subjetivo' => (string) ($e['subjetivo'] ?? ''),
            'objetivo'  => (string) ($e['objetivo'] ?? ''),
            'analisis'  => (string) ($e['analisis'] ?? ''),
            'plan'      => (string) ($e['plan'] ?? ''),
            // Una nota firmada ya no se edita: lo impide el trigger
            // trg_evolucion_bloqueo, en la base, no la pantalla. Acá solo
            // se cuenta quién la cerró y cuándo, que es lo que hay que
            // poder mostrar el día que pidan la historia.
            'firmado'     => $e['firmado_en'] !== null,
            'firmadoEn'   => (string) ($e['firmado_en'] ?? ''),
            'firmadoPor'  => (string) ($e['firma_nombre'] ?? ''),
            'citaId'      => (string) ($e['cita_uid'] ?? ''),
        ], Database::todos(
            'SELECT e.id, e.fecha, e.subjetivo, e.objetivo, e.analisis, e.plan,
                    e.firmado_en, c.uid AS cita_uid,
                    COALESCE(per.nombre_completo, u.nombre_completo, u.usuario) AS firma_nombre
               FROM hc_evoluciones e
               LEFT JOIN citas c ON c.id = e.cita_id
               LEFT JOIN usuarios u ON u.id = e.firmado_por
               LEFT JOIN personas per ON per.id = u.persona_id
              WHERE e.historia_id = ? ORDER BY e.fecha, e.id',
            [$historiaId]
        ));
    }

    private function leerAdjuntos(int $historiaId): array
    {
        $out = [];
        foreach (Database::todos(
            'SELECT a.id AS adj_id, a.categoria, a.creado_en, a.descripcion,
                    a.subido_por_paciente, c.uid AS cita_uid, c.inicio AS cita_inicio,
                    c.codigo_atencion,
                    ar.uuid, ar.nombre_original, ar.mime, ar.tamano_bytes,
                    ar.es_enlace, ar.url_externa
               FROM hc_adjuntos a
               JOIN archivos ar ON ar.id = a.archivo_id
               LEFT JOIN citas c ON c.id = a.cita_id
              WHERE a.historia_id = ? AND ar.eliminado_en IS NULL
              ORDER BY a.id',
            [$historiaId]
        ) as $f) {
            $base = Archivos::aJson($f);
            $out[] = $base + [
                'category'   => (string) $f['categoria'],
                'uploadedAt' => (string) $f['creado_en'],
                'descripcion'=> (string) ($f['descripcion'] ?? ''),
                // De qué sesión salió. Un dibujo de la persona bajo la
                // lluvia no es del paciente en general: es de la sesión
                // en que se le mandó a dibujar.
                'citaId'     => (string) ($f['cita_uid'] ?? ''),
                'citaFecha'  => substr((string) ($f['cita_inicio'] ?? ''), 0, 10),
                'codigoAtencion' => (string) ($f['codigo_atencion'] ?? ''),
                'delPaciente'=> (int) ($f['subido_por_paciente'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        $historiaId = $this->historiaId(true);
        if ($historiaId === null) {
            return;                     // el paciente aún no existe
        }
        $pacienteId = (int) $this->pacienteId();
        // Escribir en la historia de un paciente ajeno es peor que
        // leerla: deja rastro de una atención que no ocurrió.
        if (!$this->puedoVerla($pacienteId)) {
            throw new \RuntimeException(
                'No puede escribir en la historia de un paciente que no atiende usted.'
            );
        }
        $usuarioId  = Auth::usuarioId();

        // La fecha de nacimiento completa solo aparece en la historia clínica;
        // el resto del panel guarda únicamente 'MM-DD'. Se aprovecha para
        // completar la ficha de la persona.
        $fnac = self::fecha($valor['fechaNacimiento'] ?? null);
        if ($fnac !== null) {
            Database::query('UPDATE personas SET fecha_nacimiento = ? WHERE id = ?', [$fnac, $pacienteId]);
        }

        Database::query(
            'UPDATE historias_clinicas
                SET lugar_nacimiento=?, grado_instruccion=?, grado_instruccion_otro=?,
                    ocupacion=?, con_quien_vive=?, informante=?
              WHERE id = ?',
            [
                self::nz($valor['lugarNacimiento'] ?? null),
                self::nz($valor['gradoInstruccion'] ?? null),
                self::nz($valor['gradoInstruccionOtro'] ?? null),
                self::nz($valor['ocupacion'] ?? null),
                self::nz($valor['conQuienVive'] ?? null),
                self::nz($valor['informante'] ?? null),
                $historiaId,
            ]
        );

        $this->guardarEpisodios($historiaId, $valor);
        $this->guardarPruebas($historiaId, $valor['pruebas'] ?? []);
        $this->guardarDiagnosticos($historiaId, $valor['diagnosticos'] ?? []);
        $this->guardarEvoluciones($historiaId, $valor['evolucion'] ?? []);
        $this->guardarAdjuntos($historiaId, $valor['attachments'] ?? [], $usuarioId);
        $this->guardarInforme($historiaId, $valor);
    }

    /** Episodios cerrados + el actual (fecha_cierre NULL). */
    private function guardarEpisodios(int $historiaId, array $v): void
    {
        $conservar = [];

        foreach ((array) ($v['episodios'] ?? []) as $ep) {
            if (is_array($ep)) {
                $conservar[] = $this->upsertEpisodio($historiaId, $ep, self::fecha($ep['fechaCierre'] ?? null) ?? date('Y-m-d'));
            }
        }

        $actual = [
            'id'                     => $this->idActual($historiaId),
            'fechaInicio'            => $v['fechaInicioEpisodioActual'] ?? null,
            'motivoConsulta'         => $v['motivoConsulta'] ?? '',
            'anamnesis'              => $v['anamnesis'] ?? '',
            'antecedentesPersonales' => $v['antecedentesPersonales'] ?? '',
            'antecedentesFamiliares' => $v['antecedentesFamiliares'] ?? '',
            'antecedentesSociales'   => $v['antecedentesSociales'] ?? '',
            'impresionDiagnostica'   => $v['impresionDiagnostica'] ?? '',
            'planTratamiento'        => $v['planTratamiento'] ?? '',
            'examenMental'           => $v['examenMental'] ?? [],
        ];
        $conservar[] = $this->upsertEpisodio($historiaId, $actual, null);

        $this->borrarSobrantes('hc_episodios', 'historia_id', $historiaId, $conservar);
    }

    private function idActual(int $historiaId): ?string
    {
        $id = Database::valor(
            'SELECT id FROM hc_episodios WHERE historia_id = ? AND fecha_cierre IS NULL LIMIT 1',
            [$historiaId]
        );
        return $id === null ? null : 'ep' . $id;
    }

    private function upsertEpisodio(int $historiaId, array $ep, ?string $fechaCierre): int
    {
        $em = is_array($ep['examenMental'] ?? null) ? $ep['examenMental'] : [];
        $params = [
            $historiaId,
            self::fecha($ep['fechaInicio'] ?? null) ?? date('Y-m-d'),
            $fechaCierre,
            self::nz($ep['motivoConsulta'] ?? null),
            self::nz($ep['anamnesis'] ?? null),
            self::nz($ep['antecedentesPersonales'] ?? null),
            self::nz($ep['antecedentesFamiliares'] ?? null),
            self::nz($ep['antecedentesSociales'] ?? null),
            self::nz($em['orientacion'] ?? null),
            self::nz($em['apariencia'] ?? null),
            self::nz($em['afecto'] ?? null),
            self::nz($em['juicio'] ?? null),
            self::nz($em['riesgo'] ?? null),
            self::nz($ep['impresionDiagnostica'] ?? null),
            self::nz($ep['planTratamiento'] ?? null),
        ];

        $id = $this->idNumerico($ep['id'] ?? null, 'ep', 'hc_episodios', 'historia_id', $historiaId);
        if ($id !== null) {
            Database::query(
                'UPDATE hc_episodios SET historia_id=?, fecha_inicio=?, fecha_cierre=?,
                        motivo_consulta=?, anamnesis=?, antecedentes_personales=?,
                        antecedentes_familiares=?, antecedentes_sociales=?,
                        em_orientacion=?, em_apariencia=?, em_afecto=?, em_juicio=?,
                        em_riesgo=?, impresion_diagnostica=?, plan_tratamiento=?
                  WHERE id=?',
                [...$params, $id]
            );
            return $id;
        }

        Database::query(
            'INSERT INTO hc_episodios
                (historia_id, fecha_inicio, fecha_cierre, motivo_consulta, anamnesis,
                 antecedentes_personales, antecedentes_familiares, antecedentes_sociales,
                 em_orientacion, em_apariencia, em_afecto, em_juicio, em_riesgo,
                 impresion_diagnostica, plan_tratamiento)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $params
        );
        return Database::ultimoId();
    }

    private function guardarPruebas(int $historiaId, mixed $lista): void
    {
        $conservar = [];
        foreach ((array) $lista as $p) {
            if (!is_array($p) || self::nz($p['nombre'] ?? null) === null) {
                continue;
            }
            $params = [
                $historiaId,
                self::txt($p['nombre']),
                self::fecha($p['fecha'] ?? null),
                self::nz($p['resultado'] ?? null),
            ];
            $id = $this->idNumerico($p['id'] ?? null, 'pr', 'hc_pruebas', 'historia_id', $historiaId);
            if ($id !== null) {
                Database::query(
                    'UPDATE hc_pruebas SET historia_id=?, nombre=?, fecha=?, resultado=? WHERE id=?',
                    [...$params, $id]
                );
                $conservar[] = $id;
            } else {
                Database::query(
                    'INSERT INTO hc_pruebas (historia_id, nombre, fecha, resultado) VALUES (?,?,?,?)',
                    $params
                );
                $conservar[] = Database::ultimoId();
            }
        }
        $this->borrarSobrantes('hc_pruebas', 'historia_id', $historiaId, $conservar);
    }

    private function guardarDiagnosticos(int $historiaId, mixed $lista): void
    {
        $conservar = [];
        foreach ((array) $lista as $d) {
            if (!is_array($d) || self::nz($d['descripcion'] ?? null) === null) {
                continue;
            }
            $codigo = self::nz($d['codigo'] ?? null);
            // Solo se enlaza al catálogo si el código existe; si no, se guarda
            // NULL y la descripción conserva el dato. La FK no debe bloquear
            // un diagnóstico ya registrado con un código antiguo.
            if ($codigo !== null && Database::valor(
                'SELECT 1 FROM cie10_catalogo WHERE codigo = ?', [$codigo]
            ) === null) {
                $codigo = null;
            }
            $params = [
                $historiaId,
                $codigo,
                self::txt($d['descripcion']),
                self::enum($d['tipo'] ?? null, self::TIPOS_DX, 'Presuntivo'),
                self::fecha($d['fecha'] ?? null) ?? date('Y-m-d'),
            ];
            $id = $this->idNumerico($d['id'] ?? null, 'dx', 'hc_diagnosticos', 'historia_id', $historiaId);
            if ($id !== null) {
                Database::query(
                    'UPDATE hc_diagnosticos SET historia_id=?, codigo_cie10=?, descripcion=?,
                            tipo=?, fecha=? WHERE id=?',
                    [...$params, $id]
                );
                $conservar[] = $id;
            } else {
                Database::query(
                    'INSERT INTO hc_diagnosticos (historia_id, codigo_cie10, descripcion, tipo, fecha)
                     VALUES (?,?,?,?,?)',
                    $params
                );
                $conservar[] = Database::ultimoId();
            }
        }
        $this->borrarSobrantes('hc_diagnosticos', 'historia_id', $historiaId, $conservar);
    }

    private function guardarEvoluciones(int $historiaId, mixed $lista): void
    {
        $conservar = [];
        foreach ((array) $lista as $e) {
            if (!is_array($e)) {
                continue;
            }
            $params = [
                $historiaId,
                self::fecha($e['fecha'] ?? null) ?? date('Y-m-d'),
                self::nz($e['subjetivo'] ?? null),
                self::nz($e['objetivo'] ?? null),
                self::nz($e['analisis'] ?? null),
                self::nz($e['plan'] ?? null),
            ];
            $id = $this->idNumerico($e['id'] ?? null, 'ev', 'hc_evoluciones', 'historia_id', $historiaId);
            if ($id !== null) {
                // Una nota firmada es inmutable: el trigger trg_evolucion_bloqueo
                // la protege, así que ni se intenta actualizar.
                $firmada = Database::valor('SELECT firmado_en FROM hc_evoluciones WHERE id = ?', [$id]);
                if ($firmada === null) {
                    Database::query(
                        'UPDATE hc_evoluciones SET historia_id=?, fecha=?, subjetivo=?,
                                objetivo=?, analisis=?, plan=? WHERE id=?',
                        [...$params, $id]
                    );
                }
                $conservar[] = $id;
            } else {
                Database::query(
                    'INSERT INTO hc_evoluciones (historia_id, fecha, subjetivo, objetivo, analisis, plan)
                     VALUES (?,?,?,?,?,?)',
                    $params
                );
                $conservar[] = Database::ultimoId();
            }
        }
        // Las notas firmadas nunca se eliminan aunque el panel deje de enviarlas.
        $this->borrarSobrantes('hc_evoluciones', 'historia_id', $historiaId, $conservar, 'firmado_en IS NULL');
    }

    /** El id interno de una cita a partir del uid que usa el panel. */
    private static function citaIdDe(mixed $uid): ?int
    {
        $uid = is_string($uid) ? trim($uid) : '';
        if ($uid === '') {
            return null;
        }
        $id = Database::valor('SELECT id FROM citas WHERE uid = ?', [$uid]);
        return $id === null ? null : (int) $id;
    }

    /**
     * Cierra una nota de evolución con la firma de quien atendió.
     *
     * Firmar no es un adorno: a partir de acá la nota **no se puede
     * editar**, y eso lo impide la base (`trg_evolucion_bloqueo`), no la
     * pantalla. Si después hay que corregir algo, se agrega una nota
     * nueva con su fecha — así la historia muestra qué se dijo, cuándo, y
     * qué se corrigió después, que es exactamente lo que se le pide a una
     * historia clínica cuando alguien la revisa.
     *
     * No se puede firmar dos veces: la primera firma manda, y volver a
     * firmar cambiaría la fecha de algo que ya estaba cerrado.
     */
    public static function firmarEvolucion(string $idPanel, int $usuarioId): array
    {
        $id = (int) preg_replace('/\D+/', '', $idPanel);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'Nota de evolución no válida.'];
        }
        $fila = Database::uno(
            'SELECT id, firmado_en, subjetivo, objetivo, analisis, plan
               FROM hc_evoluciones WHERE id = ?',
            [$id]
        );
        if ($fila === null) {
            return ['ok' => false, 'error' => 'Esa nota de evolución ya no existe.'];
        }
        if ($fila['firmado_en'] !== null) {
            return ['ok' => false, 'error' => 'Esa nota ya estaba firmada el '
                . substr((string) $fila['firmado_en'], 0, 16) . '.'];
        }
        // Una nota vacía no se firma: firmar es afirmar que eso fue la
        // sesión, y no se puede afirmar nada de cuatro campos en blanco.
        $texto = trim(($fila['subjetivo'] ?? '') . ($fila['objetivo'] ?? '')
            . ($fila['analisis'] ?? '') . ($fila['plan'] ?? ''));
        if ($texto === '') {
            return ['ok' => false, 'error' => 'La nota está vacía. Escriba la sesión antes de firmarla.'];
        }

        Database::query(
            'UPDATE hc_evoluciones SET firmado_en = NOW(), firmado_por = ?
              WHERE id = ? AND firmado_en IS NULL',
            [$usuarioId, $id]
        );
        return ['ok' => true];
    }

    private function guardarAdjuntos(int $historiaId, mixed $lista, ?int $usuarioId): void
    {
        $conservar = [];
        foreach ((array) $lista as $a) {
            if (!is_array($a)) {
                continue;
            }
            // El adjunto llega como data-URL solo la primera vez; después ya
            // viene como referencia y el binario no se reescribe.
            $archivoId = Archivos::resolver($a, $usuarioId);
            if ($archivoId === null) {
                continue;
            }
            $existente = Database::valor(
                'SELECT id FROM hc_adjuntos WHERE historia_id = ? AND archivo_id = ?',
                [$historiaId, $archivoId]
            );
            if ($existente !== null) {
                Database::query(
                    'UPDATE hc_adjuntos SET categoria = ?, cita_id = ?, descripcion = ?
                      WHERE id = ?',
                    [
                        self::enum($a['category'] ?? null, self::CATEGORIAS, 'Otro'),
                        self::citaIdDe($a['citaId'] ?? null),
                        self::nz($a['descripcion'] ?? null),
                        $existente,
                    ]
                );
                $conservar[] = (int) $existente;
                continue;
            }
            Database::query(
                'INSERT INTO hc_adjuntos
                    (historia_id, cita_id, archivo_id, categoria, descripcion)
                 VALUES (?,?,?,?,?)',
                [
                    $historiaId,
                    self::citaIdDe($a['citaId'] ?? null),
                    $archivoId,
                    self::enum($a['category'] ?? null, self::CATEGORIAS, 'Otro'),
                    self::nz($a['descripcion'] ?? null),
                ]
            );
            $conservar[] = Database::ultimoId();
        }
        $this->borrarSobrantes('hc_adjuntos', 'historia_id', $historiaId, $conservar);
    }

    private function guardarInforme(int $historiaId, array $v): void
    {
        $texto = self::nz($v['informeTexto'] ?? null);
        if ($texto === null) {
            return;
        }
        $firmanteId = null;
        $firmante = self::nz($v['informeFirmante'] ?? null);
        if ($firmante !== null) {
            $firmanteId = Database::valor(
                'SELECT pr.persona_id FROM profesionales pr
                   JOIN personas p ON p.id = pr.persona_id
                  WHERE p.nombre_completo = ? LIMIT 1',
                [$firmante]
            );
        }

        $existente = Database::valor(
            'SELECT id FROM informes WHERE historia_id = ? ORDER BY id DESC LIMIT 1',
            [$historiaId]
        );
        $params = [
            $texto,
            self::enum($v['informeEstado'] ?? null, self::ESTADOS_INFORME, 'Borrador'),
            $firmanteId === null ? null : (int) $firmanteId,
            self::fecha($v['informeFechaFirma'] ?? null),
        ];

        if ($existente !== null) {
            Database::query(
                'UPDATE informes SET contenido=?, estado=?, firmante_id=?, fecha_firma=? WHERE id=?',
                [...$params, $existente]
            );
        } else {
            Database::query(
                'INSERT INTO informes (historia_id, contenido, estado, firmante_id, fecha_firma)
                 VALUES (?,?,?,?,?)',
                [$historiaId, ...$params]
            );
        }
    }

    /** 'ev12' -> 12, comprobando que la fila pertenezca a esta historia. */
    private function idNumerico(mixed $marcado, string $prefijo, string $tabla, string $col, int $padre): ?int
    {
        $s = self::txt($marcado);
        if (!str_starts_with($s, $prefijo)) {
            return null;
        }
        $id = (int) substr($s, strlen($prefijo));
        if ($id <= 0) {
            return null;
        }
        $ok = Database::valor("SELECT id FROM {$tabla} WHERE id = ? AND {$col} = ?", [$id, $padre]);
        return $ok === null ? null : $id;
    }

    private function borrarSobrantes(
        string $tabla,
        string $col,
        int $padre,
        array $conservar,
        string $extra = '1=1'
    ): void {
        $sql = "DELETE FROM {$tabla} WHERE {$col} = ? AND {$extra}";
        $par = [$padre];
        if ($conservar !== []) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($conservar), '?')) . ')';
            $par = [...$par, ...$conservar];
        }
        Database::query($sql, $par);
    }
}
