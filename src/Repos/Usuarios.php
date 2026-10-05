<?php
declare(strict_types=1);

namespace Centro\Repos;

use Centro\Auth;
use Centro\Database;
use Centro\Repositorio;
use RuntimeException;

/**
 * Colección 'usuarios': quién entra al sistema y con qué rol.
 *
 * Hasta ahora había una sola cuenta y todo el equipo trabajaba con ella.
 * Dos consecuencias: los roles no servían de nada —no había a quién
 * asignárselos— y el registro de auditoría decía siempre lo mismo, así que
 * no permitía saber quién hizo qué.
 *
 * Reglas que se sostienen desde aquí:
 *
 *  · la contraseña nunca sale de la base, ni siquiera con hash;
 *  · nadie puede desactivarse ni quitarse el rol a sí mismo, porque el
 *    sistema se quedaría sin quién lo administre;
 *  · tiene que quedar siempre al menos un administrador activo;
 *  · las cuentas no se borran, se desactivan: borrarlas dejaría la
 *    auditoría apuntando a alguien que ya no existe.
 */
final class Usuarios extends Repositorio
{
    private const CLAVE_MINIMA = 8;

    public function clave(): string
    {
        return 'usuarios';
    }

    public function leer(): array
    {
        $filas = Database::todos(
            'SELECT u.id, u.usuario, u.nombre_completo, u.email, u.activo,
                    u.ultimo_acceso, u.intentos_fallidos, u.bloqueado_hasta,
                    u.debe_cambiar_clave, u.clave_cambiada_en,
                    per.uid AS persona_uid, per.nombre_completo AS persona,
                    GROUP_CONCAT(r.clave ORDER BY r.clave) AS roles
               FROM usuarios u
          LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
          LEFT JOIN roles r          ON r.id = ur.rol_id
          LEFT JOIN personas per     ON per.id = u.persona_id
           GROUP BY u.id
           ORDER BY u.activo DESC, u.usuario'
        );
        $yo = Auth::usuarioId();
        return array_map(static function (array $f) use ($yo): array {
            return [
                'id'        => (int) $f['id'],
                'usuario'   => $f['usuario'],
                'nombre'    => $f['nombre_completo'],
                'email'     => $f['email'],
                'activo'    => (int) $f['activo'] === 1,
                'roles'     => $f['roles'] === null ? [] : explode(',', (string) $f['roles']),
                'ultimoAcceso' => $f['ultimo_acceso'],
                'bloqueadoHasta' => $f['bloqueado_hasta'],
                'soyYo'     => (int) $f['id'] === $yo,
                // De quién es la cuenta, y si todavía tiene la clave que
                // le dieron. Lo segundo es lo que hace que "cámbiala" deje
                // de ser una recomendación que nadie comprueba.
                'personaId' => $f['persona_uid'],
                'persona'   => (string) ($f['persona'] ?? ''),
                'debeCambiarClave' => (int) $f['debe_cambiar_clave'] === 1,
                'claveCambiadaEn'  => $f['clave_cambiada_en'],
                // La contraseña no viaja nunca, ni siquiera su hash.
            ];
        }, $filas);
    }

    /** Los roles disponibles, para armar el desplegable. */
    public static function roles(): array
    {
        return array_map(static fn(array $r): array => [
            'clave'  => $r['clave'],
            'nombre' => $r['nombre'],
            'descripcion' => $r['descripcion'],
        ], Database::todos('SELECT clave, nombre, descripcion FROM roles ORDER BY id'));
    }

    /** Desde el panel solo se cambia el estado y el rol, nunca la clave. */
    public function guardar(mixed $valor): void
    {
        if (!is_array($valor)) {
            return;
        }
        foreach ($valor as $u) {
            if (!is_array($u) || empty($u['id'])) {
                continue;
            }
            self::actualizar((int) $u['id'], $u);
        }
        $this->subirVersion();
    }

    // ------------------------------------------------------------------

    public static function crear(array $datos): int
    {
        $usuario = strtolower(trim((string) ($datos['usuario'] ?? '')));
        $nombre  = trim((string) ($datos['nombre'] ?? ''));
        $clave   = (string) ($datos['clave'] ?? '');
        $rol     = (string) ($datos['rol'] ?? '');

        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
            throw new RuntimeException(
                'El nombre de usuario debe tener entre 3 y 40 caracteres: letras, '
                . 'números, punto, guion o guion bajo. Sin espacios ni tildes.'
            );
        }
        if ($nombre === '') {
            throw new RuntimeException('Escribe el nombre completo de la persona.');
        }
        if (mb_strlen($clave) < self::CLAVE_MINIMA) {
            throw new RuntimeException(
                'La contraseña debe tener al menos ' . self::CLAVE_MINIMA . ' caracteres.'
            );
        }
        if (Database::valor('SELECT 1 FROM usuarios WHERE usuario = ?', [$usuario]) !== null) {
            throw new RuntimeException("Ya existe un usuario «{$usuario}».");
        }
        $rolId = Database::valor('SELECT id FROM roles WHERE clave = ?', [$rol]);
        if ($rolId === null) {
            throw new RuntimeException('Elige un rol para esta persona.');
        }

        // A qué persona del centro pertenece la cuenta. Sin esto las
        // cuentas flotan sueltas y no se puede saber si la de «rosa.diaz»
        // es la de la profesional Rosa o la de otra Rosa.
        $personaId = null;
        if (!empty($datos['personaId'])) {
            $personaId = Database::valor(
                'SELECT id FROM personas WHERE uid = ? AND eliminado_en IS NULL',
                [(string) $datos['personaId']]
            );
            if ($personaId === null) {
                throw new RuntimeException('Esa persona ya no está en el sistema.');
            }
            $otra = Database::valor(
                'SELECT usuario FROM usuarios WHERE persona_id = ?', [(int) $personaId]
            );
            if ($otra !== null) {
                throw new RuntimeException(
                    "Esa persona ya tiene una cuenta: «{$otra}». Si no la usa, "
                  . 'restablécele la clave en vez de crearle otra.'
                );
            }
        }

        // Cuando la clave la eligió otro —el DNI, por ejemplo— hay que
        // cambiarla al entrar. Ver db/23.
        $debeCambiar = array_key_exists('debeCambiar', $datos)
            ? ($datos['debeCambiar'] ? 1 : 0)
            : 0;

        Database::query(
            'INSERT INTO usuarios (usuario, password_hash, nombre_completo, email,
                                   persona_id, debe_cambiar_clave, clave_cambiada_en, activo)
             VALUES (?,?,?,?,?,?,?,1)',
            [$usuario, password_hash($clave, PASSWORD_DEFAULT), $nombre,
             self::nz($datos['email'] ?? null),
             $personaId === null ? null : (int) $personaId,
             $debeCambiar,
             $debeCambiar === 1 ? null : date('Y-m-d H:i:s')]
        );
        $id = (int) Database::valor('SELECT id FROM usuarios WHERE usuario = ?', [$usuario]);
        Database::query('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (?,?)', [$id, (int) $rolId]);

        Auth::auditar('CREAR_USUARIO', Auth::usuarioId(),
            ['usuario' => $usuario, 'rol' => $rol], 'usuarios');
        return $id;
    }

    /**
     * El acceso de alguien que ya está en el sistema: un profesional, un
     * practicante, quien sea que tenga ficha.
     *
     * La clave inicial es su DNI. Eso solo es aceptable porque nace
     * marcada para cambiar: mientras no la cambie, la sesión no sirve
     * para nada más (ver db/23 y Auth::debeCambiarClave).
     *
     * @return array{id:int, usuario:string, clave:string, nombre:string}
     */
    public static function crearAcceso(string $personaUid, string $rol): array
    {
        $p = Database::uno(
            'SELECT id, nombre_completo, documento, email
               FROM personas WHERE uid = ? AND eliminado_en IS NULL',
            [$personaUid]
        );
        if ($p === null) {
            throw new RuntimeException('Esa persona ya no está en el sistema.');
        }
        $dni = preg_replace('/\D/', '', (string) ($p['documento'] ?? '')) ?? '';
        if (strlen($dni) < self::CLAVE_MINIMA) {
            throw new RuntimeException(
                'Para darle acceso hace falta su DNI, que es la clave con la que '
              . 'va a entrar la primera vez. Anótalo primero en su ficha.'
            );
        }

        $id = self::crear([
            'usuario'     => self::usuarioLibre((string) $p['nombre_completo']),
            'nombre'      => (string) $p['nombre_completo'],
            'email'       => $p['email'],
            'rol'         => $rol,
            'clave'       => $dni,
            'personaId'   => $personaUid,
            'debeCambiar' => true,
        ]);

        return [
            'id'      => $id,
            'usuario' => (string) Database::valor('SELECT usuario FROM usuarios WHERE id = ?', [$id]),
            'clave'   => $dni,
            'nombre'  => (string) $p['nombre_completo'],
        ];
    }

    /**
     * Un nombre de usuario a partir del nombre de la persona:
     * «Rosa Díaz Quispe» -> «rosa.diaz». Si ya está tomado, prueba
     * «rosa.diaz2», «rosa.diaz3»…
     */
    public static function usuarioLibre(string $nombreCompleto): string
    {
        $limpio = strtolower(strtr(
            trim($nombreCompleto),
            ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
             'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n']
        ));
        $partes = array_values(array_filter(preg_split('/\s+/', $limpio) ?: []));
        $base   = implode('.', array_slice($partes, 0, 2));
        $base   = preg_replace('/[^a-z0-9._-]/', '', $base) ?? '';
        $base   = trim($base, '.-_');
        if (strlen($base) < 3) {
            $base = 'usuario';
        }
        $base = substr($base, 0, 36);

        $candidato = $base;
        $n = 1;
        while (Database::valor('SELECT 1 FROM usuarios WHERE usuario = ?', [$candidato]) !== null) {
            $n++;
            $candidato = $base . $n;
        }
        return $candidato;
    }

    public static function actualizar(int $id, array $datos): void
    {
        $yo = Auth::usuarioId();
        $fila = Database::uno('SELECT usuario, activo FROM usuarios WHERE id = ?', [$id]);
        if ($fila === null) {
            throw new RuntimeException('Ese usuario ya no existe.');
        }

        if (array_key_exists('activo', $datos)) {
            $activo = $datos['activo'] ? 1 : 0;
            if ($activo === 0) {
                if ($id === $yo) {
                    throw new RuntimeException(
                        'No puedes desactivar tu propia cuenta: te quedarías fuera del sistema.'
                    );
                }
                self::exigirOtroAdministrador($id);
            }
            Database::query('UPDATE usuarios SET activo = ? WHERE id = ?', [$activo, $id]);
            // Desactivar sin desbloquear deja a alguien fuera dos veces.
            if ($activo === 1) {
                Database::query(
                    'UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?',
                    [$id]
                );
            }
        }

        if (!empty($datos['nombre'])) {
            Database::query('UPDATE usuarios SET nombre_completo = ? WHERE id = ?',
                [trim((string) $datos['nombre']), $id]);
        }
        if (array_key_exists('email', $datos)) {
            Database::query('UPDATE usuarios SET email = ? WHERE id = ?',
                [self::nz($datos['email']), $id]);
        }

        // El panel manda la ficha entera del usuario, y ahí el rol viaja
        // como lista ('roles'), igual que lo devuelve leer(). Al escribirlo
        // se aceptaba solo 'rol' en singular, así que cambiar el rol desde
        // la pantalla no cambiaba nada: el aviso decía que sí y la persona
        // seguía con los permisos de antes. Se aceptan las dos formas.
        $rolPedido = (string) ($datos['rol'] ?? '');
        if ($rolPedido === '' && !empty($datos['roles']) && is_array($datos['roles'])) {
            $rolPedido = (string) reset($datos['roles']);
        }

        if ($rolPedido !== '') {
            $rolId = Database::valor('SELECT id FROM roles WHERE clave = ?', [$rolPedido]);
            if ($rolId === null) {
                throw new RuntimeException('Ese rol no existe.');
            }
            if ($id === $yo && $rolPedido !== 'admin') {
                throw new RuntimeException(
                    'No puedes quitarte a ti mismo el rol de administrador. '
                    . 'Pídeselo a otro administrador.'
                );
            }
            if ($rolPedido !== 'admin') {
                self::exigirOtroAdministrador($id);
            }
            Database::query('DELETE FROM usuario_roles WHERE usuario_id = ?', [$id]);
            Database::query('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (?,?)',
                [$id, (int) $rolId]);
        }

        Auth::auditar('EDITAR_USUARIO', $yo,
            ['usuario' => $fila['usuario'], 'cambios' => array_keys($datos)], 'usuarios');
    }

    /** Le pone una contraseña nueva a alguien que olvidó la suya. */
    public static function restablecerClave(int $id, string $claveNueva): string
    {
        if (mb_strlen($claveNueva) < self::CLAVE_MINIMA) {
            throw new RuntimeException(
                'La contraseña debe tener al menos ' . self::CLAVE_MINIMA . ' caracteres.'
            );
        }
        $usuario = Database::valor('SELECT usuario FROM usuarios WHERE id = ?', [$id]);
        if ($usuario === null) {
            throw new RuntimeException('Ese usuario ya no existe.');
        }
        // La eligió otro, así que vuelve a quedar prestada: al entrar
        // tiene que poner la suya. Así la clave que Luis conoce solo sirve
        // para una entrada, la de recuperar la cuenta.
        Database::query(
            'UPDATE usuarios
                SET password_hash = ?, intentos_fallidos = 0, bloqueado_hasta = NULL,
                    debe_cambiar_clave = 1, clave_cambiada_en = NULL
              WHERE id = ?',
            [password_hash($claveNueva, PASSWORD_DEFAULT), $id]
        );
        Auth::auditar('RESTABLECER_CLAVE', Auth::usuarioId(),
            ['usuario' => $usuario], 'usuarios');
        return (string) $usuario;
    }

    /**
     * Impide dejar al sistema sin administrador activo. Sin esta
     * comprobación, bastaría un clic para que nadie pudiera volver a
     * crear usuarios ni cambiar la configuración.
     */
    private static function exigirOtroAdministrador(int $excepto): void
    {
        $otros = (int) Database::valor(
            "SELECT COUNT(*) FROM usuarios u
               JOIN usuario_roles ur ON ur.usuario_id = u.id
               JOIN roles r ON r.id = ur.rol_id
              WHERE r.clave = 'admin' AND u.activo = 1 AND u.id <> ?",
            [$excepto]
        );
        if ($otros === 0) {
            throw new RuntimeException(
                'Es el único administrador activo. Si lo quitas, nadie podría '
                . 'volver a administrar el sistema. Nombra antes a otro.'
            );
        }
    }
}
