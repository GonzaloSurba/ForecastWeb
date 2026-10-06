<?php

/**
 * Endpoint GET. Obtengo las coincidencias de municipios.
 *
 * Acepta 'municipio'. No contiene logica de negocio:
 * obtener los municipios es cosa de los Services.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Exception\ApiException;
use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\MunicipioService;
use App\Services\CartoCiudadService;

try {
    $cartoCiudad = new CartoCiudadService();
    $municipios = new MunicipioService();
    $nombre = Request::texto('municipio');

    if ($nombre === null) {
        throw ApiException::peticionInvalida(
            "Faltan el parametro 'municipio'"
        );
    }

    // La API de Carto Ciudad es más completa a la hora de buscar municipios.
    // En caso de que falle, busco el municipio en el CSV.
    $municipiosEncontrados = $cartoCiudad->buscarPorNombre($nombre) ?? $municipios->porNombre($nombre);

    ApiResponse::json($municipiosEncontrados);
} catch (ApiException $e) {
    ApiResponse::desdeExcepcion($e);
} catch (Throwable $e) {
    error_log('[WebTiempo] ' . $e->getMessage());
    ApiResponse::error('Se ha producido un error interno', 500);
}