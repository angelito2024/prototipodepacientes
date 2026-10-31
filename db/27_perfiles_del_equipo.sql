-- ============================================================
--  27 · Tres permisos y tres perfiles más
-- ============================================================
--  Los cuatro roles que vinieron con la instalación —admin, recepción,
--  profesional, practicante— no alcanzan para cómo trabaja el centro de
--  verdad: quien cobra no es quien agenda, y quien vigila las alertas no
--  tiene por qué entrar a la historia clínica.
--
--  Y faltaban tres permisos para poder separarlos sin dar de más:
--
--    · alertas.ver      — hoy las alertas van con `citas.ver`, que es
--                         demasiado para quien solo hace seguimiento;
--    · reportes.ver     — hoy los reportes van con `pacientes.ver`;
--    · talleres.editar  — hoy los talleres van con `citas.editar`, así que
--                         quien agenda una cita puede tocar un convenio
--                         con un colegio.
--
--  Los tres nacen dados a los perfiles que ya los tenían de hecho, para
--  que nadie pierda acceso a nada el día que se carga esta migración.
--
--  Repetible: comprueba antes de insertar.
-- ============================================================

USE centro_psicologico;

-- --- 1. Los permisos que faltaban -----------------------------------
INSERT INTO permisos (clave, modulo)
SELECT * FROM (
    SELECT 'alertas.ver'     AS clave, 'alertas'  AS modulo
    UNION ALL SELECT 'reportes.ver',    'reportes'
    UNION ALL SELECT 'talleres.editar', 'talleres'
) AS nuevos
WHERE NOT EXISTS (
    SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave
);

-- --- 2. Los perfiles nuevos -----------------------------------------
--  `recepcion` se queda como está y pasa a llamarse Admisión en la
--  pantalla: es la misma gente, con el nombre que usa Luis.
INSERT INTO roles (clave, nombre)
SELECT * FROM (
    SELECT 'caja'      AS clave, 'Caja'                   AS nombre
    UNION ALL SELECT 'seguimiento', 'Seguimiento de alertas'
) AS nuevos
WHERE NOT EXISTS (
    SELECT 1 FROM roles r WHERE r.clave = nuevos.clave
);

-- --- 3. Qué puede cada uno ------------------------------------------
--  Se arma con una tabla de pares para no repetir el mismo INSERT seis
--  veces, y con NOT EXISTS para poder volver a cargarla sin duplicar.
INSERT INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id
  FROM (
        -- Caja: cobrar y nada más. Ve al paciente para saber a quién cobra.
        SELECT 'caja' AS rol, 'pacientes.ver'    AS permiso
  UNION ALL SELECT 'caja', 'pagos.ver'
  UNION ALL SELECT 'caja', 'pagos.registrar'
  UNION ALL SELECT 'caja', 'reportes.ver'
        -- Seguimiento: mirar y avisar. No edita pacientes ni cobra.
  UNION ALL SELECT 'seguimiento', 'alertas.ver'
  UNION ALL SELECT 'seguimiento', 'citas.ver'
  UNION ALL SELECT 'seguimiento', 'pacientes.ver'
  UNION ALL SELECT 'seguimiento', 'reportes.ver'
        -- Los tres nuevos, a quien ya los tenía de hecho.
  UNION ALL SELECT 'admin',       'alertas.ver'
  UNION ALL SELECT 'admin',       'reportes.ver'
  UNION ALL SELECT 'admin',       'talleres.editar'
  UNION ALL SELECT 'recepcion',   'alertas.ver'
  UNION ALL SELECT 'recepcion',   'reportes.ver'
        -- Admisión sigue manejando los talleres: lo decidió Luis.
  UNION ALL SELECT 'recepcion',   'talleres.editar'
  UNION ALL SELECT 'profesional', 'alertas.ver'
  UNION ALL SELECT 'profesional', 'reportes.ver'
  UNION ALL SELECT 'profesional', 'talleres.editar'
  UNION ALL SELECT 'practicante', 'alertas.ver'
  ) AS pares
  JOIN roles    r ON r.clave = pares.rol
  JOIN permisos p ON p.clave = pares.permiso
 WHERE NOT EXISTS (
    SELECT 1 FROM rol_permisos rp
     WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
 );
