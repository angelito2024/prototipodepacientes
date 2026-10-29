-- ============================================================
--  26 · El lema del año en los documentos
-- ============================================================
--  Los documentos oficiales del Perú llevan arriba el nombre que el
--  Gobierno le pone al año ("Año de la Esperanza y el Fortalecimiento
--  de la Democracia"). Cambia cada enero por decreto supremo, así que
--  no puede estar escrito dentro del programa: tiene que poder
--  cambiarlo Luis desde Datos del centro, sin esperar a nadie.
--
--  OJO — `centro_config` la comparten los dos paneles. Esta migración
--  hay que cargarla EN LAS DOS BASES, o guardar Datos del centro en el
--  panel personal revienta con «Unknown column»:
--
--    mysql -uroot --default-character-set=utf8mb4 < db/26_lema_del_anio.sql
--    sed 's/USE centro_psicologico;/USE finanzas_personales;/' \
--        db/26_lema_del_anio.sql | mysql -uroot --default-character-set=utf8mb4
--
--  Repetible: comprueba antes de agregar.
-- ============================================================

USE centro_psicologico;

SET @hay := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'centro_config'
      AND COLUMN_NAME  = 'lema_anio'
);
SET @sql := IF(@hay = 0,
    'ALTER TABLE centro_config
        ADD COLUMN lema_anio VARCHAR(160) NULL AFTER lema',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
