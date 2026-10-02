<?php

namespace App\Services;

use App\Config;
use App\Exception\ApiException;
use App\Utils\HttpClient;

/**
 * Tiempo actual por coordenadas de OpenWeather.
 *
 * La URL base esta en el .env con marcadores ({lat}, {lon}, {APIkey}, {lang}, {units}).
 */
final class OpenWeatherService {

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * Devuelve el cuerpo de la respuesta de OpenWeather tal cual, ya validado.
     *
     * Se devuelve la cadena original en vez de un array a proposito: es una respuesta
     * que solo se reenvia al cliente, y volver a serializarla alteraria los decimales.
     *
     * @throws ApiException 502 si OpenWeather falla o devuelve algo ilegible.
     */
    public function tiempo(float $latitud, float $longitud, string $lang = 'es', string $units = 'metric'): string {
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