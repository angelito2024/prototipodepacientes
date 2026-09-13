-- =====================================================================
--  ACTUALIZACIÓN — PAGOS PARCIALES EN CUENTAS PERSONALES
--
--  Un gasto fijo del mes dejaba de ser "pagado sí o no": ahora se puede ir
--  pagando por partes y saber cuánto falta, que es la pregunta real cuando
--  el dinero no alcanza para pagarlo todo de una vez.
--
--  Cada pago pasa a ser una fila con su fecha y su importe:
--   · `personal_pagos.monto` — cuánto se dio en ese abono.
--   · `personal_pagos.uid`   — el id que usa el panel, para reconciliar.
--   · se retira el único por (movimiento, periodo): un mes puede tener
--     varios abonos.
--
--  Lo ya registrado no se pierde: cada mes que estaba marcado como pagado
--  se convierte en un abono por el monto completo, que es lo que
--  significaba.
--
--  Es reejecutable. Ejecutar después de 05.
-- =====================================================================

SET NAMES utf8mb4;
USE centro_psicologico;

DROP PROCEDURE IF EXISTS _actualizar_personal_pagos;
DELIMITER $$
CREATE PROCEDURE _actualizar_personal_pagos()
BEGIN
  -- 1) El importe de cada abono.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personal_pagos'
       AND COLUMN_NAME = 'monto'
  ) THEN
    -- Entra permitiendo NULL para poder rellenar lo que ya existe; después
    -- se cierra a NOT NULL. Un ALTER directo a NOT NULL pondría ceros, que
    -- es justo lo que no queremos: un mes marcado significaba pagado entero.
    ALTER TABLE personal_pagos ADD COLUMN monto DECIMAL(12,2) NULL AFTER fecha_pago;

    UPDATE personal_pagos p
      JOIN personal_movimientos m ON m.id = p.movimiento_id
       SET p.monto = m.monto
     WHERE p.monto IS NULL;

    ALTER TABLE personal_pagos MODIFY COLUMN monto DECIMAL(12,2) NOT NULL;
  END IF;

  -- 2) El id del panel, para reconciliar abono por abono.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personal_pagos'
       AND COLUMN_NAME = 'uid'
  ) THEN
    ALTER TABLE personal_pagos ADD COLUMN uid VARCHAR(32) NULL AFTER id;
    ALTER TABLE personal_pagos ADD UNIQUE KEY uq_personal_pago_uid (uid);
  END IF;

  -- 3) Un mes puede tener varios abonos: se retira el único por mes.
  --
  -- El índice nuevo se crea ANTES de quitar el viejo. `uq_personal_pago`
  -- empieza por movimiento_id, así que es el que sostiene la clave foránea
  -- hacia personal_movimientos: si se borra primero, MySQL lo impide con
  -- "needed in a foreign key constraint". Con el reemplazo ya en su sitio,
  -- la foránea se apoya en él y el viejo sale sin problema.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personal_pagos'
       AND INDEX_NAME = 'idx_ppago_periodo'
  ) THEN
    ALTER TABLE personal_pagos ADD INDEX idx_ppago_periodo (movimiento_id, periodo, fecha_pago);
  END IF;

  IF EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personal_pagos'
       AND INDEX_NAME = 'uq_personal_pago'
  ) THEN
    ALTER TABLE personal_pagos DROP INDEX uq_personal_pago;
  END IF;

  -- 4) Un abono de cero o negativo no es un abono.
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'personal_pagos'
       AND CONSTRAINT_NAME = 'chk_ppago_monto'
  ) THEN
    ALTER TABLE personal_pagos ADD CONSTRAINT chk_ppago_monto CHECK (monto > 0);
  END IF;
END$$
DELIMITER ;

CALL _actualizar_personal_pagos();
DROP PROCEDURE _actualizar_personal_pagos;


-- La vista del mes pasa a decir cuánto se pagó y cuánto falta, no solo si
-- está pagado.
CREATE OR REPLACE VIEW v_personal_mes AS
SELECT
  m.usuario_id,
  DATE_FORMAT(CURDATE(), '%Y-%m') AS periodo,
  m.id,
  m.uid,
  m.tipo,
  m.clase,
  m.nombre,
  m.categoria,
  m.monto,
  m.dia_vencimiento,
  m.fecha,
  COALESCE(p.pagado, 0) AS pagado,
  GREATEST(m.monto - COALESCE(p.pagado, 0), 0) AS saldo,
  p.ultimo_pago AS fecha_pago,
  (COALESCE(p.pagado, 0) >= m.monto) AS saldado,
  COALESCE(p.abonos, 0) AS abonos
FROM personal_movimientos m
LEFT JOIN (
  SELECT movimiento_id, periodo,
         SUM(monto)      AS pagado,
         COUNT(*)        AS abonos,
         MAX(fecha_pago) AS ultimo_pago
    FROM personal_pagos
   GROUP BY movimiento_id, periodo
) p ON p.movimiento_id = m.id
   AND p.periodo = DATE_FORMAT(CURDATE(), '%Y-%m')
WHERE m.clase = 'fijo'
   OR DATE_FORMAT(m.fecha, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m');
