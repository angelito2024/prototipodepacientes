-- =====================================================================
--  10 · Unir un gasto personal con la deuda que ya se lleva aparte
--
--  Luis tenía las mismas deudas anotadas dos veces: como gasto fijo de la
--  casa y como préstamo con su cronograma. El panel ya sabía unirlas, pero
--  el enlace se perdía al guardar: estas columnas no existían, así que el
--  servidor descartaba el dato en silencio y el aviso de "se está contando
--  dos veces" volvía a salir en cada recarga.
--
--    vinculo_prestamo_uid · ese gasto ES la cuota de este préstamo
--    vinculo_junta_uid    · ese gasto ES la cuota de esta junta
--    no_es_duplicado      · ya se revisó y son deudas distintas: no avisar
--    sin_pareja_json      · con qué otros gastos ya se comparó y no coinciden
--
--  Los dos vínculos guardan el uid de la colección JSON (prestamos/juntas),
--  no una clave foránea: esas colecciones viven en `coleccion_json` y no
--  tienen tabla propia contra la que apuntar.
--
--  Ejecutar después de 01..09. Se puede repetir sin peligro.
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

CALL agregar_columna_si_falta('personal_movimientos', 'vinculo_prestamo_uid',
  'VARCHAR(32) NULL AFTER notas');
CALL agregar_columna_si_falta('personal_movimientos', 'vinculo_junta_uid',
  'VARCHAR(32) NULL AFTER vinculo_prestamo_uid');
CALL agregar_columna_si_falta('personal_movimientos', 'no_es_duplicado',
  'TINYINT(1) NOT NULL DEFAULT 0 AFTER vinculo_junta_uid');
CALL agregar_columna_si_falta('personal_movimientos', 'sin_pareja_json',
  'JSON NULL AFTER no_es_duplicado');

DROP PROCEDURE agregar_columna_si_falta;
