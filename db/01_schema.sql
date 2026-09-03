-- =====================================================================
--  CENTRO PSICOLÓGICO — ESQUEMA RELACIONAL
--  Compatible con MariaDB 10.6+ y MySQL 8.0+ (Laragon trae MySQL 8.4.3)
--  Motor: InnoDB · Charset: utf8mb4 · Collation: utf8mb4_unicode_ci
--
--  Reemplaza el almacenamiento actual (localStorage con 14 colecciones
--  JSON + un blob "historia_<id>" por paciente).
--
--  Convenciones:
--   · PK   = BIGINT UNSIGNED AUTO_INCREMENT (join barato, índice compacto)
--   · uid  = VARCHAR(32) UNIQUE, conserva el id base36 del prototipo
--            para poder migrar el JSON sin perder relaciones. Se puede
--            eliminar una vez terminada la migración.
--   · Dinero = DECIMAL(12,2). NUNCA FLOAT/DOUBLE (errores de redondeo).
--   · Borrado lógico (eliminado_en) en todo lo clínico y financiero:
--     la historia clínica no se puede destruir (NTS 139-MINSA/2018 y
--     Ley 29733 de Protección de Datos Personales).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS centro_psicologico
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE centro_psicologico;


-- =====================================================================
-- 1. CONFIGURACIÓN Y CATÁLOGOS
-- =====================================================================

-- Reemplaza DB.centerInfo. Fila única forzada por CHECK.
CREATE TABLE centro_config (
  id                    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  razon_social          VARCHAR(200)  NOT NULL,
  nombre_comercial      VARCHAR(200)  NULL,
  ruc                   CHAR(11)      NULL,
  direccion             VARCHAR(255)  NULL,
  telefono              VARCHAR(30)   NULL,
  email                 VARCHAR(150)  NULL,
  lema                  VARCHAR(255)  NULL,
  medios_pago           TEXT          NULL,   -- texto libre mostrado en el recibo
  logo_archivo_id       BIGINT UNSIGNED NULL, -- FK -> archivos (saca el base64 del HTML)
  qr_yape_archivo_id    BIGINT UNSIGNED NULL,
  minutos_recordatorio  SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  horas_reprogramacion  SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  porcentaje_penalidad   DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  incluir_politica      TINYINT(1)    NOT NULL DEFAULT 1,
  -- ¿El panel pide usuario y clave al abrir? Sustituye al campo `enabled`
  -- del authConfig del prototipo. Las credenciales viven en `usuarios`,
  -- con hash; aquí solo se guarda si la pantalla de acceso está activa.
  login_requerido       TINYINT(1)    NOT NULL DEFAULT 0,
  moneda                CHAR(3)       NOT NULL DEFAULT 'PEN',
  zona_horaria          VARCHAR(64)   NOT NULL DEFAULT 'America/Lima',
  actualizado_en        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_config_fila_unica CHECK (id = 1)
) ENGINE=InnoDB;

-- Credenciales de terceros fuera de centro_config: no se exportan en backups
-- de negocio ni se muestran en pantallas administrativas.
CREATE TABLE integraciones (
  clave        VARCHAR(50)  NOT NULL,   -- 'apiperu_token', 'smtp_password', ...
  valor        VARBINARY(512) NULL,     -- guardar cifrado desde la app
  descripcion  VARCHAR(255) NULL,
  actualizado_en TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB;

-- Sedes y consultorios: reemplaza el ENUM fijo "Consultorio 1/2/3".
CREATE TABLE sedes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre     VARCHAR(120) NOT NULL,
  direccion  VARCHAR(255) NULL,
  telefono   VARCHAR(30)  NULL,
  activo     TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sede_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE consultorios (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sede_id       BIGINT UNSIGNED NOT NULL,
  nombre        VARCHAR(120) NOT NULL,
  descripcion   VARCHAR(255) NULL,   -- "Adultos", "Niños", "Mixto"
  capacidad     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_consultorio (sede_id, nombre),
  CONSTRAINT fk_consultorio_sede FOREIGN KEY (sede_id) REFERENCES sedes(id)
) ENGINE=InnoDB;

-- Catálogo CIE-10. Hoy son 79 códigos incrustados en el JS; la lista real
-- pasa los 14 000. Tabla propia + índice FULLTEXT para el buscador.
CREATE TABLE cie10_catalogo (
  codigo      VARCHAR(10)  NOT NULL,
  descripcion VARCHAR(500) NOT NULL,
  capitulo    VARCHAR(120) NULL,
  vigente     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (codigo),
  KEY idx_cie10_desc (descripcion(100)),
  FULLTEXT KEY ft_cie10 (codigo, descripcion)
) ENGINE=InnoDB;

-- Catálogos extensibles por el usuario (antes eran <option> fijos en el HTML).
CREATE TABLE categorias_gasto (
  id      SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre  VARCHAR(80) NOT NULL,
  activo  TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_gasto (nombre)
) ENGINE=InnoDB;

CREATE TABLE metodos_pago (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre         VARCHAR(60) NOT NULL,
  requiere_ref   TINYINT(1)  NOT NULL DEFAULT 0,  -- pide n° de operación
  activo         TINYINT(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_metodo_pago (nombre)
) ENGINE=InnoDB;


-- =====================================================================
-- 2. SEGURIDAD Y ACCESO
--    Reemplaza DB.authConfig {enabled, username, password} — que hoy
--    guarda UNA sola clave en texto plano dentro del almacenamiento.
-- =====================================================================

CREATE TABLE roles (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave       VARCHAR(40)  NOT NULL,   -- admin, recepcion, profesional, practicante
  nombre      VARCHAR(80)  NOT NULL,
  descripcion VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rol_clave (clave)
) ENGINE=InnoDB;

CREATE TABLE permisos (
  id     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clave  VARCHAR(60) NOT NULL,   -- pacientes.ver, historia.editar, finanzas.ver...
  modulo VARCHAR(40) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permiso_clave (clave)
) ENGINE=InnoDB;

CREATE TABLE rol_permisos (
  rol_id     SMALLINT UNSIGNED NOT NULL,
  permiso_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, permiso_id),
  CONSTRAINT fk_rp_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE,
  CONSTRAINT fk_rp_permiso FOREIGN KEY (permiso_id) REFERENCES permisos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE usuarios (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  persona_id      BIGINT UNSIGNED NULL,          -- un profesional que además inicia sesión
  usuario         VARCHAR(60)  NOT NULL,
  email           VARCHAR(150) NULL,
  password_hash   VARCHAR(255) NOT NULL,         -- password_hash() PHP / Argon2id
  nombre_completo VARCHAR(180) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  mfa_secreto     VARBINARY(255) NULL,
  ultimo_acceso   DATETIME     NULL,
  intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME     NULL,
  creado_en       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuario (usuario),
  UNIQUE KEY uq_usuario_email (email)
) ENGINE=InnoDB;

CREATE TABLE usuario_roles (
  usuario_id BIGINT UNSIGNED   NOT NULL,
  rol_id     SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (usuario_id, rol_id),
  CONSTRAINT fk_ur_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_rol     FOREIGN KEY (rol_id)     REFERENCES roles(id)    ON DELETE CASCADE
) ENGINE=InnoDB;


-- =====================================================================
-- 3. PERSONAS  (supertipo)
--
--    Decisión central del diseño. Hoy pacientes, profesionales y
--    practicantes son tres colecciones con los mismos campos base
--    (nombre, dni, teléfono, correo, cumpleaños, activo), y el registro
--    de asistencia los referencia con un par (personType, personId) que
--    NINGUNA base de datos puede validar.
--
--    Con un supertipo:
--      · asistencia.persona_id es una FK real,
--      · el DNI es único en todo el centro,
--      · un practicante que se titula pasa a profesional SIN duplicar
--        su ficha ni perder su historial (caso muy real en un centro),
--      · un profesional puede además ser paciente.
-- =====================================================================

CREATE TABLE personas (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               VARCHAR(32)  NULL,             -- id base36 heredado
  tipo_documento    ENUM('DNI','CE','PAS','RUC','SIN') NOT NULL DEFAULT 'DNI',
  documento         VARCHAR(20)  NULL,
  nombres           VARCHAR(120) NOT NULL,
  apellido_paterno  VARCHAR(80)  NULL,
  apellido_materno  VARCHAR(80)  NULL,
  -- Nombre completo derivado: evita desincronización y sirve para búsquedas.
  nombre_completo   VARCHAR(290) AS (
                      TRIM(CONCAT_WS(' ', nombres, apellido_paterno, apellido_materno))
                    ) STORED,
  sexo              ENUM('F','M','X') NULL,
  fecha_nacimiento  DATE         NULL,
  -- El prototipo guarda solo 'MM-DD' y DESCARTA el año que el usuario
  -- ya escribió. Aquí se guarda la fecha completa y el MM-DD se deriva
  -- e indexa para las alertas de cumpleaños.
  -- Columna real, no generada: el prototipo guarda solo 'MM-DD' (descarta el
  -- año que el usuario escribió), así que existen registros SIN fecha
  -- completa. El trigger trg_persona_cumple la deriva cuando sí la hay.
  dia_cumple        CHAR(5) NULL,
  telefono          VARCHAR(30)  NULL,
  telefono_alt      VARCHAR(30)  NULL,
  email             VARCHAR(150) NULL,
  direccion         VARCHAR(255) NULL,
  notas             TEXT         NULL,
  activo            TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  eliminado_en      DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_persona_uid (uid),
  UNIQUE KEY uq_persona_doc (tipo_documento, documento),
  KEY idx_persona_nombre (nombre_completo),
  KEY idx_persona_cumple (dia_cumple),
  KEY idx_persona_activo (activo, eliminado_en),
  FULLTEXT KEY ft_persona (nombre_completo)
) ENGINE=InnoDB;

ALTER TABLE usuarios
  ADD CONSTRAINT fk_usuario_persona FOREIGN KEY (persona_id) REFERENCES personas(id);

-- --- Subtipo: PACIENTE ------------------------------------------------
CREATE TABLE pacientes (
  persona_id         BIGINT UNSIGNED NOT NULL,      -- PK = FK (subtipo 1:1)
  codigo_historia    VARCHAR(20)  NULL,             -- N° de historia clínica del centro
  tipo_atencion      ENUM('Individual','Pareja','Familia','Colegio',
                          'Organizacional','Evaluación','Taller','Charla')
                     NOT NULL DEFAULT 'Individual',
  modalidad_default  ENUM('Presencial','Virtual','Domicilio') NOT NULL DEFAULT 'Presencial',
  profesional_id     BIGINT UNSIGNED NULL,          -- profesional tratante actual
  es_menor           TINYINT(1)   NOT NULL DEFAULT 0,
  enlace_default     VARCHAR(500) NULL,             -- Meet/Zoom habitual
  direccion_default  VARCHAR(255) NULL,             -- para modalidad Domicilio
  referencia_default VARCHAR(255) NULL,
  estado_informe     ENUM('No aplica','Pendiente','En revisión','Entregado')
                     NOT NULL DEFAULT 'No aplica',
  -- Respaldo manual del estado de pago. Se usa solo cuando no hay paquete
  -- con precio configurado y por tanto no hay saldo que calcular
  -- (evaluaciones puntuales, convenios con colegios, organizacional).
  estado_pago_manual ENUM('Pendiente','Parcial','Completo') NOT NULL DEFAULT 'Pendiente',
  fecha_limite_informe DATE NULL,
  fecha_alta         DATE NULL,                     -- alta terapéutica
  motivo_alta        VARCHAR(255) NULL,
  PRIMARY KEY (persona_id),
  KEY idx_paciente_prof (profesional_id),
  KEY idx_paciente_informe (estado_informe, fecha_limite_informe),
  UNIQUE KEY uq_paciente_codigo (codigo_historia),
  CONSTRAINT fk_paciente_persona FOREIGN KEY (persona_id) REFERENCES personas(id)
) ENGINE=InnoDB;

-- Acompañantes / apoderados. Antes: string multilínea, luego array JSON.
-- El apoderado legal de un menor es solo un acompañante con es_apoderado=1.
CREATE TABLE paciente_acompanantes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  paciente_id  BIGINT UNSIGNED NOT NULL,
  nombre       VARCHAR(180) NOT NULL,
  documento    VARCHAR(20)  NULL,
  parentesco   VARCHAR(60)  NULL,
  telefono     VARCHAR(30)  NULL,
  email        VARCHAR(150) NULL,
  es_apoderado TINYINT(1)   NOT NULL DEFAULT 0,
  creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_acomp_paciente (paciente_id),
  CONSTRAINT fk_acomp_paciente FOREIGN KEY (paciente_id)
    REFERENCES pacientes(persona_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --- Subtipo: PROFESIONAL --------------------------------------------
CREATE TABLE profesionales (
  persona_id          BIGINT UNSIGNED NOT NULL,
  titulo              VARCHAR(60)  NULL,   -- "Lic.", "Mg.", "Dr."
  carrera             VARCHAR(120) NULL,
  especialidad        VARCHAR(120) NULL,
  colegiatura         VARCHAR(40)  NULL,
  colegiatura_habil   TINYINT(1)   NOT NULL DEFAULT 1,
  colegiatura_esp     VARCHAR(40)  NULL,
  colegiatura_esp_habil TINYINT(1) NULL,
  -- Modelo económico: Comisión (el centro se queda un monto por sesión)
  -- vs Alquiler (el externo paga por usar el consultorio) vs Planilla.
  modelo_pago         ENUM('Comisión','Alquiler de espacio','Planilla','Honorarios')
                      NOT NULL DEFAULT 'Comisión',
  monto_centro_sesion DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  alquiler_modo       ENUM('Por paciente','Por dia','Mensual') NOT NULL DEFAULT 'Por paciente',
  alquiler_tarifa     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  fecha_ingreso       DATE NULL,
  horario_texto       VARCHAR(255) NULL,   -- nota libre de disponibilidad
  -- Saldo corriente de honorarios. La tabla liquidaciones_profesional
  -- guarda el detalle por periodo; estos campos son el acumulado vigente
  -- que muestra el panel.
  saldo_por_pagar     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  al_dia              TINYINT(1) NOT NULL DEFAULT 1,
  ultima_liquidacion  DATE NULL,
  PRIMARY KEY (persona_id),
  KEY idx_prof_modelo (modelo_pago),
  CONSTRAINT fk_prof_persona FOREIGN KEY (persona_id) REFERENCES personas(id)
) ENGINE=InnoDB;

ALTER TABLE pacientes
  ADD CONSTRAINT fk_paciente_prof FOREIGN KEY (profesional_id)
    REFERENCES profesionales(persona_id);

-- --- Subtipo: PRACTICANTE --------------------------------------------
CREATE TABLE practicantes (
  persona_id      BIGINT UNSIGNED NOT NULL,
  universidad     VARCHAR(150) NULL,
  supervisor_id   BIGINT UNSIGNED NULL,           -- profesional supervisor
  fecha_inicio    DATE NULL,
  fecha_fin       DATE NULL,
  horas_meta      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (persona_id),
  KEY idx_pract_supervisor (supervisor_id),
  CONSTRAINT fk_pract_persona    FOREIGN KEY (persona_id)    REFERENCES personas(id),
  CONSTRAINT fk_pract_supervisor FOREIGN KEY (supervisor_id) REFERENCES profesionales(persona_id)
) ENGINE=InnoDB;

-- --- Horarios (profesionales y practicantes comparten estructura) -----
-- Antes: objeto weeklySchedule {Lunes:{active,start,end}, ...} embebido.
-- Como filas se puede preguntar "¿quién atiende el martes a las 16:00?"
-- con un índice, en vez de recorrer todos los registros en JavaScript.
CREATE TABLE persona_horarios (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  persona_id   BIGINT UNSIGNED NOT NULL,
  rol          ENUM('Profesional','Practicante') NOT NULL,
  dia_semana   TINYINT UNSIGNED NOT NULL,   -- 1=Lunes ... 7=Domingo (ISO-8601)
  hora_inicio  TIME NOT NULL,
  hora_fin     TIME NOT NULL,
  sede_id      BIGINT UNSIGNED NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_horario (persona_id, rol, dia_semana, hora_inicio),
  KEY idx_horario_dia (dia_semana, hora_inicio, hora_fin),
  CONSTRAINT fk_horario_persona FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE CASCADE,
  CONSTRAINT fk_horario_sede    FOREIGN KEY (sede_id)    REFERENCES sedes(id),
  CONSTRAINT chk_horario_dia    CHECK (dia_semana BETWEEN 1 AND 7),
  CONSTRAINT chk_horario_rango  CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB;

-- Excepciones puntuales: vacaciones, día libre, horario especial.
CREATE TABLE persona_horario_excepciones (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  persona_id  BIGINT UNSIGNED NOT NULL,
  fecha       DATE NOT NULL,
  tipo        ENUM('Libre','Especial') NOT NULL DEFAULT 'Libre',
  hora_inicio TIME NULL,
  hora_fin    TIME NULL,
  nota        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_excepcion (persona_id, fecha),
  KEY idx_excepcion_fecha (fecha),
  CONSTRAINT fk_excepcion_persona FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE CASCADE,
  CONSTRAINT chk_excepcion_horas  CHECK (tipo = 'Libre' OR (hora_inicio IS NOT NULL AND hora_fin > hora_inicio))
) ENGINE=InnoDB;


-- =====================================================================
-- 4. SERVICIOS, TARIFAS Y PAQUETES
-- =====================================================================

-- El prototipo guarda el precio como TEXTO ("S/100", "A definir",
-- "Variable (ej. S/20 de S/50)"): imposible sumar, comparar o graficar.
CREATE TABLE servicios (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               VARCHAR(32)  NULL,
  nombre            VARCHAR(150) NOT NULL,
  descripcion       TEXT         NULL,
  es_paquete        TINYINT(1)   NOT NULL DEFAULT 0,
  sesiones_paquete  SMALLINT UNSIGNED NULL,
  precio            DECIMAL(12,2) NULL,          -- NULL = "a definir"
  -- El panel guarda precio y unidad como TEXTO libre ("S/100", "A definir",
  -- "Variable (ej. S/20 de S/50)", "por paciente atendido"). La columna
  -- numérica es la que usan finanzas y los reportes; estas dos conservan
  -- literalmente lo que escribió el usuario para no perder nada.
  precio_texto      VARCHAR(120) NULL,
  unidad_texto      VARCHAR(120) NULL,
  unidad            ENUM('Por sesión','Por paquete','Mensual','Por taller',
                         'Por paciente atendido','Por evento','Otro')
                    NOT NULL DEFAULT 'Por sesión',
  moneda            CHAR(3)      NOT NULL DEFAULT 'PEN',
  -- Precio por sesión derivado, para comparar paquetes contra sesión suelta.
  precio_por_sesion DECIMAL(12,2) AS (
                      CASE WHEN es_paquete = 1 AND sesiones_paquete > 0
                           THEN ROUND(precio / sesiones_paquete, 2) END
                    ) STORED,
  activo            TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_servicio_uid (uid),
  KEY idx_servicio_activo (activo, nombre),
  CONSTRAINT chk_servicio_paquete CHECK (es_paquete = 0 OR sesiones_paquete > 0)
) ENGINE=InnoDB;

-- Historial de precios: un cambio de tarifa NO debe reescribir lo ya cobrado.
CREATE TABLE servicio_tarifas (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  servicio_id  BIGINT UNSIGNED NOT NULL,
  precio       DECIMAL(12,2) NOT NULL,
  vigente_desde DATE NOT NULL,
  vigente_hasta DATE NULL,
  PRIMARY KEY (id),
  KEY idx_tarifa_vigencia (servicio_id, vigente_desde),
  CONSTRAINT fk_tarifa_servicio FOREIGN KEY (servicio_id) REFERENCES servicios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- PAQUETES DEL PACIENTE — reemplaza el bloque de campos sueltos
-- packageTotal / sessionsUsed / billedSessions / packageStartDate /
-- pricePerSession / paquetesHistorial[] que hoy vive dentro del paciente.
-- Cada paquete (o tramo de facturación individual) es una fila; el
-- paquete cerrado queda como historial sin ninguna mutación destructiva.
CREATE TABLE paciente_paquetes (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid               VARCHAR(32) NULL,
  paciente_id       BIGINT UNSIGNED NOT NULL,
  servicio_id       BIGINT UNSIGNED NULL,
  tipo_facturacion  ENUM('Paquete','Individual') NOT NULL DEFAULT 'Paquete',
  sesiones_totales  SMALLINT UNSIGNED NULL,       -- NULL cuando es Individual
  precio_sesion     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  fecha_inicio      DATE NOT NULL,
  fecha_cierre      DATE NULL,
  estado            ENUM('Activo','Cerrado','Anulado') NOT NULL DEFAULT 'Activo',
  -- Contadores que hoy mantiene la aplicación (sessionsUsed / billedSessions).
  -- Lo correcto es derivarlos de `citas`, y la vista v_saldo_paquete expone
  -- ambos valores para poder conciliarlos; pero el panel permite corregirlos
  -- a mano, así que la app sigue siendo su dueña hasta que eso cambie.
  sesiones_usadas      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sesiones_facturadas  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  notas             VARCHAR(255) NULL,
  creado_en         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Truco de índice parcial (MySQL/MariaDB no los tienen): la columna vale
  -- el id del paciente solo si el paquete está activo, así el UNIQUE
  -- garantiza "un único paquete activo por paciente" e ignora los cerrados.
  paciente_activo   BIGINT UNSIGNED AS (
                      CASE WHEN estado = 'Activo' THEN paciente_id END
                    ) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_paquete_uid (uid),
  UNIQUE KEY uq_paquete_activo (paciente_activo),
  KEY idx_paquete_paciente (paciente_id, fecha_inicio),
  CONSTRAINT fk_paquete_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(persona_id),
  CONSTRAINT fk_paquete_servicio FOREIGN KEY (servicio_id) REFERENCES servicios(id),
  CONSTRAINT chk_paquete_sesiones CHECK (tipo_facturacion = 'Individual' OR sesiones_totales > 0)
) ENGINE=InnoDB;


-- =====================================================================
-- 5. AGENDA
-- =====================================================================

CREATE TABLE citas (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid             VARCHAR(32)  NULL,
  paciente_id     BIGINT UNSIGNED NOT NULL,
  profesional_id  BIGINT UNSIGNED NOT NULL,
  paquete_id      BIGINT UNSIGNED NULL,   -- a qué paquete descuenta la sesión
  servicio_id     BIGINT UNSIGNED NULL,
  -- El prototipo guarda fecha y hora como dos strings y detecta cruces
  -- comparando la hora EXACTA: una cita 10:00-11:00 no choca con otra de
  -- 10:30. Con DATETIME + duración, el trigger de abajo detecta el
  -- solapamiento real.
  inicio          DATETIME NOT NULL,
  duracion_min    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  fin             DATETIME AS (inicio + INTERVAL duracion_min MINUTE) STORED,
  modalidad       ENUM('Presencial','Virtual','Domicilio') NOT NULL DEFAULT 'Presencial',
  consultorio_id  BIGINT UNSIGNED NULL,   -- obligatorio si es Presencial
  enlace          VARCHAR(500) NULL,      -- obligatorio si es Virtual
  direccion       VARCHAR(255) NULL,
  referencia      VARCHAR(255) NULL,
  estado          ENUM('Programada','Confirmada','Completada','Cancelada','Reprogramada','No asistió')
                  NOT NULL DEFAULT 'Programada',
  motivo_cancelacion VARCHAR(255) NULL,
  cita_origen_id  BIGINT UNSIGNED NULL,   -- si nació de una reprogramación
  recordatorio_enviado TINYINT(1) NOT NULL DEFAULT 0,
  recordatorio_en DATETIME NULL,
  cuenta_sesion   TINYINT(1) NOT NULL DEFAULT 0,  -- ya descontó del paquete
  creado_por      BIGINT UNSIGNED NULL,
  creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  eliminado_en    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cita_uid (uid),
  KEY idx_cita_inicio (inicio),
  KEY idx_cita_paciente (paciente_id, inicio),
  KEY idx_cita_prof (profesional_id, inicio),
  KEY idx_cita_consultorio (consultorio_id, inicio),
  KEY idx_cita_estado (estado, inicio),
  KEY idx_cita_recordatorio (recordatorio_enviado, inicio),
  CONSTRAINT fk_cita_paciente    FOREIGN KEY (paciente_id)    REFERENCES pacientes(persona_id),
  CONSTRAINT fk_cita_prof        FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id),
  CONSTRAINT fk_cita_paquete     FOREIGN KEY (paquete_id)     REFERENCES paciente_paquetes(id),
  CONSTRAINT fk_cita_servicio    FOREIGN KEY (servicio_id)    REFERENCES servicios(id),
  CONSTRAINT fk_cita_consultorio FOREIGN KEY (consultorio_id) REFERENCES consultorios(id),
  CONSTRAINT fk_cita_origen      FOREIGN KEY (cita_origen_id) REFERENCES citas(id),
  CONSTRAINT fk_cita_usuario     FOREIGN KEY (creado_por)     REFERENCES usuarios(id),
  CONSTRAINT chk_cita_duracion   CHECK (duracion_min BETWEEN 5 AND 600)
  -- Nota: NO se exige consultorio para las citas presenciales. El panel
  -- permite agendar una sesión presencial y asignar el consultorio después,
  -- y una restricción que rechaza datos reales existentes no es integridad:
  -- es una migración que falla. La validación queda en la aplicación.
) ENGINE=InnoDB;

-- Bitácora de cambios de estado de la cita (quién reprogramó y cuándo).
CREATE TABLE cita_historial (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cita_id       BIGINT UNSIGNED NOT NULL,
  estado_antes  VARCHAR(20) NULL,
  estado_despues VARCHAR(20) NOT NULL,
  inicio_antes  DATETIME NULL,
  inicio_despues DATETIME NULL,
  motivo        VARCHAR(255) NULL,
  usuario_id    BIGINT UNSIGNED NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cita_hist (cita_id, creado_en),
  CONSTRAINT fk_citahist_cita    FOREIGN KEY (cita_id)    REFERENCES citas(id) ON DELETE CASCADE,
  CONSTRAINT fk_citahist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Fechas especiales del centro (feriados, aniversarios). Antes: 'MM-DD'.
CREATE TABLE eventos_calendario (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid         VARCHAR(32)  NULL,
  nombre      VARCHAR(150) NOT NULL,
  fecha       DATE NULL,   -- NULL cuando solo se conoce el día y el mes
  anual       TINYINT(1) NOT NULL DEFAULT 1,   -- se repite cada año
  tipo        ENUM('Festividad','Feriado','Institucional','Otro') NOT NULL DEFAULT 'Festividad',
  dia_mes     CHAR(5) NULL,   -- derivado de `fecha` por trigger cuando existe
  PRIMARY KEY (id),
  UNIQUE KEY uq_evento_uid (uid),
  KEY idx_evento_dia (dia_mes),
  KEY idx_evento_fecha (fecha),
  CONSTRAINT chk_evento_fecha CHECK (fecha IS NOT NULL OR dia_mes IS NOT NULL)
) ENGINE=InnoDB;


-- =====================================================================
-- 6. HISTORIA CLÍNICA
--    Hoy es un blob JSON por paciente ("historia_<id>") que se reescribe
--    ENTERO en cada nota de evolución. Con 200 notas y adjuntos en
--    base64, cada guardado mueve megabytes y una escritura concurrente
--    pisa a la otra. Aquí cada elemento es una fila.
-- =====================================================================

CREATE TABLE historias_clinicas (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  paciente_id        BIGINT UNSIGNED NOT NULL,
  lugar_nacimiento   VARCHAR(150) NULL,
  grado_instruccion  VARCHAR(120) NULL,
  grado_instruccion_otro VARCHAR(150) NULL,   -- texto cuando se elige "Otro"
  ocupacion          VARCHAR(150) NULL,
  con_quien_vive     VARCHAR(255) NULL,
  informante         VARCHAR(180) NULL,
  fecha_apertura     DATE NOT NULL DEFAULT (CURRENT_DATE),
  actualizado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_historia_paciente (paciente_id),
  CONSTRAINT fk_historia_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(persona_id)
) ENGINE=InnoDB;

-- EPISODIOS. En el prototipo el episodio "actual" son campos sueltos de
-- la historia y "archivarlo" los copia a un array y los borra. Aquí el
-- episodio actual es simplemente la fila con fecha_cierre IS NULL:
-- no hay copia, no hay borrado, no se pierde nada.
CREATE TABLE hc_episodios (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  historia_id             BIGINT UNSIGNED NOT NULL,
  fecha_inicio            DATE NOT NULL,
  fecha_cierre            DATE NULL,
  motivo_consulta         TEXT NULL,
  anamnesis               MEDIUMTEXT NULL,
  antecedentes_personales TEXT NULL,
  antecedentes_familiares TEXT NULL,
  antecedentes_sociales   TEXT NULL,
  -- Examen mental (antes objeto anidado examenMental)
  em_orientacion          VARCHAR(120) NULL,
  em_apariencia           TEXT NULL,
  em_afecto               VARCHAR(60) NULL,
  em_juicio               VARCHAR(60) NULL,
  em_riesgo               VARCHAR(120) NULL,
  impresion_diagnostica   TEXT NULL,
  plan_tratamiento        TEXT NULL,
  profesional_id          BIGINT UNSIGNED NULL,
  creado_en               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  historia_abierta        BIGINT UNSIGNED AS (
                            CASE WHEN fecha_cierre IS NULL THEN historia_id END
                          ) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_episodio_abierto (historia_abierta),  -- un solo episodio abierto
  KEY idx_episodio_historia (historia_id, fecha_inicio),
  -- Sin ON DELETE CASCADE: MySQL lo prohíbe sobre la columna base de una
  -- columna generada STORED (historia_id -> historia_abierta). Además, la
  -- historia clínica no debe poder borrarse en cascada.
  CONSTRAINT fk_episodio_historia FOREIGN KEY (historia_id) REFERENCES historias_clinicas(id),
  CONSTRAINT fk_episodio_prof     FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id)
) ENGINE=InnoDB;

CREATE TABLE hc_diagnosticos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  historia_id   BIGINT UNSIGNED NOT NULL,
  episodio_id   BIGINT UNSIGNED NULL,
  codigo_cie10  VARCHAR(10)  NULL,        -- FK al catálogo
  descripcion   VARCHAR(500) NOT NULL,    -- copia congelada: el catálogo cambia
  tipo          ENUM('Presuntivo','Definitivo','Diferencial','Descartado')
                NOT NULL DEFAULT 'Presuntivo',
  fecha         DATE NOT NULL,
  profesional_id BIGINT UNSIGNED NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_diag_historia (historia_id, fecha),
  KEY idx_diag_codigo (codigo_cie10),
  CONSTRAINT fk_diag_historia FOREIGN KEY (historia_id)  REFERENCES historias_clinicas(id) ON DELETE CASCADE,
  CONSTRAINT fk_diag_episodio FOREIGN KEY (episodio_id)  REFERENCES hc_episodios(id) ON DELETE SET NULL,
  CONSTRAINT fk_diag_cie10    FOREIGN KEY (codigo_cie10) REFERENCES cie10_catalogo(codigo),
  CONSTRAINT fk_diag_prof     FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id)
) ENGINE=InnoDB;

CREATE TABLE hc_pruebas (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  historia_id   BIGINT UNSIGNED NOT NULL,
  episodio_id   BIGINT UNSIGNED NULL,
  nombre        VARCHAR(200) NOT NULL,
  fecha         DATE NULL,
  resultado     TEXT NULL,
  puntaje       VARCHAR(60) NULL,
  archivo_id    BIGINT UNSIGNED NULL,       -- protocolo escaneado
  profesional_id BIGINT UNSIGNED NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prueba_historia (historia_id, fecha),
  CONSTRAINT fk_prueba_historia FOREIGN KEY (historia_id) REFERENCES historias_clinicas(id) ON DELETE CASCADE,
  CONSTRAINT fk_prueba_episodio FOREIGN KEY (episodio_id) REFERENCES hc_episodios(id) ON DELETE SET NULL,
  CONSTRAINT fk_prueba_prof     FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id)
) ENGINE=InnoDB;

-- Notas de evolución en formato SOAP. Es la tabla que más crece
-- (1 fila por sesión atendida) y la de mayor peso legal.
CREATE TABLE hc_evoluciones (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  historia_id   BIGINT UNSIGNED NOT NULL,
  episodio_id   BIGINT UNSIGNED NULL,
  cita_id       BIGINT UNSIGNED NULL,       -- vincula la nota con la sesión
  fecha         DATE NOT NULL,
  subjetivo     TEXT NULL,
  objetivo      TEXT NULL,
  analisis      TEXT NULL,
  plan          TEXT NULL,
  profesional_id BIGINT UNSIGNED NULL,
  -- Una nota firmada queda inmutable (ver trigger trg_evolucion_bloqueo).
  firmado_en    DATETIME NULL,
  firmado_por   BIGINT UNSIGNED NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_evolucion_cita (cita_id),
  KEY idx_evo_historia (historia_id, fecha),
  KEY idx_evo_episodio (episodio_id, fecha),
  CONSTRAINT fk_evo_historia FOREIGN KEY (historia_id) REFERENCES historias_clinicas(id) ON DELETE CASCADE,
  CONSTRAINT fk_evo_episodio FOREIGN KEY (episodio_id) REFERENCES hc_episodios(id) ON DELETE SET NULL,
  CONSTRAINT fk_evo_cita     FOREIGN KEY (cita_id)     REFERENCES citas(id),
  CONSTRAINT fk_evo_prof     FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id),
  CONSTRAINT fk_evo_firmante FOREIGN KEY (firmado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Informes psicológicos. Antes: 4 campos sueltos dentro del blob, con
-- lo que solo cabía UN informe por paciente en toda su vida.
CREATE TABLE informes (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  historia_id   BIGINT UNSIGNED NOT NULL,
  episodio_id   BIGINT UNSIGNED NULL,
  titulo        VARCHAR(200) NOT NULL DEFAULT 'Informe psicológico',
  contenido     MEDIUMTEXT NULL,
  estado        ENUM('Borrador','En revisión','Aprobado','Entregado') NOT NULL DEFAULT 'Borrador',
  firmante_id   BIGINT UNSIGNED NULL,
  fecha_firma   DATE NULL,
  fecha_entrega DATE NULL,
  archivo_id    BIGINT UNSIGNED NULL,      -- PDF final generado
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_informe_historia (historia_id, creado_en),
  KEY idx_informe_estado (estado),
  CONSTRAINT fk_informe_historia FOREIGN KEY (historia_id) REFERENCES historias_clinicas(id) ON DELETE CASCADE,
  CONSTRAINT fk_informe_episodio FOREIGN KEY (episodio_id) REFERENCES hc_episodios(id) ON DELETE SET NULL,
  CONSTRAINT fk_informe_firmante FOREIGN KEY (firmante_id) REFERENCES profesionales(persona_id)
) ENGINE=InnoDB;


-- =====================================================================
-- 7. ARCHIVOS
--    Punto crítico de escalabilidad: hoy los adjuntos se guardan como
--    data-URL base64 DENTRO del JSON de la historia (+33 % de tamaño) y
--    se releen enteros en cada render. Aquí la BD guarda solo metadatos;
--    el binario va al disco (storage/) o a S3, y sha256 evita duplicados.
-- =====================================================================

CREATE TABLE archivos (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid           CHAR(36) NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  mime           VARCHAR(120) NULL,
  tamano_bytes   BIGINT UNSIGNED NULL,
  sha256         CHAR(64) NULL,
  ruta_relativa  VARCHAR(500) NULL,     -- 'storage/2026/09/ab12....pdf'
  es_enlace      TINYINT(1) NOT NULL DEFAULT 0,
  url_externa    VARCHAR(1000) NULL,    -- Google Drive u otro
  subido_por     BIGINT UNSIGNED NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  eliminado_en   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_archivo_uuid (uuid),
  KEY idx_archivo_sha (sha256),
  CONSTRAINT fk_archivo_usuario FOREIGN KEY (subido_por) REFERENCES usuarios(id),
  CONSTRAINT chk_archivo_destino CHECK (
    (es_enlace = 0 AND ruta_relativa IS NOT NULL) OR
    (es_enlace = 1 AND url_externa   IS NOT NULL)
  )
) ENGINE=InnoDB;

ALTER TABLE centro_config
  ADD CONSTRAINT fk_config_logo FOREIGN KEY (logo_archivo_id)    REFERENCES archivos(id),
  ADD CONSTRAINT fk_config_qr   FOREIGN KEY (qr_yape_archivo_id) REFERENCES archivos(id);
ALTER TABLE hc_pruebas
  ADD CONSTRAINT fk_prueba_archivo FOREIGN KEY (archivo_id) REFERENCES archivos(id);
ALTER TABLE informes
  ADD CONSTRAINT fk_informe_archivo FOREIGN KEY (archivo_id) REFERENCES archivos(id);

-- Adjuntos de la historia clínica (consentimientos, informes externos...).
CREATE TABLE hc_adjuntos (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  historia_id BIGINT UNSIGNED NOT NULL,
  episodio_id BIGINT UNSIGNED NULL,
  archivo_id  BIGINT UNSIGNED NOT NULL,
  categoria   ENUM('Consentimiento informado','Informe psicológico',
                   'Resultado de prueba','Documento de identidad','Otro')
              NOT NULL DEFAULT 'Otro',
  descripcion VARCHAR(255) NULL,
  creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_adjunto_historia (historia_id, categoria),
  CONSTRAINT fk_adjunto_historia FOREIGN KEY (historia_id) REFERENCES historias_clinicas(id) ON DELETE CASCADE,
  CONSTRAINT fk_adjunto_episodio FOREIGN KEY (episodio_id) REFERENCES hc_episodios(id) ON DELETE SET NULL,
  CONSTRAINT fk_adjunto_archivo  FOREIGN KEY (archivo_id)  REFERENCES archivos(id)
) ENGINE=InnoDB;


-- =====================================================================
-- 8. PRÁCTICAS PRE-PROFESIONALES
-- =====================================================================

CREATE TABLE practicante_actividades (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  practicante_id BIGINT UNSIGNED NOT NULL,
  descripcion   VARCHAR(500) NOT NULL,
  fecha         DATE NULL,
  turno         ENUM('Mañana','Tarde','Noche') NULL,
  hora          TIME NULL,
  lugar         VARCHAR(150) NULL,
  horas         DECIMAL(5,2) NULL,        -- horas acreditadas
  estado        ENUM('Pendiente','Completada','Observada') NOT NULL DEFAULT 'Pendiente',
  archivo_id    BIGINT UNSIGNED NULL,     -- entregable
  revisado_por  BIGINT UNSIGNED NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_act_practicante (practicante_id, fecha),
  KEY idx_act_estado (estado),
  CONSTRAINT fk_act_practicante FOREIGN KEY (practicante_id) REFERENCES practicantes(persona_id) ON DELETE CASCADE,
  CONSTRAINT fk_act_archivo     FOREIGN KEY (archivo_id)     REFERENCES archivos(id),
  CONSTRAINT fk_act_revisor     FOREIGN KEY (revisado_por)   REFERENCES profesionales(persona_id)
) ENGINE=InnoDB;


-- =====================================================================
-- 9. ASISTENCIA
--    Gracias al supertipo `personas`, persona_id es una FK real y no el
--    par (personType, personId) sin validación del prototipo. Además ya
--    no hace falta duplicar el nombre en cada registro (personName).
-- =====================================================================

CREATE TABLE asistencia_registros (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid          VARCHAR(32) NULL,
  persona_id   BIGINT UNSIGNED NOT NULL,
  rol          ENUM('Paciente','Profesional','Practicante') NOT NULL,
  fecha        DATE NOT NULL,
  tipo         ENUM('Puntual','Tardanza','Falta','Falta justificada',
                    'Permiso de salud','Permiso familiar','Otro permiso')
               NOT NULL DEFAULT 'Puntual',
  minutos_tardanza SMALLINT UNSIGNED NULL,
  hora_entrada TIME NULL,
  hora_salida  TIME NULL,
  cita_id      BIGINT UNSIGNED NULL,      -- si corresponde a una sesión
  nota         VARCHAR(500) NULL,
  registrado_por BIGINT UNSIGNED NULL,
  creado_en    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asistencia_uid (uid),
  UNIQUE KEY uq_asistencia_dia (persona_id, rol, fecha, tipo),
  KEY idx_asist_persona (persona_id, fecha),
  KEY idx_asist_fecha (fecha, rol),
  CONSTRAINT fk_asist_persona FOREIGN KEY (persona_id)     REFERENCES personas(id) ON DELETE CASCADE,
  CONSTRAINT fk_asist_cita    FOREIGN KEY (cita_id)        REFERENCES citas(id) ON DELETE SET NULL,
  CONSTRAINT fk_asist_usuario FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;


-- =====================================================================
-- 10. FINANZAS
-- =====================================================================

-- INGRESOS. El prototipo mete en una sola colección tres cosas distintas
-- (pago de paciente, pago de colegio, alquiler de consultorio) y obliga a
-- inventar una ficha de paciente para cobrarle a un profesional externo.
-- Aquí `categoria` decide qué FK es obligatoria (CHECK).
CREATE TABLE pagos (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid            VARCHAR(32) NULL,
  numero_recibo  VARCHAR(20) NULL,           -- correlativo del recibo
  categoria      ENUM('Paciente','Colegio','Alquiler','Producto','Otro')
                 NOT NULL DEFAULT 'Paciente',
  paciente_id    BIGINT UNSIGNED NULL,
  profesional_id BIGINT UNSIGNED NULL,       -- pagos de alquiler
  paquete_id     BIGINT UNSIGNED NULL,
  cita_id        BIGINT UNSIGNED NULL,
  concepto       VARCHAR(255) NULL,
  tipo_pago      ENUM('Sesión completa','Adelanto de sesión','Paquete completo',
                      'Adelanto de paquete','Saldo pendiente','Colegio','Otro')
                 NOT NULL DEFAULT 'Sesión completa',
  monto          DECIMAL(12,2) NOT NULL,
  moneda         CHAR(3) NOT NULL DEFAULT 'PEN',
  metodo_pago_id SMALLINT UNSIGNED NULL,
  referencia     VARCHAR(80) NULL,           -- n° de operación Yape/transferencia
  fecha          DATE NOT NULL,
  hora           TIME NULL,
  anulado_en     DATETIME NULL,              -- anulación en lugar de DELETE
  anulado_por    BIGINT UNSIGNED NULL,
  motivo_anulacion VARCHAR(255) NULL,
  registrado_por BIGINT UNSIGNED NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pago_uid (uid),
  UNIQUE KEY uq_pago_recibo (numero_recibo),
  KEY idx_pago_fecha (fecha),
  KEY idx_pago_paciente (paciente_id, fecha),
  KEY idx_pago_paquete (paquete_id),
  KEY idx_pago_prof (profesional_id, fecha),
  KEY idx_pago_categoria (categoria, fecha),
  CONSTRAINT fk_pago_paciente FOREIGN KEY (paciente_id)    REFERENCES pacientes(persona_id),
  CONSTRAINT fk_pago_prof     FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id),
  CONSTRAINT fk_pago_paquete  FOREIGN KEY (paquete_id)     REFERENCES paciente_paquetes(id),
  CONSTRAINT fk_pago_cita     FOREIGN KEY (cita_id)        REFERENCES citas(id),
  CONSTRAINT fk_pago_metodo   FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id),
  CONSTRAINT fk_pago_usuario  FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
  CONSTRAINT chk_pago_monto   CHECK (monto > 0),
  CONSTRAINT chk_pago_destino CHECK (
    (categoria IN ('Paciente','Colegio') AND paciente_id    IS NOT NULL) OR
    (categoria =  'Alquiler'             AND profesional_id IS NOT NULL) OR
    (categoria IN ('Producto','Otro'))
  )
) ENGINE=InnoDB;

-- Uso de consultorio por profesionales externos = cuenta por cobrar.
CREATE TABLE usos_consultorio (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid            VARCHAR(32) NULL,
  profesional_id BIGINT UNSIGNED NOT NULL,
  consultorio_id BIGINT UNSIGNED NULL,
  modo           ENUM('Por paciente','Por dia','Mensual') NOT NULL DEFAULT 'Por paciente',
  fecha          DATE NOT NULL,
  periodo        CHAR(7) NULL,              -- 'AAAA-MM' cuando el modo es Mensual
  cantidad       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  monto          DECIMAL(12,2) NOT NULL,
  cobrado        TINYINT(1) NOT NULL DEFAULT 0,
  pago_id        BIGINT UNSIGNED NULL,      -- pago que lo canceló
  nota           VARCHAR(255) NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_uso_uid (uid),
  -- Evita registrar dos veces la mensualidad del mismo mes (hoy solo se
  -- avisa con un confirm() que el usuario puede aceptar por error).
  UNIQUE KEY uq_uso_mensual (profesional_id, modo, periodo),
  KEY idx_uso_prof (profesional_id, fecha),
  KEY idx_uso_cobrado (cobrado, fecha),
  CONSTRAINT fk_uso_prof        FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id),
  CONSTRAINT fk_uso_consultorio FOREIGN KEY (consultorio_id) REFERENCES consultorios(id),
  CONSTRAINT fk_uso_pago        FOREIGN KEY (pago_id)        REFERENCES pagos(id),
  CONSTRAINT chk_uso_periodo    CHECK (modo <> 'Mensual' OR periodo IS NOT NULL)
) ENGINE=InnoDB;

-- Liquidación de honorarios al profesional. Reemplaza los campos
-- mutables amountToPay / paid / lastSettledDate de la ficha, que
-- sobrescriben el importe anterior y borran el rastro de lo ya pagado.
CREATE TABLE liquidaciones_profesional (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  profesional_id BIGINT UNSIGNED NOT NULL,
  periodo_desde  DATE NOT NULL,
  periodo_hasta  DATE NOT NULL,
  sesiones       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  monto_bruto    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  descuentos     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  monto_neto     DECIMAL(12,2) AS (monto_bruto - descuentos) STORED,
  estado         ENUM('Borrador','Aprobada','Pagada','Anulada') NOT NULL DEFAULT 'Borrador',
  fecha_pago     DATE NULL,
  gasto_id       BIGINT UNSIGNED NULL,      -- el egreso que generó
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_liquidacion (profesional_id, periodo_desde, periodo_hasta),
  KEY idx_liq_estado (estado, fecha_pago),
  CONSTRAINT fk_liq_prof FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id),
  CONSTRAINT chk_liq_periodo CHECK (periodo_hasta >= periodo_desde)
) ENGINE=InnoDB;

-- EGRESOS. En el prototipo un gasto fijo mensual es UNA fila con
-- recurring=true: nunca queda registro de en qué meses se pagó.
-- Aquí la plantilla y el pago efectivo son cosas distintas.
CREATE TABLE gastos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid           VARCHAR(32) NULL,
  nombre        VARCHAR(180) NOT NULL,
  categoria_id  SMALLINT UNSIGNED NULL,
  monto         DECIMAL(12,2) NOT NULL,
  fecha_pago    DATE NOT NULL,
  periodo       CHAR(7) NULL,               -- 'AAAA-MM' que cubre el gasto
  es_recurrente TINYINT(1) NOT NULL DEFAULT 0,
  plantilla_id  BIGINT UNSIGNED NULL,       -- gasto fijo que lo originó
  profesional_id BIGINT UNSIGNED NULL,      -- si es pago de honorarios
  comprobante_id BIGINT UNSIGNED NULL,
  nota          VARCHAR(255) NULL,
  registrado_por BIGINT UNSIGNED NULL,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  anulado_en    DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gasto_uid (uid),
  KEY idx_gasto_fecha (fecha_pago),
  KEY idx_gasto_categoria (categoria_id, fecha_pago),
  KEY idx_gasto_recurrente (es_recurrente, periodo),
  CONSTRAINT fk_gasto_categoria FOREIGN KEY (categoria_id)   REFERENCES categorias_gasto(id),
  CONSTRAINT fk_gasto_plantilla FOREIGN KEY (plantilla_id)   REFERENCES gastos(id),
  CONSTRAINT fk_gasto_prof      FOREIGN KEY (profesional_id) REFERENCES profesionales(persona_id),
  CONSTRAINT fk_gasto_archivo   FOREIGN KEY (comprobante_id) REFERENCES archivos(id),
  CONSTRAINT fk_gasto_usuario   FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
  CONSTRAINT chk_gasto_monto    CHECK (monto > 0)
) ENGINE=InnoDB;

ALTER TABLE liquidaciones_profesional
  ADD CONSTRAINT fk_liq_gasto FOREIGN KEY (gasto_id) REFERENCES gastos(id);

-- Productos / ideas de negocio (pestaña Ideas).
CREATE TABLE productos (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid            VARCHAR(32) NULL,
  nombre         VARCHAR(180) NOT NULL,
  costo          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  precio         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unidades_mes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  margen_unitario DECIMAL(12,2) AS (precio - costo) STORED,
  margen_mensual  DECIMAL(14,2) AS ((precio - costo) * unidades_mes) STORED,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_producto_uid (uid)
) ENGINE=InnoDB;


-- =====================================================================
-- 11. AUDITORÍA
--     Datos de salud = "datos sensibles" (Ley 29733). Se necesita saber
--     quién vio y quién modificó cada historia clínica.
-- =====================================================================

CREATE TABLE auditoria (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tabla         VARCHAR(64) NOT NULL,
  registro_id   BIGINT UNSIGNED NULL,
  accion        ENUM('INSERT','UPDATE','DELETE','SELECT','LOGIN','LOGOUT','EXPORT') NOT NULL,
  usuario_id    BIGINT UNSIGNED NULL,
  datos_antes   JSON NULL,
  datos_despues JSON NULL,
  ip            VARBINARY(16) NULL,          -- INET6_ATON()
  user_agent    VARCHAR(255) NULL,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id, creado_en),
  KEY idx_audit_tabla (tabla, registro_id, creado_en),
  KEY idx_audit_usuario (usuario_id, creado_en)
) ENGINE=InnoDB
-- Particionado mensual: purgar/archivar historial antiguo es un
-- ALTER ... DROP PARTITION instantáneo en vez de un DELETE masivo.
PARTITION BY RANGE COLUMNS (creado_en) (
  PARTITION p2026q1 VALUES LESS THAN ('2026-04-01'),
  PARTITION p2026q2 VALUES LESS THAN ('2026-07-01'),
  PARTITION p2026q3 VALUES LESS THAN ('2026-10-01'),
  PARTITION p2026q4 VALUES LESS THAN ('2027-01-01'),
  PARTITION pmax    VALUES LESS THAN (MAXVALUE)
);

SET FOREIGN_KEY_CHECKS = 1;
