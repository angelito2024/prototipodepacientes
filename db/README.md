# Base de datos — Centro Psicológico

Esquema relacional que reemplaza el almacenamiento actual del prototipo
(`index.html`, 17 colecciones JSON en `localStorage` + un blob `historia_<id>`
por paciente).

## Motor

Laragon instaló **MySQL 8.4.3**, no MariaDB (`C:\laragon\bin\mysql\mysql-8.4.3-winx64`).
El esquema está escrito para funcionar sin cambios en **MySQL 8.0+ y MariaDB 10.6+**:
sin `JSON_TABLE`, sin `INVISIBLE`, sin funciones de ventana en las vistas, y con
`utf8mb4_unicode_ci` (presente en ambos motores).

El usuario `root` de esta instalación tiene contraseña **vacía**, no `root`.

## Instalación

### Desde phpMyAdmin
Importar en este orden, uno por uno:

1. `01_schema.sql` — crea la base y las 49 tablas
2. `02_vistas_triggers.sql` — 9 vistas, 13 triggers, 1 procedimiento
3. `03_datos_base.sql` — catálogos, roles, permisos y usuario `admin`
4. `04_api.sql` — versiones de colección y sesiones, para la capa PHP

En una instalación **nueva** eso es todo: `01` ya incluye talleres y cuentas
personales. `05_actualizacion_talleres.sql` es solo para una base instalada
antes de que existieran esos módulos (ver *Actualizaciones*).

> En phpMyAdmin, `02_vistas_triggers.sql` usa `DELIMITER`. phpMyAdmin lo
> soporta, pero si diera error, ajustar el campo "Delimitador" a `$$`.

### Desde la línea de comandos
```bash
cd C:/laragon/www/prototipodepacientes/db
MYSQL="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql"
$MYSQL -uroot --default-character-set=utf8mb4 < 01_schema.sql
$MYSQL -uroot --default-character-set=utf8mb4 < 02_vistas_triggers.sql
$MYSQL -uroot --default-character-set=utf8mb4 < 03_datos_base.sql
$MYSQL -uroot --default-character-set=utf8mb4 < 04_api.sql
```

### Actualizaciones

Si la base ya estaba instalada antes de que el panel tuviera talleres y
cuentas personales, hay que ponerla al día una vez:

```bash
$MYSQL -uroot --default-character-set=utf8mb4 < 05_actualizacion_talleres.sql
```

Agrega las seis tablas nuevas, el ámbito de las tarifas, los destinatarios
de los recordatorios y la duración de la sesión. Es reejecutable y no toca
los datos existentes: deja la base igual que una instalación desde cero
(verificado comparando columnas e índices de ambas).

### Después de instalar
1. Cambiar la contraseña del usuario `admin` (temporal: `Magusa2026*`).
2. Cambiar la contraseña de `app_centro` en `03_datos_base.sql` antes de
   ejecutarlo, o con `ALTER USER` después. La aplicación **nunca** debe
   conectarse como `root`.
3. Crear la carpeta `storage/` fuera de `www/` para los archivos adjuntos.

## Mapa: colección JSON → tablas

| Colección actual | Tablas nuevas |
|---|---|
| `patients` | `personas` + `pacientes` + `paciente_acompanantes` + `paciente_paquetes` |
| `professionals` | `personas` + `profesionales` + `persona_horarios` + `persona_horario_excepciones` |
| `practicantes` | `personas` + `practicantes` + `persona_horarios` + `practicante_actividades` |
| `appointments` | `citas` + `cita_historial` |
| `historia_<id>` | `historias_clinicas` + `hc_episodios` + `hc_diagnosticos` + `hc_pruebas` + `hc_evoluciones` + `hc_adjuntos` + `informes` |
| `payments` | `pagos` |
| `roomUsage` | `usos_consultorio` |
| `expenses` | `gastos` + `categorias_gasto` |
| `services` | `servicios` + `servicio_tarifas` |
| `attendanceLog` | `asistencia_registros` |
| `calendarEvents` | `eventos_calendario` |
| `products` | `productos` |
| `centerInfo` | `centro_config` + `sedes` + `consultorios` + `integraciones` |
| `authConfig` | `usuarios` + `roles` + `permisos` + `rol_permisos` + `usuario_roles` |
| `talleres` | `talleres` + `taller_sesiones` + `taller_participantes` |
| `personalEntries` | `personal_movimientos` + `personal_pagos` |
| `personalConfig` | `personal_config` (la clave, con hash) |
| (no existía) | `archivos`, `auditoria`, `liquidaciones_profesional`, `cie10_catalogo` |

## Migración de los datos existentes

El botón "Exportar respaldo" del panel (`exportBackupJSON()`) genera un JSON con
todas las colecciones y las historias. Ese archivo es la entrada del importador.

Cada tabla migrada tiene una columna `uid VARCHAR(32) UNIQUE` que conserva el id
base36 del prototipo (`Date.now().toString(36) + random`). El orden de carga
debe respetar las dependencias:

```
personas → profesionales → pacientes → practicantes
        → servicios → paciente_paquetes → citas
        → historias_clinicas → hc_episodios → (diagnósticos, pruebas, evoluciones)
        → pagos → usos_consultorio → gastos → asistencia_registros
```

Las FK se resuelven con `SELECT id FROM personas WHERE uid = ?`. Terminada la
migración y validada, las columnas `uid` se pueden eliminar.

Puntos que requieren decisión durante la migración:

- **Cumpleaños.** El prototipo guarda `'MM-DD'` y descarta el año que el usuario
  ya había escrito. Los registros migrados quedarán con `fecha_nacimiento` NULL
  o con un año placeholder a completar.
- **Adjuntos base64.** Hay que decodificar cada `dataUrl` a un archivo en
  `storage/` y registrar solo los metadatos en `archivos`.
- **Precios en texto.** `services.price` es texto libre (`"S/100"`,
  `"A definir"`, `"Variable (ej. S/20 de S/50)"`). Requiere revisión manual:
  lo que no se pueda parsear queda con `precio = NULL`.
- **Paquetes.** `packageTotal` / `sessionsUsed` / `billedSessions` /
  `paquetesHistorial[]` se convierten en filas de `paciente_paquetes`: el
  historial primero (`estado='Cerrado'`), el vigente al final (`'Activo'`).

## Vistas disponibles

| Vista | Reemplaza a |
|---|---|
| `v_saldo_paquete` | `patientBalance()` + `computedPaymentStatus()` |
| `v_ingreso_centro` | `centerIncomeByProfessional()` |
| `v_deuda_alquiler` | el cálculo de saldo pendiente en `renderProfRentalHistory()` |
| `v_agenda_fechas` | `viewCalendario()` + `nextBirthdayDays()` |
| `v_resultado_mensual` | los totales de la pestaña Finanzas |
| `v_agenda_citas` | el cruce de 4 colecciones que hace `viewCitas()` |
| `v_taller_resumen` | `tallerCobrado()` + `tallerPorCobrar()` + el conteo de inscritos |
| `v_personal_mes` | `personalDelMes()` |

## Reglas que la base garantiza

Verificadas contra MySQL 8.4.3:

- Un consultorio o un profesional no pueden tener dos citas solapadas
  (trigger sobre rangos reales, no solo horas de inicio idénticas).
- Un paciente no puede tener dos paquetes activos a la vez.
- Una historia clínica no puede tener dos episodios abiertos.
- Una nota de evolución firmada no se puede modificar.
- Un pago de alquiler exige profesional; uno de paciente exige paciente.
- Un participante de taller marcado como pagado tiene que tener importe.
- Un movimiento personal fijo lleva día de vencimiento y no fecha suelta;
  uno variable, al revés. Mezclarlos era lo que hacía que una compra de
  marzo siguiera restando del margen en setiembre.
- Un mes solo se puede marcar pagado una vez por movimiento personal.
- Una cita presencial exige consultorio.
- El documento de identidad es único en todo el centro.
- La mensualidad de alquiler de un mes no se puede registrar dos veces.

## Mantenimiento

- **Respaldo diario:**
  `mysqldump -uroot --single-transaction --routines --triggers centro_psicologico > backup.sql`
  Los archivos de `storage/` se respaldan aparte.
- **Auditoría particionada por trimestre.** Agregar la partición del año
  siguiente antes de que se llene `pmax`:
  `ALTER TABLE auditoria REORGANIZE PARTITION pmax INTO (...)`.
- **Crecimiento.** Las tablas que crecen de verdad son `citas`,
  `hc_evoluciones`, `pagos` y `auditoria`. Con 2 000 sesiones al año, ninguna
  llega al millón de filas en una década: los índices actuales bastan y no hace
  falta particionar nada más que la auditoría.
