-- =====================================================================
--  22 · El practicante llena su propia ficha, una sola vez
--
--  Hasta ahora Luis escribía a mano los datos de cada practicante:
--  nombre, DNI, teléfono, correo, universidad, fechas de prácticas y el
--  horario de la semana. Son doce campos por persona, copiados de un
--  WhatsApp, con el DNI mal tipeado cada tanto.
--
--  Lo que falta es lo mismo que ya resolvimos para las familias con los
--  materiales: un enlace con clave que se manda por WhatsApp, se abre
--  una vez y se cierra solo. Acá el que llena es el practicante, y lo
--  que llena entra derecho a su ficha.
--
--  Las reglas que hacen que esto no sea un agujero:
--
--    · el enlace vence, como el de un material o el de una prueba;
--    · sirve UNA vez: apenas se registra queda usado y deja de abrir,
--      así el mismo enlace reenviado no crea dos fichas;
--    · queda anotado si lo abrió y cuándo, que es lo que uno quiere
--      saber cuando mandó la invitación hace tres días;
--    · Luis lo puede anular antes de que lo usen;
--    · registrarse NO da acceso al sistema. El practicante llena su
--      ficha y nada más. La cuenta con usuario y clave es otra cosa, y
--      la da Luis aparte, cuando de verdad hace falta.
--
--  Ejecutar después de 01..21. Se puede repetir sin peligro.
-- =====================================================================

USE centro_psicologico;

CREATE TABLE IF NOT EXISTS practicante_invitacion (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid           VARCHAR(40) NOT NULL,
  -- El enlace se muestra una sola vez al generarlo. De la clave queda
  -- solo el hash: si alguien se lleva la base, no se lleva los enlaces.
  token_hash    CHAR(64)    NOT NULL,

  -- Para reconocer la invitación en la lista antes de que la usen:
  -- "se la mandé a Andrea el lunes". No es la ficha, es el recordatorio.
  referencia    VARCHAR(180) NULL,
  telefono      VARCHAR(30)  NULL,
  email         VARCHAR(150) NULL,
  mensaje       TEXT         NULL,

  creada_en     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en     DATETIME     NULL,
  abierta_en    DATETIME     NULL,
  n_aperturas   INT UNSIGNED NOT NULL DEFAULT 0,

  -- El momento en que se llenó y la persona que quedó creada. Con esto
  -- la invitación deja de servir y la ficha tiene de dónde salió.
  usada_en      DATETIME     NULL,
  persona_id    BIGINT UNSIGNED NULL,

  anulada_en    DATETIME     NULL,
  creada_por    BIGINT UNSIGNED NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_pinv_uid   (uid),
  UNIQUE KEY uq_pinv_token (token_hash),
  KEY ix_pinv_estado  (usada_en, anulada_en, expira_en),
  KEY ix_pinv_persona (persona_id),
  CONSTRAINT fk_pinv_persona FOREIGN KEY (persona_id)
    REFERENCES personas (id) ON DELETE SET NULL,
  CONSTRAINT fk_pinv_creada_por FOREIGN KEY (creada_por)
    REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- La universidad, que se le pregunta al practicante --------------
-- La columna ya existe en `practicantes` desde el esquema original, pero
-- el panel nunca la pedía. El formulario de registro sí, porque es el
-- dato que el practicante sabe de memoria y Luis tiene que averiguar.

-- --- Qué invitaciones hay, en una sola mirada ------------------------
CREATE OR REPLACE VIEW v_invitaciones_practicante AS
SELECT
  i.uid,
  i.referencia,
  i.telefono,
  i.email,
  i.creada_en,
  i.expira_en,
  i.abierta_en,
  i.n_aperturas,
  i.usada_en,
  p.nombre_completo AS practicante,
  CASE
    WHEN i.anulada_en IS NOT NULL                    THEN 'anulada'
    WHEN i.usada_en   IS NOT NULL                    THEN 'registrado'
    WHEN i.expira_en  IS NOT NULL
     AND i.expira_en  < NOW()                        THEN 'vencida'
    WHEN i.abierta_en IS NOT NULL                    THEN 'abierta'
    ELSE 'enviada'
  END AS estado
FROM practicante_invitacion i
LEFT JOIN personas p ON p.id = i.persona_id;
