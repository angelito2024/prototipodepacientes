-- ============================================================
--  32 · Cada profesional ve lo suyo, y se le paga por donde cobra
-- ============================================================
--  1. VER LO SUYO
--
--     Los permisos dicen qué SECCIONES abre cada perfil, pero no DE
--     QUIÉN. Hoy el servidor no filtra nada: una psicóloga nueva, el día
--     que entra, ve la agenda completa del centro, los 13 pacientes y
--     **todas las historias clínicas**, con permiso para escribir y
--     firmar en ellas. Con una sola psicóloga da igual —todos los
--     pacientes son suyos—; con dos deja de dar igual, y leer la
--     historia de un paciente que uno no atiende es una falta de
--     confidencialidad, no un detalle de software.
--
--     `ver.todo` es el permiso que distingue a quien administra el
--     centro de quien atiende en él. Lo tienen admin y recepción. Quien
--     no lo tiene ve solo lo suyo — y de lo ajeno, nada más que la hora
--     ocupada, porque sin eso agendaría encima de un colega.
--
--  2. POR DÓNDE SE LE PAGA
--
--     El cálculo de la comisión ya existe y funciona atención por
--     atención. Lo que faltaba es lo de alrededor: a qué Yape se le
--     manda la plata, cada cuánto se le paga, y a qué cuenta si es
--     transferencia. Sin eso, cada pago obliga a buscar el número en
--     una conversación de WhatsApp de hace tres semanas.
--
--  Repetible: se puede cargar dos veces sin romper nada.
-- ============================================================

USE centro_psicologico;

-- ---------- 1. El permiso ----------
INSERT INTO permisos (clave, modulo)
SELECT 'ver.todo', 'pacientes'
 WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'ver.todo');

--  Admin y recepción administran el centro entero: sin esto, el propio
--  administrador dejaría de ver la agenda que tiene que organizar.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'ver.todo'
 WHERE r.clave IN ('admin', 'recepcion')
   AND NOT EXISTS (
        SELECT 1 FROM rol_permisos rp
         WHERE rp.rol_id = r.id AND rp.permiso_id = p.id);

-- ---------- 2. Cómo se le paga al profesional ----------
SET @hay := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'profesionales' AND COLUMN_NAME = 'yape'
);
SET @sql := IF(@hay = 0,
    'ALTER TABLE profesionales
        ADD COLUMN yape VARCHAR(20) NULL AFTER saldo_por_pagar,
        ADD COLUMN plin VARCHAR(20) NULL AFTER yape,
        ADD COLUMN banco VARCHAR(60) NULL AFTER plin,
        ADD COLUMN cuenta VARCHAR(40) NULL AFTER banco,
        ADD COLUMN cci VARCHAR(40) NULL AFTER cuenta,
        ADD COLUMN titular_cuenta VARCHAR(160) NULL AFTER cci,
        ADD COLUMN frecuencia_pago
            ENUM(''Semanal'',''Quincenal'',''Mensual'',''A convenir'')
            NOT NULL DEFAULT ''Mensual'' AFTER titular_cuenta,
        ADD COLUMN dia_pago TINYINT UNSIGNED NULL AFTER frecuencia_pago',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
