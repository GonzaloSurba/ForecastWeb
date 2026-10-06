<?php

namespace App\Services;

use App\Config;
use App\Exception\ApiException;
use App\Utils\Encoding;
use App\Utils\HttpClient;

/**
 * Búsqueda de municipios y poblaciones mediante la api Carto Ciudad.
 */
final class CartoCiudadService {

    private const MUNICODE = 'muniCode';

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * Devuelve una lista de diccionarios con los resultados ordenados de mayor a menor coincidencia.
     *
     * @throws ApiException 502 si Carto Ciudad falla o devuelve algo ilegible.
     */
    public function buscarPorNombre(string $nombreMunicipio, int $limit = 5): ?array {
        $config = Config::cartoCiudad();

        $url = strtr($config['url'], [
            '{municipio}' => rawurlencode($nombreMunicipio),
            '{limit}' => (string) $limit,
        ]);

        $respuesta = $this->http->get($url);

        $datos = HttpClient::aArray($respuesta);

        // Carto Ciudad devuelve una lista vacía si no encuentra el municipio
        if ($datos === null || $datos === []) {
            return null;
        }

        return $datos;
    }

    /**
     * Codigo INE de un municipio, aparece en muniCode
     */
    public function codigoIne(string $nombre): ?string {
        $resultados = $this->buscarPorNombre($nombre);
        
        if (empty($resultados) || !isset($resultados[0][self::MUNICODE])) {
            return null;
        }

        return $resultados[0][self::MUNICODE];
    }
}