-- =====================================================================
--  13 · Dirección con la que se arman los enlaces para los pacientes
--
--  El enlace de una prueba se armaba con la dirección desde la que el
--  psicólogo tiene abierto el panel. En la PC del centro eso es
--  "http://localhost/...", y localhost en el celular del paciente significa
--  ese mismo celular: el enlace no abre nunca.
--
--  Aquí se guarda la dirección por la que SÍ se llega al sistema desde
--  fuera de esta computadora:
--
--    · en el WiFi del consultorio, la IP de la PC (http://192.168.x.x/...)
--    · cuando el sistema esté en internet, su dominio (https://...)
--
--  Ejecutar después de 01..12. Se puede repetir sin peligro.
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

CALL agregar_columna_si_falta('centro_config', 'url_publica',
  'VARCHAR(255) NULL COMMENT "Dirección con la que se arman los enlaces que se envían a pacientes"');

DROP PROCEDURE agregar_columna_si_falta;
