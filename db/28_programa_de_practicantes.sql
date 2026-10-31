-- ============================================================
--  28 · El programa de practicantes
-- ============================================================
--  Luis armó once formatos (MAG-PP-01 a 11) y hay que meterlos al
--  sistema. Pero los once NO son la misma cosa, y tratarlos igual sería
--  el error:
--
--    · CUATRO se firman una vez al empezar y no cambian nunca más
--      (reglamento, funciones, protocolo de riesgo, confidencialidad).
--      De esos el sistema no guarda el texto: guarda SI ESTÁN FIRMADOS
--      y cuándo. Eso es lo que hace falta el día que la universidad
--      pregunta, o el día que algo sale mal.
--
--    · TRES no son documentos, son pantallas: el plan de rotación, el
--      registro de supervisión semanal y la ficha de evaluación. El
--      papel es la *salida* de esa pantalla, no su origen.
--
--    · TRES salen hacia afuera y sí son documentos generados: el
--      consentimiento del paciente, la carta de convenio y la constancia.
--
--    · Y el once —el checklist— no es un documento: es el estado del
--      file. Sus casillas se marcan solas cuando el documento existe.
--
--  Esta migración pone los datos que faltaban para poder emitirlos.
--
--  OJO: `centro_config` no se toca acá, así que esta migración es solo
--  para la base del centro. El panel personal no tiene practicantes.
-- ============================================================

USE centro_psicologico;

-- --- 1. Las universidades con las que hay convenio -------------------
--  Hoy la universidad es texto libre en la ficha de cada practicante,
--  así que la misma sale escrita de tres maneras distintas y la carta de
--  convenio no tiene a quién dirigirse. Con su propia tabla, se escribe
--  una vez y se elige de una lista.
CREATE TABLE IF NOT EXISTS universidades (
    id                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre            VARCHAR(180) NOT NULL,
    siglas            VARCHAR(20)  NULL,
    -- A quién va dirigida la carta de solicitud de convenio.
    contacto_nombre   VARCHAR(180) NULL,
    contacto_cargo    VARCHAR(160) NULL
                      DEFAULT 'Director(a) de la Escuela Profesional de Psicología',
    contacto_correo   VARCHAR(150) NULL,
    contacto_telefono VARCHAR(30)  NULL,
    -- El convenio: sin él no empieza ninguna práctica.
    convenio_estado   ENUM('Sin convenio','Solicitado','Vigente','Vencido')
                      NOT NULL DEFAULT 'Sin convenio',
    convenio_desde    DATE NULL,
    convenio_hasta    DATE NULL,
    notas             VARCHAR(400) NULL,
    activo            TINYINT(1) NOT NULL DEFAULT 1,
    creado_en         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_universidad_nombre (nombre),
    KEY ix_universidad_convenio (convenio_estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- 2. Lo que faltaba de cada practicante ---------------------------
--  Las claves de los ENUM van sin tildes a propósito: al cargar un .sql
--  sin --default-character-set=utf8mb4 los acentos se corrompen y
--  después no entra ninguna fila. Las tildes las pone la pantalla.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'practicantes'
        AND COLUMN_NAME = 'modalidad') = 0,
    'ALTER TABLE practicantes
        ADD COLUMN universidad_id SMALLINT UNSIGNED NULL AFTER universidad,
        ADD COLUMN modalidad ENUM(''Preprofesional'',''Profesional'')
            NOT NULL DEFAULT ''Preprofesional'' AFTER universidad_id,
        ADD COLUMN condicion ENUM(''Estudiante'',''Egresado'')
            NOT NULL DEFAULT ''Estudiante'' AFTER modalidad,
        ADD COLUMN horas_diarias DECIMAL(4,2) NULL AFTER horas_meta,
        ADD COLUMN hora_entrada TIME NULL AFTER horas_diarias,
        ADD COLUMN hora_salida  TIME NULL AFTER hora_entrada,
        ADD COLUMN subvencion DECIMAL(10,2) NULL AFTER hora_salida,
        ADD KEY ix_practicante_universidad (universidad_id)',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- --- 3. El file: qué documentos firmó y cuándo -----------------------
--  Esto es el checklist MAG-PP-11 convertido en datos. Sin esto, saber
--  si un practicante firmó su compromiso de confidencialidad depende de
--  que alguien se acuerde o encuentre el papel.
CREATE TABLE IF NOT EXISTS practicante_documentos (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    practicante_id BIGINT UNSIGNED NOT NULL,
    -- El código del formato: MAG-PP-01 … MAG-PP-11, o un documento que
    -- pide la universidad y no está en la lista.
    codigo         VARCHAR(20)  NOT NULL,
    titulo         VARCHAR(180) NULL,
    estado         ENUM('Pendiente','Emitido','Firmado','No aplica')
                   NOT NULL DEFAULT 'Pendiente',
    fecha_emision  DATE NULL,
    fecha_firma    DATE NULL,
    -- El papel escaneado, si se guardó.
    archivo_id     BIGINT UNSIGNED NULL,
    notas          VARCHAR(400) NULL,
    creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_practicante_documento (practicante_id, codigo),
    KEY ix_practicante_documento_estado (estado),
    CONSTRAINT fk_pdoc_practicante FOREIGN KEY (practicante_id)
        REFERENCES practicantes (persona_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- 4. El consentimiento del paciente VA EN EL PACIENTE -------------
--  MAG-PP-06 lo firma el paciente, no el practicante. Guardarlo en la
--  carpeta del practicante sería buscarlo en el lado equivocado el día
--  que el paciente pregunte quién lo atendió.
--
--  El sistema ya guarda consentimiento_fecha y consentimiento_firmante;
--  lo que faltaba es a QUÉ practicante se le dio permiso, y hasta dónde.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacientes'
        AND COLUMN_NAME = 'practicante_autorizado_id') = 0,
    'ALTER TABLE pacientes
        ADD COLUMN practicante_autorizado_id BIGINT UNSIGNED NULL
            AFTER consentimiento_firmante,
        ADD COLUMN practicante_alcance
            ENUM(''No acepta'',''Solo observa'',''Participa'') NULL
            AFTER practicante_autorizado_id,
        ADD COLUMN practicante_consentimiento_fecha DATE NULL
            AFTER practicante_alcance,
        ADD KEY ix_paciente_practicante (practicante_autorizado_id)',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- --- 5. Las universidades que ya están escritas a mano ---------------
--  Se traen las que figuran en las fichas, para no empezar de cero.
INSERT INTO universidades (nombre)
SELECT DISTINCT TRIM(universidad)
  FROM practicantes
 WHERE universidad IS NOT NULL AND TRIM(universidad) <> ''
   AND NOT EXISTS (
       SELECT 1 FROM universidades u WHERE u.nombre = TRIM(practicantes.universidad)
   );

UPDATE practicantes p
  JOIN universidades u ON u.nombre = TRIM(p.universidad)
   SET p.universidad_id = u.id
 WHERE p.universidad_id IS NULL;
