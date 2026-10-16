<?php
declare(strict_types=1);

namespace Centro;

/**
 * La cara del centro en las páginas donde entra el paciente.
 *
 * Son tres —responder una prueba, recibir una tarea, registrarse como
 * practicante— y hasta ahora ninguna decía de quién era. Un enlace que
 * llega por WhatsApp sin logo ni nombre, y que pide escribir cómo te
 * sientes, se parece a una estafa: o no lo abren, o lo abren con
 * desconfianza, que para una prueba psicológica es peor todavía.
 *
 * Todo lo de acá es público a propósito: el nombre del centro, su logo y
 * una frase. Nada de esto identifica al paciente.
 */
final class Marca
{
    /**
     * Lo que se le muestra al paciente en la portada del enlace.
     *
     * @param int $apertura cuántas veces se abrió este enlace (para rotar
     *                      la frase; la primera apertura es 1).
     * @return array{centro:string, lema:string, logo:string, mensaje:string, frase:string}
     */
    public static function portada(int $apertura = 1): array
    {
        $c = Database::uno('SELECT * FROM centro_config WHERE id = 1') ?? [];

        $nombre = trim((string) ($c['nombre_comercial'] ?? ''));
        if ($nombre === '') {
            $nombre = trim((string) ($c['razon_social'] ?? ''));
        }

        return [
            'centro'  => $nombre !== '' ? $nombre : 'Centro Psicológico',
            'lema'    => (string) ($c['lema'] ?? ''),
            'logo'    => self::logo(),
            'mensaje' => self::mensaje($c),
            'frase'   => self::frase($apertura),
        ];
    }

    /**
     * ¿El enlace pide el documento del paciente antes de abrirse?
     *
     * Se lee con `SELECT *` y no por nombre de columna para que el sitio
     * siga funcionando si la migración 25 todavía no se cargó: una columna
     * que falta queda en null, y null es «no lo pide».
     */
    public static function pideDocumento(): bool
    {
        $c = Database::uno('SELECT * FROM centro_config WHERE id = 1') ?? [];
        return (int) ($c['pedir_documento'] ?? 0) === 1;
    }

    /**
     * El logo, como dirección relativa a la raíz del sitio.
     *
     * Se sirve como archivo y no incrustado en el JSON porque son 37 KB:
     * incrustado viaja entero en cada apertura y no se guarda en la caché
     * del teléfono. Como archivo, se baja una vez.
     */
    public static function logo(): string
    {
        return 'marca/logo.png';
    }

    /** El texto corto del centro; si no lo escribieron, uno que sirve. */
    private static function mensaje(array $c): string
    {
        $m = trim((string) ($c['mensaje_pacientes'] ?? ''));
        if ($m !== '') {
            return $m;
        }
        return 'Este enlace es tuyo y no lo ve nadie más. Lo que respondas llega '
             . 'directo a tu psicóloga y se conversa en la consulta.';
    }

    /**
     * Una frase distinta en cada apertura.
     *
     * Rota en orden, no al azar: así nunca sale dos veces seguidas la
     * misma —que es lo que molesta— y además se recorren todas antes de
     * repetir ninguna.
     *
     * Qué NO dicen estas frases, y es deliberado: ninguna promete mejoría,
     * ninguna manda a estar bien y ninguna felicita por sentirse mejor. A
     * alguien que está por contestar un cuestionario de ansiedad, un «tú
     * puedes con todo» lo empuja a responder que está mejor de lo que
     * está, y eso cambia el resultado. Hablan del trabajo, no del ánimo.
     */
    public static function frase(int $apertura): string
    {
        $n = max(1, $apertura) - 1;
        return self::FRASES[$n % count(self::FRASES)];
    }

    public const FRASES = [
        'Lo que se practica en casa es lo que termina cambiando las cosas.',
        'Un paso pequeño hecho hoy vale más que uno grande dejado para mañana.',
        'Pedir ayuda también es una manera de cuidarse.',
        'No tiene que salir perfecto. Tiene que salir.',
        'Lo que hoy cuesta, con práctica se vuelve costumbre.',
        'Avanzar despacio sigue siendo avanzar.',
        'Nadie cambia de golpe: se cambia de a poco y seguido.',
        'Volver a intentarlo también cuenta como avance.',
        'El trabajo de la semana es lo que sostiene la sesión.',
        'Lo importante no es la rapidez, es la constancia.',
        'Cada ejercicio hecho en casa es tiempo ganado en consulta.',
        'Está bien tener un día difícil. Mañana se retoma.',
        'Lo que se puede nombrar se puede trabajar.',
        'Equivocarse es parte del ejercicio, no una falla.',
        'Hacerlo acompañado siempre es más fácil que hacerlo solo.',
        'Cuidarse no es un premio por portarse bien: es parte del tratamiento.',
        'El esfuerzo se hace antes; los cambios se notan después.',
        'Tomarse el tiempo de contestar con calma ya es parte del trabajo.',
    ];

    /**
     * El nombre del paciente, recortado para la portada.
     *
     * «Julio P. G.» en vez del nombre completo. La familia lo reconoce
     * al toque; quien recibió el enlace por error no se entera de a quién
     * se está atendiendo en el centro. El nombre entero aparece recién
     * después de confirmar la identidad.
     *
     * Se arma de los campos de la ficha y no partiendo el nombre completo,
     * porque partiendo la cadena no hay manera de saber dónde terminan los
     * nombres y empiezan los apellidos: «Julio Andrés Pérez García»
     * salía como «Julio A. P.», que recorta el apellido —lo que uno
     * quiere ver— y deja el segundo nombre, que no sirve para reconocer a
     * nadie. Con los campos sale el primer nombre y la inicial de cada
     * apellido, que es como se nombra a alguien en una lista.
     *
     * @param array $p fila de `personas`, o cualquier arreglo con
     *                 `nombres`, `apellido_paterno`, `apellido_materno`;
     *                 si no vienen, cae a `nombre_completo`.
     */
    public static function nombreCorto(array $p): string
    {
        $primer = static function (string $s): string {
            $t = preg_split('/\s+/u', trim($s)) ?: [];
            return (string) ($t[0] ?? '');
        };

        $nombre = $primer((string) ($p['nombres'] ?? ''));
        $apellidos = array_filter([
            trim((string) ($p['apellido_paterno'] ?? '')),
            trim((string) ($p['apellido_materno'] ?? '')),
        ], static fn(string $a): bool => $a !== '');

        // Ficha vieja o incompleta: queda el nombre completo, y de ahí se
        // toma el primer nombre con las iniciales de lo que siga.
        if ($nombre === '' && $apellidos === []) {
            $partes = array_values(array_filter(
                preg_split('/\s+/u', trim((string) ($p['nombre_completo'] ?? ''))) ?: [],
                static fn(string $x): bool => $x !== ''
            ));
            if ($partes === []) {
                return '';
            }
            $nombre = array_shift($partes);
            $apellidos = array_slice($partes, 0, 2);
        }

        $iniciales = array_map(
            static fn(string $a): string => mb_strtoupper(mb_substr($a, 0, 1)) . '.',
            $apellidos
        );
        return trim($nombre . ' ' . implode(' ', $iniciales));
    }
}
