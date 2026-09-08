# Panel del Centro Psicológico MAGUSA

Herramienta de gestión del **Centro Psicológico MAGUSA ARCOIRIS DE ESPERANZA S.A.C.** (Perú, UTC-5).
La usa Luis Yangua, gerente general, para el trabajo diario del centro: pacientes, citas, pagos, talleres y cuentas.

Este archivo existe para que cualquier sesión nueva —en esta PC o en otro equipo— retome el trabajo sin volver a preguntar lo mismo.

## Cómo está hecho

Todo el proyecto es **un solo archivo**: `centro-psicologico.html` (~7750 líneas).
Dentro lleva su `<style>` inline y un único `<script>` que empieza cerca de la línea 851.
No hay build, ni npm, ni framework. Se abre con doble clic o con Live Server.

- **Estado:** `let DB = {...}` (~línea 1209) con `patients`, `appointments`, `professionals`, `practicantes`, `services`, `payments`, `expenses`, `calendarEvents`, `products`, `centerInfo`, `roomUsage`, `attendanceLog`, `talleres`, `personalEntries`, `personalConfig`, `authConfig`.
- **Persistencia:** `getColl` / `setColl`. `STORAGE_MODE` vale `'claude'` si existe `window.storage`, si no `'local'` sobre localStorage con prefijo `centroPsicologico_`.
- **Vistas:** template literals; `renderPanel()` hace un switch sobre `currentTab` (~línea 1587).
- **Módulos:** resumen, alertas, pacientes, citas, profesionales, practicantes, asistencia, tarifas, pagos, informes, talleres, reportes, personal (cuentas personales con PIN), finanzas, gráficos, calendario, ideas, historia clínica.

## Reglas del negocio que hay que respetar

Estas no se deducen del código; las definió Luis:

- **Talleres, dos modelos.** *Por participante*: él organiza todo y cobra a cada inscrito, con registro de datos para diplomas y alertas. *Pago grupal*: un colegio o empresa lo contrata solo para dictarlo — ahí **no** debe figurar "Personas inscritas".
- **Pagos.** El tipo de pago se filtra según la categoría: registrando un paciente no deben aparecer pagos de talleres, colegios ni alquiler. Los adelantos se descuentan y el mensaje de cobro envía **solo el saldo pendiente**, nunca el total.
- **Recordatorios humanos.** A un menor se le avisa al apoderado nombrando el parentesco real ("su hija", "su sobrino"). Si ambos contactos son adultos, se avisa a los dos, con botón para omitir a cada uno.
- **Consultas de DNI.** Buscar primero en la base de datos propia y recién después llamar a apiperu.dev: los toques de la API son limitados y cuestan.
- **Agenda.** Validar que no se crucen consultorio, profesional ni paciente en la misma fecha y hora.
- **Gastos.** Fijos y variables van separados, y los variables se suman solo del mes que corresponde: una compra de marzo no debe seguir bajando el margen de setiembre.

## Fechas

Perú es UTC-5, así que `toISOString()` devuelve el día siguiente a partir de las 7 de la noche.
Para cualquier fecha del día usar `fechaLocalISO()` / `todayISO()`, nunca `toISOString()` directo.
Este detalle ya causó un bug real: un adelanto de S/150 desaparecía del saldo.

## Datos y secretos — límites firmes

- El repositorio está en GitHub. **Nunca** escribir en un archivo versionado el token de apiperu.dev; su único lugar es el campo "Token de apiperu.dev" en Datos del centro, que vive en el localStorage del navegador.
- **Nunca** commitear datos de pacientes: historias clínicas, DNI, teléfonos, pagos. El repo guarda el programa; los datos viven en el navegador de Luis.
- Antes de cada commit: revisar `git status` para que solo esté preparado el archivo previsto, y leer el diff buscando tokens, contraseñas o datos personales.
- El localStorage tope medido es **4.9 MB**. Los adjuntos van en base64, así que un PDF de 2 MB ocupa 2.67 MB.
- El JSON de "Descargar copia de seguridad" es texto plano: contiene historias clínicas y también el PIN de cuentas personales. El PIN no es cifrado real, y conviene guardar varias copias fechadas, no solo la última.

## Cómo verificar un cambio

En la máquina de Luis **no hay node ni python**. Los cambios se comprueban con Chrome headless: se arma una copia de prueba inyectando un script de sondeo antes de `</body>` y se vuelca el DOM.

```bash
{ sed '$d' centro-psicologico.html | sed '$d'; echo '<script>'; cat "$S/probe.js"; echo '</script>'; echo '</body>'; echo '</html>'; } > "$S/test.html"
```

Detalles que muerden: headless se cuelga con `confirm()` (hay que sobrescribirlo), `innerHTML` escapa `&` como `&amp;`, y el mes se abrevia "set." y no "sept".

Al escribir texto dentro de un template literal, cerrar las etiquetas como `<\/body><\/html>` — si no, Live Server inyecta su script en el lugar equivocado y el JavaScript aparece como texto en la página.

## Cómo trabajar

- Responder **en español**, con lenguaje del negocio (pacientes, apoderados, tarifas, saldos), no de programación.
- Luis pide los commits él mismo. Terminar el cambio, verificarlo, mostrarle el resultado y preguntarle si lo sube.
- Mensajes de commit en español, describiendo lo que gana el centro. Ejemplos del historial: *"Corrige el desfase de fechas y hace visible lo que está por cobrar"*, *"Cuentas personales, gastos por mes y arreglo del botón Editar"*.
- Decir los límites sin adornos cuando aplican.
