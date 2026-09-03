-- =====================================================================
--  SOPORTE PARA LA API PHP
--  Ejecutar después de 01, 02 y 03.
-- =====================================================================

USE centro_psicologico;

-- Versión por colección: bloqueo optimista.
--
-- El prototipo llama a setColl('patients', DB.patients) enviando el ARRAY
-- COMPLETO. Con un solo navegador y localStorage eso era inofensivo; con
-- varias personas trabajando a la vez, el guardado de la recepcionista B
-- borraría el paciente que acaba de crear la recepcionista A.
--
-- La app envía la versión que leyó; si no coincide con la actual, el
-- servidor responde 409 y la app recarga antes de reintentar.
CREATE TABLE IF NOT EXISTS coleccion_version (
  clave          VARCHAR(64) NOT NULL,
  version        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  actualizado_por BIGINT UNSIGNED NULL,
  PRIMARY KEY (clave)
) ENGINE=InnoDB;

-- Sesiones de usuario en base de datos (no en archivos): permite cerrar
-- sesiones remotamente y auditar accesos a datos de salud.
CREATE TABLE IF NOT EXISTS sesiones (
  id          CHAR(64)     NOT NULL,
  usuario_id  BIGINT UNSIGNED NOT NULL,
  ip          VARBINARY(16) NULL,
  user_agent  VARCHAR(255) NULL,
  creada_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en   DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sesion_usuario (usuario_id, expira_en),
  CONSTRAINT fk_sesion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Semillas de versión para cada colección que expone la API.
INSERT IGNORE INTO coleccion_version (clave, version) VALUES
  ('patients',1), ('appointments',1), ('professionals',1), ('practicantes',1),
  ('services',1), ('payments',1), ('expenses',1), ('calendarEvents',1),
  ('products',1), ('centerInfo',1), ('roomUsage',1), ('attendanceLog',1),
  ('authConfig',1);
