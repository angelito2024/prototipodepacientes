-- ============================================================
--  31 · Lo que se produce en la sesión, y quién puede atenderla
-- ============================================================
--  Tres cosas que se piden solas cuando el centro pasa a trabajar
--  todo en digital.
--
--  1. EL ANEXO CUELGA DE LA ATENCIÓN, NO DE LA CARPETA
--
--     Hoy `hc_adjuntos` solo sabe de qué historia es un archivo. Pero
--     un dibujo de «la persona bajo la lluvia» no es del paciente en
--     general: es DE LA SESIÓN del 30 de octubre, la que se le mandó a
--     dibujar ese día. Sin `cita_id` no se puede abrir una sesión y ver
--     qué salió de ella, que es justo lo que uno mira al preparar la
--     siguiente. Es el mismo hueco que tienen los diagnósticos.
--
--     Y se amplían las categorías: lo que el paciente PRODUCE en la
--     sesión —dibujos, tareas, escritos— no es «resultado de prueba»
--     ni «otro». Es material clínico y merece llamarse por su nombre.
--
--  2. LA COLEGIATURA VENCE, Y HOY NADIE SE ENTERA
--
--     La tabla ya sabe si está habilitada (sí/no), pero no HASTA
--     CUÁNDO. Un sí/no que alguien tiene que acordarse de cambiar a
--     mano no avisa de nada: el día que vence sigue diciendo «sí». Con
--     la fecha, el sistema avisa antes. Importa de verdad: un informe
--     firmado por quien no estaba habilitado ese día es un informe
--     impugnable, y eso se descubre cuando ya se entregó.
--
--  3. QUIÉN LEYÓ UNA HISTORIA CLÍNICA
--
--     La auditoría registra entradas al sistema y permisos denegados,
--     pero no las LECTURAS. Con caja y admisión entrando al panel, no
--     se puede responder quién abrió la historia de un paciente. Es lo
--     primero que se pregunta cuando hay un reclamo, y la respuesta
--     «no se registra» es la peor de todas.
--
--  Repetible: se puede cargar dos veces sin romper nada.
-- ============================================================

USE centro_psicologico;

-- ---------- 1. El anexo sabe de qué sesión salió ----------
SET @hay := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'hc_adjuntos' AND COLUMN_NAME = 'cita_id'
);
SET @sql := IF(@hay = 0,
    'ALTER TABLE hc_adjuntos
        ADD COLUMN cita_id BIGINT UNSIGNED NULL AFTER episodio_id,
        ADD COLUMN subido_por_paciente TINYINT(1) NOT NULL DEFAULT 0 AFTER descripcion,
        ADD KEY ix_adj_cita (cita_id),
        ADD CONSTRAINT fk_adj_cita FOREIGN KEY (cita_id)
            REFERENCES citas(id) ON DELETE SET NULL',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

--  Categorías nuevas. Sin tildes en las claves nuevas por la misma
--  razón de siempre: un ENUM cargado sin --default-character-set=utf8mb4
--  se corrompe y después no entra ninguna fila. Las que ya existían se
--  dejan tal cual para no invalidar lo guardado.
ALTER TABLE hc_adjuntos
    MODIFY COLUMN categoria ENUM(
        'Consentimiento informado',
        'Informe psicológico',
        'Resultado de prueba',
        'Documento de identidad',
        'Produccion del paciente',   -- dibujos, tareas, escritos de la sesión
        'Protocolo aplicado',        -- la hoja física que se llenó y se escaneó
        'Documento firmado',         -- el PDF que volvió con la firma
        'Otro'
    ) NOT NULL DEFAULT 'Otro';

-- ---------- 2. Hasta cuándo está habilitado ----------
SET @hay := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profesionales' AND COLUMN_NAME = 'colegiatura_vence'
);
SET @sql := IF(@hay = 0,
    'ALTER TABLE profesionales
        ADD COLUMN colegiatura_vence DATE NULL AFTER colegiatura_habil,
        ADD COLUMN colegiatura_esp_vence DATE NULL AFTER colegiatura_esp_habil,
        ADD COLUMN colegiatura_verificada DATE NULL AFTER colegiatura_vence',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------- 3. Quién leyó qué ----------
--  Se apoya en la tabla `auditoria` que ya existe, nada nuevo que
--  mantener. Solo un índice: sin él, «quién abrió la historia de este
--  paciente» recorre la tabla entera, y esa tabla solo crece.
SET @hay := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'auditoria' AND INDEX_NAME = 'ix_audit_lectura'
);
SET @sql := IF(@hay = 0,
    'ALTER TABLE auditoria ADD KEY ix_audit_lectura (tabla, registro_id, creado_en)',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
