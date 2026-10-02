<?php

/**
 * Endpoint GET. Prevision horaria por municipio de AEMET.
 *
 * Acepta 'municipio' o bien 'latitud' + 'longitud'. No contiene logica de negocio:
 * resolver el municipio y pedir la prediccion es cosa de los Services.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Exception\ApiException;
use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\AemetService;
use App\Services\MunicipioService;

try {
    $municipios = new MunicipioService();
    $nombre = Request::texto('municipio');

    if ($nombre === null) {
        if (Request::texto('latitud') === null || Request::texto('longitud') === null) {
            throw ApiException::peticionInvalida(
                "Faltan los parametros 'municipio' o 'latitud' y 'longitud'"
            );
        }

        $nombre = $municipios->nombrePorCoordenadas(
            Request::numero('latitud'),
            Request::numero('longitud')
        );
    }

    $prevision = (new AemetService())->prevision($municipios->codigoIne($nombre));

    ApiResponse::jsonCrudo($prevision);
} catch (ApiException $e) {
    ApiResponse::desdeExcepcion($e);
} catch (Throwable $e) {
    error_log('[WebTiempo] ' . $e->getMessage());
    ApiResponse::error('Se ha producido un error interno', 500);
}