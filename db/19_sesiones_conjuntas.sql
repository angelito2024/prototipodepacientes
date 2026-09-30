-- =====================================================================
--  19 · Sesiones a las que viene más de una persona
--
--  Sigue el caso de la pareja del paquete compartido, y aparece también
--  con las familias: hay sesiones donde vienen todos a la vez, y otras
--  donde cada uno viene solo, en su día y a su hora. Todas salen del
--  mismo paquete.
--
--  La cita ya tenía un paciente. Lo que faltaba era poder decir quién
--  MÁS estuvo en esa misma sesión. De ahí esta tabla.
--
--  Dos consecuencias, y las dos importan:
--
--    · Una sesión conjunta descuenta UNA sesión del paquete, no una por
--      cabeza. Es una sesión: la que se dio. Por eso los participantes
--      van en una tabla aparte y no como citas separadas, que es como
--      se contarían de más.
--
--    · Lo que se trabajó en esa sesión tiene que llegar a la historia
--      clínica de cada uno de los que estuvo. Una sesión familiar no es
--      un dato de la madre nada más.
--
--  Ejecutar después de 01..18. Se puede repetir sin peligro.
-- =====================================================================

USE centro_psicologico;

CREATE TABLE IF NOT EXISTS cita_participantes (
  cita_id     BIGINT UNSIGNED NOT NULL,
  paciente_id BIGINT UNSIGNED NOT NULL,
  creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cita_id, paciente_id),
  KEY ix_participante_paciente (paciente_id),
  CONSTRAINT fk_participante_cita
    FOREIGN KEY (cita_id) REFERENCES citas (id) ON DELETE CASCADE,
  CONSTRAINT fk_participante_paciente
    FOREIGN KEY (paciente_id) REFERENCES pacientes (persona_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién más estuvo en la sesión, para verlo desde la cita y desde la
-- historia de cada uno.
CREATE OR REPLACE VIEW v_sesiones_conjuntas AS
SELECT c.id            AS cita_id,
       c.uid           AS cita_uid,
       c.inicio,
       c.estado,
       tit.nombre_completo AS paciente_principal,
       COUNT(cp.paciente_id) + 1 AS personas,
       GROUP_CONCAT(otros.nombre_completo ORDER BY otros.nombre_completo SEPARATOR ' · ') AS acompanantes
  FROM citas c
  JOIN personas tit ON tit.id = c.paciente_id
  JOIN cita_participantes cp ON cp.cita_id = c.id
  JOIN personas otros ON otros.id = cp.paciente_id
 WHERE c.eliminado_en IS NULL
 GROUP BY c.id, c.uid, c.inicio, c.estado, tit.nombre_completo;
