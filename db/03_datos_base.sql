-- =====================================================================
--  DATOS BASE (catálogos, roles y configuración inicial)
--  Ejecutar después de 01_schema.sql y 02_vistas_triggers.sql
-- =====================================================================

USE centro_psicologico;

-- --- Configuración del centro ----------------------------------------
INSERT INTO centro_config (id, razon_social, nombre_comercial, medios_pago)
VALUES (1,
  'Centro Psicológico MAGUSA ARCOIRIS DE ESPERANZA S.A.C.',
  'MAGUSA Arcoíris de Esperanza',
  CONCAT('Yape / Plin: 961287594 - Luis Gustavo Yangua Jimenez (Gerente General)\n',
         'Cuenta BBVA: 0011-0147-0200744101\n',
         'CCI: 01114700020074410163\n',
         'Código SWIFT (solo para transferencias desde el extranjero): BCONPEPL'))
ON DUPLICATE KEY UPDATE razon_social = VALUES(razon_social);

-- --- Sede y consultorios (antes: ENUM fijo en el HTML) ---------------
INSERT INTO sedes (nombre, direccion) VALUES ('Sede principal', NULL)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO consultorios (sede_id, nombre, descripcion) VALUES
  (1, 'Consultorio 1', 'Adultos'),
  (1, 'Consultorio 2', 'Niños'),
  (1, 'Consultorio 3', 'Mixto')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- --- Catálogos -------------------------------------------------------
-- Las cuatro últimas son el gasto del día a día del centro, que antes
-- caía todo en 'Otro' y no se podía leer en el reporte de finanzas.
INSERT INTO categorias_gasto (nombre) VALUES
  ('Alquiler'), ('Servicios'), ('Materiales / pruebas psicológicas'),
  ('Pago a profesionales'), ('Planilla'), ('Marketing'), ('Impuestos'), ('Otro'),
  ('Insumos y limpieza'), ('Útiles de oficina'), ('Publicidad'), ('Mantenimiento')
ON DUPLICATE KEY UPDATE activo = 1;

INSERT INTO metodos_pago (nombre, requiere_ref) VALUES
  ('Efectivo', 0), ('Yape/Plin', 1), ('Transferencia', 1),
  ('Tarjeta', 1), ('Otro', 0)
ON DUPLICATE KEY UPDATE activo = 1;

-- --- Roles y permisos ------------------------------------------------
INSERT INTO roles (clave, nombre, descripcion) VALUES
  ('admin',        'Administrador',   'Acceso total, incluida configuración y finanzas'),
  ('recepcion',    'Recepción',       'Agenda, pacientes y cobros; sin historia clínica'),
  ('profesional',  'Profesional',     'Historia clínica de SUS pacientes y su agenda'),
  ('practicante',  'Practicante',     'Solo sus actividades y su asistencia')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO permisos (clave, modulo) VALUES
  ('pacientes.ver','pacientes'),      ('pacientes.editar','pacientes'),
  ('citas.ver','agenda'),             ('citas.editar','agenda'),
  ('historia.ver','clinico'),         ('historia.editar','clinico'),
  ('historia.firmar','clinico'),      ('informes.editar','clinico'),
  ('pagos.ver','finanzas'),           ('pagos.registrar','finanzas'),
  ('pagos.anular','finanzas'),        ('gastos.ver','finanzas'),
  ('finanzas.reportes','finanzas'),   ('profesionales.editar','equipo'),
  ('practicantes.editar','equipo'),   ('asistencia.registrar','equipo'),
  ('config.editar','sistema'),        ('usuarios.editar','sistema'),
  ('auditoria.ver','sistema'),        ('backup.exportar','sistema')
ON DUPLICATE KEY UPDATE modulo = VALUES(modulo);

-- admin: todo
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permisos p WHERE r.clave = 'admin';

-- recepción: agenda, pacientes y cobros; NUNCA la historia clínica
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
  ON p.clave IN ('pacientes.ver','pacientes.editar','citas.ver','citas.editar',
                 'pagos.ver','pagos.registrar','asistencia.registrar')
WHERE r.clave = 'recepcion';

-- profesional: clínico + su agenda
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
  ON p.clave IN ('pacientes.ver','citas.ver','citas.editar','historia.ver',
                 'historia.editar','historia.firmar','informes.editar')
WHERE r.clave = 'profesional';

-- practicante: mínimo
INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
  ON p.clave IN ('citas.ver')
WHERE r.clave = 'practicante';

-- --- Usuario administrador inicial -----------------------------------
-- Hash Bcrypt de la contraseña temporal: Magusa2026*
-- CÁMBIALA en el primer inicio de sesión.
INSERT INTO usuarios (usuario, email, password_hash, nombre_completo)
VALUES ('admin', NULL,
        '$2y$12$CULNhXwcju.w7Z6eA2Xg1.IgizMm8n.I3Hs0EEHnsazscsDXkz67G',
        'Administrador del centro')
ON DUPLICATE KEY UPDATE nombre_completo = VALUES(nombre_completo);

INSERT IGNORE INTO usuario_roles (usuario_id, rol_id)
SELECT u.id, r.id FROM usuarios u, roles r WHERE u.usuario = 'admin' AND r.clave = 'admin';

-- --- Servicios / tarifas por defecto ---------------------------------
-- El `uid` es obligatorio en las filas semilla: la API concilia por uid, y
-- una fila sin uid se daría de baja en el primer guardado del panel.
INSERT INTO servicios (uid, nombre, es_paquete, sesiones_paquete, precio, precio_texto, unidad, unidad_texto, descripcion) VALUES
  ('seedsvc01','Sesión individual',        0, NULL,  50.00, 'S/50',       'Por sesión',            'por sesión',            NULL),
  ('seedsvc02','Paquete de 8 sesiones',    1, 8,    360.00, NULL,         'Por paquete',           'paquete de 8 sesiones', NULL),
  ('seedsvc03','Turno en colegio',         0, NULL, 100.00, 'S/100',      'Mensual',               'mensual',               '1 día por semana'),
  ('seedsvc04','Taller',                   0, NULL,   NULL, 'A definir',  'Por taller',            'por taller',            'Precio a definir por evento'),
  ('seedsvc05','Alquiler de espacio',      0, NULL,  10.00, 'S/10',       'Por paciente atendido', 'por paciente atendido', 'Profesionales EXTERNOS sin consultorio propio'),
  ('seedsvc06','Comisión por profesional', 0, NULL,   NULL, 'Variable',   'Por sesión',            'por sesión',            'El monto del centro se define en la ficha del profesional');

-- --- Gastos fijos recurrentes (plantillas) ---------------------------
INSERT INTO gastos (uid, nombre, categoria_id, monto, fecha_pago, periodo, es_recurrente)
SELECT 'seedgas01','Alquiler del local', id, 1000.00, CURDATE(), DATE_FORMAT(CURDATE(), '%Y-%m'), 1
FROM categorias_gasto WHERE nombre = 'Alquiler';
INSERT INTO gastos (uid, nombre, categoria_id, monto, fecha_pago, periodo, es_recurrente)
SELECT 'seedgas02','Internet', id, 100.00, CURDATE(), DATE_FORMAT(CURDATE(), '%Y-%m'), 1
FROM categorias_gasto WHERE nombre = 'Servicios';
INSERT INTO gastos (uid, nombre, categoria_id, monto, fecha_pago, periodo, es_recurrente)
SELECT 'seedgas03','Luz y agua', id, 100.00, CURDATE(), DATE_FORMAT(CURDATE(), '%Y-%m'), 1
FROM categorias_gasto WHERE nombre = 'Servicios';

-- --- CIE-10: los 79 códigos que hoy están incrustados en el JS -------
-- Cargar el catálogo completo (F00–F99 y demás capítulos) desde el CSV
-- oficial del MINSA con LOAD DATA INFILE cuando esté disponible.
INSERT INTO cie10_catalogo (codigo, descripcion, capitulo) VALUES
  ('F32.0','Episodio depresivo leve','Trastornos del humor'),
  ('F32.1','Episodio depresivo moderado','Trastornos del humor'),
  ('F32.2','Episodio depresivo grave sin síntomas psicóticos','Trastornos del humor'),
  ('F33.0','Trastorno depresivo recurrente, episodio actual leve','Trastornos del humor'),
  ('F41.0','Trastorno de pánico','Trastornos neuróticos'),
  ('F41.1','Trastorno de ansiedad generalizada','Trastornos neuróticos'),
  ('F41.2','Trastorno mixto ansioso-depresivo','Trastornos neuróticos'),
  ('F43.0','Reacción a estrés agudo','Trastornos neuróticos'),
  ('F43.1','Trastorno de estrés post-traumático','Trastornos neuróticos'),
  ('F43.2','Trastornos de adaptación','Trastornos neuróticos'),
  ('F90.0','Trastorno de la actividad y de la atención','Trastornos del comportamiento'),
  ('F90.1','Trastorno hipercinético disocial','Trastornos del comportamiento'),
  ('F84.0','Autismo en la niñez','Trastornos del desarrollo psicológico'),
  ('F84.5','Síndrome de Asperger','Trastornos del desarrollo psicológico'),
  ('F80.9','Trastorno del desarrollo del habla y del lenguaje, no especificado','Trastornos del desarrollo psicológico'),
  ('F81.0','Trastorno específico de la lectura','Trastornos del desarrollo psicológico'),
  ('F70.9','Retraso mental leve, sin deterioro del comportamiento','Retraso mental'),
  ('Z00.4','Examen psiquiátrico general, no clasificado en otra parte','Factores que influyen en la salud'),
  ('Z63.0','Problemas en la relación entre esposos o pareja','Factores que influyen en la salud')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- --- Usuario de aplicación con privilegios mínimos -------------------
-- La app NUNCA debe conectarse como root. Cambia la contraseña.
CREATE USER IF NOT EXISTS 'app_centro'@'localhost' IDENTIFIED BY 'CambiaEstaClave_2026';
GRANT SELECT, INSERT, UPDATE, DELETE ON centro_psicologico.* TO 'app_centro'@'localhost';
GRANT EXECUTE ON centro_psicologico.* TO 'app_centro'@'localhost';
-- Sin DROP, sin ALTER, sin GRANT: las migraciones se aplican con otra cuenta.
FLUSH PRIVILEGES;
