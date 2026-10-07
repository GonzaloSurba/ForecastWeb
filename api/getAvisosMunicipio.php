<?php

/**
 * Endpoint GET. Avisos Meteoalerta de AEMET vigentes o proximos para un municipio.
 *
 * Acepta 'municipio', 'codine' o bien 'latitud' + 'longitud'. No contiene logica de negocio:
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
    $codine = Request::codigoINE('codine');

    if ($codine === null) {
        if ($nombre === null) {
            if (Request::texto('latitud') === null || Request::texto('longitud') === null) {
                throw ApiException::peticionInvalida(
                    "Faltan los parametros 'municipio', 'codine' o 'latitud' y 'longitud'"
                );
            }

            $nombre = $municipios->nombrePorCoordenadas(
                Request::numero('latitud'),
                Request::numero('longitud')
            );
        }
    }

    $codigoIne = $codine ?? $municipios->codigoIne($nombre);
    $zona = (new ZonaMeteoalertaService())->zonaDe($codigoIne);

    // Sin zona no hay forma de saber que avisos afectan al municipio, pero tampoco es un
    // fallo de la peticion: se responde 200 con la lista vacia y se avisa al log.
    if ($zona === null) {
        // El nombre viene del cliente: sin filtrar los saltos de linea permitiría
        // inventar lineas falsas en el log del servidor.
        $nombreLog = str_replace(["\r", "\n"], ' ', $nombre ?? $codine);
        error_log("[WebTiempo] El municipio '$nombreLog' no tiene zona Meteoalerta en la tabla");

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