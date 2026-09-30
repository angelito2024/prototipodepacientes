-- =====================================================================
--  16 · Biblioteca de materiales de trabajo
--
--  Luis tiene el material en carpetas del escritorio: pictogramas,
--  manuales para padres, tarjetas de lenguaje, videos. Eso significa que
--  el material solo existe en esa computadora, que nadie más del equipo
--  sabe qué hay, y que lo que se le entregó a una familia no queda
--  anotado en ninguna parte.
--
--  Aquí el material queda en el sistema, con tres cosas que la carpeta
--  no puede dar:
--
--    · se le entrega al paciente por un enlace con vencimiento, igual
--      que una prueba, y queda escrito qué se entregó y cuándo;
--    · se ve si la familia lo abrió o no —que es lo que de verdad se
--      quiere saber cuando se manda una tarea para la casa—;
--    · cada material lleva de dónde salió y qué se puede hacer con él,
--      porque no todo lo que sirve en consulta se puede repartir.
--
--  Sobre esto último: el archivo en sí NO viaja en el repositorio. Son
--  materiales de terceros; el repositorio guarda el programa que los
--  ordena, no el contenido.
--
--  Ejecutar después de 01..15. Se puede repetir sin peligro.
-- =====================================================================

USE centro_psicologico;

-- --- El catálogo ----------------------------------------------------
CREATE TABLE IF NOT EXISTS materiales (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid         VARCHAR(40)  NOT NULL,
  titulo      VARCHAR(200) NOT NULL,
  -- La colección es el pack ('Niños con autismo (TEA)'); la sección, la
  -- carpeta de adentro ('Pictogramas de rutinas diarias').
  coleccion   VARCHAR(120) NOT NULL DEFAULT 'General',
  seccion     VARCHAR(160) NULL,
  descripcion TEXT         NULL,
  archivo_id  BIGINT UNSIGNED NULL,
  formato     ENUM('pdf','video','imagen','presentacion','hoja','audio','enlace','otro')
              NOT NULL DEFAULT 'otro',
  edad_min    TINYINT UNSIGNED NULL,
  edad_max    TINYINT UNSIGNED NULL,
  -- Para quién es. Un manual de manejo conductual es para los padres;
  -- una hoja de registro es para el profesional.
  destino     ENUM('paciente','familia','docente','profesional')
              NOT NULL DEFAULT 'familia',
  -- Qué se puede hacer con él. Esta columna existe porque es la
  -- diferencia entre usar material ajeno en consulta —que es normal— y
  -- repartirlo o venderlo, que no le corresponde al centro:
  --   propio      hecho en el centro: se entrega y, si se quiere, se cobra
  --   libre       de distribución libre: se puede entregar tal cual
  --   solo_sesion se usa en consulta, no se reparte
  --   reservado   material con derechos, solo para el profesional
  licencia    ENUM('propio','libre','solo_sesion','reservado')
              NOT NULL DEFAULT 'solo_sesion',
  origen      VARCHAR(255) NULL,      -- autor, institución o página de donde salió
  orden       SMALLINT NOT NULL DEFAULT 0,
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_material_uid (uid),
  KEY ix_material_coleccion (coleccion, seccion, orden),
  KEY ix_material_licencia (licencia),
  CONSTRAINT fk_material_archivo FOREIGN KEY (archivo_id) REFERENCES archivos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Cada vez que se le entrega material a alguien -------------------
CREATE TABLE IF NOT EXISTS material_entrega (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid            VARCHAR(40)  NOT NULL,
  paciente_id    BIGINT UNSIGNED NOT NULL,
  profesional_id BIGINT UNSIGNED NULL,
  -- Misma idea que en las pruebas: con esta clave se entra sin usuario,
  -- así que se guarda su hash y no el valor.
  token_hash     CHAR(64)     NOT NULL,
  titulo         VARCHAR(200) NULL,    -- 'Material para trabajar en casa'
  mensaje        TEXT         NULL,    -- la indicación del profesional
  via            ENUM('enlace','whatsapp','correo','en_consulta','impreso')
                 NOT NULL DEFAULT 'enlace',
  entregada_en   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en      DATETIME     NULL,
  abierta_en     DATETIME     NULL,    -- la primera vez que la familia entró
  n_aperturas    INT UNSIGNED NOT NULL DEFAULT 0,
  anulada_en     DATETIME     NULL,
  creada_por     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entrega_uid (uid),
  UNIQUE KEY uq_entrega_token (token_hash),
  KEY ix_entrega_paciente (paciente_id, entregada_en),
  CONSTRAINT fk_entrega_paciente
    FOREIGN KEY (paciente_id) REFERENCES pacientes (persona_id) ON DELETE CASCADE,
  CONSTRAINT fk_entrega_profesional
    FOREIGN KEY (profesional_id) REFERENCES profesionales (persona_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Qué material llevaba cada entrega ------------------------------
CREATE TABLE IF NOT EXISTS material_entrega_item (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entrega_id      BIGINT UNSIGNED NOT NULL,
  material_id     BIGINT UNSIGNED NOT NULL,
  descargas       INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_descarga DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entrega_material (entrega_id, material_id),
  CONSTRAINT fk_ei_entrega  FOREIGN KEY (entrega_id)  REFERENCES material_entrega (id) ON DELETE CASCADE,
  CONSTRAINT fk_ei_material FOREIGN KEY (material_id) REFERENCES materiales (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Quién abrió qué y desde dónde ----------------------------------
CREATE TABLE IF NOT EXISTS material_acceso (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entrega_id  BIGINT UNSIGNED NOT NULL,
  material_id BIGINT UNSIGNED NULL,
  momento     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accion      VARCHAR(40) NOT NULL,     -- abrir, descargar
  ip          VARCHAR(45) NULL,
  agente      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY ix_macceso_entrega (entrega_id, momento),
  CONSTRAINT fk_macceso_entrega FOREIGN KEY (entrega_id) REFERENCES material_entrega (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
