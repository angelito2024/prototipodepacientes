-- =====================================================================
--  11 · Datos del responsable y consentimiento informado
--
--  El consentimiento informado tiene que identificar con precisión quién
--  trata los datos: no basta el nombre del centro. La Ley 29733 y su
--  Reglamento (D.S. 003-2013-JUS) exigen que el titular del banco de datos
--  esté identificado, y la Ley General de Salud (26842) que el usuario sepa
--  ante quién ejerce sus derechos.
--
--  Estos campos NO van en el código: son datos personales del responsable.
--  Se escriben una vez en "Datos del centro" y viven en la base.
--
--  Se guarda además cuándo firmó cada paciente su consentimiento, porque si
--  algún día hay un reclamo es lo primero que se pide.
--
--  Ejecutar después de 01..10. Se puede repetir sin peligro.
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

-- --- Quién responde por el centro y por los datos -------------------
CALL agregar_columna_si_falta('centro_config', 'titular_nombre',
  'VARCHAR(180) NULL AFTER nombre_comercial');
CALL agregar_columna_si_falta('centro_config', 'titular_documento',
  'VARCHAR(20) NULL AFTER titular_nombre');
CALL agregar_columna_si_falta('centro_config', 'titular_cargo',
  "VARCHAR(120) NULL AFTER titular_documento");
-- Número de colegiatura del responsable, si es psicólogo colegiado.
CALL agregar_columna_si_falta('centro_config', 'titular_colegiatura',
  'VARCHAR(40) NULL AFTER titular_cargo');

-- --- Cuándo firmó cada paciente su consentimiento -------------------
CALL agregar_columna_si_falta('pacientes', 'consentimiento_fecha',
  'DATE NULL');
CALL agregar_columna_si_falta('pacientes', 'consentimiento_firmante',
  'VARCHAR(180) NULL AFTER consentimiento_fecha');

DROP PROCEDURE agregar_columna_si_falta;
