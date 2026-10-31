-- ============================================================
--  29 · Si la cita es de alguien nuevo o de alguien que sigue
-- ============================================================
--  Al agendar, saber si es la primera vez de esa persona cambia todo:
--  cuánto dura la sesión, qué se prepara, si hay que pedirle el
--  consentimiento y a qué tarifa entra. Hoy eso se sabía mirando su
--  ficha, que es salir de la pantalla en la que uno está.
--
--  El sistema lo puede deducir de sus citas atendidas, y lo hace. Pero
--  se guarda igual, por dos razones:
--
--    · un paciente que vuelve después de dos años es un REINGRESO, y eso
--      es una decisión de quien atiende, no una cuenta de días;
--    · para poder decir en el tablero cuántos pacientes nuevos hubo en
--      octubre sin recalcular toda la historia cada vez.
--
--  NULL quiere decir «no se anotó»: ahí el panel lo deduce. Las 66 citas
--  que ya existen se quedan en NULL, y se siguen viendo bien.
--
--  Las claves del ENUM van sin tildes a propósito: al cargar un .sql sin
--  --default-character-set=utf8mb4 los acentos de un ENUM se corrompen y
--  después no entra ninguna fila.
-- ============================================================

USE centro_psicologico;

SET @hay := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'citas'
      AND COLUMN_NAME  = 'tipo_paciente'
);
SET @sql := IF(@hay = 0,
    'ALTER TABLE citas
        ADD COLUMN tipo_paciente
            ENUM(''Primera vez'',''Continuador'',''Reingreso'') NULL
            AFTER tipo_sesion,
        ADD KEY ix_cita_tipo_paciente (tipo_paciente)',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
