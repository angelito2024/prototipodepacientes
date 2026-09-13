-- =====================================================================
--  08 · Cómo se le paga al profesional, y la atención en colegio
--
--  Hasta ahora se guardaba lo que se quedaba EL CENTRO por sesión
--  (monto_centro_sesion). Luis lo trabaja al revés: al profesional se le
--  paga un monto fijo según cómo atendió y el resto de la tarifa queda para
--  el centro. Con tarifas distintas por paciente (45, 47, 50) las dos
--  formas NO dan lo mismo.
--
--  El turno en colegio es un caso aparte: ahí no hay tarifa de paciente que
--  repartir, sino dos montos acordados — uno para el profesional y otro
--  para el centro. Por eso lleva sus propias dos columnas.
--
--  La columna vieja se conserva: las fichas que nunca fijaron su tarifa
--  siguen calculándose como antes.
--
--  Se añade también el detalle de los pagos ya hechos, con QUÉ atenciones
--  cubrió cada uno. Antes solo se guardaba una fecha de corte, y una
--  atención registrada el mismo día del pago se perdía para siempre.
--  Va como JSON, igual que préstamos y juntas: son pocos, se leen enteros
--  y la forma todavía puede cambiar.
--
--  Ejecutar después de 01..07. Cada cambio se comprueba por separado, así
--  que se puede repetir y se puede ejecutar sobre una base que ya tenga
--  parte de esto aplicado.
-- =====================================================================

USE centro_psicologico;

-- --- Columnas nuevas de la ficha del profesional ---------------------
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

CALL agregar_columna_si_falta('profesionales', 'pago_virtual',
  'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER monto_centro_sesion');
CALL agregar_columna_si_falta('profesionales', 'pago_presencial',
  'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pago_virtual');
-- Turno en colegio: los dos montos se acuerdan, no salen de una tarifa.
CALL agregar_columna_si_falta('profesionales', 'pago_colegio',
  'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pago_presencial');
CALL agregar_columna_si_falta('profesionales', 'centro_colegio',
  'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pago_colegio');
CALL agregar_columna_si_falta('profesionales', 'liquidaciones_json',
  'JSON NULL AFTER ultima_liquidacion');

DROP PROCEDURE agregar_columna_si_falta;

-- --- 'Colegio' como modalidad ----------------------------------------
-- Sin esto MySQL recorta el valor al guardarlo y la atención en colegio
-- volvería a figurar como presencial, que se paga distinto.
ALTER TABLE pacientes
  MODIFY COLUMN modalidad_default
    ENUM('Presencial','Virtual','Domicilio','Colegio') NOT NULL DEFAULT 'Presencial';
ALTER TABLE citas
  MODIFY COLUMN modalidad
    ENUM('Presencial','Virtual','Domicilio','Colegio') NOT NULL DEFAULT 'Presencial';
