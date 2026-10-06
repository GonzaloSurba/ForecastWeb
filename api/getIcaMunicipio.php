<?php

/**
 * Endpoint GET. ICA (Indice de Calidad del Aire) de la estacion mas cercana.
 *
 * Acepta 'latitud' + 'longitud' o bien 'municipio'. No contiene logica de negocio:
 * resolver las coordenadas y buscar la estacion es cosa de los Services.
 *
 * Responde 200 con 'ica' a null cuando no hay estaciones dentro del radio de
 * busqueda: es ausencia de dato, no un error de la peticion.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Exception\ApiException;
use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\IcaService;
use App\Services\MunicipioService;

try {
    $municipios = new MunicipioService();
    $nombre = Request::texto('municipio');

    if ($nombre !== null) {
        [$latitud, $longitud] = $municipios->coordenadas($nombre);
    } else {
        if (Request::texto('latitud') === null || Request::texto('longitud') === null) {
            throw ApiException::peticionInvalida(
                "Faltan los parametros 'municipio' o 'latitud' y 'longitud'"
            );
        }

        $latitud = Request::numero('latitud');
        $longitud = Request::numero('longitud');
    }

    ApiResponse::json([
        'ica' => (new IcaService())->estacionMasCercana($latitud, $longitud),
    ]);
} catch (ApiException $e) {
    ApiResponse::desdeExcepcion($e);
} catch (Throwable $e) {
    error_log('[WebTiempo] ' . $e->getMessage());
    ApiResponse::error('Se ha producido un error interno', 500);
}
