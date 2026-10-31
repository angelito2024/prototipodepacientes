-- ============================================================
--  30 · Cada atención con su código, y un surtidor de correlativos
-- ============================================================
--  Las citas ya tenían un identificador único (`uid`) y la base ya
--  impedía repetirlo. El problema era otro: ese código es de máquina
--  —`mve1ng04jmg90`— y no se puede dictar por teléfono, ni escribir en
--  un comprobante, ni buscar sin copiarlo y pegarlo.
--
--  `codigo_atencion` es el mismo dato para la máquina y uno legible para
--  las personas: A-2026-00001. Doce caracteres, se dicta en tres
--  segundos, y el año se lee de un vistazo.
--
--  TRES REGLAS QUE LO SOSTIENEN
--
--  1. El número lo da el SERVIDOR, nunca el panel. El panel manda la
--     lista entera de citas y el servidor concilia; si dos pantallas
--     abiertas calcularan «el último + 1», las dos sacarían el mismo.
--     Por eso el surtidor vive acá y el UNIQUE lo hace imposible.
--
--  2. El número se da cuando la atención OCURRE (estado Completada), no
--     cuando se agenda. Una cita cancelada no es una prestación y no
--     tiene por qué gastarse un número: un correlativo con huecos obliga
--     a explicar dónde está el que falta.
--
--  3. Un número no se recicla NUNCA. Si una atención con código se anula
--     después, el código se queda con ella. Reutilizarlo pondría el
--     mismo papel sobre dos atenciones distintas, y entonces el código
--     deja de ser evidencia de nada.
--
--  El surtidor es genérico a propósito: `pagos.numero_recibo` existe
--  vacío desde el primer día y necesita exactamente lo mismo, igual que
--  las ventas cuando haya tienda.
--
--  Repetible: se puede cargar dos veces sin romper nada.
-- ============================================================

USE centro_psicologico;

-- ---------- 1. El surtidor ----------
--  (ambito, periodo) -> último número entregado. Entregar uno es un solo
--  INSERT ... ON DUPLICATE KEY UPDATE con LAST_INSERT_ID(), que es
--  atómico: dos pedidos simultáneos reciben números distintos sin que
--  nadie tenga que bloquear la tabla a mano.
CREATE TABLE IF NOT EXISTS correlativos (
  ambito         VARCHAR(40)     NOT NULL COMMENT 'atencion, recibo, venta...',
  periodo        CHAR(4)         NOT NULL COMMENT 'el año; 0000 si no se reinicia',
  ultimo         INT UNSIGNED    NOT NULL DEFAULT 0,
  actualizado_en TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ambito, periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- 2. El código en la cita ----------
SET @hay := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'citas'
      AND COLUMN_NAME  = 'codigo_atencion'
);
SET @sql := IF(@hay = 0,
    'ALTER TABLE citas
        ADD COLUMN codigo_atencion VARCHAR(16) NULL AFTER uid,
        ADD UNIQUE KEY uq_cita_codigo (codigo_atencion)',
    'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------- 3. Las atenciones que ya pasaron ----------
--  Se numeran por ORDEN DE FECHA, no por orden de carga: el correlativo
--  tiene que leerse como la historia del centro. Si se numeraran por id,
--  las tres sesiones de octubre que se cargaron a mano la semana pasada
--  recibirían números posteriores a los de noviembre.
--
--  Solo las Completadas, y solo las que no tienen código todavía.
UPDATE citas c
  JOIN (
        SELECT uid,
               YEAR(inicio) AS anio,
               ROW_NUMBER() OVER (PARTITION BY YEAR(inicio) ORDER BY inicio, id) AS pos
          FROM citas
         WHERE estado = 'Completada'
           AND eliminado_en IS NULL
           AND codigo_atencion IS NULL
       ) x ON x.uid = c.uid
   SET c.codigo_atencion = CONCAT('A-', x.anio, '-', LPAD(x.pos, 5, '0'))
 WHERE c.codigo_atencion IS NULL;

-- ---------- 4. El surtidor arranca donde terminó el pasado ----------
--  Si no, la próxima atención repetiría un número ya entregado y el
--  UNIQUE la rechazaría.
INSERT INTO correlativos (ambito, periodo, ultimo)
SELECT 'atencion', SUBSTRING(codigo_atencion, 3, 4),
       MAX(CAST(SUBSTRING(codigo_atencion, 8) AS UNSIGNED))
  FROM citas
 WHERE codigo_atencion LIKE 'A-%'
 GROUP BY SUBSTRING(codigo_atencion, 3, 4)
ON DUPLICATE KEY UPDATE ultimo = GREATEST(correlativos.ultimo, VALUES(ultimo));
