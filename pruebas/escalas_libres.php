<?php
declare(strict_types=1);

/**
 * Escalas de tamizaje de uso libre.
 *
 * Aquí solo entran instrumentos de dominio público o de uso libre
 * declarado. Las pruebas con derechos de autor —Millon, BarOn, Raven,
 * WISC, Bender, Beck, CASM-83— no se descargan de internet ni se escriben
 * acá: las carga el centro desde su propio protocolo, con su licencia.
 *
 * Qué son y qué no: sirven para decidir si hace falta una evaluación más
 * larga, no para diagnosticar. Un PHQ-9 de 18 puntos no es un diagnóstico
 * de depresión mayor; es una razón para evaluar en serio.
 *
 * --------------------------------------------------------------------
 *  ANTES DE LA PRIMERA APLICACIÓN
 * --------------------------------------------------------------------
 * Los enunciados se transcribieron de la versión en español de cada
 * instrumento, pero no se pudieron contrastar contra el documento
 * oficial en línea. Por eso cada escala se carga con la marca
 * `revisarAntes`, y el panel avisa hasta que un profesional la confirme
 * contra el protocolo que el centro tiene en mano.
 *
 * Fuentes oficiales para esa revisión:
 *   PHQ-9 y GAD-7 .... phqscreeners.com (dominio público, versión español)
 *   Zung ............. protocolo propio del centro (ya lo aplica)
 */

return [

// =====================================================================
//  PHQ-9 · Tamizaje de síntomas depresivos
// =====================================================================
'phq9' => [
    'codigo' => 'phq9',
    'nombre' => 'Cuestionario de Salud del Paciente PHQ-9',
    'nombreEscala' => 'Síntomas depresivos',
    'siglas' => 'PHQ-9',
    'autor'  => 'Spitzer, Kroenke y Williams · dominio público',
    'tipo'   => 'suma',
    'nItems' => 9,
    'valorMinimo' => 0,
    'revisarAntes' => true,
    'ficha' => [
        'descripcion' => 'Tamizaje de síntomas depresivos de las últimas dos semanas. '
                       . 'Nueve preguntas, dos minutos. Sirve para decidir si hace falta '
                       . 'una evaluación más completa, y para medir el cambio entre sesiones.',
        'edadMinima' => 12,
        'minutos'    => 3,
    ],
    'enunciado' => 'Durante las últimas 2 semanas, ¿con qué frecuencia le han '
                 . 'molestado los siguientes problemas?',
    'opciones' => [
        ['valor' => '0', 'etiqueta' => 'Ningún día'],
        ['valor' => '1', 'etiqueta' => 'Varios días'],
        ['valor' => '2', 'etiqueta' => 'Más de la mitad de los días'],
        ['valor' => '3', 'etiqueta' => 'Casi todos los días'],
    ],
    'items' => [
        [1, 'Poco interés o placer en hacer las cosas'],
        [2, 'Se ha sentido decaído(a), deprimido(a) o sin esperanzas'],
        [3, 'Ha tenido dificultad para quedarse o permanecer dormido(a), o ha dormido demasiado'],
        [4, 'Se ha sentido cansado(a) o con poca energía'],
        [5, 'Sin apetito o ha comido en exceso'],
        [6, 'Se ha sentido mal con usted mismo(a), o que es un fracaso, o que ha quedado mal '
          . 'con usted mismo(a) o con su familia'],
        [7, 'Ha tenido dificultad para concentrarse en ciertas actividades, como leer el '
          . 'periódico o ver televisión'],
        [8, 'Se ha movido o hablado tan lento que otras personas podrían haberlo notado; '
          . 'o lo contrario, ha estado tan inquieto(a) o agitado(a) que se ha estado '
          . 'moviendo mucho más de lo normal'],
        [9, 'Pensamientos de que estaría mejor muerto(a) o de lastimarse de alguna manera'],
    ],
    // El 9 no se lee dentro del total: se mira siempre.
    'itemsCriticos' => [
        ['item' => 9, 'desde' => 1,
         'aviso' => 'Marcó pensamientos de muerte o de lastimarse. Esta respuesta se '
                  . 'atiende hoy, sin esperar al resultado del cuestionario ni a la '
                  . 'próxima cita.'],
    ],
    'cortes' => ['total' => [
        ['hasta' => 4,  'texto' => 'Sin síntomas depresivos significativos (0-4)'],
        ['hasta' => 9,  'texto' => 'Síntomas leves (5-9): seguimiento y reevaluación'],
        ['hasta' => 14, 'texto' => 'Síntomas moderados (10-14): sugiere evaluación clínica'],
        ['hasta' => 19, 'texto' => 'Síntomas moderadamente graves (15-19): evaluación e '
                                 . 'inicio de tratamiento'],
        ['hasta' => 999,'texto' => 'Síntomas graves (20-27): evaluación prioritaria y '
                                 . 'considerar interconsulta psiquiátrica'],
    ]],
],

// =====================================================================
//  GAD-7 · Tamizaje de ansiedad generalizada
// =====================================================================
'gad7' => [
    'codigo' => 'gad7',
    'nombre' => 'Escala de Ansiedad Generalizada GAD-7',
    'nombreEscala' => 'Síntomas de ansiedad',
    'siglas' => 'GAD-7',
    'autor'  => 'Spitzer, Kroenke, Williams y Löwe · dominio público',
    'tipo'   => 'suma',
    'nItems' => 7,
    'valorMinimo' => 0,
    'revisarAntes' => true,
    'ficha' => [
        'descripcion' => 'Tamizaje de ansiedad de las últimas dos semanas. Siete '
                       . 'preguntas. Se usa junto al PHQ-9, porque la ansiedad y la '
                       . 'depresión se presentan juntas más veces que separadas.',
        'edadMinima' => 12,
        'minutos'    => 2,
    ],
    'enunciado' => 'Durante las últimas 2 semanas, ¿con qué frecuencia le han '
                 . 'molestado los siguientes problemas?',
    'opciones' => [
        ['valor' => '0', 'etiqueta' => 'Ningún día'],
        ['valor' => '1', 'etiqueta' => 'Varios días'],
        ['valor' => '2', 'etiqueta' => 'Más de la mitad de los días'],
        ['valor' => '3', 'etiqueta' => 'Casi todos los días'],
    ],
    'items' => [
        [1, 'Se ha sentido nervioso(a), ansioso(a) o con los nervios de punta'],
        [2, 'No ha sido capaz de parar o controlar su preocupación'],
        [3, 'Se ha preocupado demasiado por diferentes cosas'],
        [4, 'Ha tenido dificultad para relajarse'],
        [5, 'Se ha sentido tan inquieto(a) que no ha podido quedarse quieto(a)'],
        [6, 'Se ha molestado o irritado fácilmente'],
        [7, 'Ha tenido miedo de que algo terrible fuera a suceder'],
    ],
    'itemsCriticos' => [],
    'cortes' => ['total' => [
        ['hasta' => 4,  'texto' => 'Sin ansiedad significativa (0-4)'],
        ['hasta' => 9,  'texto' => 'Ansiedad leve (5-9): seguimiento'],
        ['hasta' => 14, 'texto' => 'Ansiedad moderada (10-14): sugiere evaluación clínica'],
        ['hasta' => 999,'texto' => 'Ansiedad grave (15-21): evaluación prioritaria'],
    ]],
],

// =====================================================================
//  Rosenberg · Autoestima
// =====================================================================
'rosenberg' => [
    'codigo' => 'rosenberg',
    'nombre' => 'Escala de Autoestima de Rosenberg',
    'nombreEscala' => 'Autoestima',
    'siglas' => 'EAR',
    'autor'  => 'Morris Rosenberg · uso libre con atribución',
    'tipo'   => 'suma',
    'nItems' => 10,
    'valorMinimo' => 1,
    'revisarAntes' => true,
    'ficha' => [
        'descripcion' => 'Diez frases sobre cómo se ve la persona a sí misma. Es la '
                       . 'escala de autoestima más usada y se aplica en dos minutos; '
                       . 'útil al inicio del tratamiento y para medir el cambio.',
        'edadMinima' => 12,
        'minutos'    => 3,
    ],
    'enunciado' => 'Indique en qué medida está de acuerdo con cada una de las '
                 . 'siguientes frases.',
    'opciones' => [
        ['valor' => '1', 'etiqueta' => 'Muy en desacuerdo'],
        ['valor' => '2', 'etiqueta' => 'En desacuerdo'],
        ['valor' => '3', 'etiqueta' => 'De acuerdo'],
        ['valor' => '4', 'etiqueta' => 'Muy de acuerdo'],
    ],
    'items' => [
        [1,  'Siento que soy una persona digna de aprecio, al menos en igual medida que los demás'],
        [2,  'Estoy convencido(a) de que tengo buenas cualidades'],
        [3,  'Soy capaz de hacer las cosas tan bien como la mayoría de la gente'],
        [4,  'Tengo una actitud positiva hacia mí mismo(a)'],
        [5,  'En general, estoy satisfecho(a) conmigo mismo(a)'],
        [6,  'Siento que no tengo mucho de lo que estar orgulloso(a)'],
        [7,  'En general, me inclino a pensar que soy un fracaso'],
        [8,  'Me gustaría poder sentir más respeto por mí mismo(a)'],
        [9,  'Hay veces que realmente pienso que soy un inútil'],
        [10, 'A veces creo que no soy buena persona'],
    ],
    // Las cinco últimas están redactadas al revés.
    'inversos' => [6, 7, 8, 9, 10],
    'itemsCriticos' => [],
    'cortes' => ['total' => [
        ['hasta' => 25, 'texto' => 'Autoestima baja (10-25): conviene trabajarla'],
        ['hasta' => 29, 'texto' => 'Autoestima media (26-29): sin problemas graves, '
                                 . 'con aspectos por mejorar'],
        ['hasta' => 999,'texto' => 'Autoestima normal o alta (30-40)'],
    ]],
],

];
