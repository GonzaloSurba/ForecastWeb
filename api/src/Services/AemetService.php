<?php

namespace App\Services;

use App\Config;
use App\Exception\ApiException;
use App\Utils\Cache;
use App\Utils\Encoding;
use App\Utils\HttpClient;

/**
 * Previsión horaria por municipio de AEMET (OpenData).
 *
 * La API es de dos saltos: la primera petición devuelve las URLs de los datos y de los
 * metadatos, y hay que ir a por la de datos con una segunda petición sin cabecera.
 */
final class AemetService {

    /** La predicción de AEMET se reescribe varias veces al día; 10 min es suficiente. */
    private const TTL_CACHE_SEGUNDOS = 600;

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * Devuelve el cuerpo de la predicción de AEMET ya convertido a UTF-8.
     *
     * Se devuelve la cadena original en vez de un array a propósito: es una respuesta
     * que solo se reenvia al cliente, y volver a serializarla alteraría los decimales.
     *
     * @throws ApiException 502 si AEMET falla o devuelve algo ilegible.
     */
    public function prevision(string $codigoMunicipio): string {
        $clave = 'aemet-' . $codigoMunicipio;
        $cacheada = Cache::obtener($clave, self::TTL_CACHE_SEGUNDOS);

        if ($cacheada !== null) {
            return $cacheada;
        }

        try {
            $cuerpo = $this->descargar($codigoMunicipio);
        } catch (ApiException $e) {
            $caducada = Cache::obtenerCaduco($clave);

            if ($caducada !== null) {
                error_log('[WebTiempo] AEMET: sin datos frescos, se sirve la cache caducada: ' . $e->getMessage());

                return $caducada;
            }

            throw $e;
        }

        Cache::guardar($clave, $cuerpo);

        return $cuerpo;
    }

    /**
     * Descarga y valida la predicción de AEMET para el municipio.
     *
     * @throws ApiException 502 si AEMET falla o devuelve algo ilegible.
     */
    private function descargar(string $codigoMunicipio): string {
        $config = Config::aemet();
        $url = str_replace('{municipio}', $codigoMunicipio, $config['url']);

        $respuesta = $this->http->get($url, [
            'Authorization: Bearer ' . $config['apikey'],
            'Content-Type: application/json',
        ]);

        $datos = HttpClient::aArray($respuesta);

        if ($datos === null || $datos === []) {
            throw ApiException::errorExterno('No se obtuvieron datos de AEMET');
        }

        // AEMET informa de sus errores con un 'estado' >= 400.
        if (isset($datos['estado']) && (int) $datos['estado'] >= 400) {
            throw ApiException::errorExterno(
                'AEMET: ' . (string) ($datos['descripcion'] ?? 'la peticion ha fallado')
            );
        }

        $urlDatos = $datos['datos'] ?? null;

        if (!is_string($urlDatos) || $urlDatos === '') {
            throw ApiException::errorExterno('AEMET no ha devuelto la direccion de los datos');
        }

        // La segunda url la decide AEMET: antes de descargarla se comprueba que sea
        // del propio dominio, por si la respuesta viniera manipulada.
        if (!self::esUrlDeAemet($urlDatos, $config['url'])) {
            throw ApiException::errorExterno('AEMET ha devuelto una direccion de datos inesperada');
        }

        $cuerpo = $this->http->get($urlDatos);

        if (trim($cuerpo) === '') {
            throw ApiException::errorExterno('No se obtuvieron datos de la segunda url de AEMET');
        }

        // AEMET mezcla ISO-8859-15 en el bloque "origen" con UTF-8 en los datos, así que
        // hay que normalizar antes de poder comprobar que el cuerpo es JSON válido.
        $cuerpo = Encoding::aUtf8Mixtos($cuerpo);

        if (HttpClient::aArray($cuerpo) === null) {
            throw ApiException::errorExterno('AEMET ha devuelto una respuesta ilegible');
        }

        return $cuerpo;
    }

    /**
     * true si la url es http(s) y su host es el de la API configurada o un
     * subdominio de aemet.es.
     */
    private static function esUrlDeAemet(string $url, string $urlConfigurada): bool {
        $esquema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $hostConfigurado = strtolower((string) parse_url($urlConfigurada, PHP_URL_HOST));

        if (!in_array($esquema, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return $host === $hostConfigurado
            || $host === 'aemet.es'
            || str_ends_with($host, '.aemet.es');
    }
}