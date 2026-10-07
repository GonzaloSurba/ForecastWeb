<?php

/**
 * Endpoint GET. Tiempo actual por coordenadas de OpenWeather.
 *
 * Acepta 'municipio' o bien 'latitud' + 'longitud', mas los opcionales 'lang' y 'units'.
 * No contiene logica de negocio: resolver las coordenadas y pedir el tiempo es cosa de
 * los Services.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Exception\ApiException;
use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\MunicipioService;
use App\Services\OpenWeatherService;

try {
    $municipios = new MunicipioService();
    $municipio = Request::texto('municipio');

    if ($municipio !== null) {
        [$latitud, $longitud] = $municipios->coordenadas($municipio);
    } else {
        $latitud = Request::numero('latitud');
        $longitud = Request::numero('longitud');
    }

    $tiempo = (new OpenWeatherService())->tiempo(
        $latitud,
        $longitud,
        Request::enum('lang', ['es', 'sp', 'pt', 'ca', 'eu', 'gl', 'en'], 'es'),
        Request::enum('units', ['standard', 'metric', 'imperial'], 'metric')
    );

    ApiResponse::jsonCrudo($tiempo);
} catch (ApiException $e) {
    ApiResponse::desdeExcepcion($e);
} catch (Throwable $e) {
    error_log('[WebTiempo] ' . $e->getMessage());
    ApiResponse::error('Se ha producido un error interno', 500);
}