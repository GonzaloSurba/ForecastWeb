<?php

namespace App\Services;

use App\Config;
use App\Exception\ApiException;
use App\Utils\Cache;
use App\Utils\HttpClient;

/**
 * Tiempo actual por coordenadas de OpenWeather.
 *
 * La URL base esta en el .env con marcadores ({lat}, {lon}, {APIkey}, {lang}, {units}).
 */
final class OpenWeatherService {

    /** El tiempo actual cambia poco en minutos; 10 min mantiene la sensacion de frescura. */
    private const TTL_CACHE_SEGUNDOS = 600;

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * Devuelve el cuerpo de la respuesta de OpenWeather tal cual, ya validado.
     *
     * Se devuelve la cadena original en vez de un array a propósito: es una respuesta
     * que solo se reenvia al cliente, y volver a serializarla alteraría los decimales.
     *
     * @throws ApiException 502 si OpenWeather falla o devuelve algo ilegible.
     */
    public function tiempo(float $latitud, float $longitud, string $lang = 'es', string $units = 'metric'): string {
        $clave = sprintf('ow-%s,%s,%s,%s', $latitud, $longitud, $lang, $units);
        $cacheada = Cache::obtener($clave, self::TTL_CACHE_SEGUNDOS);

        if ($cacheada !== null) {
            return $cacheada;
        }

        try {
            $cuerpo = $this->descargar($latitud, $longitud, $lang, $units);
        } catch (ApiException $e) {
            $caducada = Cache::obtenerCaduco($clave);

            if ($caducada !== null) {
                error_log('[WebTiempo] OpenWeather: sin datos frescos, se sirve la cache caducada: ' . $e->getMessage());

                return $caducada;
            }

            throw $e;
        }

        Cache::guardar($clave, $cuerpo);

        return $cuerpo;
    }

    /**
     * Descarga y valida el tiempo actual de OpenWeather para el punto pedido.
     *
     * @throws ApiException 502 si OpenWeather falla o devuelve algo ilegible.
     */
    private function descargar(float $latitud, float $longitud, string $lang, string $units): string {
        $config = Config::openWeather();

        $url = strtr($config['url'], [
            '{lat}' => (string) $latitud,
            '{lon}' => (string) $longitud,
            '{APIkey}' => $config['apikey'],
            '{lang}' => $lang,
            '{units}' => $units,
        ]);

        $respuesta = $this->http->get($url);

        $datos = HttpClient::aArray($respuesta);

        if ($datos === null || $datos === []) {
            throw ApiException::errorExterno('OpenWeather no ha devuelto datos');
        }

        return $respuesta;
    }
}