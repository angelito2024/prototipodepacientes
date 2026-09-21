-- =====================================================================
--  12 · Pruebas psicológicas aplicadas al paciente
--
--  Hasta ahora cada prueba se aplicaba en un Excel suelto, con el nombre
--  del paciente en el título del archivo. Eso tiene tres problemas: el
--  archivo se pierde, el resultado no llega a la historia clínica, y el
--  Excel deja cerrar el protocolo a medias (el caso que motivó esto salió
--  con 23 ítems sin responder y aun así arrojó perfil).
--
--  Aquí la prueba se aplica desde el sistema, el paciente la responde por
--  un enlace con clave propia, y el resultado queda pegado a su historia.
--
--  Dos tablas:
--    pruebas           el catálogo: qué preguntas tiene y cómo se corrige
--    prueba_aplicacion cada vez que se le toma a un paciente
--
--  El contenido de las pruebas (ítems, clave y baremos) NO viaja en el
--  repositorio: el MCMI-IV y el ICE BarOn son material con derechos de
--  autor. Se cargan una vez desde el Excel de Luis con el script
--  `pruebas/cargar.php` y viven solo en esta base.
--
--  Ejecutar después de 01..11. Se puede repetir sin peligro.
-- =====================================================================

USE centro_psicologico;

-- --- Catálogo de pruebas -------------------------------------------
CREATE TABLE IF NOT EXISTS pruebas (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  codigo         VARCHAR(40)  NOT NULL,          -- 'mcmi4', 'ice_baron'
  nombre         VARCHAR(180) NOT NULL,
  siglas         VARCHAR(40)  NULL,
  autor          VARCHAR(180) NULL,
  descripcion    TEXT         NULL,
  edad_minima    TINYINT UNSIGNED NULL,          -- a quién se le puede aplicar
  edad_maxima    TINYINT UNSIGNED NULL,
  minutos_aprox  SMALLINT UNSIGNED NULL,         -- cuánto suele demorar
  n_items        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Ítems, clave de corrección y baremos. Es un documento porque cada
  -- prueba se corrige distinto y normalizarlo obligaría a migrar tablas
  -- cada vez que se agregue una.
  definicion     JSON         NOT NULL,
  activa         TINYINT(1)   NOT NULL DEFAULT 1,
  creada_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizada_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pruebas_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Cada aplicación a un paciente ---------------------------------
CREATE TABLE IF NOT EXISTS prueba_aplicacion (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid            VARCHAR(40)  NOT NULL,          -- id que usa el panel
  prueba_id      BIGINT UNSIGNED NOT NULL,
  paciente_id    BIGINT UNSIGNED NOT NULL,
  profesional_id BIGINT UNSIGNED NULL,           -- quién la indicó
  -- Clave del enlace que se le manda al paciente. Es larga a propósito:
  -- con ella se entra sin usuario ni contraseña, así que tiene que ser
  -- imposible de adivinar. Se guarda su hash, no el valor.
  token_hash     CHAR(64)     NOT NULL,
  estado         ENUM('pendiente','en_curso','terminada','anulada')
                 NOT NULL DEFAULT 'pendiente',
  asignada_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  abierta_en     DATETIME     NULL,              -- cuándo empezó a responder
  terminada_en   DATETIME     NULL,
  expira_en      DATETIME     NULL,              -- después de esto el enlace no sirve
  -- Lo que va respondiendo, guardado a medida que avanza para que no
  -- pierda el trabajo si se corta la luz o se le acaba la batería.
  respuestas     JSON         NULL,
  n_respondidos  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Puntajes, escalas e índices de validez, ya corregidos por el servidor.
  resultado      JSON         NULL,
  -- Cuánto demoró: un protocolo de 195 ítems resuelto en tres minutos no
  -- se leyó, y eso cambia la lectura del perfil.
  segundos_total INT UNSIGNED NULL,
  observaciones  TEXT         NULL,
  creada_por     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aplicacion_uid (uid),
  UNIQUE KEY uq_aplicacion_token (token_hash),
  KEY ix_aplicacion_paciente (paciente_id, asignada_en),
  KEY ix_aplicacion_estado (estado, expira_en),
  CONSTRAINT fk_aplicacion_prueba
    FOREIGN KEY (prueba_id) REFERENCES pruebas (id) ON DELETE RESTRICT,
  CONSTRAINT fk_aplicacion_paciente
    FOREIGN KEY (paciente_id) REFERENCES pacientes (persona_id) ON DELETE CASCADE,
  CONSTRAINT fk_aplicacion_profesional
    FOREIGN KEY (profesional_id) REFERENCES profesionales (persona_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Quién abrió el enlace y desde dónde ----------------------------
-- Si un resultado se cuestiona, hay que poder decir cuándo se abrió, con
-- qué equipo y desde qué dirección. Sin esto, un protocolo respondido por
-- otra persona es indistinguible de uno legítimo.
CREATE TABLE IF NOT EXISTS prueba_acceso (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  aplicacion_id BIGINT UNSIGNED NOT NULL,
  momento       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accion        VARCHAR(40)  NOT NULL,           -- abrir, guardar, terminar
  ip            VARCHAR(45)  NULL,
  agente        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_acceso_aplicacion (aplicacion_id, momento),
  CONSTRAINT fk_acceso_aplicacion
    FOREIGN KEY (aplicacion_id) REFERENCES prueba_aplicacion (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
