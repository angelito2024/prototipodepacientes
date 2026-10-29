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
- **Dónde corta un paquete y empieza el siguiente.** La fecha que se guarda al renovar es la de **inicio del paquete nuevo**, y el día del corte va al **nuevo**, no al que se cierra. Una sesión ese mismo día es la primera del siguiente. Pasó con un paciente de verdad: su última sesión del paquete anterior fue un día 4 y el nuevo arrancó el 8 — contando el 8 para el viejo salían once sesiones en un paquete de diez y el nuevo mostraba dos cuando iban tres. Ese criterio estaba copiado en tres sitios con las mismas cuatro líneas, y una de ellas al revés (`a.date < desde` en lugar de `>= desde`): fue la que se escapó de la corrección y la que hacía que el contador dijera "van 2" mientras el detalle listaba tres. Ahora vive en **una sola función**, `cortesDePaquete(paciente, idDelPaquete)`, que devuelve `{desde, hasta}` —vacío quiere decir sin límite por ese lado— y de ahí lo toman el contador del paquete vigente, la cobertura de los pagos, el detalle que se manda por WhatsApp, el rótulo de cada fila de cita, la última sesión del paciente y el número de la próxima. Si hay que cambiar el criterio, se cambia ahí y en ningún otro lado. Y como la lista de citas muestra todas las del paciente, cada fila lleva escrito de qué paquete salió; **no** se usa el `paquete_id` guardado en la cita, porque al renovar se reutiliza la fila del paquete y las sesiones viejas se quedan apuntando al nuevo.
- **El historial de paquetes se consulta, no solo se guarda.** Un paquete que terminó deja de ser una operación y pasa a ser un antecedente, y la pregunta que se hace meses después no es "¿cuántas sesiones tenía?" sino **qué días vino, cuánto pagó y si quedó debiendo**. Pero antecedente no es lo mismo que pantalla: de entrada se muestra **solo el paquete en curso con sus fechas**, y lo terminado va detrás de un botón (`verHistorialDePaquetes`). Con todos desplegados, un paciente de tres paquetes llenaba la ficha de cosas acabadas y había que buscar entre ellas cuál es el que está corriendo, que es el que uno viene a ver nueve de cada diez veces. El botón **cerrado ya dice cuántos paquetes anteriores y cuántas sesiones fueron** —si para contar los paquetes de un paciente hay que abrirlo, no sirve de resumen y hay que abrirlo siempre— y abierto encabeza con el total de todo lo recibido desde que llegó al centro: paquetes, sesiones, contratado y pagado. Lo mismo manda en la **lista de citas**: al buscar a un paciente se muestran solo las atenciones del paquete que está corriendo, y las de los cerrados van detrás del mismo botón (`citasDelPaqueteDeAhora`). Trece filas con el rótulo del paquete en cada una no resuelven la pregunta —cuáles son las de ahora—: obligan a leerlas todas. El rótulo sigue ahí para cuando se abren las de antes, el contador de arriba **no cambia** al desplegarlas (sigue contando el paquete vigente) y el despliegue se cierra solo al buscar a otro paciente, o el siguiente aparecería abierto sin que nadie lo pidiera. Por eso cada paquete muestra sus sesiones con fecha, hora, tipo y profesional —en pantalla, no solo armando el mensaje de WhatsApp— y sus pagos repartidos con el **mismo corte** que las sesiones (`pagosDelPaquete`): un pago de agosto abona al paquete de agosto, no al que se abrió en octubre. El mismo cuadro va en la ficha (donde se puede editar) y en la **historia clínica** (solo para leer): "¿cuánto tratamiento recibió este paciente?" no es una pregunta que uno vaya a responder entrando a editar su ficha. Y las fechas que se muestran son las de **las sesiones**, no la que se tecleó al registrar el paquete: son dos cosas distintas y se separaron a la fuerza, porque hubo un paquete anotado el 12 de setiembre cuya primera sesión había sido el 31 de agosto, y el encabezado decía "desde el 12" encima de una lista que empezaba el 31. Cuando no coinciden la pantalla lo dice, y en un paquete **ya cerrado** ofrece corregirlo con un botón; en el vigente **no**, porque ahí esa fecha es la que separa sus pagos de los del anterior y moverla cambiaría el saldo.
- **Pautas de observación.** Hay pruebas que responde el paciente y pautas que marca el profesional mirando al niño (la evaluación pedagógica por edades). En una pauta hay **tres** respuestas, no dos: *sí lo hace*, *aún no* y ***no evaluado***. La tercera no es un adorno: un niño que ese día no colaboró no "falla" el ítem, y el porcentaje de logro se calcula **solo sobre lo que se observó**. El sistema elige la hoja por la fecha de nacimiento, y si se usa otra lo dice por escrito — bajar de hoja es una decisión clínica normal, pero tiene que quedar anotada.
- **Quién entra al sistema.** Los roles existen en la base desde el principio (admin, recepción, profesional, practicante) y `Colecciones::PERMISOS` es lo que les da efecto: cada sección exige un permiso para leer y otro para escribir. El acceso se da desde la **ficha de la persona** —el botón 🔑 en Profesionales y Practicantes—, y la cuenta queda pegada a esa ficha: una por persona. Nunca se borran, se desactivan —borrarlas dejaría la auditoría apuntando a alguien que ya no existe— y **siempre tiene que quedar un administrador activo**, así que nadie puede desactivarse ni bajarse el rol a sí mismo. La contraseña no sale de la base ni con hash: solo se restablece.
- **La clave prestada dura una entrada.** La cuenta nace con el **DNI** como clave, porque es lo que Luis puede dictar en el momento sin inventar ni anotar nada. Pero el DNI no es un secreto: está en el carné, en la ficha y en la lista de asistencia. Por eso nace marcada (`debe_cambiar_clave`) y **mientras lo esté, la sesión no sirve para nada más**: `api/index.php` rechaza toda acción que no sea cambiarla. El bloqueo va en el servidor, no escondiendo pantallas — si estuviera en el navegador bastaría con no abrir el panel. Restablecer una clave vuelve a marcarla, así que la clave que Luis conoce solo sirve para recuperar la cuenta. En la lista de cuentas se ve quién ya puso la suya y quién no.
- **El practicante llena su propia ficha.** Se le manda un enlace con clave y él pone su nombre, DNI, universidad, fechas y los días que viene. El enlace **vence**, sirve **una sola vez** —si no, el mismo enlace reenviado al grupo crearía una ficha por cada persona que lo toque— y queda anotado si lo abrió, que es lo que uno quiere saber cuando lo mandó hace tres días y no llega nada. Registrarse **no da acceso al sistema**: deja una ficha y nada más. La cuenta con usuario y clave es otra cosa y se da aparte.
- **El enlace que recibe el paciente.** Las páginas donde entra sin cuenta —responder una prueba, recibir su tarea— llevan **el logo y el nombre del centro** antes que cualquier pregunta: un enlace que llega por WhatsApp sin decir de quién es, y que pide escribir cómo te sientes, se parece a una estafa, y o no lo abren o lo abren desconfiando, que para una prueba es peor. Debajo va el **mensaje del centro** (`centro_config.mensaje_pacientes`, editable desde Datos del centro: es la voz del centro y no puede depender de que alguien edite un archivo) y **una frase distinta en cada apertura**, que rota en orden —nunca dos veces la misma seguida, y se recorren todas antes de repetir—. Las frases hablan **del trabajo y de la constancia, nunca del ánimo**: un «tú puedes con todo» antes de un cuestionario de ansiedad empuja a contestar que uno está mejor de lo que está, y eso cambia el resultado. Por eso la frase de aliento va **al terminar**, cuando ya no hay respuesta que inclinar.
- **De quién es este enlace.** Antes de mostrar una sola pregunta, la página dice para quién es, con el nombre **recortado** («Julio P. G.»): la familia lo reconoce al toque y quien lo recibió por error no se entera de a quién se atiende en el centro. El nombre completo aparece recién después de confirmar. Esto no es seguridad —el secreto es la clave de 64 caracteres del enlace— y el **DNI tampoco agrega secreto**: está en el carné, en la ficha y en la lista de asistencia. Resuelve otra cosa: un WhatsApp se reenvía, y si al abrirlo salen las preguntas de una, contesta quien lo abrió y esas respuestas entran a la historia clínica de otra persona. Un protocolo respondido por otro es idéntico a uno legítimo —mismos ítems, mismo tiempo, mismo perfil— y es el único error de este sistema que no deja rastro. El candado del DNI (`centro_config.pedir_documento`) se comprueba **en el servidor**, con cinco intentos por cuarto de hora, y solo aplica a quien tenga el documento en su ficha: exigirlo al resto los dejaría encerrados afuera de su propia prueba, así que a ellos se les pide confirmar sin número. Nace apagado a propósito —hoy 11 de los 12 pacientes no tienen DNI cargado— y el panel avisa, al generar cada enlace, si el candado va a aplicar o no.
- **La cara de los documentos.** Un informe psicolaboral que se entrega a una empresa, o uno educativo que va a un colegio, compite con los de otros centros: la primera página decide si lo leen con atención o lo archivan. Los colores **no se eligen**, se miden sobre el logo —petróleo `#24546C`, agua `#3C6C78`, oro `#D8A854`, magenta `#C078A8`, lila `#B490C0` y coral `#F06C60`, los cinco personajes del círculo— y viven en `MARCA_DOC`. Así la hoja y el logo son la misma marca y no dos cosas pegadas. Cada sección estrena un acento, turnándolos: con un solo color las diez secciones de un informe se ven como un bloque y cuesta ubicar dónde empieza cada una. Todo se arma con **tablas y estilos en el atributo `style`**, nunca con clases ni hoja de estilos: Word importa HTML y de todo lo que existe en CSS solo respeta con seguridad eso, así que una clase se pierde al abrir el `.doc`.
- **El membrete y el pie se repiten en cada hoja, y cada salida lo consigue a su manera.** Una hoja suelta de un informe de cuatro tiene que seguir diciendo de dónde salió, así que el membrete **no va dentro del cuerpo** — `documentoHTML(tipo, paraImpresion, sinMembrete)` lo deja fuera y lo pone quien exporta. En **Word** hace falta declarar una sección (`@page Section1`) y colgarle dos bloques `mso-element:header` / `footer`; de paso el número de página queda como campo de Word de verdad (`mso-field-code:PAGE` / `NUMPAGES`), que se recalcula solo al editar. Al **imprimir**, Chrome repite lo que esté en `position:fixed`. En **PDF** no se fotografía la página entera: el membrete, la franja, la marca de agua y el pie con su número los dibuja jsPDF hoja por hoja, y del documento solo se captura el cuerpo, que se reparte en el hueco entre uno y otro. Antes era una sola foto cortada cada 842 puntos: el membrete salía solo en la primera página y los cortes caían a media línea. Dos detalles que muerden: el cuerpo se captura en una caja de `anchoUtil × 96/72` píxeles —el documento está escrito en puntos, y en una caja más ancha el mismo texto de 11pt ocupa una fracción menor de la hoja y sale diminuto—, y va en **PNG con fondo transparente**, porque en JPEG el blanco taparía la marca de agua. La marca de agua es solo del PDF y de la impresión: en Word no es HTML sino un objeto del encabezado, y lo que se consigue imitándola es un dibujo que se arrastra al escribir.
- **El centro tiene dos nombres y no son intercambiables.** La **razón social** —con su `S.A.C.`— es la que identifica a la empresa ante SUNAT, así que es la que va junto al RUC en informes, contratos y recibos. El **nombre comercial** es con el que lo conoce la gente, y es el que ve el paciente al abrir su prueba o su tarea: un niño no tiene por qué leer «S.A.C.». Están en `razon_social` y `nombre_comercial`, los dos editables desde Datos del centro, y `Marca::portada()` usa el comercial con el legal de respaldo. Y el **RUC se comprueba**: once dígitos exactos o no se guarda. No es pedantería — llegó uno de doce, y un RUC mal copiado no se nota en pantalla sino en el papel que ya se entregó, con el membrete y la firma de la psicóloga encima.
- **El lema del año no se escribe en el programa.** Los documentos oficiales del Perú llevan arriba el nombre que el Gobierno le pone al año, y cambia cada enero por decreto. Vive en `centro_config.lema_anio` (migración `26`, cargada **en las dos bases**) y se edita desde Datos del centro, junto al eslogan — que es el que sale al pie, al lado de «Documento confidencial».
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

Y lo que se olvida siempre: una migración que toque una tabla que los dos
paneles comparten —`centro_config` es la que muerde— hay que cargarla **en
las dos bases**, cambiando el `USE`. Como el programa es el mismo,
`CentroInfo::guardar()` escribe las mismas columnas en las dos; si faltan
en una, guardar Datos del centro ahí revienta con «Unknown column», y no
se nota hasta que alguien abre el panel personal. Pasó con la migración
`25`: quedó solo en la base del centro y el panel personal se habría roto
al copiarle `src/`.

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

Eso tiene una consecuencia que se paga en la pantalla: una ventana `--app`
**no tiene barra de direcciones ni botón de recargar**. Si los datos
cambian por fuera —otra sesión, una carga hecha aparte, un respaldo
restaurado— la ventana abierta se queda con lo viejo y no hay cómo
refrescarla. Por eso la barra de arriba lleva el botón **Actualizar**
(`refrescarTodo()`), que vuelve a pedirle todo al servidor sin recargar la
página y **respeta la pestaña** en la que está. No es un adorno: sin él la
única salida es cerrar y volver a abrir el programa.

Y una más, que costó tres vueltas: **Chrome se guarda la pantalla aunque
Apache mande `no-store`**, sobre todo si ya hay otra ventana suya abierta
—se reúsa el proceso y con él su caché—. Se cerraba el panel, se volvía a
abrir desde el acceso directo y seguía saliendo el `index.html` viejo, sin
los botones nuevos. Por eso `abrir-panel.ps1` abre con
`?v=<fecha y hora>`: una dirección distinta en cada arranque, de la que
no hay copia guardada. El panel no lee nada de la query, así que no
molesta.

Ojo con la diferencia que confundió de verdad: **Actualizar trae los
datos, no el programa**. Una ventana abierta desde ayer sigue corriendo
el `index.html` de ayer —con los botones de ayer— por mucho que se
refresquen los datos, y uno busca en pantalla un botón que su copia
todavía no tiene. Por eso `api/index.php?accion=salud` devuelve ahora
`panel.version`, leída del propio `index.html`, y el panel la compara con
su `PANEL_VERSION` después de cada Actualizar: si no coinciden, ofrece
recargar. **Al tocar la pantalla hay que subir `PANEL_VERSION`**, o el
aviso no salta y la persona se queda con la copia vieja sin enterarse.

Lo mismo con la **sesión vencida**. Antes el panel seguía dibujando todo
como si nada y cada cosa que se tocaba fallaba al guardar, con un aviso
chico en la barra que nadie mira: la persona trabajaba media hora para
nada. Ahora, en cuanto el servidor contesta «sesión no iniciada o
expirada» —al guardar o al actualizar—, el panel **vuelve a la pantalla
de entrar** diciendo que lo último no se guardó. Dejar seguir trabajando
sobre una sesión muerta es peor que cortar.

## Fechas

Perú es UTC-5, así que `toISOString()` devuelve el día siguiente a partir de las 7 de la noche.
Para cualquier fecha del día usar `fechaLocalISO()` / `todayISO()`, nunca `toISOString()` directo.
Este detalle ya causó un bug real: un adelanto de S/150 desaparecía del saldo.

Y al revés, al **mostrar**: `new Date('2026-10-08')` se lee como medianoche
UTC y al pintarla en hora de Perú sale **07 oct**. Una fecha sola
(AAAA-MM-DD) hay que armarla con sus tres números —`new Date(a, m-1, d)`—
en vez de dejar que el navegador la parsee. Estaba corriendo un día las
fechas de los paquetes, los vencimientos de las pruebas y el último
acceso de cada cuenta; lo arregla `fmtFechaCorta()`.

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
