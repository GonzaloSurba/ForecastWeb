<?php

/**
 * Endpoint GET. Avisos Meteoalerta de AEMET vigentes o proximos para un municipio.
 *
 * Acepta 'municipio' o bien 'latitud' + 'longitud'. No contiene logica de negocio:
 * resolver el municipio, buscar su zona Meteoalerta y pedir los avisos es cosa de los
 * Services.
 */

require_once __DIR__ . '/src/bootstrap.php';

use App\Exception\ApiException;
use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\AemetAvisosService;
use App\Services\MunicipioService;
use App\Services\ZonaMeteoalertaService;

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

    $zona = (new ZonaMeteoalertaService())->zonaDe($municipios->codigoIne($nombre));

    // Sin zona no hay forma de saber que avisos afectan al municipio, pero tampoco es un
    // fallo de la peticion: se responde 200 con la lista vacia y se avisa al log.
    if ($zona === null) {
        error_log("[WebTiempo] El municipio '$nombre' no tiene zona Meteoalerta en la tabla");

        $avisos = [];
    } else {
        $avisos = (new AemetAvisosService())->avisos($zona['codigo']);
    }

    ApiResponse::json([
        'municipio' => $nombre,
        'zona' => $zona === null ? null : $zona + ['ccaa' => ZonaMeteoalertaService::ccaaDe($zona['codigo'])],
        'avisos' => $avisos,
    ]);
} catch (ApiException $e) {
    ApiResponse::desdeExcepcion($e);
} catch (Throwable $e) {
    error_log('[WebTiempo] ' . $e->getMessage());
    ApiResponse::error('Se ha producido un error interno', 500);
}