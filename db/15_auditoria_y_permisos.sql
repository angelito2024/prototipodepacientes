-- =====================================================================
--  15 · Que la auditoría registre de verdad
--
--  La columna `accion` era un ENUM con siete valores: INSERT, UPDATE,
--  DELETE, SELECT, LOGIN, LOGOUT y EXPORT. Cualquier otra cosa que se
--  intentara anotar —quitar la clave de las cuentas personales, cargar una
--  prueba psicológica, asignarla a un paciente, o un intento de entrar a
--  una sección sin permiso— MySQL la descartaba, y como la auditoría está
--  escrita para no tumbar nunca la operación principal, el error se tragaba
--  en silencio.
--
--  Resultado: la mitad de lo que el sistema creía estar registrando no se
--  registraba. Los 25 movimientos que había eran entradas y salidas.
--
--  Pasa a VARCHAR para que una acción nueva no obligue a migrar la tabla
--  otra vez ni, peor, se pierda sin avisar.
--
--  Ejecutar después de 01..14. Se puede repetir sin peligro.
-- =====================================================================

USE centro_psicologico;

ALTER TABLE auditoria
  MODIFY COLUMN accion VARCHAR(40) NOT NULL
  COMMENT 'Qué se hizo: INSERT, LOGIN, PERMISO_DENEGADO, ASIGNAR_PRUEBA…';

-- Buscar por acción es lo primero que se hace al revisar un incidente
-- ("¿quién intentó entrar donde no debía?"), y sin índice eso recorre la
-- tabla entera.
SET @existe := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'auditoria'
                   AND index_name = 'ix_auditoria_accion');
SET @sql := IF(@existe = 0,
  'CREATE INDEX ix_auditoria_accion ON auditoria (accion, creado_en)',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
