<?php

namespace App\Services;

use App\Config;
use App\Exception\ApiException;
use App\Utils\HttpClient;
use Generator;

/**
 * Índice de Calidad del Aire (ICA) de la estación más cercana a un punto.
 *
 * MITECO publica un CSV con las estaciones que miden el ICA, pero no todos los
 * municipios tienen estación: se busca la más cercana dentro de un radio (50 km)
 * y, si no la hay, se devuelve null. Null no es un error, igual que en
 * ZonaMeteoalertaService: el dato simplemente no existe para esa ubicación.
 *
 * El fichero se actualiza cada hora, así que se cachea en disco para no pedirlo
 * en cada búsqueda.
 */
final class IcaService {

    /** Radio máximo de búsqueda de estación, en kilómetros. */
    private const RADIO_KM = 50.0;

    /** Los datos son horarios: 15 minutos de caché es más que suficiente. */
    private const TTL_CACHE_SEGUNDOS = 900;

    private const RUTA_CACHE = '/webtiempo-ica.csv';

    /** El servidor MITECO no envía su intermediate FNMT, hace falta bundle propio. */
    private const CAINFO = __DIR__ . '/../../config/ca-ica.pem';

    private const COL_CODIGO = 'cod_estacion';
    private const COL_NOMBRE = 'nombre';
    private const COL_TIPO = 'tipo';
    private const COL_LATITUD = 'latitud';
    private const COL_LONGITUD = 'longitud';
    private const COL_ACTIVA = 'activa';
    private const COL_FECHA = 'fecha';
    private const COL_INDICE = 'indice';
    private const COL_DEBIDO_A = 'debido_a';

    /**
     * Categorías oficiales del ICA (Orden TEC/351/2019 y su actualización).
     * El valor del índice es la categoría; cuando el dato esta calculado con
     * menos contaminantes de los que mide la estación, MITECO lo publica
     * multiplicado por 10 (10, 20, ... 60).
     */
    private const CATEGORIAS = [
        1 => 'Buena',
        2 => 'Razonablemente buena',
        3 => 'Regular',
        4 => 'Desfavorable',
        5 => 'Muy desfavorable',
        6 => 'Extremadamente desfavorable',
    ];

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * Estación activa con ICA más cercana al punto, o null si no hay ninguna
     * dentro del radio de búsqueda.
     *
     * @return array<string,mixed>|null
     * @throws ApiException 502 si MITECO no responde y no hay caché previa.
     */
    public function estacionMasCercana(float $latitud, float $longitud): ?array {
        $csv = $this->csv();

        $mejor = null;
        $mejorDistancia = 0.0;

        foreach (self::filas($csv) as $fila) {
            $distancia = self::distanciaKm(
                $latitud,
                $longitud,
                (float) $fila[self::COL_LATITUD],
                (float) $fila[self::COL_LONGITUD]
            );

            if ($distancia > self::RADIO_KM) {
                continue;
            }

            if ($mejor === null || $distancia < $mejorDistancia) {
                $mejor = $fila;
                $mejorDistancia = $distancia;
            }
        }

        if ($mejor === null) {
            return null;
        }

        return self::aFormatoWeb($mejor, $mejorDistancia);
    }

    /**
     * El CSV descargado, cacheado en disco. Si MITECO falla pero hay una caché
     * caducada, se usa esta: un dato de hace media hora sigue siendo utilizable
     * y evita que el ICA desaparezca de la página por un corta encajado.
     *
     * @throws ApiException 502 si no hay ni descarga ni caché.
     */
    private function csv(): string {
        $rutaCache = sys_get_temp_dir() . self::RUTA_CACHE;
        $cache = @file_get_contents($rutaCache);

        if ($cache !== false && @filemtime($rutaCache) >= time() - self::TTL_CACHE_SEGUNDOS) {
            return $cache;
        }

        try {
            $csv = $this->http->get(Config::ica()['url'], [], self::CAINFO);
        } catch (ApiException $e) {
            if ($cache !== false) {
                error_log('[WebTiempo] ICA: sin conexion, se usa la cache caducada: ' . $e->getMessage());

                return $cache;
            }

            throw ApiException::errorExterno('No se pudo descargar el indice de calidad del aire');
        }

        // Antes de guardar, compruebo que es el CSV y no una página de error:
        // lo que se cachea mal se mantiene mal hasta que caduque.
        if (!str_contains(substr($csv, 0, 64), self::COL_CODIGO)) {
            throw ApiException::errorExterno('MITECO ha devuelto un fichero inesperado');
        }

        $temporal = $rutaCache . '.' . getmypid();

        if (@file_put_contents($temporal, $csv) !== false) {
            // rename es atómico: nunca se lee un fichero a medias.
            if (!@rename($temporal, $rutaCache)) {
                @unlink($temporal);
            }
        }

        return $csv;
    }

    /**
     * Filas válidas del CSV: las inactivas o sin índice no sirven para informar.
     *
     * @return Generator<int,array<string,string>>
     */
    private static function filas(string $csv): Generator {
        $lineas = preg_split('/\r\n|\n|\r/', $csv);
        $encabezados = array_map('trim', str_getcsv(array_shift($lineas)));

        foreach (array_intersect($encabezados, [self::COL_ACTIVA, self::COL_LATITUD, self::COL_LONGITUD]) as $requerida) {
            if (!in_array($requerida, $encabezados, true)) {
                throw ApiException::errorExterno('El CSV del ICA no tiene la columna ' . $requerida);
            }
        }

        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }

            $campos = str_getcsv($linea);

            if (count($campos) !== count($encabezados)) {
                continue;
            }

            $fila = array_combine($encabezados, $campos);

            if (strtolower(trim($fila[self::COL_ACTIVA])) !== 'true') {
                continue;
            }

            if (trim($fila[self::COL_INDICE]) === '') {
                continue;
            }

            if (!is_numeric($fila[self::COL_LATITUD]) || !is_numeric($fila[self::COL_LONGITUD])) {
                continue;
            }

            yield $fila;
        }
    }

    /**
     * Distancia entre dos puntos en kilómetros (haversine).
     */
    private static function distanciaKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $radioTierra = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $radioTierra * asin(min(1.0, sqrt($a)));
    }

    /**
     * @param array<string,string> $fila
     * @return array<string,mixed>
     */
    private static function aFormatoWeb(array $fila, float $distancia): array {
        $indice = (int) $fila[self::COL_INDICE];
        $parcial = $indice >= 10 && $indice % 10 === 0;
        $categoria = $indice === 0 ? 'Sin datos' : self::CATEGORIAS[$parcial ? intdiv($indice, 10) : $indice] ?? 'Sin datos';

        return [
            'estacion' => [
                'codigo' => $fila[self::COL_CODIGO],
                'nombre' => $fila[self::COL_NOMBRE],
                'tipo' => $fila[self::COL_TIPO],
            ],
            'indice' => $indice,
            'categoria' => $categoria,
            'parcial' => $parcial,
            'debido_a' => trim($fila[self::COL_DEBIDO_A]) ?: null,
            'fecha' => $fila[self::COL_FECHA],
            // String, no float: el serialize_precision=100 del php.ini expandiría
            // 3.7 a 3.7000000000000001776... en el JSON.
            'distancia_km' => number_format($distancia, 1, '.', ''),
        ];
    }
}
