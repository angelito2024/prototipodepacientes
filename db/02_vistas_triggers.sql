-- =====================================================================
--  VISTAS, TRIGGERS Y PROCEDIMIENTOS
--  Traslada a la base de datos los cálculos que hoy recorren TODO el
--  array en JavaScript en cada renderizado (patientBalance,
--  centerIncomeByProfessional, professionalEarningsSince, ...).
-- =====================================================================

USE centro_psicologico;

-- ---------------------------------------------------------------------
-- VISTA: saldo por paquete  (reemplaza patientBalance())
-- ---------------------------------------------------------------------
-- Reproduce exactamente patientBalance() del panel:
--   Paquete    -> sesiones contratadas x precio (fijo desde el inicio)
--   Individual -> sesiones ya FACTURADAS x precio; el total solo crece
--                 cuando se registra un cobro, no se inventan sesiones.
-- `sesiones_por_citas` no entra en el cálculo: está para conciliar el
-- contador que mantiene la aplicación contra las citas realmente
-- completadas (ver la nota en paciente_paquetes).
CREATE OR REPLACE VIEW v_saldo_paquete AS
SELECT
  pq.id                       AS paquete_id,
  pq.paciente_id,
  per.nombre_completo         AS paciente,
  pq.tipo_facturacion,
  pq.estado,
  pq.fecha_inicio,
  pq.precio_sesion,
  pq.sesiones_totales,
  pq.sesiones_usadas,
  pq.sesiones_facturadas,
  COALESCE(ses.sesiones_por_citas, 0) AS sesiones_por_citas,
  CASE WHEN pq.tipo_facturacion = 'Paquete'
       THEN COALESCE(pq.sesiones_totales, 0) * pq.precio_sesion
       ELSE pq.sesiones_facturadas * pq.precio_sesion
  END                         AS total_a_pagar,
  COALESCE(pg.pagado, 0)      AS pagado,
  GREATEST(
    CASE WHEN pq.tipo_facturacion = 'Paquete'
         THEN COALESCE(pq.sesiones_totales, 0) * pq.precio_sesion
         ELSE pq.sesiones_facturadas * pq.precio_sesion
    END - COALESCE(pg.pagado, 0), 0
  )                           AS saldo,
  CASE
    WHEN CASE WHEN pq.tipo_facturacion = 'Paquete'
              THEN COALESCE(pq.sesiones_totales, 0) * pq.precio_sesion
              ELSE pq.sesiones_facturadas * pq.precio_sesion
         END <= 0 THEN 'Sin tarifa'
    WHEN COALESCE(pg.pagado, 0) <= 0 THEN 'Pendiente'
    WHEN COALESCE(pg.pagado, 0) >=
         CASE WHEN pq.tipo_facturacion = 'Paquete'
              THEN COALESCE(pq.sesiones_totales, 0) * pq.precio_sesion
              ELSE pq.sesiones_facturadas * pq.precio_sesion
         END THEN 'Completo'
    ELSE 'Parcial'
  END                         AS estado_pago
FROM paciente_paquetes pq
JOIN personas per ON per.id = pq.paciente_id
LEFT JOIN (
  SELECT paquete_id, COUNT(*) AS sesiones_por_citas
  FROM citas
  WHERE estado = 'Completada' AND eliminado_en IS NULL AND paquete_id IS NOT NULL
  GROUP BY paquete_id
) ses ON ses.paquete_id = pq.id
LEFT JOIN (
  SELECT paquete_id, SUM(monto) AS pagado
  FROM pagos
  WHERE anulado_en IS NULL AND paquete_id IS NOT NULL
  GROUP BY paquete_id
) pg ON pg.paquete_id = pq.id;

-- ---------------------------------------------------------------------
-- VISTA: ingreso del centro por profesional
-- (reemplaza centerIncomeByProfessional(): comisión + alquiler)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_ingreso_centro AS
SELECT
  origen, profesional_id, profesional, fecha, monto_centro
FROM (
  -- Comisión: el centro se queda un monto fijo de cada pago de paciente,
  -- nunca más de lo efectivamente cobrado.
  SELECT
    'Comisión'                  AS origen,
    pr.persona_id               AS profesional_id,
    per.nombre_completo         AS profesional,
    pg.fecha                    AS fecha,
    LEAST(pg.monto, pr.monto_centro_sesion) AS monto_centro
  FROM pagos pg
  JOIN pacientes pa     ON pa.persona_id = pg.paciente_id
  JOIN profesionales pr ON pr.persona_id = pa.profesional_id
  JOIN personas per     ON per.id = pr.persona_id
  WHERE pg.anulado_en IS NULL
    AND pr.modelo_pago = 'Comisión'

  UNION ALL

  -- Alquiler: el externo paga por usar el consultorio. No requiere que
  -- su paciente exista como ficha en el centro.
  SELECT
    'Alquiler'                  AS origen,
    pr.persona_id               AS profesional_id,
    per.nombre_completo         AS profesional,
    u.fecha                     AS fecha,
    u.monto                     AS monto_centro
  FROM usos_consultorio u
  JOIN profesionales pr ON pr.persona_id = u.profesional_id
  JOIN personas per     ON per.id = pr.persona_id
  WHERE pr.modelo_pago = 'Alquiler de espacio'
) t;

-- ---------------------------------------------------------------------
-- VISTA: deuda de alquiler pendiente por profesional
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_deuda_alquiler AS
SELECT
  u.profesional_id,
  per.nombre_completo AS profesional,
  COUNT(*)            AS usos_pendientes,
  SUM(u.monto)        AS saldo_pendiente,
  MIN(u.fecha)        AS deuda_mas_antigua
FROM usos_consultorio u
JOIN personas per ON per.id = u.profesional_id
WHERE u.cobrado = 0
GROUP BY u.profesional_id, per.nombre_completo;

-- ---------------------------------------------------------------------
-- VISTA: cumpleaños próximos (pacientes, profesionales, practicantes y
-- fechas del centro en una sola consulta). Usa las columnas generadas
-- dia_cumple / dia_mes, ambas indexadas.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_agenda_fechas AS
SELECT
  p.id                AS persona_id,
  p.nombre_completo   AS nombre,
  CASE
    WHEN pac.persona_id IS NOT NULL THEN 'Paciente'
    WHEN pro.persona_id IS NOT NULL THEN 'Profesional'
    WHEN prc.persona_id IS NOT NULL THEN 'Practicante'
    ELSE 'Persona'
  END                 AS tipo,
  p.dia_cumple        AS dia_mes,
  p.fecha_nacimiento,
  TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad,
  p.activo
FROM personas p
LEFT JOIN pacientes     pac ON pac.persona_id = p.id
LEFT JOIN profesionales pro ON pro.persona_id = p.id
LEFT JOIN practicantes  prc ON prc.persona_id = p.id
WHERE p.fecha_nacimiento IS NOT NULL AND p.eliminado_en IS NULL
UNION ALL
SELECT NULL, e.nombre, 'Festividad', e.dia_mes, e.fecha, NULL, 1
FROM eventos_calendario e;

-- ---------------------------------------------------------------------
-- VISTA: resultado financiero mensual (pestaña Finanzas)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_resultado_mensual AS
SELECT
  periodo,
  SUM(ingresos) AS ingresos,
  SUM(egresos)  AS egresos,
  SUM(ingresos) - SUM(egresos) AS utilidad
FROM (
  SELECT DATE_FORMAT(fecha, '%Y-%m') AS periodo, SUM(monto) AS ingresos, 0 AS egresos
  FROM pagos WHERE anulado_en IS NULL
  GROUP BY periodo
  UNION ALL
  SELECT DATE_FORMAT(fecha_pago, '%Y-%m'), 0, SUM(monto)
  FROM gastos WHERE anulado_en IS NULL
  GROUP BY 1
) t
GROUP BY periodo;

-- ---------------------------------------------------------------------
-- VISTA: agenda del día con todo lo que la pantalla necesita, sin que
-- la aplicación tenga que cruzar cuatro colecciones en memoria.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_agenda_citas AS
SELECT
  c.id, c.inicio, c.fin, c.duracion_min, c.estado, c.modalidad,
  c.paciente_id,    pac.nombre_completo AS paciente,    pac.telefono AS paciente_telefono,
  c.profesional_id, pro.nombre_completo AS profesional,
  co.nombre  AS consultorio,
  s.nombre   AS sede,
  c.enlace, c.direccion, c.referencia, c.recordatorio_enviado
FROM citas c
JOIN personas pac ON pac.id = c.paciente_id
JOIN personas pro ON pro.id = c.profesional_id
LEFT JOIN consultorios co ON co.id = c.consultorio_id
LEFT JOIN sedes s         ON s.id = co.sede_id
WHERE c.eliminado_en IS NULL;


-- ---------------------------------------------------------------------
-- VISTA: citas cruzadas. Como la capa de compatibilidad puede omitir el
-- trigger (el panel permite forzar un cruce), esta vista deja ver los
-- solapamientos que quedaron en los datos. Debería estar siempre vacía.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_citas_solapadas AS
SELECT
  a.id AS cita_a, b.id AS cita_b,
  a.inicio AS inicio_a, a.fin AS fin_a,
  b.inicio AS inicio_b, b.fin AS fin_b,
  CASE WHEN a.consultorio_id <=> b.consultorio_id AND a.consultorio_id IS NOT NULL
       THEN 'Consultorio' ELSE 'Profesional' END AS motivo,
  pa.nombre_completo AS paciente_a,
  pb.nombre_completo AS paciente_b
FROM citas a
JOIN citas b
  ON b.id > a.id
 AND a.inicio < b.fin AND a.fin > b.inicio
 AND (a.profesional_id = b.profesional_id
      OR (a.consultorio_id IS NOT NULL AND a.consultorio_id = b.consultorio_id))
JOIN personas pa ON pa.id = a.paciente_id
JOIN personas pb ON pb.id = b.paciente_id
WHERE a.estado NOT IN ('Cancelada','Reprogramada')
  AND b.estado NOT IN ('Cancelada','Reprogramada')
  AND a.eliminado_en IS NULL AND b.eliminado_en IS NULL;



-- ---------------------------------------------------------------------
-- VISTA: estado de cada taller — cuánto se cobró y cuánto falta.
-- Reemplaza a tallerCobrado() / tallerPorCobrar(), que recorrían el
-- array de participantes en memoria cada vez que se pintaba la tabla.
--
-- Lo cobrado se lee distinto según el modo, porque el negocio es distinto:
-- en 'participante' es la suma de los inscritos que pagaron; en 'grupal'
-- es lo que ya abonó la entidad contratante.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_taller_resumen AS
SELECT
  t.id,
  t.uid,
  t.nombre,
  t.tipo,
  t.modo_cobro,
  t.estado,
  t.fecha_inicio,
  t.cupo,
  pro.nombre_completo AS profesional,
  (SELECT COUNT(*) FROM taller_participantes p WHERE p.taller_id = t.id) AS inscritos,
  (SELECT COUNT(*) FROM taller_participantes p WHERE p.taller_id = t.id AND p.asistio = 1) AS asistieron,
  (SELECT COUNT(*) FROM taller_participantes p WHERE p.taller_id = t.id AND p.diploma_entregado = 1) AS diplomas,
  (SELECT COUNT(*) FROM taller_sesiones s WHERE s.taller_id = t.id) AS fechas,
  CASE t.modo_cobro
    WHEN 'grupal' THEN t.monto_cobrado
    ELSE COALESCE((SELECT SUM(p.monto) FROM taller_participantes p
                    WHERE p.taller_id = t.id AND p.pagado = 1), 0)
  END AS cobrado,
  CASE t.modo_cobro
    WHEN 'grupal' THEN GREATEST(COALESCE(t.monto_acordado, 0) - t.monto_cobrado, 0)
    ELSE COALESCE((SELECT SUM(p.monto) FROM taller_participantes p
                    WHERE p.taller_id = t.id AND p.pagado = 0), 0)
  END AS por_cobrar
FROM talleres t
LEFT JOIN personas pro ON pro.id = t.profesional_id
WHERE t.eliminado_en IS NULL;


-- ---------------------------------------------------------------------
-- VISTA: cuentas personales del mes en curso, por usuario.
--
-- Un fijo pesa todos los meses hasta que se dé de baja; un variable pesa
-- solo en el mes de su fecha. Mezclarlos era lo que hacía que una compra
-- de marzo siguiera restando del margen en setiembre.
--
-- No se cruza con `pagos` ni con `gastos`: este dinero no es del centro y
-- no debe aparecer en v_resultado_mensual.
-- ---------------------------------------------------------------------
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
  pg.fecha_pago,
  (pg.id IS NOT NULL) AS pagado
FROM personal_movimientos m
LEFT JOIN personal_pagos pg
       ON pg.movimiento_id = m.id
      AND pg.periodo = DATE_FORMAT(CURDATE(), '%Y-%m')
WHERE m.clase = 'fijo'
   OR DATE_FORMAT(m.fecha, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m');

-- =====================================================================
-- TRIGGERS
-- =====================================================================
DELIMITER $$

-- Solapamiento REAL de consultorio. El prototipo solo detecta choques
-- con la misma hora exacta de inicio: 10:00-11:00 y 10:30-11:30 pasan.
CREATE TRIGGER trg_cita_solape_ins
BEFORE INSERT ON citas
FOR EACH ROW
BEGIN
  DECLARE v_fin DATETIME;
  DECLARE v_n INT DEFAULT 0;
  SET v_fin = NEW.inicio + INTERVAL NEW.duracion_min MINUTE;

  -- @centro_permitir_solape = 1 desactiva la validación solo en esa sesión.
  -- Lo usan la migración y la capa de compatibilidad, porque el panel
  -- permite forzar un cruce a propósito después de confirmarlo, y ya hay
  -- datos históricos con cruces. Las escrituras normales sí se validan.
  IF COALESCE(@centro_permitir_solape, 0) = 0 AND NEW.estado <> 'Cancelada' THEN

    IF NEW.consultorio_id IS NOT NULL THEN
      SELECT COUNT(*) INTO v_n FROM citas
       WHERE consultorio_id = NEW.consultorio_id
         AND estado NOT IN ('Cancelada','Reprogramada')
         AND eliminado_en IS NULL
         AND inicio < v_fin AND fin > NEW.inicio;
      IF v_n > 0 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'El consultorio ya está ocupado en ese rango horario.';
      END IF;
    END IF;

    -- Un profesional tampoco puede estar en dos sesiones a la vez.
    SELECT COUNT(*) INTO v_n FROM citas
     WHERE profesional_id = NEW.profesional_id
       AND estado NOT IN ('Cancelada','Reprogramada')
       AND eliminado_en IS NULL
       AND inicio < v_fin AND fin > NEW.inicio;
    IF v_n > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El profesional ya tiene otra cita en ese rango horario.';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_cita_solape_upd
BEFORE UPDATE ON citas
FOR EACH ROW
BEGIN
  DECLARE v_fin DATETIME;
  DECLARE v_n INT DEFAULT 0;
  SET v_fin = NEW.inicio + INTERVAL NEW.duracion_min MINUTE;

  IF COALESCE(@centro_permitir_solape, 0) = 0
     AND (NEW.inicio <> OLD.inicio OR NEW.duracion_min <> OLD.duracion_min
      OR NOT (NEW.consultorio_id <=> OLD.consultorio_id)
      OR NEW.profesional_id <> OLD.profesional_id)
     AND NEW.estado NOT IN ('Cancelada','Reprogramada') THEN

    IF NEW.consultorio_id IS NOT NULL THEN
      SELECT COUNT(*) INTO v_n FROM citas
       WHERE id <> NEW.id
         AND consultorio_id = NEW.consultorio_id
         AND estado NOT IN ('Cancelada','Reprogramada')
         AND eliminado_en IS NULL
         AND inicio < v_fin AND fin > NEW.inicio;
      IF v_n > 0 THEN
        SIGNAL SQLSTATE '45000'
          SET MESSAGE_TEXT = 'El consultorio ya está ocupado en ese rango horario.';
      END IF;
    END IF;

    SELECT COUNT(*) INTO v_n FROM citas
     WHERE id <> NEW.id
       AND profesional_id = NEW.profesional_id
       AND estado NOT IN ('Cancelada','Reprogramada')
       AND eliminado_en IS NULL
       AND inicio < v_fin AND fin > NEW.inicio;
    IF v_n > 0 THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'El profesional ya tiene otra cita en ese rango horario.';
    END IF;
  END IF;
END$$

-- Registra automáticamente el cambio de estado / reprogramación.
CREATE TRIGGER trg_cita_historial
AFTER UPDATE ON citas
FOR EACH ROW
BEGIN
  IF NEW.estado <> OLD.estado OR NEW.inicio <> OLD.inicio THEN
    INSERT INTO cita_historial (cita_id, estado_antes, estado_despues, inicio_antes, inicio_despues)
    VALUES (NEW.id, OLD.estado, NEW.estado, OLD.inicio, NEW.inicio);
  END IF;
END$$

-- Una nota de evolución firmada es inmutable (requisito de historia clínica).
CREATE TRIGGER trg_evolucion_bloqueo
BEFORE UPDATE ON hc_evoluciones
FOR EACH ROW
BEGIN
  IF OLD.firmado_en IS NOT NULL AND (
       NOT (NEW.subjetivo <=> OLD.subjetivo) OR
       NOT (NEW.objetivo  <=> OLD.objetivo)  OR
       NOT (NEW.analisis  <=> OLD.analisis)  OR
       NOT (NEW.plan      <=> OLD.plan)
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'La nota de evolución ya fue firmada y no puede modificarse. Agregue una nota nueva.';
  END IF;
END$$

-- Deriva 'MM-DD' desde la fecha completa cuando esta existe. Si solo se
-- conoce el día y el mes (datos que vienen del prototipo), se respeta el
-- valor que envía la aplicación.
CREATE TRIGGER trg_persona_cumple_ins
BEFORE INSERT ON personas
FOR EACH ROW
BEGIN
  IF NEW.fecha_nacimiento IS NOT NULL THEN
    SET NEW.dia_cumple = DATE_FORMAT(NEW.fecha_nacimiento, '%m-%d');
  END IF;
END$$

CREATE TRIGGER trg_persona_cumple_upd
BEFORE UPDATE ON personas
FOR EACH ROW
BEGIN
  IF NEW.fecha_nacimiento IS NOT NULL THEN
    SET NEW.dia_cumple = DATE_FORMAT(NEW.fecha_nacimiento, '%m-%d');
  END IF;
END$$

CREATE TRIGGER trg_evento_dia_ins
BEFORE INSERT ON eventos_calendario
FOR EACH ROW
BEGIN
  IF NEW.fecha IS NOT NULL THEN
    SET NEW.dia_mes = DATE_FORMAT(NEW.fecha, '%m-%d');
  END IF;
END$$

CREATE TRIGGER trg_evento_dia_upd
BEFORE UPDATE ON eventos_calendario
FOR EACH ROW
BEGIN
  IF NEW.fecha IS NOT NULL THEN
    SET NEW.dia_mes = DATE_FORMAT(NEW.fecha, '%m-%d');
  END IF;
END$$

-- Marca el uso de consultorio como cobrado al asociarle un pago.
CREATE TRIGGER trg_uso_cobrado
BEFORE UPDATE ON usos_consultorio
FOR EACH ROW
BEGIN
  IF NEW.pago_id IS NOT NULL AND OLD.pago_id IS NULL THEN
    SET NEW.cobrado = 1;
  END IF;
END$$


-- La fecha de inicio del taller es siempre la primera de sus sesiones.
-- Se mantiene aquí y no en la aplicación: si se agrega una fecha anterior
-- desde cualquier sitio, el orden de la lista sigue siendo correcto.
CREATE TRIGGER trg_taller_sesion_ins
AFTER INSERT ON taller_sesiones
FOR EACH ROW
BEGIN
  UPDATE talleres
     SET fecha_inicio = (SELECT MIN(fecha) FROM taller_sesiones WHERE taller_id = NEW.taller_id)
   WHERE id = NEW.taller_id;
END$$

CREATE TRIGGER trg_taller_sesion_upd
AFTER UPDATE ON taller_sesiones
FOR EACH ROW
BEGIN
  UPDATE talleres
     SET fecha_inicio = (SELECT MIN(fecha) FROM taller_sesiones WHERE taller_id = NEW.taller_id)
   WHERE id = NEW.taller_id;
END$$

CREATE TRIGGER trg_taller_sesion_del
AFTER DELETE ON taller_sesiones
FOR EACH ROW
BEGIN
  UPDATE talleres
     SET fecha_inicio = (SELECT MIN(fecha) FROM taller_sesiones WHERE taller_id = OLD.taller_id)
   WHERE id = OLD.taller_id;
END$$

-- Un participante marcado como pagado tiene que tener un monto. En modo
-- grupal no paga nadie, así que ahí no aplica.
CREATE TRIGGER trg_tpart_monto_ins
BEFORE INSERT ON taller_participantes
FOR EACH ROW
BEGIN
  IF NEW.pagado = 1 AND NEW.monto <= 0
     AND (SELECT modo_cobro FROM talleres WHERE id = NEW.taller_id) = 'participante' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Un participante marcado como pagado necesita un monto mayor a cero.';
  END IF;
END$$

DELIMITER ;


-- =====================================================================
-- PROCEDIMIENTO: cerrar el paquete actual y abrir uno nuevo
-- (reemplaza startNewPackage(), que hoy muta y reinicia contadores)
-- =====================================================================
DELIMITER $$
CREATE PROCEDURE sp_nuevo_paquete(
  IN p_paciente_id BIGINT UNSIGNED,
  IN p_servicio_id BIGINT UNSIGNED,
  IN p_sesiones    SMALLINT UNSIGNED,
  IN p_precio      DECIMAL(12,2)
)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;
    UPDATE paciente_paquetes
       SET estado = 'Cerrado', fecha_cierre = CURDATE()
     WHERE paciente_id = p_paciente_id AND estado = 'Activo';

    INSERT INTO paciente_paquetes
      (paciente_id, servicio_id, tipo_facturacion, sesiones_totales, precio_sesion, fecha_inicio, estado)
    VALUES
      (p_paciente_id, p_servicio_id,
       IF(p_sesiones IS NULL OR p_sesiones = 0, 'Individual', 'Paquete'),
       NULLIF(p_sesiones, 0), p_precio, CURDATE(), 'Activo');
  COMMIT;
END$$
DELIMITER ;
