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
- **Cada profesional ve lo suyo, y de lo ajeno solo la hora ocupada.** Los permisos decían qué SECCIONES abre cada perfil, no DE QUIÉN: el servidor no filtraba nada, así que una psicóloga nueva veía la agenda completa, los 13 pacientes y **todas las historias clínicas**, con permiso para escribir y firmar en ellas. Con una sola psicóloga daba igual —todos los pacientes eran suyos—; con dos deja de dar igual, y leer la historia de un paciente que uno no atiende es una falta de confidencialidad, no un detalle de pantalla. `ver.todo` (migración `32`) distingue a quien administra el centro de quien atiende en él: lo tienen `admin` y `recepcion`. Quien no lo tiene ve solo a sus pacientes —el corte va en el **WHERE** de `Pacientes::leer()`, no filtrando después: lo que no corresponde no llega a salir de la base— y `Historia::puedoVerla()` corta leer **y escribir**, porque escribir en la historia de un paciente ajeno es peor que leerla: deja rastro de una atención que no ocurrió. Lo que **no** se esconde es la hora: las citas de otros llegan marcadas `ajena` con fecha, hora y sala y **sin paciente, sin teléfono, sin nada más**. Esconderlas del todo sería peor que el problema — agendaría encima de un colega y el paciente se encontraría el consultorio tomado. Sin acceso con clave (el panel abierto, como lo usa Luis hoy) `veTodoElCentro()` dice que sí y no cambia nada: no hay a quién limitar, y limitar a nadie dejaría la pantalla en blanco.

- **El cruce de agenda ya no se fuerza, salvo para cargar lo que ya pasó.** Antes cualquier cruce se guardaba confirmando un aviso; lo que eso producía era una agenda con sesiones encimadas que nadie veía hasta el día de la cita. Ahora `confirmarPeseAlCruce()` **bloquea** a quien no tiene `ver.todo` —para quien agenda su propia consulta un cruce es un error, no una excepción— y le dice qué hacer en vez de solo negarse. Quien administra el centro todavía puede forzarlo, porque hace falta para cargar datos históricos. Y como negarse sin ofrecer alternativa es inútil, el formulario lleva **🕐 Ver horas libres** (`horasLibresDe()`), que resta de su horario lo que ya tiene —incluidas **las citas ajenas**, que llegan sin nombre pero con su hora: por eso el consultorio no se pisa aunque uno no vea de quién es la otra sesión— y escribe la hora elegida en el formulario, porque una hora que hay que copiar a mano se copia mal.

- **Firmar cierra la nota, y lo impide la base.** Una historia clínica sin firmas es un cuaderno de apuntes: no dice quién se hace responsable de lo que ahí está escrito ni desde cuándo. `firmarEvolucion()` pone `firmado_en` y `firmado_por`, y a partir de ahí la nota **no se edita** — lo impide el trigger `trg_evolucion_bloqueo`, en la base, no la pantalla: esconder el botón no detiene a quien llame a la API a mano. Para corregir se agrega una nota nueva con su fecha, y así la historia muestra qué se dijo, cuándo, y qué se corrigió después, que es lo que se mira cuando alguien la revisa. Tres candados: **no se firma dos veces** (la primera firma manda; volver a firmar cambiaría la fecha de algo ya cerrado), **no se firma una nota vacía** (firmar es afirmar que eso fue la sesión, y no se puede afirmar nada de cuatro campos en blanco), y lo escrito en pantalla **se guarda antes de firmar**, o se firmaría lo que está en la base y no lo que se está leyendo. La pantalla sigue a la base: una nota firmada no ofrece Editar ni Eliminar, porque un botón que al tocarlo siempre falla es peor que no tenerlo. Esto es la firma **del sistema** —deja constancia de quién y cuándo—; la firma digital con DNIe va sobre el PDF exportado y es otra cosa: se complementan y ninguna reemplaza a la otra.

- **Quién abrió una historia clínica queda registrado, y un intento rechazado no cuenta.** `HISTORIA_ABIERTA` se anota en `auditoria` (índice `ix_audit_lectura` de la migración `31`) **después** de comprobar sesión y permiso, a propósito: un 401 no es una lectura, y mezclarlo haría que «quién vio esta historia» devolviera gente que nunca llegó a verla. Lo rechazado ya tiene su propio renglón en `PERMISO_DENEGADO`. Solo las lecturas: el guardado deja su propio rastro y anotarlo dos veces ensucia la búsqueda.

- **La colegiatura vence, y el sí/no no avisa.** La ficha del profesional sabía si estaba habilitada, pero no hasta cuándo — y un sí/no que alguien tiene que acordarse de cambiar a mano no avisa de nada: el día que vence sigue diciendo que sí. Ahora lleva `colegiatura_vence` y `colegiatura_verificada` (migración `31`), y `estadoDeHabilitacion()` es la única fuente del veredicto: *sin colegiatura*, *no habilitada*, *habilitada sin fecha*, *por vencer*, *vencida*, *al día*. Lo que una persona marcó a mano **manda sobre la fecha**: si dijo que no está habilitada, no lo está aunque el vencimiento no haya llegado. Avisa `DIAS_AVISO_COLEGIATURA` (45) días antes, que alcanza para pagar la cuota y que el colegio la registre, y el aviso va **arriba de Alertas, sin esconderse detrás de un botón**: alguien atendiendo sin habilitación es de las pocas cosas que justifican ocupar la pantalla principal. Por qué importa de verdad: **un informe firmado por quien no estaba habilitado ese día es impugnable**, y eso se descubre cuando ya se entregó, con el membrete y la firma encima.

- **El anexo es de la sesión, no de la carpeta.** Un dibujo de «la persona bajo la lluvia» no es del paciente en general: es **de la sesión** en que se le mandó a dibujar. Por eso `hc_adjuntos` lleva `cita_id` (migración `31`) y categorías que nombran lo que de verdad entra: *Produccion del paciente*, *Protocolo aplicado*, *Documento firmado*. Y el tope de subida pasó de 4MB a `TOPE_ANEXO` (25MB) aceptando Word, Excel, audio y video: **el motor de archivos ya los guardaba** —hay 262MB de video y planillas en `archivos` desde hace meses—, lo que no los dejaba entrar era el `accept` de la pantalla y un tope de 4MB heredado de cuando todo iba en base64 al localStorage. Era una cerradura vieja en una puerta ya cambiada, y dejaba afuera justo lo que el centro necesita: la foto de un dibujo tomada con el celular pasa de 4MB sin esfuerzo. El límite que sí queda es el navegador, que lee el archivo entero en memoria antes de mandarlo; por eso un video largo sigue yendo por enlace.

- **Una lista vacía no da de baja nada.** Todos los repositorios concilian igual: el panel manda la colección completa y lo que no está en ella se marca como eliminado. Eso funciona mientras la lista sea de verdad. El 30 de octubre dejó de serlo: se parcheó `Citas::leer()` para traer `codigo_atencion` **dos minutos antes** de cargar la migración que crea esa columna, el SELECT reventó con «Unknown column», el panel se quedó con `DB.appointments = []` y la pantalla mostró **Citas (0)** y todos los paquetes en «0 de 6». Los datos nunca se tocaron —62 citas vivas en la base todo el tiempo—, pero **el único paso que faltaba para perderlas era que alguien tocara guardar**: `bajaFaltantes([])` ejecutaba un `UPDATE citas SET eliminado_en = NOW()` sin condición. Ahora `Repositorio::noVaciarTodo()` frena eso en los tres sitios que daban de baja en bloque (`Citas`, `Talleres`, `Personas`): **lista vacía + filas vivas = no se toca nada** y se devuelve un 409 con el texto que la persona tiene que leer —qué pasó, que sus datos están, y que cierre y vuelva a abrir—, no un «error interno» que va al log. Si la colección ya estaba vacía no hay nada que proteger y se sigue de largo, y vaciar a propósito sigue siendo posible borrando fila por fila desde la pantalla, que es una decisión y no un accidente. La lección de orden: **una migración se carga ANTES de subir el código que la necesita**, nunca después; entre las dos cosas el panel queda roto, y un panel que lee vacío es un panel armado.

- **Cada atención con su código, y de dónde sale ese número.** Las citas siempre tuvieron un identificador único y la base siempre impidió repetirlo, pero era de máquina —`mve1ng04jmg90`— y no se puede dictar por teléfono, ni escribir en un comprobante, ni buscar sin copiarlo y pegarlo. `codigo_atencion` es el mismo dato para la máquina y uno legible para las personas: **A-2026-00001**, doce caracteres, con el año a la vista. Tres reglas lo sostienen, y las tres viven en el **servidor** porque en la pantalla no se pueden cumplir. **El número lo entrega el servidor, nunca el panel**: el panel manda la agenda entera en cada guardado, así que si calculara «el último + 1», dos pantallas abiertas sacarían el mismo — y eso es exactamente lo que viene con caja y admisión trabajando a la vez. Lo entrega `Citas::correlativo()` con un `INSERT ... ON DUPLICATE KEY UPDATE ultimo = LAST_INSERT_ID(ultimo + 1)` sobre `correlativos`, que es **una sola operación atómica**: MySQL serializa los pedidos por la clave de la fila y cada conexión recibe el valor que le tocó a ella. Un `SELECT MAX + 1` se rompe con el segundo usuario. **El número se da cuando la atención ocurre** (estado `Completada`), no al agendar: una cita cancelada no es una prestación y no tiene por qué gastarse un número, y un correlativo con huecos obliga a explicar dónde está el que falta. **Y no se recicla nunca**: si una atención con código se anula después, el código se queda con ella, porque el comprobante que salió con ese número sigue existiendo — reutilizarlo pondría el mismo papel sobre dos atenciones distintas y el código dejaría de ser evidencia de nada. El año es el de **la atención**, no el de hoy: una sesión de 2025 cargada ahora es `A-2025-…`, porque el correlativo se lee como la historia del centro, y por eso el relleno de las que ya existían las numeró **por orden de fecha** y no por orden de carga. El surtidor es genérico a propósito (`ambito`, `periodo`): `pagos.numero_recibo` existe vacío desde el primer día y necesita lo mismo, igual que las ventas. La tabla `correlativos` se creó **en las dos bases** aunque `finanzas_personales` no tenga citas, por lo de siempre: el programa es el mismo y el día que los recibos usen el surtidor, allá faltaría.
- **Qué fue cada sesión.** Una cita lleva su tipo: *Consulta* (la primera, donde se ve qué necesita), *Evaluacion*, *Terapia*, *Seguimiento*, *Devolucion* (entrega de resultados), *Taller*, *Otro*. Importa para leer la historia clínica, para el informe del colegio y para cobrar — una consulta y una sesión de terapia no valen lo mismo. Las claves del ENUM van **sin tildes a propósito**: al cargar un `.sql` sin `--default-character-set=utf8mb4` los acentos de un ENUM se corrompen y después no entra ninguna fila. Las tildes las pone la pantalla (`TIPOS_DE_SESION` en el panel, con `clave` y `etiqueta`). Al cargar migraciones, usar siempre ese parámetro.
- **Dónde corta un paquete y empieza el siguiente.** La fecha que se guarda al renovar es la de **inicio del paquete nuevo**, y el día del corte va al **nuevo**, no al que se cierra. Una sesión ese mismo día es la primera del siguiente. Pasó con un paciente de verdad: su última sesión del paquete anterior fue un día 4 y el nuevo arrancó el 8 — contando el 8 para el viejo salían once sesiones en un paquete de diez y el nuevo mostraba dos cuando iban tres. Ese criterio estaba copiado en tres sitios con las mismas cuatro líneas, y una de ellas al revés (`a.date < desde` en lugar de `>= desde`): fue la que se escapó de la corrección y la que hacía que el contador dijera "van 2" mientras el detalle listaba tres. Ahora vive en **una sola función**, `cortesDePaquete(paciente, idDelPaquete)`, que devuelve `{desde, hasta}` —vacío quiere decir sin límite por ese lado— y de ahí lo toman el contador del paquete vigente, la cobertura de los pagos, el detalle que se manda por WhatsApp, el rótulo de cada fila de cita, la última sesión del paciente y el número de la próxima. Si hay que cambiar el criterio, se cambia ahí y en ningún otro lado. Y como la lista de citas muestra todas las del paciente, cada fila lleva escrito de qué paquete salió; **no** se usa el `paquete_id` guardado en la cita, porque al renovar se reutiliza la fila del paquete y las sesiones viejas se quedan apuntando al nuevo.
- **El historial de paquetes se consulta, no solo se guarda.** Un paquete que terminó deja de ser una operación y pasa a ser un antecedente, y la pregunta que se hace meses después no es "¿cuántas sesiones tenía?" sino **qué días vino, cuánto pagó y si quedó debiendo**. Pero antecedente no es lo mismo que pantalla: de entrada se muestra **solo el paquete en curso con sus fechas**, y lo terminado va detrás de un botón (`verHistorialDePaquetes`). Con todos desplegados, un paciente de tres paquetes llenaba la ficha de cosas acabadas y había que buscar entre ellas cuál es el que está corriendo, que es el que uno viene a ver nueve de cada diez veces. El botón **cerrado ya dice cuántos paquetes anteriores y cuántas sesiones fueron** —si para contar los paquetes de un paciente hay que abrirlo, no sirve de resumen y hay que abrirlo siempre— y abierto encabeza con el total de todo lo recibido desde que llegó al centro: paquetes, sesiones, contratado y pagado. Lo mismo manda en la **lista de citas**: al buscar a un paciente se muestran solo las atenciones del paquete que está corriendo, y las de los cerrados van detrás del mismo botón (`citasDelPaqueteDeAhora`). Trece filas con el rótulo del paquete en cada una no resuelven la pregunta —cuáles son las de ahora—: obligan a leerlas todas. El rótulo sigue ahí para cuando se abren las de antes, el contador de arriba **no cambia** al desplegarlas (sigue contando el paquete vigente) y el despliegue se cierra solo al buscar a otro paciente, o el siguiente aparecería abierto sin que nadie lo pidiera. Por eso cada paquete muestra sus sesiones con fecha, hora, tipo y profesional —en pantalla, no solo armando el mensaje de WhatsApp— y sus pagos repartidos con el **mismo corte** que las sesiones (`pagosDelPaquete`): un pago de agosto abona al paquete de agosto, no al que se abrió en octubre. El mismo cuadro va en la ficha (donde se puede editar) y en la **historia clínica** (solo para leer): "¿cuánto tratamiento recibió este paciente?" no es una pregunta que uno vaya a responder entrando a editar su ficha. Y las fechas que se muestran son las de **las sesiones**, no la que se tecleó al registrar el paquete: son dos cosas distintas y se separaron a la fuerza, porque hubo un paquete anotado el 12 de setiembre cuya primera sesión había sido el 31 de agosto, y el encabezado decía "desde el 12" encima de una lista que empezaba el 31. Cuando no coinciden la pantalla lo dice, y en un paquete **ya cerrado** ofrece corregirlo con un botón; en el vigente **no**, porque ahí esa fecha es la que separa sus pagos de los del anterior y moverla cambiaría el saldo.
- **Escalas breves, marcadas en la consulta.** Las pruebas del centro —MCMI-IV, ICE BarOn, C.A.R.S.— son largas: se mandan por enlace y se corrigen después. El **GAD-7** y el **PHQ-9** son otra cosa: siete y nueve preguntas que la psicóloga marca mientras conversa, y que dan un número comparable sesión a sesión — eso es lo que convierte «lo veo mejor» en «bajó de 15 a 8». Se eligieron estas dos porque son de **uso libre**: sus autores lo dicen en el propio protocolo («No se requiere permiso para reproducir, traducir ni distribuir»), al revés que los paquetes de instrumentos pirateados, que además traen baremos de otro país y fallan sin avisar. Viven en `ESCALAS_BREVES`, se corrigen con `corregirEscala()` y se guardan entre las pruebas de la historia clínica — no en una colección nueva: lo que se quiere es que la de hoy quede al lado de la de hace dos meses.
- **El ítem 9 del PHQ-9 no es un ítem más.** Pregunta por pensamientos de hacerse daño, y **cualquier respuesta distinta de «ningún día» dispara el aviso**, aunque el puntaje total quede por debajo del corte: se puede tener 3 de 27 y estar en riesgo. No se queda en la pantalla — al guardar, el sistema marca «Riesgo autolítico» en el **examen mental** con la fecha y la escala, que es donde se mira antes de cerrar la sesión. Una escala que suma bien pero no avisa de eso está mal hecha. Y una escala a medias no se guarda: un puntaje incompleto no se puede comparar con el de la próxima sesión, así que el botón se queda bloqueado hasta marcar todos los ítems.
- **Pautas de observación.** Hay pruebas que responde el paciente y pautas que marca el profesional mirando al niño (la evaluación pedagógica por edades). En una pauta hay **tres** respuestas, no dos: *sí lo hace*, *aún no* y ***no evaluado***. La tercera no es un adorno: un niño que ese día no colaboró no "falla" el ítem, y el porcentaje de logro se calcula **solo sobre lo que se observó**. El sistema elige la hoja por la fecha de nacimiento, y si se usa otra lo dice por escrito — bajar de hoja es una decisión clínica normal, pero tiene que quedar anotada.
- **Quién entra al sistema.** Los roles existen en la base desde el principio (admin, recepción, profesional, practicante) y `Colecciones::PERMISOS` es lo que les da efecto: cada sección exige un permiso para leer y otro para escribir. El acceso se da desde la **ficha de la persona** —el botón 🔑 en Profesionales y Practicantes—, y la cuenta queda pegada a esa ficha: una por persona. Nunca se borran, se desactivan —borrarlas dejaría la auditoría apuntando a alguien que ya no existe— y **siempre tiene que quedar un administrador activo**, así que nadie puede desactivarse ni bajarse el rol a sí mismo. La contraseña no sale de la base ni con hash: solo se restablece.
- **Cada perfil ve solo lo suyo, y los perfiles se arman desde la pantalla.** Los permisos existían desde el principio y el servidor siempre los respetó, pero el panel **no escondía nada**: la de admisión veía las diecinueve pestañas, entraba a Historia clínica, escribía media página y recién al guardar le saltaba el error. Ahora `PERMISO_DE_PESTANA` ata cada pestaña a un permiso y `tabsDeEstePanel()` filtra — caja pasa de 19 pestañas a 5. Se **esconde**, no se deshabilita: un botón apagado sigue invitando a tocarlo y obliga a preguntar «¿por qué no me deja?»; una pestaña que no está no genera esa pregunta. Esconder es **comodidad, no seguridad**: el servidor sigue rechazando lo que no corresponde, y las dos cosas hacen falta en ese orden. Sin cuentas de verdad —el panel abierto sin clave, como lo usa Luis hoy— `puedo()` dice que sí a todo y no cambia nada.
- **Los perfiles se suman, no se eligen de a uno.** Cuando la de caja se va de vacaciones, a admisión se le agrega el perfil de caja y ve las dos cosas juntas; al volver, se le quita. Por eso un usuario tiene *roles* en plural y los permisos se unen. Los perfiles se crean y editan desde **Accesos al sistema** (migración `27`: `alertas.ver`, `reportes.ver` y `talleres.editar`, más los perfiles `caja` y `seguimiento`), con las casillas escritas en cristiano y no en claves técnicas. Tres candados viven en el **servidor**, no en la pantalla: al perfil `admin` no se le pueden quitar `usuarios.editar` ni `config.editar` —sería cerrar la puerta con la llave adentro—, no se borra un perfil que tenga usuarios, y todo exige `usuarios.editar`.
- **Un paquete que no llega al final también se cierra.** Antes solo se podía cerrar abriendo otro, así que el paciente que se fue en la sesión 5 de 6 seguía figurando con una sesión por usar, en las alertas de cobro y en los reportes, para siempre. `abrirCierreDePaquete()` pide **por qué** se cierra —no es lo mismo un alta que un abandono— y **qué pasa con las sesiones sin usar**: se pierden, se devuelven o quedan a favor. Si hay devolución entra en `payments` **en negativo**, con su fecha y su motivo: una resta hecha a mano en un cuaderno no aparece en ningún reporte. No deja devolver más de lo que pagó ni cerrar antes de la última sesión atendida, y cerrar **no borra la deuda** si quedó debiendo. **Anular** es otra cosa y lo dice: cerrar significa «terminó», anular significa «se registró por error», y si hay sesiones dadas avisa de que quedarían sin paquete al que pertenecer.
- **Pasar un paciente de un profesional a otro.** Cuando un psicólogo deja el centro, cambiar el profesional en la ficha no alcanzaba: las **citas futuras seguían apuntando a quien se fue**, y el día de la cita no la atendía nadie. `abrirTraspaso()` mueve las dos cosas y comprueba antes que el profesional nuevo tenga esas horas libres. Lo que **no** se toca: las citas ya atendidas —las atendió quien las atendió— y el paquete con lo pagado, que son del paciente. Queda anotado en su evolución con el motivo.
- **La clave prestada dura una entrada.** La cuenta nace con el **DNI** como clave, porque es lo que Luis puede dictar en el momento sin inventar ni anotar nada. Pero el DNI no es un secreto: está en el carné, en la ficha y en la lista de asistencia. Por eso nace marcada (`debe_cambiar_clave`) y **mientras lo esté, la sesión no sirve para nada más**: `api/index.php` rechaza toda acción que no sea cambiarla. El bloqueo va en el servidor, no escondiendo pantallas — si estuviera en el navegador bastaría con no abrir el panel. Restablecer una clave vuelve a marcarla, así que la clave que Luis conoce solo sirve para recuperar la cuenta. En la lista de cuentas se ve quién ya puso la suya y quién no.
- **El practicante llena su propia ficha.** Se le manda un enlace con clave y él pone su nombre, DNI, universidad, fechas y los días que viene. El enlace **vence**, sirve **una sola vez** —si no, el mismo enlace reenviado al grupo crearía una ficha por cada persona que lo toque— y queda anotado si lo abrió, que es lo que uno quiere saber cuando lo mandó hace tres días y no llega nada. Registrarse **no da acceso al sistema**: deja una ficha y nada más. La cuenta con usuario y clave es otra cosa y se da aparte.
- **El enlace que recibe el paciente.** Las páginas donde entra sin cuenta —responder una prueba, recibir su tarea— llevan **el logo y el nombre del centro** antes que cualquier pregunta: un enlace que llega por WhatsApp sin decir de quién es, y que pide escribir cómo te sientes, se parece a una estafa, y o no lo abren o lo abren desconfiando, que para una prueba es peor. Debajo va el **mensaje del centro** (`centro_config.mensaje_pacientes`, editable desde Datos del centro: es la voz del centro y no puede depender de que alguien edite un archivo) y **una frase distinta en cada apertura**, que rota en orden —nunca dos veces la misma seguida, y se recorren todas antes de repetir—. Las frases hablan **del trabajo y de la constancia, nunca del ánimo**: un «tú puedes con todo» antes de un cuestionario de ansiedad empuja a contestar que uno está mejor de lo que está, y eso cambia el resultado. Por eso la frase de aliento va **al terminar**, cuando ya no hay respuesta que inclinar.
- **De quién es este enlace.** Antes de mostrar una sola pregunta, la página dice para quién es, con el nombre **recortado** («Julio P. G.»): la familia lo reconoce al toque y quien lo recibió por error no se entera de a quién se atiende en el centro. El nombre completo aparece recién después de confirmar. Esto no es seguridad —el secreto es la clave de 64 caracteres del enlace— y el **DNI tampoco agrega secreto**: está en el carné, en la ficha y en la lista de asistencia. Resuelve otra cosa: un WhatsApp se reenvía, y si al abrirlo salen las preguntas de una, contesta quien lo abrió y esas respuestas entran a la historia clínica de otra persona. Un protocolo respondido por otro es idéntico a uno legítimo —mismos ítems, mismo tiempo, mismo perfil— y es el único error de este sistema que no deja rastro. El candado del DNI (`centro_config.pedir_documento`) se comprueba **en el servidor**, con cinco intentos por cuarto de hora, y solo aplica a quien tenga el documento en su ficha: exigirlo al resto los dejaría encerrados afuera de su propia prueba, así que a ellos se les pide confirmar sin número. Nace apagado a propósito —hoy 11 de los 12 pacientes no tienen DNI cargado— y el panel avisa, al generar cada enlace, si el candado va a aplicar o no.
- **La cara de los documentos.** Un informe psicolaboral que se entrega a una empresa, o uno educativo que va a un colegio, compite con los de otros centros: la primera página decide si lo leen con atención o lo archivan. Los colores **no se eligen**, se miden sobre el logo —petróleo `#24546C`, agua `#3C6C78`, oro `#D8A854`, magenta `#C078A8`, lila `#B490C0` y coral `#F06C60`, los cinco personajes del círculo— y viven en `MARCA_DOC`. Así la hoja y el logo son la misma marca y no dos cosas pegadas. Cada sección estrena un acento, turnándolos: con un solo color las diez secciones de un informe se ven como un bloque y cuesta ubicar dónde empieza cada una. Todo se arma con **tablas y estilos en el atributo `style`**, nunca con clases ni hoja de estilos: Word importa HTML y de todo lo que existe en CSS solo respeta con seguridad eso, así que una clase se pierde al abrir el `.doc`.
- **El membrete y el pie se repiten en cada hoja, y cada salida lo consigue a su manera.** Una hoja suelta de un informe de cuatro tiene que seguir diciendo de dónde salió, así que el membrete **no va dentro del cuerpo** — `documentoHTML(tipo, paraImpresion, sinMembrete)` lo deja fuera y lo pone quien exporta. En **Word** hace falta declarar una sección (`@page Section1`) y colgarle dos bloques `mso-element:header` / `footer`; de paso el número de página queda como campo de Word de verdad (`mso-field-code:PAGE` / `NUMPAGES`), que se recalcula solo al editar. Al **imprimir**, Chrome repite lo que esté en `position:fixed`. En **PDF** no se fotografía la página entera: el membrete, la franja, la marca de agua y el pie con su número los dibuja jsPDF hoja por hoja, y del documento solo se captura el cuerpo, que se reparte en el hueco entre uno y otro. Antes era una sola foto cortada cada 842 puntos: el membrete salía solo en la primera página y los cortes caían a media línea. Dos detalles que muerden: el cuerpo se captura en una caja de `anchoUtil × 96/72` píxeles —el documento está escrito en puntos, y en una caja más ancha el mismo texto de 11pt ocupa una fracción menor de la hoja y sale diminuto—, y va en **PNG con fondo transparente**, porque en JPEG el blanco taparía la marca de agua. La marca de agua es solo del PDF y de la impresión: en Word no es HTML sino un objeto del encabezado, y lo que se consigue imitándola es un dibujo que se arrastra al escribir.
- **El centro tiene dos nombres y no son intercambiables.** La **razón social** —con su `S.A.C.`— es la que identifica a la empresa ante SUNAT, así que es la que va junto al RUC en informes, contratos y recibos. El **nombre comercial** es con el que lo conoce la gente, y es el que ve el paciente al abrir su prueba o su tarea: un niño no tiene por qué leer «S.A.C.». Están en `razon_social` y `nombre_comercial`, los dos editables desde Datos del centro, y `Marca::portada()` usa el comercial con el legal de respaldo. Y el **RUC se comprueba**: once dígitos exactos o no se guarda. No es pedantería — llegó uno de doce, y un RUC mal copiado no se nota en pantalla sino en el papel que ya se entregó, con el membrete y la firma de la psicóloga encima.
- **El lema del año no se escribe en el programa.** Los documentos oficiales del Perú llevan arriba el nombre que el Gobierno le pone al año, y cambia cada enero por decreto. Vive en `centro_config.lema_anio` (migración `26`, cargada **en las dos bases**) y se edita desde Datos del centro, junto al eslogan — que es el que sale al pie, al lado de «Documento confidencial».
- **Un pack comprado no es material libre, y el nombre del archivo no lo distingue.** El importador clasifica mirando el nombre, y eso alcanza mientras el nombre diga de dónde salió. Con el kit «Rutinas visuales y Rutinas mágicas» no alcanzó: la palabra *rutina* dispara la regla de los pictogramas de ARASAAC y los 32 PDF quedaban marcados `libre` · `familia`, o sea **entregables por WhatsApp a treinta familias** — repartir el producto de otro. Son de *Digital School*, vendidos a padres: las propias instrucciones del kit hablan de «tu pequeño», no de un profesional. Por eso `importar.php` acepta ahora `--licencia=` y `--origen=`, que **pisan a las reglas**: quien descargó el pack es el único que sabe si lo pagó. Se importó entero como `solo_sesion` —se usa en consulta y no sale de ahí— hasta que Luis confirme con el vendedor si la licencia alcanza para entregarlo. Y lo pedido se aplica **antes** de contar y de mostrar, no al escribir: una vista previa que enseña una licencia y guarda otra es peor que no tener vista previa. Las instrucciones de impresión del propio kit van como `profesional`: dicen cómo plastificar, no son material para el paciente.

- **Los packs de material no entran al repositorio.** Pesan cientos de megas y son de otros autores. `/materiales/*/` está en `.gitignore` —se agregó cuando una carpeta de 199 MB apareció en `git status` con el repo todavía público—; lo versionado de esa carpeta es `importar.php` y `ver.html`, nada más. Los archivos viven en `storage/materiales/`, deduplicados por `sha256`: el mismo PDF en dos carpetas ocupa espacio una sola vez.

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
