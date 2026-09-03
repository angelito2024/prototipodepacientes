<?php
declare(strict_types=1);

namespace Centro;

/** Utilidades para responder JSON desde la API. */
final class Http
{
    /** Cuerpo JSON de la petición, ya decodificado. */
    public static function cuerpo(): array
    {
        $crudo = file_get_contents('php://input') ?: '';
        if ($crudo === '') {
            return [];
        }
        $datos = json_decode($crudo, true);
        if (!is_array($datos)) {
            self::error('El cuerpo de la petición no es JSON válido.', 400);
        }
        return $datos;
    }

    public static function json(array $datos, int $codigo = 200): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    public static function ok(array $extra = []): never
    {
        self::json(['ok' => true] + $extra);
    }

    public static function error(string $mensaje, int $codigo = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => $mensaje] + $extra, $codigo);
    }

    public static function metodo(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** IP del cliente en binario, lista para VARBINARY(16). */
    public static function ipBinaria(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $bin = $ip !== '' ? @inet_pton($ip) : false;
        return $bin === false ? null : $bin;
    }

    public static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return $ua === '' ? null : mb_substr($ua, 0, 255);
    }
}
