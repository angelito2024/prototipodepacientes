-- =====================================================================
--  18 · Un paquete que consumen dos personas
--
--  El caso que lo motivó: una paciente y su pareja compran juntos un paquete
--  de 10 sesiones. No es terapia de pareja —cada uno tiene su sesión, a su
--  hora, a veces el mismo día y a veces en días distintos—, pero las diez
--  sesiones son una sola bolsa: cada sesión que toma cualquiera de los dos
--  descuenta de las diez.
--
--  Hasta ahora el paquete era de una sola persona. Registrarlos por
--  separado obligaba a partir las diez sesiones a ojo, y eso falla el día
--  que uno viene tres veces y el otro una.
--
--  La solución es una columna:
--
--    titular_id    quién es el dueño del paquete. Si está puesta, esta
--                  persona NO tiene paquete propio: consume del titular.
--
--  De ahí salen tres reglas que respeta el panel:
--    · las sesiones usadas son la suma de las de todos los miembros;
--    · el dinero lo debe el titular, una sola vez (si no, el mismo saldo
--      aparecería dos veces en las alertas de cobro);
--    · los pagos de cualquiera de los dos abonan al mismo paquete.
--
--  Ejecutar después de 01..17. Se puede repetir sin peligro.
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

CALL agregar_columna_si_falta('paciente_paquetes', 'titular_id',
  'BIGINT UNSIGNED NULL COMMENT "Si está puesta, este paquete es del titular y esta persona solo consume de él"');

DROP PROCEDURE agregar_columna_si_falta;

-- Buscar a los que consumen del paquete de alguien es la consulta que hace
-- el panel cada vez que dibuja el avance del paquete.
SET @ix := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paciente_paquetes'
               AND INDEX_NAME = 'ix_paquete_titular');
SET @sql := IF(@ix = 0,
  'CREATE INDEX ix_paquete_titular ON paciente_paquetes (titular_id, estado)',
  'SELECT "el índice ya estaba" AS nota');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paciente_paquetes'
               AND CONSTRAINT_NAME = 'fk_paquete_titular');
SET @sql := IF(@fk = 0,
  'ALTER TABLE paciente_paquetes ADD CONSTRAINT fk_paquete_titular
     FOREIGN KEY (titular_id) REFERENCES pacientes (persona_id) ON DELETE SET NULL',
  'SELECT "la clave foránea ya estaba" AS nota');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Que nadie sea titular de sí mismo se comprueba en la capa PHP y no aquí:
-- MySQL no admite una columna en un CHECK y a la vez en una clave foránea
-- con ON DELETE SET NULL (error 3823). Entre las dos cosas vale más la
-- clave foránea, que es la que impide apuntar a un paciente que no existe.
