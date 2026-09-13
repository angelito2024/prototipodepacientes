-- =====================================================================
--  FINANZAS PERSONALES: préstamos, recaudaciones y juntas
--  Ejecutar después de los anteriores. Es reejecutable.
-- =====================================================================

USE centro_psicologico;

-- Estas tres colecciones se guardan como documento y no en tablas
-- normalizadas, a propósito:
--
--   · Son privadas de Luis, no del centro: nada del resto del sistema las
--     referencia, así que no hay integridad referencial que ganar.
--   · Son pequeñas (decenas de filas) y se leen enteras siempre.
--   · Su forma cambia seguido — un fondo nuevo, otra clase de junta — y
--     normalizarlas obligaría a migrar tablas cada vez.
--
-- Lo que sí necesitan es persistencia real, respaldo y control de versión,
-- y eso lo da esta tabla. Si algún día hay que cruzarlas con las finanzas
-- del centro, se normalizan entonces.
CREATE TABLE IF NOT EXISTS coleccion_json (
  clave          VARCHAR(64) NOT NULL,
  contenido      JSON        NOT NULL,
  actualizado_en TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  actualizado_por BIGINT UNSIGNED NULL,
  PRIMARY KEY (clave),
  CONSTRAINT fk_json_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO coleccion_version (clave, version) VALUES
  ('prestamos', 1), ('recaudaciones', 1), ('juntas', 1);
