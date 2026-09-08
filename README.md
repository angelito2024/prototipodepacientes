# Panel del Centro Psicológico

Panel de gestión (`index.html`) conectado a MySQL/MariaDB mediante una capa
PHP. Sustituye el almacenamiento en `localStorage` del prototipo.

## Puesta en marcha

1. **Crear la base.** Importar en phpMyAdmin, en este orden:
   `db/01_schema.sql` → `db/02_vistas_triggers.sql` → `db/03_datos_base.sql` → `db/04_api.sql`
   (o por consola, ver [db/README.md](db/README.md)).

   Si la base **ya estaba instalada** antes de que el panel tuviera talleres
   y cuentas personales, importar además `db/05_actualizacion_talleres.sql`.
   Es reejecutable y no toca los datos existentes.

2. **Configurar el acceso.**
   ```
   copy config\config.ejemplo.php config\config.php
   ```
   Ajustar usuario y contraseña. `config/config.php` está en `.gitignore`.

3. **Abrir el panel** en `http://localhost/prototipodepacientes/`.
   El pie de página debe decir *"Conectado a la base de datos del centro"*.
   Si algo falla: `http://localhost/prototipodepacientes/api/index.php?accion=salud`.

4. **Traer los datos existentes** (ver *Migración* más abajo).

5. **Cambiar las contraseñas**: la del usuario `admin` (temporal:
   `Magusa2026*`) y la de `app_centro` en `db/03_datos_base.sql`.

### Entorno verificado
MySQL 8.4.3 y PHP 8.3.33, los que instaló Laragon. El código es portable a
MariaDB 10.6+. `root` tiene contraseña **vacía**; la aplicación no lo usa:
se conecta como `app_centro`, sin permisos de `DROP` ni `ALTER`.

## Estructura

```
index.html                 el panel (sin cambios de interfaz)
config/config.php          credenciales — no se versiona
src/
  Database.php             conexión PDO única
  Auth.php                 sesión, hash de contraseñas, bloqueo por intentos
  Archivos.php             adjuntos: binario a disco, metadatos a la base
  Repositorio.php          base de los mapeadores
  Colecciones.php          clave del panel -> repositorio
  Repos/*.php              un mapeador por colección
api/
  index.php                colecciones, login, sesión, diagnóstico
  archivo.php              descarga de adjuntos con control de acceso
  dni.php                  consulta a apiperu.dev desde el servidor
migrar.php                 importador del respaldo JSON
storage/                   adjuntos (no se versiona)
db/*.sql                   esquema, vistas, triggers, datos base
```

## Cómo encaja con `index.html`

El panel guarda con `setColl('patients', DB.patients)`: envía el **array
completo**, no el elemento que cambió, y lo hace desde unos 40 sitios
distintos. Reescribir esos 40 puntos era la vía más rápida a romper una
aplicación que funciona, así que la API acepta ese array tal cual y lo
concilia contra las tablas: inserta lo nuevo, actualiza lo cambiado y da de
baja lo que ya no viene. La bisagra es el `uid` — el id base36 que genera
`uid()` en el panel — guardado en una columna de cada tabla.

Los cambios en `index.html` se limitan a la capa de almacenamiento:

| Función | Cambio |
|---|---|
| `STORAGE_MODE` | tercer modo `'api'`, activo cuando la página se sirve por HTTP |
| `getColl` / `setColl` | hablan con `api/index.php` y llevan la versión de la colección |
| `attemptLogin` / `logout` | verifican contra el servidor, no contra un texto en el JSON |
| `buscarPorDNI` | busca primero en la base propia; si no está, `api/dni.php` con el token en el servidor |
| `desbloquearPersonal` | la clave de cuentas personales la comprueba el servidor contra un hash |
| `downloadAttachment`, `downloadActivityFile` | abren la URL del archivo en vez de un data-URL |
| `confirmWipe` | vacía cada colección por la API (borrado lógico) |

Los modos `'claude'` y `'local'` siguen funcionando igual que antes.

### Concurrencia

Enviar el array completo tiene un riesgo que con un solo navegador no
existía: si dos personas trabajan a la vez, el guardado de la segunda borra
lo que acaba de crear la primera. Por eso cada colección lleva un contador
de versión (`coleccion_version`). El panel manda la versión que leyó; si no
coincide, el servidor responde **409** con los datos buenos y el panel
recarga en vez de sobrescribir.

Es una solución de compatibilidad. El siguiente paso natural es pasar a
endpoints por entidad (`POST /api/pacientes` con un solo paciente), que
elimina el problema de raíz y reduce el tráfico.

## API

| Petición | Qué hace |
|---|---|
| `GET  api/index.php?accion=salud` | diagnóstico de la conexión |
| `GET  api/index.php?accion=coleccion&key=patients` | `{ok, version, value}` |
| `PUT  api/index.php?accion=coleccion&key=patients` | recibe `{version, value}`; 409 si hay conflicto |
| `POST api/index.php?accion=login` | `{usuario, clave}` |
| `POST api/index.php?accion=logout` | cierra la sesión |
| `GET  api/index.php?accion=sesion` | si hace falta iniciar sesión y si ya hay una |
| `POST api/index.php?accion=personal_pin` | `{pin}` → comprueba la clave de cuentas personales |
| `GET  api/archivo.php?id=<uuid>` | entrega un adjunto (exige sesión si el login está activo) |
| `POST api/dni.php` | `{dni}` → nombre desde apiperu.dev |

Claves de colección: las 16 del panel, más `historia_<uid del paciente>`.

## Migración de los datos existentes

Exportar desde el panel con **"Descargar copia de seguridad"** y luego:

```bash
php migrar.php backup-centro-psicologico-2026-09-02.json --simular   # solo informa
php migrar.php backup-centro-psicologico-2026-09-02.json             # escribe
php migrar.php backup-centro-psicologico-2026-09-02.json --vaciar    # borra lo previo
```

Es reejecutable: la conciliación va por `uid`, así que repetir la
importación actualiza en vez de duplicar.

Si el panel nunca se exportó, el propio script explica cómo volcar el
`localStorage` desde la consola del navegador (ejecutarlo sin argumentos).

Al terminar informa de lo que necesita revisión humana:

- **Cumpleaños sin año.** El panel guardaba `'MM-DD'` y descartaba el año
  que el usuario ya había escrito. Las alertas siguen funcionando; la edad
  no se puede calcular hasta completar la fecha.
- **Citas cruzadas.** El panel avisaba del choque pero dejaba guardarlo, así
  que los datos históricos pueden traerlos. Se importan y se listan
  (`SELECT * FROM v_citas_solapadas`).
- **Precios en texto.** `"A definir"`, `"Variable (ej. S/20 de S/50)"`. El
  texto original se conserva en `servicios.precio_texto`, pero esos
  servicios no entran en los reportes hasta ponerles un importe.
- **Contadores de sesiones descuadrados** respecto a las citas completadas.

Las contraseñas **no se importan**: en el prototipo estaban en texto plano.

## Lo que cambia respecto al prototipo

- **Nada se borra de verdad.** Pacientes y citas se marcan como eliminados;
  pagos y gastos se anulan. La historia clínica no se puede destruir desde
  el panel (NTS 139-MINSA/2018, Ley 29733).
- **Las contraseñas se guardan con hash** (bcrypt, coste 12), con bloqueo
  tras 5 intentos fallidos, y hay roles y permisos: recepción no ve la
  historia clínica.
- **Los adjuntos van al disco**, no dentro del JSON en base64. Se sirven por
  `api/archivo.php`, que exige sesión; `storage/` está cerrada por
  `.htaccess`. Archivos idénticos se guardan una sola vez (SHA-256).
- **El token de apiperu.dev no sale del servidor.**
- **Los cruces de agenda se detectan de verdad**: el panel solo comparaba
  horas de inicio idénticas, así que 10:00–11:00 y 10:30–11:30 no chocaban.
- **La clave de las cuentas personales se guarda con hash** y la comprueba
  el servidor. Antes viajaba en claro al navegador y salía en el JSON de la
  copia de seguridad.

## Talleres y cuentas personales

Los dos módulos que el panel sumó después de la primera carga tienen sus
propias tablas, no un hueco en las que ya había:

- **Talleres** (`talleres` + `taller_sesiones` + `taller_participantes`).
  Un taller no es un paciente: no abre historia clínica ni paquete. Sus
  fechas son filas, así que un programa con colegio puede ser cuatro
  sábados con horarios distintos. Los inscritos también, y de ahí sale lo
  cobrado en el cobro por participante; en el pago grupal lo cobrado es lo
  que abonó la entidad contratante y la lista sirve solo para la
  asistencia. Un inscrito que ya está registrado en el centro se enlaza con
  su ficha (`persona_id`), así que el buscador por DNI lo encuentra sin
  gastar consulta de apiperu.dev.
- **Cuentas personales** (`personal_movimientos` + `personal_pagos`). Es
  dinero propio, no del centro: no entra en Finanzas ni en
  `v_resultado_mensual`. Cada mes pagado es una fila con su fecha real, en
  vez del array `pagados[]` con un objeto `fechasPago{}` en paralelo. Son
  **privadas por usuario**: cada quien ve solo las suyas. Si el centro no
  tiene activado el acceso con clave no hay a quién preguntarle, así que se
  guardan a nombre de la cuenta de administrador.

Además, cada tarifa declara su **ámbito** (paciente, grupal o interno), y
en la ficha del paciente solo se ofrecen las de ámbito `paciente`: ni
charlas, ni alquiler del consultorio, ni comisiones. Las tarifas creadas
antes se clasifican por su nombre —la misma regla que usa el panel— y se
pueden corregir a mano.

### Restricción retirada a propósito

El esquema no exige consultorio en las citas presenciales. El panel permite
agendar una sesión presencial y asignar la sala después, y una restricción
que rechaza datos reales existentes no es integridad: es una migración que
falla. La validación se queda en la aplicación.

Por el mismo motivo, la capa de compatibilidad y el importador desactivan el
trigger de solapamiento (`@centro_permitir_solape`) mientras escriben. Las
escrituras directas a la base sí lo aplican, y `v_citas_solapadas` muestra
lo que haya quedado.

## Seguridad

Las carpetas `config/`, `src/`, `db/` y `storage/` están cerradas con
`.htaccess`. **Eso es Apache**: con nginx hay que replicar la regla en la
configuración del sitio, y el servidor embebido de PHP (`php -S`) las ignora
por completo. Lo más sólido es mover `storage/` fuera de `www/` y apuntar
`config/config.php` a la nueva ruta.

Para producción: HTTPS, `'secure' => true` en la cookie de sesión y
`'debug' => false` en la configuración.

## Respaldos

```bash
mysqldump -uroot --single-transaction --routines --triggers centro_psicologico > backup.sql
```

`storage/` se respalda aparte: el volcado SQL no contiene los archivos.
