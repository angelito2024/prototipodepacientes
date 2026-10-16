-- =====================================================================
--  25 · La cara del centro ante el paciente
--
--  Las tres páginas a las que el paciente entra sin cuenta —responder una
--  prueba, recibir una tarea para la casa, registrarse como practicante—
--  no decían de quién eran. Un enlace sin logo ni nombre, que llega por
--  WhatsApp y pide escribir cómo te sientes, se parece demasiado a una
--  estafa: la familia no lo abre, o lo abre y no lo responde.
--
--  Dos cosas, entonces:
--
--  1. `mensaje_pacientes`: el texto corto que el centro le pone a esas
--     páginas. Va en la base y no en el programa porque es la voz del
--     centro, y cambiarla no puede depender de que alguien edite un
--     archivo.
--
--  2. `pedir_documento`: si el enlace, además de su clave larga, pide el
--     documento del paciente antes de mostrar nada. La clave del enlace
--     ya es secreta; el documento no lo es, así que esto NO agrega
--     secreto. Agrega otra cosa: impide que el enlace reenviado al grupo
--     de la familia lo responda la persona equivocada, que es el error
--     que arruina un protocolo sin que se note. Un resultado respondido
--     por otro es indistinguible de uno legítimo, y es el único error
--     clínico de este sistema que no deja rastro.
--
--     Nace en 0 a propósito: hoy 11 de los 12 pacientes no tienen
--     documento cargado, y prenderlo de golpe dejaría a casi todos sin
--     poder abrir su prueba. Se prende cuando las fichas estén completas.
--
--  Ejecutar después de 01..24. Se puede repetir sin peligro.
--  Cargar con:  mysql.exe --default-character-set=utf8mb4
--
--  OJO · va también en `finanzas_personales`. El panel personal corre el
--  mismo programa, así que `CentroInfo::guardar()` escribe estas dos
--  columnas en las dos bases: si faltan en una, guardar Datos del centro
--  ahí revienta con «Unknown column». Toda migración que toque
--  `centro_config` hay que cargarla dos veces, cambiando el USE:
--
--      sed 's/USE centro_psicologico;/USE finanzas_personales;/' \
--        db/25_la_cara_del_centro.sql | mysql.exe -uroot --default-character-set=utf8mb4
-- =====================================================================

USE centro_psicologico;

SET @hay := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name   = 'centro_config'
                AND column_name  = 'mensaje_pacientes');
SET @sql := IF(@hay = 0,
  'ALTER TABLE centro_config
     ADD COLUMN mensaje_pacientes VARCHAR(400) NULL AFTER lema',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

SET @hay := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name   = 'centro_config'
                AND column_name  = 'pedir_documento');
SET @sql := IF(@hay = 0,
  'ALTER TABLE centro_config
     ADD COLUMN pedir_documento TINYINT(1) NOT NULL DEFAULT 0 AFTER mensaje_pacientes',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
