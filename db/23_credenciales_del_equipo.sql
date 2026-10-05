-- =====================================================================
--  23 · La cuenta nace con clave prestada y hay que cambiarla
--
--  Luis quiere entregar el acceso en el momento: "tu usuario es rosa.diaz
--  y tu clave es tu DNI". Es lo razonable —no hay que inventar nada, ni
--  anotarlo, ni que él conozca la clave definitiva de nadie.
--
--  Pero el DNI no es un secreto. Está en el carné, en la ficha, en la
--  lista de asistencia y en la base de este sistema. Una cuenta cuya
--  clave es el DNI la puede abrir cualquiera que tenga la ficha delante.
--
--  Por eso la clave prestada solo se aguanta si dura poco. Estas dos
--  columnas son las que hacen que dure poco:
--
--    · `debe_cambiar_clave` obliga a cambiarla al entrar. Mientras esté
--      puesta, la sesión no sirve para nada más: el servidor rechaza
--      cualquier otra operación. No es un aviso que se pueda saltar.
--    · `clave_cambiada_en` deja ver en la pantalla de cuentas quién ya
--      puso la suya y quién sigue con el DNI. Sin eso, "cámbiala" es una
--      recomendación que nadie sigue y nadie comprueba.
--
--  Se aprovecha también para dejar escrito a qué persona pertenece cada
--  cuenta. La columna `persona_id` existe desde el esquema original pero
--  nadie la llenaba: las cuentas flotaban sueltas, sin poder saber si la
--  de "rosa.diaz" era la de la profesional Rosa o la de otra Rosa.
--
--  Ejecutar después de 01..22. Se puede repetir sin peligro.
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

CALL agregar_columna_si_falta('usuarios', 'debe_cambiar_clave',
  'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "La clave la puso otro: hay que cambiarla al entrar"');

CALL agregar_columna_si_falta('usuarios', 'clave_cambiada_en',
  'DATETIME NULL COMMENT "Cuándo eligió su propia clave. NULL = sigue con la que le dieron"');

DROP PROCEDURE agregar_columna_si_falta;

-- --- Una cuenta por persona ------------------------------------------
-- Dos cuentas apuntando a la misma persona no significan nada bueno: o
-- es un duplicado, o alguien tiene dos accesos con permisos distintos y
-- nadie se va a acordar de desactivar los dos.
-- (Se crea solo si falta; `persona_id` admite NULL, y en MySQL varios
--  NULL no chocan entre sí, así que las cuentas sin persona conviven.)
SET @existe := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                   AND INDEX_NAME = 'uq_usuarios_persona');
SET @sql := IF(@existe = 0,
  'ALTER TABLE usuarios ADD UNIQUE KEY uq_usuarios_persona (persona_id)',
  'SELECT "el índice ya existe"');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Las cuentas que ya existen se dan por buenas: quien viene usando la
-- suya desde antes no tiene por qué cambiarla hoy por una migración.
UPDATE usuarios
   SET clave_cambiada_en = COALESCE(clave_cambiada_en, creado_en)
 WHERE debe_cambiar_clave = 0
   AND clave_cambiada_en IS NULL;
