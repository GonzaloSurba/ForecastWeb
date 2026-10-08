<?php

namespace App\Services;

use App\Config;
use App\Exception\ApiException;
use App\Utils\Cache;
use App\Utils\Encoding;
use App\Utils\HttpClient;

/**
 * Búsqueda de municipios y poblaciones mediante la api Carto Ciudad.
 */
final class CartoCiudadService {

    private const MUNICODE = 'muniCode';

    /** La nomenclatura municipal cambia muy después; 24 h evita repetir búsquedas. */
    private const TTL_CACHE_SEGUNDOS = 86400;

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * Devuelve una lista de diccionarios con los resultados ordenados de mayor a menor coincidencia.
     *
     * @throws ApiException 502 si Carto Ciudad falla o devuelve algo ilegible.
     */
    public function buscarPorNombre(string $nombreMunicipio, int $limit = 5): ?array {
        $clave = 'carto-' . sha1(mb_strtolower(trim($nombreMunicipio)) . '|' . $limit);
        $cacheada = Cache::obtener($clave, self::TTL_CACHE_SEGUNDOS);

        if ($cacheada !== null) {
            $datos = HttpClient::aArray($cacheada);

            if ($datos !== null && $datos !== []) {
                return $datos;
            }
        }

        $config = Config::cartoCiudad();

        $url = strtr($config['url'], [
            '{municipio}' => rawurlencode($nombreMunicipio),
            '{limit}' => (string) $limit,
        ]);

        try {
            $respuesta = $this->http->get($url);
        } catch (ApiException $e) {
            $caducada = Cache::obtenerCaduco($clave);

            if ($caducada !== null) {
                $datos = HttpClient::aArray($caducada);

                if ($datos !== null && $datos !== []) {
                    error_log('[WebTiempo] CartoCiudad: sin datos frescos, se sirve la cache caducada: ' . $e->getMessage());

                    return $datos;
                }
            }

            throw $e;
        }

        $datos = HttpClient::aArray($respuesta);

        // Carto Ciudad devuelve una lista vacía si no encuentra el municipio
        if ($datos === null || $datos === []) {
            return null;
        }

        Cache::guardar($clave, $respuesta);

        return $datos;
    }

    /**
     * Código INE de un municipio, aparece en muniCode
     */
    public function codigoIne(string $nombre): ?string {
        $resultados = $this->buscarPorNombre($nombre);
        
        if (empty($resultados) || !isset($resultados[0][self::MUNICODE])) {
            return null;
        }

        return $resultados[0][self::MUNICODE];
    }
}