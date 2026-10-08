<?php

namespace App;

use App\Exception\ApiException;

/**
 * Acceso a la configuración del .env.
 */
final class Config {

    private const CLAVE_AEMET = 'AEMET_API_KEY';
    private const URL_AEMET = 'AEMET_API_URL';
    private const CLAVE_OPEN_WEATHER = 'OPEN_WEATHER_API_KEY';
    private const URL_OPEN_WEATHER = 'OPEN_WEATHER_API_URL';
    private const URL_CARTO_CIUDAD = 'CARTO_CIUDAD_API_URL';
    private const URL_ICA = 'ICA_ULTIMA_HORA_URL';

    private function __construct() {}

    /** @return array{apikey: string, url: string} */
    public static function aemet(): array {
        return [
            'apikey' => self::requerido(self::CLAVE_AEMET),
            'url' => self::requerido(self::URL_AEMET),
        ];
    }

    /** @return array{apikey: string, url: string} */
    public static function openWeather(): array {
        return [
            'apikey' => self::requerido(self::CLAVE_OPEN_WEATHER),
            'url' => self::requerido(self::URL_OPEN_WEATHER),
        ];
    }

    /** @return array{apikey: string, url: string} */
    public static function cartoCiudad(): array {
        return [
            'url' => self::requerido(self::URL_CARTO_CIUDAD),
        ];
    }

    /** @return array{url: string} */
    public static function ica(): array {
        return [
            'url' => self::requerido(self::URL_ICA),
        ];
    }

    /**
     * Lee una variable de entorno obligatoria.
     */
    private static function requerido(string $clave): string {
        $valor = $_ENV[$clave] ?? getenv($clave);

        if ($valor === false || $valor === null || trim($valor) === '') {
            throw new ApiException(
                "Falta la variable de entorno '$clave' en el fichero .env",
                500
            );
        }

        return trim($valor);
    }
}