-- =====================================================================
--  24 · Qué es cada sesión, y de qué es cada paquete
--
--  Dos preguntas que el sistema no sabía contestar.
--
--  La primera: mirando la agenda de un paciente, cuál de esas nueve
--  sesiones fue la consulta y cuáles fueron terapia. Todas se veían
--  iguales. Importa para leer la historia clínica, para el informe que
--  pide el colegio y para saber qué se le cobró a cada una: una consulta
--  y una sesión de terapia no valen lo mismo.
--
--  La segunda: un paciente no compra "un paquete" y ya. Uno de ellos compró
--  cinco sesiones de EVALUACIÓN en agosto, después diez de TERAPIA, y
--  está por tomar un tercero. Son tres acuerdos distintos, con precios
--  distintos y propósitos distintos. El sistema guardaba los paquetes
--  cerrados pero sin decir de qué eran, así que el historial era una
--  lista de números sin sentido.
--
--  Sobre el relleno de abajo: a las citas que ya existen se les pone
--  'Terapia', salvo la primera de cada paciente, que queda como
--  'Consulta'. Es lo que pasa en el centro —primero se evalúa qué
--  necesita y después se trabaja—, pero es una suposición: cualquiera
--  se corrige desde la cita.
--
--  Ejecutar después de 01..23. Se puede repetir sin peligro.
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

-- --- Qué es cada sesión ----------------------------------------------
CALL agregar_columna_si_falta('citas', 'tipo_sesion',
  "ENUM('Consulta','Evaluacion','Terapia','Seguimiento','Devolucion','Taller','Otro')
   NOT NULL DEFAULT 'Terapia'
   COMMENT 'Sin tildes a proposito: el cargador de SQL las rompe. La pantalla las pone. Consulta = la primera, donde se ve qué necesita. Devolución = entrega de resultados'");

-- --- De qué es cada paquete ------------------------------------------
CALL agregar_columna_si_falta('paciente_paquetes', 'concepto',
  "VARCHAR(60) NULL COMMENT 'Evaluación, Terapia, Terapia de lenguaje… para qué se compró'");

-- El historial guardaba la fecha de cierre en las dos fechas, así que un
-- paquete cerrado parecía haber empezado y terminado el mismo día. Con
-- esta columna el paquete de agosto puede decir que fue de agosto.
CALL agregar_columna_si_falta('paciente_paquetes', 'nota_historial',
  'VARCHAR(255) NULL COMMENT "Para anotar algo del paquete al cerrarlo"');

DROP PROCEDURE agregar_columna_si_falta;

-- --- Lo que ya estaba -------------------------------------------------
-- Primero todo a 'Terapia' (es el default de la columna, pero las filas
-- que ya existían pudieron tomar otro valor si la columna ya estaba).
UPDATE citas SET tipo_sesion = 'Terapia'
 WHERE tipo_sesion IS NULL;

-- Y la primera cita de cada paciente pasa a 'Consulta'. Se toma la más
-- antigua por fecha; si hubo dos el mismo día, la de menor id.
UPDATE citas c
  JOIN (
    SELECT MIN(c2.id) AS id
      FROM citas c2
      JOIN (SELECT paciente_id, MIN(inicio) AS primera
              FROM citas WHERE eliminado_en IS NULL
             GROUP BY paciente_id) pr
        ON pr.paciente_id = c2.paciente_id AND pr.primera = c2.inicio
     WHERE c2.eliminado_en IS NULL
     GROUP BY c2.paciente_id
  ) q ON q.id = c.id
   SET c.tipo_sesion = 'Consulta';

-- A los paquetes que ya existen se les pone 'Terapia' como concepto:
-- es lo que son casi todos, y el que no lo sea se corrige en su ficha.
UPDATE paciente_paquetes
   SET concepto = 'Terapia'
 WHERE concepto IS NULL
   AND tipo_facturacion = 'Paquete';
