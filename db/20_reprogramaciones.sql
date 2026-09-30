-- =====================================================================
--  20 · El historial de reprogramaciones de una cita
--
--  Hasta ahora, reprogramar una cita pisaba la fecha: la nueva entraba y
--  la anterior desaparecía. La agenda quedaba bien, pero se perdía algo
--  que hace falta:
--
--    · cuántas veces se le movió la sesión a un paciente;
--    · a qué fechas, para poder decírselo cuando pregunta;
--    · y, sobre todo, QUIÉN pidió el cambio. No es lo mismo que el
--      paciente haya pedido mover su hora tres veces a que el centro
--      haya tenido que moverla porque la psicóloga se enfermó. Con una
--      sola columna de fecha las dos cosas se veían igual.
--
--  Cada cambio es una fila. La cita conserva su fecha vigente; acá queda
--  de dónde venía, adónde fue y por qué.
--
--  Ejecutar después de 01..19. Se puede repetir sin peligro.
-- =====================================================================

USE centro_psicologico;

CREATE TABLE IF NOT EXISTS cita_reprogramaciones (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cita_id        BIGINT UNSIGNED NOT NULL,
  -- De dónde venía y adónde se movió.
  fecha_anterior DATETIME NOT NULL,
  fecha_nueva    DATETIME NOT NULL,
  modalidad_anterior ENUM('Presencial','Virtual','Domicilio','Colegio') NULL,
  sala_anterior  VARCHAR(120) NULL,
  -- Quién lo pidió. Es la columna que da sentido a todo lo demás: el
  -- paciente que mueve su hora cuatro veces y el centro que se la movió
  -- por un feriado no merecen la misma conversación.
  a_pedido_de    ENUM('paciente','centro','profesional','otro')
                 NOT NULL DEFAULT 'paciente',
  motivo         VARCHAR(255) NULL,
  registrado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  registrado_por BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_reprog_cita (cita_id, registrado_en),
  CONSTRAINT fk_reprog_cita
    FOREIGN KEY (cita_id) REFERENCES citas (id) ON DELETE CASCADE,
  CONSTRAINT fk_reprog_usuario
    FOREIGN KEY (registrado_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cuántas veces se le movió la sesión a cada paciente y por pedido de
-- quién. Es la consulta que se hace antes de hablar con él.
CREATE OR REPLACE VIEW v_reprogramaciones_paciente AS
SELECT per.id                AS paciente_id,
       per.nombre_completo   AS paciente,
       COUNT(*)              AS veces,
       SUM(r.a_pedido_de = 'paciente')    AS a_pedido_suyo,
       SUM(r.a_pedido_de <> 'paciente')   AS por_el_centro,
       MAX(r.registrado_en)  AS ultima
  FROM cita_reprogramaciones r
  JOIN citas c   ON c.id = r.cita_id
  JOIN personas per ON per.id = c.paciente_id
 WHERE c.eliminado_en IS NULL
 GROUP BY per.id, per.nombre_completo;
