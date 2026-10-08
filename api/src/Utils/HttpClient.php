<?php

namespace App\Utils;

use App\Exception\ApiException;

/**
 * Cliente HTTP mínimo sobre cURL.
 */
final class HttpClient {

    private const TIMEOUT = 10;
    private const TIMEOUT_CONEXION = 5;

    /**
     * Realiza un GET y devuelve el cuerpo de la respuesta tal cual.
     *
     * @param string[] $cabeceras
     * @param string|null $cainfo Ruta a un bundle CA propio para esta petición,
     *                            para servidores cuya cadena de certificados no
     *                            completa el store del sistema (p. ej. MITECO).
     */
    public function get(string $url, array $cabeceras = [], ?string $cainfo = null): string {
        $manejador = curl_init($url);

        if ($manejador === false) {
            throw new ApiException('No se pudo iniciar la peticion HTTP', 500);
        }

        curl_setopt_array($manejador, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONEXION,
            CURLOPT_FOLLOWLOCATION => true,
            // Solo http/https, también en las redirecciones: sin esto, una URL
            // decidida por un tercero (p. ej. la de datos de AEMET) podría apuntar
            // a file:// o gopher:// y leer ficheros locales (SSRF).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $cabeceras,
        ]);

        if ($cainfo !== null) {
            curl_setopt($manejador, CURLOPT_CAINFO, $cainfo);
        }

        $respuesta = curl_exec($manejador);
        $codigoError = curl_errno($manejador);
        $descripcionError = curl_error($manejador);

        if ($codigoError !== 0 || $respuesta === false) {
            // El detalle va al log del servidor, nunca al cliente: curl_error()
            // incluye la URL, y en OpenWeather la URL lleva la API key.
            error_log('[WebTiempo] Error cURL: ' . $descripcionError);
            throw ApiException::errorExterno('No se pudo contactar con el servicio de meteorologia');
        }

        return $respuesta;
    }

    /**
     * Decodifica un JSON asumiendo UTF-8 y devuelve null si el cuerpo no es JSON válido.
     *
     * @return array<mixed>|null
     */
    public static function aArray(string $cuerpo): ?array {
        $datos = json_decode(Encoding::aUtf8Mixtos($cuerpo), true);

        return is_array($datos) ? $datos : null;
    }
}