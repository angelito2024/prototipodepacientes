-- =====================================================================
--  09 · Salas fijas de videollamada
--
--  Zoom y Google Meet no dejan crear una reunión desde fuera sin conectar
--  la cuenta. Pero los dos dan una sala PERSONAL que es siempre la misma y
--  siempre está disponible: la "Sala personal" de Zoom y el enlace de un
--  espacio de Meet. Guardándolas una vez, ponerlas en una cita es un clic,
--  y el paciente y el profesional entran a la hora acordada sin que nadie
--  tenga que crear nada en el momento.
--
--  Ejecutar después de 01..08. Se puede repetir sin peligro.
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

CALL agregar_columna_si_falta('centro_config', 'sala_zoom',
  'VARCHAR(255) NULL AFTER medios_pago');
CALL agregar_columna_si_falta('centro_config', 'sala_meet',
  'VARCHAR(255) NULL AFTER sala_zoom');

DROP PROCEDURE agregar_columna_si_falta;
