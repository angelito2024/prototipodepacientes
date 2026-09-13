# Panel del Centro Psicológico MAGUSA

Herramienta de gestión del **Centro Psicológico MAGUSA ARCOIRIS DE ESPERANZA S.A.C.** (Perú, UTC-5).
La usa Luis Yangua, gerente general, para el trabajo diario del centro: pacientes, citas, pagos, talleres y cuentas.

Este archivo existe para que cualquier sesión nueva —en esta PC o en otro equipo— retome el trabajo sin volver a preguntar lo mismo.

## Cómo está hecho

La pantalla sigue siendo **un solo archivo**: `index.html` (~7950 líneas; se
llamaba `centro-psicologico.html` hasta que se conectó la base de datos).
Dentro lleva su `<style>` inline y un único `<script>`. No hay build, ni npm,
ni framework.

Detrás hay una **base de datos MySQL y una capa PHP** (rama `capa-datos-mysql`):
`db/*.sql` el esquema, `src/` los mapeadores, `api/` los puntos de entrada.
Ver [README.md](README.md) y [db/README.md](db/README.md).

- **Estado:** `let DB = {...}` con `patients`, `appointments`, `professionals`, `practicantes`, `services`, `payments`, `expenses`, `calendarEvents`, `products`, `centerInfo`, `roomUsage`, `attendanceLog`, `talleres`, `personalEntries`, `personalConfig`, `authConfig`.
- **Persistencia:** `getColl` / `setColl`, con tres modos:
  `'api'` cuando la página se sirve por HTTP (Laragon) — los datos van a
  MySQL por `api/index.php`; `'claude'` si existe `window.storage`; `'local'`
  sobre localStorage con prefijo `centroPsicologico_` al abrir el archivo
  con doble clic. Los tres siguen funcionando.
- **Vistas:** template literals; `renderPanel()` hace un switch sobre `currentTab`.
- **Módulos:** resumen, alertas, pacientes, citas, profesionales, practicantes, asistencia, tarifas, pagos, informes, talleres, reportes, personal (cuentas personales con clave), finanzas, gráficos, calendario, ideas, historia clínica.

Al tocar el panel hay que mantener los tres modos: una función nueva que
guarde algo necesita su rama `'api'`, y una colección nueva necesita además
su repositorio en `src/Repos/`, su entrada en `src/Colecciones.php` y sus
tablas en `db/`.

## Reglas del negocio que hay que respetar

Estas no se deducen del código; las definió Luis:

- **Talleres, dos modelos.** *Por participante*: él organiza todo y cobra a cada inscrito, con registro de datos para diplomas y alertas. *Pago grupal*: un colegio o empresa lo contrata solo para dictarlo — ahí **no** debe figurar "Personas inscritas".
- **Pagos.** El tipo de pago se filtra según la categoría: registrando un paciente no deben aparecer pagos de talleres, colegios ni alquiler. Los adelantos se descuentan y el mensaje de cobro envía **solo el saldo pendiente**, nunca el total.
- **Recordatorios humanos.** A un menor se le avisa al apoderado nombrando el parentesco real ("su hija", "su sobrino"). Si ambos contactos son adultos, se avisa a los dos, con botón para omitir a cada uno.
- **Consultas de DNI.** Buscar primero en la base de datos propia y recién después llamar a apiperu.dev: los toques de la API son limitados y cuestan.
- **Agenda.** Validar que no se crucen consultorio, profesional ni paciente en la misma fecha y hora.
- **Gastos.** Fijos y variables van separados, y los variables se suman solo del mes que corresponde: una compra de marzo no debe seguir bajando el margen de setiembre.
- **Cuentas personales.** Un gasto se puede pagar por partes: lo que importa ver es **cuánto falta**, no si está pagado o no. El **fijo** se renueva cada mes en su día de vencimiento; el **suelto** es un pago único con su propia fecha, y al pagarlo se terminó. Un suelto que quedó debiendo no puede desaparecer al cambiar de mes, pero tampoco vuelve a pesar en el gasto del mes nuevo.

## Fechas

Perú es UTC-5, así que `toISOString()` devuelve el día siguiente a partir de las 7 de la noche.
Para cualquier fecha del día usar `fechaLocalISO()` / `todayISO()`, nunca `toISOString()` directo.
Este detalle ya causó un bug real: un adelanto de S/150 desaparecía del saldo.

## Datos y secretos — límites firmes

- El repositorio está en GitHub. **Nunca** escribir en un archivo versionado el token de apiperu.dev. Con base de datos vive en la tabla `integraciones` y solo lo usa el servidor; sin ella, en el campo "Token de apiperu.dev" de Datos del centro, en el localStorage del navegador.
- **Nunca** commitear datos de pacientes: historias clínicas, DNI, teléfonos, pagos. El repo guarda el programa; los datos viven en la base de Luis. `config/config.php` y `storage/` están en `.gitignore`: son credenciales y adjuntos.
- Antes de cada commit: revisar `git status` para que solo esté preparado el archivo previsto, y leer el diff buscando tokens, contraseñas o datos personales.
- El localStorage tope medido es **4.9 MB**. Los adjuntos van en base64, así que un PDF de 2 MB ocupa 2.67 MB. Ese techo aplica a los modos `'claude'` y `'local'`; con base de datos los adjuntos van a disco y se sirven por `api/archivo.php`.
- El JSON de "Descargar copia de seguridad" es texto plano y contiene historias clínicas: conviene guardar varias copias fechadas, no solo la última. Con base de datos la clave de cuentas personales ya **no** sale ahí (se guarda con hash y la comprueba el servidor); sin base de datos sigue en claro y no es cifrado real.

## Cómo verificar un cambio

En la máquina de Luis **no hay node ni python**, pero sí PHP y MySQL de Laragon:

```
/c/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe
/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe     (root, contraseña vacía)
```

**La pantalla** se comprueba con Chrome headless: se arma una copia de prueba
inyectando un script de sondeo antes de `</body>` y se vuelca el DOM.

```bash
{ sed '$d' index.html | sed '$d'; echo '<script>'; cat "$S/probe.js"; echo '</script>'; echo '</body>'; echo '</html>'; } > "$S/test.html"
"/c/Program Files/Google/Chrome/Application/chrome.exe" --headless --disable-gpu \
  --dump-dom --virtual-time-budget=8000 --user-data-dir="$S/perfil" "file:///$S/test.html"
```

Una sonda útil recorre las 17 pestañas (`currentTab = t; renderPanel()`): si
una vista rompe, se ve ahí.

**El esquema y la capa PHP** se comprueban contra una base desechable, nunca
contra `centro_psicologico`: se carga `db/*.sql` cambiando el nombre de la
base con `sed`, y se ejercitan los repositorios inyectando la configuración
por reflexión sobre `Database::$config`. Así se verificó que la migración
`05` deja la base idéntica a una instalación desde cero (comparando columnas
e índices en `information_schema`).

Detalles que muerden: headless se cuelga con `confirm()` (hay que sobrescribirlo), `innerHTML` escapa `&` como `&amp;`, y el mes se abrevia "set." y no "sept".

Al escribir texto dentro de un template literal, cerrar las etiquetas como `<\/body><\/html>` — si no, Live Server inyecta su script en el lugar equivocado y el JavaScript aparece como texto en la página.

## Cómo trabajar

- Responder **en español**, con lenguaje del negocio (pacientes, apoderados, tarifas, saldos), no de programación.
- Luis pide los commits él mismo. Terminar el cambio, verificarlo, mostrarle el resultado y preguntarle si lo sube.
- Mensajes de commit en español, describiendo lo que gana el centro. Ejemplos del historial: *"Corrige el desfase de fechas y hace visible lo que está por cobrar"*, *"Cuentas personales, gastos por mes y arreglo del botón Editar"*.
- Decir los límites sin adornos cuando aplican.
