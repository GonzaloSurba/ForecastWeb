<?php

namespace App\Http;

use App\Exception\ApiException;

/**
 * Unica salida JSON de la aplicacion. Centraliza el Content-Type y evita que cada
 * endpoint lo repita.
 */
final class ApiResponse {

    private function __construct() {}

    /**
     * @param array<mixed> $datos
     */
    public static function json(array $datos, int $estado = 200): never {
        self::cabecera($estado);

        $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE);

        if ($cuerpo === false) {
            $estado = 500;
            $cuerpo = json_encode(['error' => 'No se pudo codificar la respuesta']);
        }

        echo $cuerpo;
        exit;
    }

    /**
     * Devuelve un cuerpo JSON ya serializado por el servicio de terceros, sin volver a
     * codificarlo. Hace falta porque el php.ini de LAMPP tiene serialize_precision=100,
     * y pasar el JSON por json_decode/json_encode expandia cada decimal a 100 digitos.
     */
    public static function jsonCrudo(string $cuerpo, int $estado = 200): never {
        self::cabecera($estado);

        echo $cuerpo;
        exit;
    }

    public static function error(string $mensaje, int $estado = 400): never {
        self::json(['error' => $mensaje], $estado);
    }

    /**
     * Traduce una excepcion controlada a su respuesta JSON equivalente.
     */
    public static function desdeExcepcion(ApiException $e): never {
        self::error($e->getMessage(), $e->estadoHttp());
    }

    private static function cabecera(int $estado): void {
        http_response_code($estado);
        header('Content-Type: application/json; charset=utf-8');
    }
}