-- =====================================================================
--  17 · Pautas de observación: las que aplica el profesional
--
--  Hasta ahora una "prueba" era algo que el paciente respondía por un
--  enlace. Pero la mitad del trabajo del centro no es así: la evaluación
--  pedagógica por edades, el C.A.R.S., el registro de una sesión de juego
--  los marca el profesional mientras observa al niño. Esas hojas estaban
--  escaneadas: no se podían llenar, no se podían sumar, y el resultado no
--  llegaba nunca a la historia clínica.
--
--  Tres columnas nuevas en `pruebas` sostienen eso:
--
--    aplicador   quién la marca: el paciente o el profesional
--    familia     a qué escalera pertenece, cuando hay varias hojas por
--                edad; así el sistema elige sola la que le toca al niño
--    edad en meses   porque entre "0 a 1 mes" y "1 a 3 meses" hay una
--                diferencia enorme, y la columna en años no la ve
--
--  Y una en `prueba_aplicacion`:
--
--    notas       lo que el profesional anota ítem por ítem. En la hoja de
--                papel había un solo renglón de OBSERVACIONES al final, y
--                ahí no entra "esto lo logró pero solo con apoyo".
--
--  Ejecutar después de 01..16. Se puede repetir sin peligro.
-- =====================================================================

USE centro_psicologico;

DROP PROCEDURE IF EXISTS agregar_columna_si_falta;
DELIMITER $$
CREATE PROCEDURE agregar_columna_si_falta(
  IN p_tabla VARCHAR(64), IN p_columna VARCHAR(64), IN p_definicion TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = p_tabla AND COLUMN_NAME = p_columna) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tabla, '` ADD COLUMN `', p_columna, '` ', p_definicion);
    PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END$$
DELIMITER ;

CALL agregar_columna_si_falta('pruebas', 'aplicador',
  "ENUM('paciente','profesional') NOT NULL DEFAULT 'paciente'
   COMMENT 'Quién marca las respuestas'");

CALL agregar_columna_si_falta('pruebas', 'familia',
  "VARCHAR(40) NULL COMMENT 'Escalera a la que pertenece, si hay una hoja por edad'");

CALL agregar_columna_si_falta('pruebas', 'edad_meses_min',
  'SMALLINT UNSIGNED NULL COMMENT "Edad mínima en meses"');

CALL agregar_columna_si_falta('pruebas', 'edad_meses_max',
  'SMALLINT UNSIGNED NULL COMMENT "Edad máxima en meses"');

CALL agregar_columna_si_falta('pruebas', 'orden',
  'SMALLINT NOT NULL DEFAULT 0 COMMENT "Posición dentro de su familia"');

CALL agregar_columna_si_falta('prueba_aplicacion', 'notas',
  'JSON NULL COMMENT "Lo que el profesional anotó ítem por ítem"');

DROP PROCEDURE agregar_columna_si_falta;

-- Buscar la hoja que le toca a un niño de tantos meses es la consulta que
-- hace el panel cada vez que se abre una evaluación.
SET @ix := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pruebas'
               AND INDEX_NAME = 'ix_pruebas_familia');
SET @sql := IF(@ix = 0,
  'CREATE INDEX ix_pruebas_familia ON pruebas (familia, orden)',
  'SELECT "el índice ya estaba" AS nota');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
