-- =====================================================================
--  ACTUALIZACIÓN — TALLERES, CUENTAS PERSONALES Y ÁMBITO DE TARIFAS
--
--  Para una base YA INSTALADA. Pone al día el esquema con los módulos
--  que se agregaron al panel después de la primera carga:
--
--   · Talleres y charlas (servicios grupales, inscritos, asistencia,
--     diplomas, varias fechas por taller).
--   · Cuentas personales (dinero propio, separado del centro, con clave).
--   · Ámbito de cada tarifa: en la ficha del paciente ya no deben
--     ofrecerse charlas, alquiler de consultorio ni comisiones.
--   · Destinatarios de los recordatorios y varios apoderados por paciente.
--   · Duración de la sesión, para el control de cruces de agenda.
--   · Categorías nuevas para el gasto del día a día.
--
--  Es reejecutable: no hace nada dos veces y no toca datos existentes.
--  En una instalación NUEVA no hace falta — 01_schema.sql ya lo trae.
--
--  Ejecutar después de 01, 02, 03 y 04.
-- =====================================================================

SET NAMES utf8mb4;
USE centro_psicologico;

-- ---------------------------------------------------------------------
-- Ayudante: agregar una columna solo si falta.
-- MySQL no admite ADD COLUMN IF NOT EXISTS (MariaDB sí), así que se
-- consulta information_schema. Se elimina al final del archivo.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _agregar_columna;
DELIMITER $$
CREATE PROCEDURE _agregar_columna(
  IN p_tabla   VARCHAR(64),
  IN p_columna VARCHAR(64),
  IN p_def     TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = p_tabla
       AND COLUMN_NAME  = p_columna
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tabla, '` ADD COLUMN ', p_def);
    PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;


-- =====================================================================
-- 1. COLUMNAS NUEVAS EN TABLAS EXISTENTES
-- =====================================================================

-- Duración de la sesión: de aquí sale el rango con el que se comparan los
-- cruces de agenda. Comparando solo la hora de inicio, 10:00-11:00 y
-- 10:30-11:30 no chocaban.
CALL _agregar_columna('centro_config', 'minutos_sesion',
  '`minutos_sesion` SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER `minutos_recordatorio`');

-- A quién se le cobra cada tarifa. Sin esto, la ficha del paciente ofrecía
-- charlas, alquiler del consultorio y comisiones del profesional.
CALL _agregar_columna('servicios', 'ambito',
  "`ambito` ENUM('paciente','grupal','interno') NOT NULL DEFAULT 'paciente'");

-- Clasificación inicial del catálogo que ya existe, por el nombre. Es la
-- misma regla que aplica el panel; después se corrige a mano lo que haga
-- falta. Solo toca las filas que aún no se hayan clasificado.
UPDATE servicios
   SET ambito = CASE
     WHEN es_paquete = 1 THEN 'paciente'
     WHEN nombre REGEXP 'alquiler|[Cc]omisi' THEN 'interno'
     WHEN nombre REGEXP 'harla|apacitaci|rganizacional|mpresa|olegio|aller' THEN 'grupal'
     ELSE 'paciente'
   END
 WHERE ambito = 'paciente';

-- El índice de tarifas activas pasa a incluir el ámbito: siempre se filtra
-- por él al ofrecer tarifas en una ficha.
DROP PROCEDURE IF EXISTS _rehacer_indice_servicio;
DELIMITER $$
CREATE PROCEDURE _rehacer_indice_servicio()
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'servicios'
         AND INDEX_NAME = 'idx_servicio_activo') = 2 THEN
    ALTER TABLE servicios
      DROP INDEX idx_servicio_activo,
      ADD  INDEX idx_servicio_activo (activo, ambito, nombre);
  END IF;
END$$
DELIMITER ;
CALL _rehacer_indice_servicio();
DROP PROCEDURE _rehacer_indice_servicio;

-- Varios apoderados por paciente, en orden: el primero es el contacto
-- principal, y es a quien se avisa cuando el paciente es menor.
CALL _agregar_columna('paciente_acompanantes', 'orden',
  '`orden` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `es_apoderado`');

-- ¿Recibe los recordatorios de cita? NULL = lo que corresponda por defecto.
-- Un valor explícito es una decisión tomada en la ficha, y se respeta.
CALL _agregar_columna('paciente_acompanantes', 'avisar',
  '`avisar` TINYINT(1) NULL AFTER `orden`');
CALL _agregar_columna('pacientes', 'avisar_paciente',
  '`avisar_paciente` TINYINT(1) NULL');

-- El índice de acompañantes pasa a incluir el orden: siempre se leen así.
DROP PROCEDURE IF EXISTS _rehacer_indice_acomp;
DELIMITER $$
CREATE PROCEDURE _rehacer_indice_acomp()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'paciente_acompanantes'
       AND INDEX_NAME = 'idx_acomp_paciente'
       AND SEQ_IN_INDEX = 1
     GROUP BY INDEX_NAME
    HAVING COUNT(*) = 1
  ) THEN
    ALTER TABLE paciente_acompanantes
      DROP INDEX idx_acomp_paciente,
      ADD  INDEX idx_acomp_paciente (paciente_id, orden, id);
  END IF;
END$$
DELIMITER ;
CALL _rehacer_indice_acomp();
DROP PROCEDURE _rehacer_indice_acomp;

-- Categorías del gasto del día a día. Antes caían todas en 'Otro'.
INSERT INTO categorias_gasto (nombre) VALUES
  ('Insumos y limpieza'), ('Útiles de oficina'), ('Publicidad'), ('Mantenimiento')
ON DUPLICATE KEY UPDATE activo = 1;


-- =====================================================================
-- 2. TALLERES, CHARLAS Y PROGRAMAS
--     Ver el comentario largo en 01_schema.sql, sección 11.
-- =====================================================================

CREATE TABLE IF NOT EXISTS talleres (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               VARCHAR(32)  NULL,
  nombre            VARCHAR(200) NOT NULL,
  tipo              ENUM('Taller','Charla','Programa organizacional','Programa con colegio')
                    NOT NULL DEFAULT 'Taller',
  modo_cobro        ENUM('participante','grupal') NOT NULL DEFAULT 'participante',
  cliente_nombre    VARCHAR(200) NULL,
  cliente_contacto  VARCHAR(200) NULL,
  monto_acordado    DECIMAL(12,2) NULL,
  monto_cobrado     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  precio_persona    DECIMAL(12,2) NULL,
  cupo              SMALLINT UNSIGNED NULL,
  profesional_id    BIGINT UNSIGNED NULL,
  servicio_id       BIGINT UNSIGNED NULL,
  modalidad         ENUM('Presencial','Virtual','Mixto') NOT NULL DEFAULT 'Presencial',
  lugar             VARCHAR(255) NULL,
  enlace            VARCHAR(500) NULL,
  estado            ENUM('Planificado','En curso','Realizado','Cancelado')
                    NOT NULL DEFAULT 'Planificado',
  notas             TEXT NULL,
  fecha_inicio      DATE NULL,
  creado_en         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  eliminado_en      DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_taller_uid (uid),
  KEY idx_taller_fecha (fecha_inicio, estado),
  KEY idx_taller_estado (estado, eliminado_en),
  KEY idx_taller_prof (profesional_id),
  CONSTRAINT fk_taller_prof     FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id),
  CONSTRAINT fk_taller_servicio FOREIGN KEY (servicio_id)    REFERENCES servicios(id),
  CONSTRAINT chk_taller_montos  CHECK (
    (monto_acordado IS NULL OR monto_acordado >= 0) AND
    (precio_persona IS NULL OR precio_persona >= 0) AND
    monto_cobrado >= 0
  )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS taller_sesiones (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid          VARCHAR(32) NULL,
  taller_id    BIGINT UNSIGNED NOT NULL,
  fecha        DATE NOT NULL,
  hora_inicio  TIME NULL,
  hora_fin     TIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_taller_sesion (taller_id, fecha, hora_inicio),
  KEY idx_tsesion_fecha (fecha),
  CONSTRAINT fk_tsesion_taller FOREIGN KEY (taller_id) REFERENCES talleres(id) ON DELETE CASCADE,
  CONSTRAINT chk_tsesion_horas CHECK (hora_fin IS NULL OR hora_inicio IS NULL OR hora_fin > hora_inicio)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS taller_participantes (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               VARCHAR(32) NULL,
  taller_id         BIGINT UNSIGNED NOT NULL,
  persona_id        BIGINT UNSIGNED NULL,
  nombre            VARCHAR(200) NOT NULL,
  documento         VARCHAR(20)  NULL,
  sexo              ENUM('F','M','X') NULL,
  telefono          VARCHAR(30)  NULL,
  email             VARCHAR(150) NULL,
  procedencia       VARCHAR(180) NULL,
  monto             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pagado            TINYINT(1) NOT NULL DEFAULT 0,
  asistio           TINYINT(1) NOT NULL DEFAULT 0,
  diploma_entregado TINYINT(1) NOT NULL DEFAULT 0,
  inscrito_el       DATE NULL,
  creado_en         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tpart_uid (uid),
  KEY idx_tpart_taller (taller_id, id),
  KEY idx_tpart_doc (documento),
  KEY idx_tpart_persona (persona_id),
  CONSTRAINT fk_tpart_taller  FOREIGN KEY (taller_id)  REFERENCES talleres(id) ON DELETE CASCADE,
  CONSTRAINT fk_tpart_persona FOREIGN KEY (persona_id) REFERENCES personas(id),
  CONSTRAINT chk_tpart_monto  CHECK (monto >= 0)
) ENGINE=InnoDB;


-- =====================================================================
-- 3. CUENTAS PERSONALES
--     Privadas por usuario. No entran en ningún reporte del centro.
-- =====================================================================

CREATE TABLE IF NOT EXISTS personal_movimientos (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid             VARCHAR(32) NULL,
  usuario_id      BIGINT UNSIGNED NOT NULL,
  tipo            ENUM('ingreso','gasto') NOT NULL DEFAULT 'gasto',
  clase           ENUM('fijo','variable') NOT NULL DEFAULT 'fijo',
  nombre          VARCHAR(180) NOT NULL,
  categoria       VARCHAR(60) NULL,
  monto           DECIMAL(12,2) NOT NULL,
  dia_vencimiento TINYINT UNSIGNED NULL,
  fecha           DATE NULL,
  notas           VARCHAR(500) NULL,
  creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_personal_uid (uid),
  KEY idx_personal_usuario (usuario_id, tipo, clase),
  KEY idx_personal_fecha (usuario_id, fecha),
  CONSTRAINT fk_personal_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT chk_personal_monto  CHECK (monto > 0),
  CONSTRAINT chk_personal_dia    CHECK (dia_vencimiento IS NULL OR dia_vencimiento BETWEEN 1 AND 31),
  CONSTRAINT chk_personal_clase  CHECK (
    (clase = 'fijo'     AND fecha IS NULL) OR
    (clase = 'variable' AND dia_vencimiento IS NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS personal_pagos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  movimiento_id BIGINT UNSIGNED NOT NULL,
  periodo       CHAR(7) NOT NULL,
  fecha_pago    DATE NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_personal_pago (movimiento_id, periodo),
  CONSTRAINT fk_ppago_movimiento FOREIGN KEY (movimiento_id)
    REFERENCES personal_movimientos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS personal_config (
  usuario_id     BIGINT UNSIGNED NOT NULL,
  pin_activo     TINYINT(1) NOT NULL DEFAULT 0,
  pin_hash       VARCHAR(255) NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id),
  CONSTRAINT fk_pconfig_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- =====================================================================
-- 4. VISTAS Y DISPARADORES
-- =====================================================================

CREATE OR REPLACE VIEW v_taller_resumen AS
SELECT
  t.id,
  t.uid,
  t.nombre,
  t.tipo,
  t.modo_cobro,
  t.estado,
  t.fecha_inicio,
  t.cupo,
  pro.nombre_completo AS profesional,
  (SELECT COUNT(*) FROM taller_participantes p WHERE p.taller_id = t.id) AS inscritos,
  (SELECT COUNT(*) FROM taller_participantes p WHERE p.taller_id = t.id AND p.asistio = 1) AS asistieron,
  (SELECT COUNT(*) FROM taller_participantes p WHERE p.taller_id = t.id AND p.diploma_entregado = 1) AS diplomas,
  (SELECT COUNT(*) FROM taller_sesiones s WHERE s.taller_id = t.id) AS fechas,
  CASE t.modo_cobro
    WHEN 'grupal' THEN t.monto_cobrado
    ELSE COALESCE((SELECT SUM(p.monto) FROM taller_participantes p
                    WHERE p.taller_id = t.id AND p.pagado = 1), 0)
  END AS cobrado,
  CASE t.modo_cobro
    WHEN 'grupal' THEN GREATEST(COALESCE(t.monto_acordado, 0) - t.monto_cobrado, 0)
    ELSE COALESCE((SELECT SUM(p.monto) FROM taller_participantes p
                    WHERE p.taller_id = t.id AND p.pagado = 0), 0)
  END AS por_cobrar
FROM talleres t
LEFT JOIN personas pro ON pro.id = t.profesional_id
WHERE t.eliminado_en IS NULL;

CREATE OR REPLACE VIEW v_personal_mes AS
SELECT
  m.usuario_id,
  DATE_FORMAT(CURDATE(), '%Y-%m') AS periodo,
  m.id,
  m.uid,
  m.tipo,
  m.clase,
  m.nombre,
  m.categoria,
  m.monto,
  m.dia_vencimiento,
  m.fecha,
  pg.fecha_pago,
  (pg.id IS NOT NULL) AS pagado
FROM personal_movimientos m
LEFT JOIN personal_pagos pg
       ON pg.movimiento_id = m.id
      AND pg.periodo = DATE_FORMAT(CURDATE(), '%Y-%m')
WHERE m.clase = 'fijo'
   OR DATE_FORMAT(m.fecha, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m');

DROP TRIGGER IF EXISTS trg_taller_sesion_ins;
DROP TRIGGER IF EXISTS trg_taller_sesion_upd;
DROP TRIGGER IF EXISTS trg_taller_sesion_del;
DROP TRIGGER IF EXISTS trg_tpart_monto_ins;

DELIMITER $$

CREATE TRIGGER trg_taller_sesion_ins
AFTER INSERT ON taller_sesiones
FOR EACH ROW
BEGIN
  UPDATE talleres
     SET fecha_inicio = (SELECT MIN(fecha) FROM taller_sesiones WHERE taller_id = NEW.taller_id)
   WHERE id = NEW.taller_id;
END$$

CREATE TRIGGER trg_taller_sesion_upd
AFTER UPDATE ON taller_sesiones
FOR EACH ROW
BEGIN
  UPDATE talleres
     SET fecha_inicio = (SELECT MIN(fecha) FROM taller_sesiones WHERE taller_id = NEW.taller_id)
   WHERE id = NEW.taller_id;
END$$

CREATE TRIGGER trg_taller_sesion_del
AFTER DELETE ON taller_sesiones
FOR EACH ROW
BEGIN
  UPDATE talleres
     SET fecha_inicio = (SELECT MIN(fecha) FROM taller_sesiones WHERE taller_id = OLD.taller_id)
   WHERE id = OLD.taller_id;
END$$

CREATE TRIGGER trg_tpart_monto_ins
BEFORE INSERT ON taller_participantes
FOR EACH ROW
BEGIN
  IF NEW.pagado = 1 AND NEW.monto <= 0
     AND (SELECT modo_cobro FROM talleres WHERE id = NEW.taller_id) = 'participante' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Un participante marcado como pagado necesita un monto mayor a cero.';
  END IF;
END$$

DELIMITER ;


-- =====================================================================
-- 5. VERSIONES DE LAS COLECCIONES NUEVAS
-- =====================================================================
INSERT IGNORE INTO coleccion_version (clave, version) VALUES
  ('talleres',1), ('personalEntries',1), ('personalConfig',1);

DROP PROCEDURE _agregar_columna;
