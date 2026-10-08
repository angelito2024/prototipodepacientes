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
- **Módulos:** resumen, alertas, pacientes, citas, pruebas psicológicas, materiales, profesionales, practicantes, asistencia, tarifas, pagos, informes, talleres, reportes, personal (cuentas personales con clave), finanzas, gráficos, calendario, ideas, historia clínica.

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
- **Reprogramaciones.** Mover una cita **no pisa la fecha anterior**: cada cambio queda como antecedente, con la fecha y hora de la que venía, a la que fue, y **quién pidió el cambio** (el paciente, el centro o el profesional). Esa última parte es la que da sentido a todo: el paciente que corrió su hora cuatro veces y la cita que el centro tuvo que mover no merecen la misma conversación. El mensaje que se le manda al paciente lista **solo las que pidió él** — recordarle las que movió el centro sería echárselas en cara.
- **Paquetes compartidos y sesiones conjuntas.** Una pareja o una familia puede comprar **un** paquete y consumirlo entre varios: cada sesión que toma cualquiera descuenta de la misma bolsa, aunque se atiendan en días y horas distintas. El paquete tiene un **titular**: las sesiones se suman entre todos, pero **la deuda es suya y una sola vez** (si cada uno mostrara el total, el mismo saldo saldría dos veces en las alertas de cobro). Un pago de cualquiera abona al mismo paquete. Aparte están las **sesiones conjuntas**, donde vienen varios a la misma sesión. Ahí el descuento sigue **de dónde sale el dinero, no cuántas personas hubo en la sala**: si comparten paquete —una sola bolsa— la sesión descuenta **una**; si cada uno tiene su propio paquete o se atiende por sesión, a **cada uno se le descuenta la suya**, porque si no el que acompaña recibiría la atención gratis y su paquete nunca bajaría. En los dos casos la sesión se registra en la historia clínica de **cada uno** de los que estuvo. El caso más común no es la pareja sino **el apoderado que también se atiende**: se compra el paquete para el hijo y la madre empieza a tomar sesiones de la misma bolsa. Por eso se da de alta desde la tarjeta del apoderado, en la ficha del paciente ("+ También se atiende"), con los datos que ya están escritos: registrarla a mano desde cero invitaba a olvidar el titular, y sin titular sus sesiones no descontaban de ninguna parte — atención dada y no contada.
- **Varios paquetes a lo largo del tiempo.** Un paciente no compra "un paquete" y ya: cinco sesiones de evaluación en agosto, diez de terapia en setiembre, ocho de lenguaje después. Son acuerdos distintos, con precios y propósitos distintos, y cada uno es una fila en `paciente_paquetes` — uno solo `Activo`, el resto `Cerrado`. Por eso el paquete lleva **concepto**: sin él el historial son números sin sentido. Un paquete que ya pasó se registra desde la ficha con **sus propias fechas**, sin tocar el que está en curso, y no puede terminar después de que empezó el vigente: dos paquetes vivos a la vez dejarían las sesiones sin saber a cuál ir.
- **Qué fue cada sesión.** Una cita lleva su tipo: *Consulta* (la primera, donde se ve qué necesita), *Evaluacion*, *Terapia*, *Seguimiento*, *Devolucion* (entrega de resultados), *Taller*, *Otro*. Importa para leer la historia clínica, para el informe del colegio y para cobrar — una consulta y una sesión de terapia no valen lo mismo. Las claves del ENUM van **sin tildes a propósito**: al cargar un `.sql` sin `--default-character-set=utf8mb4` los acentos de un ENUM se corrompen y después no entra ninguna fila. Las tildes las pone la pantalla (`TIPOS_DE_SESION` en el panel, con `clave` y `etiqueta`). Al cargar migraciones, usar siempre ese parámetro.
- **Pautas de observación.** Hay pruebas que responde el paciente y pautas que marca el profesional mirando al niño (la evaluación pedagógica por edades). En una pauta hay **tres** respuestas, no dos: *sí lo hace*, *aún no* y ***no evaluado***. La tercera no es un adorno: un niño que ese día no colaboró no "falla" el ítem, y el porcentaje de logro se calcula **solo sobre lo que se observó**. El sistema elige la hoja por la fecha de nacimiento, y si se usa otra lo dice por escrito — bajar de hoja es una decisión clínica normal, pero tiene que quedar anotada.
- **Quién entra al sistema.** Los roles existen en la base desde el principio (admin, recepción, profesional, practicante) y `Colecciones::PERMISOS` es lo que les da efecto: cada sección exige un permiso para leer y otro para escribir. El acceso se da desde la **ficha de la persona** —el botón 🔑 en Profesionales y Practicantes—, y la cuenta queda pegada a esa ficha: una por persona. Nunca se borran, se desactivan —borrarlas dejaría la auditoría apuntando a alguien que ya no existe— y **siempre tiene que quedar un administrador activo**, así que nadie puede desactivarse ni bajarse el rol a sí mismo. La contraseña no sale de la base ni con hash: solo se restablece.
- **La clave prestada dura una entrada.** La cuenta nace con el **DNI** como clave, porque es lo que Luis puede dictar en el momento sin inventar ni anotar nada. Pero el DNI no es un secreto: está en el carné, en la ficha y en la lista de asistencia. Por eso nace marcada (`debe_cambiar_clave`) y **mientras lo esté, la sesión no sirve para nada más**: `api/index.php` rechaza toda acción que no sea cambiarla. El bloqueo va en el servidor, no escondiendo pantallas — si estuviera en el navegador bastaría con no abrir el panel. Restablecer una clave vuelve a marcarla, así que la clave que Luis conoce solo sirve para recuperar la cuenta. En la lista de cuentas se ve quién ya puso la suya y quién no.
- **El practicante llena su propia ficha.** Se le manda un enlace con clave y él pone su nombre, DNI, universidad, fechas y los días que viene. El enlace **vence**, sirve **una sola vez** —si no, el mismo enlace reenviado al grupo crearía una ficha por cada persona que lo toque— y queda anotado si lo abrió, que es lo que uno quiere saber cuando lo mandó hace tres días y no llega nada. Registrarse **no da acceso al sistema**: deja una ficha y nada más. La cuenta con usuario y clave es otra cosa y se da aparte.
- **Materiales de trabajo.** Cada material lleva escrito qué se puede hacer con él: *del centro* y *de uso libre* se le pueden entregar a la familia; *solo en sesión* y *reservado* se usan en consulta y no salen de ahí. Los protocolos de pruebas (C.A.R.S y cualquier otro) van siempre como **reservado**: repartirlos infringe los derechos del autor y además arruina la prueba para quien la tenga que responder después. El sistema comprueba esto **en el servidor**, no solo escondiendo el botón. Material ajeno no se vende: lo que el centro cobra es su trabajo.

## Dos paneles, un solo programa

Las cuentas personales de Luis —gastos de casa, préstamos, juntas,
recaudaciones— **ya no viven en el sistema del centro**. El día que entre
una recepcionista o la psicóloga, eso no debe estar ahí ni detrás de una
clave: debe no estar.

| | dirección | base de datos | usuario de MySQL |
|---|---|---|---|
| El centro | `/prototipodepacientes/` | `centro_psicologico` | `app_centro` |
| Lo suyo | `/misfinanzas/` | `finanzas_personales` | `app_finanzas` |

**Usuarios de base distintos a propósito**: con el mismo usuario, separar
las bases no separa gran cosa. Está comprobado que ninguno alcanza la base
del otro.

El programa es **el mismo archivo** para no mantener dos copias que se van
separando con el tiempo: `SOLO_PERSONAL` en `index.html` mira la dirección
desde la que se abrió, y de ahí sale qué pestañas se muestran
(`tabsDeEstePanel()`) y qué colecciones se piden (`loadAll()`). Para
propagar una mejora a `misfinanzas/` se vuelve a copiar `index.html`,
`api/` y `src/` — nunca `config/`, que apunta a la otra base.

`respaldar.ps1` copia **las dos** bases, cada una con su copia cifrada
fuera de la computadora.

## Cómo lo abre Luis

Con el acceso directo **"Panel del Centro MAGUSA"** del escritorio, que corre
`abrir-panel.ps1`. Hace falta porque en esta máquina **Apache y MySQL no son
servicios de Windows**: los levanta Laragon, y con Laragon cerrado un atajo a
la dirección solo muestra "no se puede acceder a este sitio". El script
comprueba si el panel responde, si no levanta `mysqld` y `httpd` (buscados por
patrón, para que sobrevivan a una actualización de Laragon), espera a que
contesten mostrando una ventanita, y abre Chrome con `--app=` para que se vea
como un programa y no como una pestaña. Si algo no arranca lo dice con
palabras. `laragon.exe start` **no** sirve: abre la interfaz pero no enciende
los servicios.

## Fechas

Perú es UTC-5, así que `toISOString()` devuelve el día siguiente a partir de las 7 de la noche.
Para cualquier fecha del día usar `fechaLocalISO()` / `todayISO()`, nunca `toISOString()` directo.
Este detalle ya causó un bug real: un adelanto de S/150 desaparecía del saldo.

## Datos y secretos — límites firmes

- El repositorio está en GitHub. **Nunca** escribir en un archivo versionado el token de apiperu.dev. Con base de datos vive en la tabla `integraciones` y solo lo usa el servidor; sin ella, en el campo "Token de apiperu.dev" de Datos del centro, en el localStorage del navegador.
- **Nunca** commitear datos de pacientes: historias clínicas, DNI, teléfonos, pagos. El repo guarda el programa; los datos viven en la base de Luis. `config/config.php` y `storage/` están en `.gitignore`: son credenciales y adjuntos.
- Antes de cada commit: revisar `git status` para que solo esté preparado el archivo previsto, y leer el diff buscando tokens, contraseñas o datos personales.
- El localStorage tope medido es **4.9 MB**. Los adjuntos van en base64, así que un PDF de 2 MB ocupa 2.67 MB. Ese techo aplica a los modos `'claude'` y `'local'`; con base de datos los adjuntos van a disco y se sirven por `api/archivo.php`.
- **El respaldo de los datos** lo hace `respaldo/respaldar.ps1`, que corre cada noche por la tarea de Windows *MAGUSA respaldo*: vuelca la base entera a `respaldo/copias/`, guarda 30 días, y sube una copia **cifrada** a `OneDrive\Respaldos MAGUSA` (14 copias). La clave vive en `respaldo/clave.txt` y nunca sube. Un respaldo que nadie mira no es un respaldo: el script deja su parte en `copias/estado.json` y el panel lo lee por `api/respaldo.php` para pintar el botón de la barra y avisar en Alertas cuando hace días que no sale una copia. Apache corre con otro usuario de Windows y **no alcanza la carpeta de OneDrive**, por eso el estado se lee de ese archivo y no listando la nube.
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

Una sonda útil recorre las 18 pestañas (`currentTab = t; renderPanel()`): si
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
