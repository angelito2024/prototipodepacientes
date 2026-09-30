-- =====================================================================
--  21 · El precio del paquete se pacta entero, no por sesión
--
--  Luis no vende sesiones sueltas cuando arma un paquete: le dice al
--  paciente "son S/290 por seis sesiones". El sistema, en cambio, solo
--  guardaba el precio POR SESIÓN y multiplicaba.
--
--  Con seis sesiones eso no cierra: 290 / 6 = 48.333…, y la columna
--  guarda dos decimales. Puesto 48.40 el paquete valía S/290.40; puesto
--  48.33, S/289.98. En pantalla aparecía un saldo de siete céntimos que
--  no existe en ninguna conversación con el paciente.
--
--  Por eso el total pactado se guarda tal cual. Cuando está puesto, manda
--  él: el precio por sesión se deriva para lo que lo necesita (saber qué
--  sesiones cubre un pago), pero la deuda es la del acuerdo.
--
--  Ejecutar después de 01..20. Se puede repetir sin peligro.
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

CALL agregar_columna_si_falta('paciente_paquetes', 'precio_total',
  'DECIMAL(12,2) NULL COMMENT "Precio pactado del paquete completo. Si está puesto, manda sobre precio_sesion × sesiones"');

DROP PROCEDURE agregar_columna_si_falta;

-- A los paquetes que ya existen se les pone el total que el sistema venía
-- calculando: así nada cambia de valor por la migración, y de aquí en
-- adelante el número que vale es el que se pactó.
UPDATE paciente_paquetes
   SET precio_total = ROUND(sesiones_totales * precio_sesion, 2)
 WHERE precio_total IS NULL
   AND tipo_facturacion = 'Paquete'
   AND sesiones_totales > 0
   AND precio_sesion > 0;
