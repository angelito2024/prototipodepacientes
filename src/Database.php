<?php
declare(strict_types=1);

namespace Centro;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexión PDO única a MySQL/MariaDB.
 *
 * Reemplaza el almacenamiento en localStorage del prototipo. Toda la
 * aplicación comparte una sola conexión por petición.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static array $config = [];
    private static int $nivelTransaccion = 0;

    /** Carga config/config.php (o config.ejemplo.php como respaldo). */
    public static function config(): array
    {
        if (self::$config !== []) {
            return self::$config;
        }
        $propio  = __DIR__ . '/../config/config.php';
        $ejemplo = __DIR__ . '/../config/config.ejemplo.php';
        $ruta = is_file($propio) ? $propio : $ejemplo;
        if (!is_file($ruta)) {
            throw new RuntimeException(
                'Falta config/config.php. Copia config/config.ejemplo.php y ajusta las credenciales.'
            );
        }
        /** @var array $cfg */
        $cfg = require $ruta;
        self::$config = $cfg;
        return self::$config;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = self::config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            (int) $cfg['puerto'],
            $cfg['nombre'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['usuario'], $cfg['clave'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Sentencias preparadas reales: sin emulación, el servidor
                // recibe los parámetros por separado (nada de concatenar SQL).
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Devuelve INT y FLOAT como tipos nativos, no como texto:
                // los montos DECIMAL siguen llegando como string a propósito,
                // para no perder precisión al pasar por float.
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(self::explicarError($e, $cfg), (int) $e->getCode(), $e);
        }

        // La app trabaja en hora de Lima; el servidor puede estar en UTC.
        $zona = self::config()['zona_horaria'] ?? 'America/Lima';
        date_default_timezone_set($zona);
        $offset = (new \DateTime('now', new \DateTimeZone($zona)))->format('P');
        self::$pdo->exec("SET time_zone = '{$offset}'");

        // Modo estricto explícito: que un dato inválido falle en vez de
        // guardarse truncado en silencio.
        self::$pdo->exec(
            "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'"
        );

        return self::$pdo;
    }

    /** Traduce los errores de conexión más comunes a algo accionable. */
    private static function explicarError(PDOException $e, array $cfg): string
    {
        $codigo = (int) $e->getCode();
        $base   = 'No se pudo conectar a la base de datos. ';

        return match ($codigo) {
            1045 => $base . sprintf(
                'Usuario o contraseña incorrectos para "%s". Revisa config/config.php. '
                . 'Recuerda que el usuario app_centro se crea en db/03_datos_base.sql.',
                $cfg['usuario']
            ),
            1049 => $base . sprintf(
                'La base "%s" no existe. Importa db/01_schema.sql primero.',
                $cfg['nombre']
            ),
            2002 => $base . sprintf(
                'No hay servidor MySQL escuchando en %s:%d. ¿Está Laragon iniciado?',
                $cfg['host'],
                (int) $cfg['puerto']
            ),
            default => $base . $e->getMessage(),
        };
    }

    // ---------- Atajos ----------

    /** @param array<string,mixed>|list<mixed> $params */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** @return list<array<string,mixed>> */
    public static function todos(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function uno(string $sql, array $params = []): ?array
    {
        $fila = self::query($sql, $params)->fetch();
        return $fila === false ? null : $fila;
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function ultimoId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Transacción con anidamiento por SAVEPOINT. Permite que un repositorio
     * abra su propia transacción aunque ya venga dentro de otra.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaccion(callable $fn): mixed
    {
        $pdo = self::pdo();

        if (self::$nivelTransaccion === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT nivel_' . self::$nivelTransaccion);
        }
        self::$nivelTransaccion++;

        try {
            $resultado = $fn();
            self::$nivelTransaccion--;
            if (self::$nivelTransaccion === 0) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT nivel_' . self::$nivelTransaccion);
            }
            return $resultado;
        } catch (\Throwable $e) {
            self::$nivelTransaccion--;
            if (self::$nivelTransaccion === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT nivel_' . self::$nivelTransaccion);
            }
            throw $e;
        }
    }

    /** Solo para pruebas: fuerza una reconexión. */
    public static function reiniciar(): void
    {
        self::$pdo = null;
        self::$config = [];
        self::$nivelTransaccion = 0;
    }
}
