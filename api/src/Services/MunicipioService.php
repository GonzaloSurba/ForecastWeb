<?php

namespace App\Services;

use App\Exception\ApiException;
use App\Utils\Encoding;
use Generator;

/**
 * Consulta de los dos csv de municipios del proyecto.
 *
 *   config/MUNICIPIOS.csv     8133 municipios, separador ';', codificacion ISO-8859-15, contiene coordenadas
 *   config/diccionario26.csv  8133 municipios, separador ',', codificacion UTF-8
 *                             (columnas CPRO/CMUN, el codigo INE que espera AEMET)
 */
final class MunicipioService {

    private const RUTA_MUNICIPIOS = __DIR__ . '/../../config/MUNICIPIOS.csv';
    private const RUTA_DICCIONARIO = __DIR__ . '/../../config/diccionario26.csv';

    private const SEP_MUNICIPIOS = ';';
    private const SEP_DICCIONARIO = ',';

    private const COL_NOMBRE_ACTUAL = 'NOMBRE_ACTUAL';
    private const COL_NOMBRE = 'NOMBRE';
    private const COL_CPRO = 'CPRO';
    private const COL_CMUN = 'CMUN';
    private const COL_LATITUD = 'LATITUD_ETRS89_REGCAN95';
    private const COL_LONGITUD = 'LONGITUD_ETRS89_REGCAN95';

    /** Radio de busqueda aproximado, en grados (~5,5 km). */
    private const RADIO_BUSQUEDA_GRADOS = 0.05;

    /**
     * Normalizados ya calculados.
     *
     * @var array<string,string>
     */
    private static array $cacheNormalizados = [];

    public function __construct(
        private readonly string $rutaMunicipios = self::RUTA_MUNICIPIOS,
        private readonly string $rutaDiccionario = self::RUTA_DICCIONARIO,
    ) {}

    /**
     * Fila del diccionario26.csv para un nombre de municipio.
     *
     * @return array<string,string>
     * @throws ApiException 404 si no hay ningun municipio con ese nombre.
     */
    public function porNombre(string $nombre): array {
        $objetivo = self::normalizar($nombre);

        foreach ($this->filas($this->rutaDiccionario, self::SEP_DICCIONARIO, self::COL_NOMBRE) as $fila) {
            if (self::coincideCon($fila[self::COL_NOMBRE], $objetivo)) {
                return $fila;
            }
        }

        throw ApiException::noEncontrado("No se encontro el municipio '$nombre'");
    }

    /**
     * Codigo INE de un municipio, concatenando CPRO y CMUN como espera AEMET
     * (por ejemplo '01' + '051' -> '01051').
     */
    public function codigoIne(string $nombre): string {
        $fila = $this->porNombre($nombre);

        if (!isset($fila[self::COL_CPRO], $fila[self::COL_CMUN])) {
            throw new ApiException(
                'El diccionario de municipios no tiene las columnas CPRO y CMUN',
                500
            );
        }

        return $fila[self::COL_CPRO] . $fila[self::COL_CMUN];
    }

    /**
     * Fila del MUNICIPIOS.csv mas cercana a unas coordenadas, ya convertida a UTF-8.
     *
     * @return array<string,string>
     * @throws ApiException 404 si no hay ningun municipio en el radio de busqueda.
     */
    public function porCoordenadas(float $latitud, float $longitud): array {
        $mejorDistancia = PHP_FLOAT_MAX;
        $encontrado = null;

        foreach ($this->filas(
            $this->rutaMunicipios,
            self::SEP_MUNICIPIOS,
            self::COL_LATITUD,
            self::COL_LONGITUD
        ) as $fila) {
            $lat = (float) str_replace(',', '.', $fila[self::COL_LATITUD]);
            $lon = (float) str_replace(',', '.', $fila[self::COL_LONGITUD]);
            $distancia = sqrt(($lat - $latitud) ** 2 + ($lon - $longitud) ** 2);

            if ($distancia < $mejorDistancia) {
                $mejorDistancia = $distancia;
                $encontrado = $fila;
            }
        }

        if ($encontrado === null || $mejorDistancia > self::RADIO_BUSQUEDA_GRADOS) {
            throw ApiException::noEncontrado('No hay ningun municipio cerca de esas coordenadas');
        }

        return self::aUtf8($encontrado);
    }

    /**
     * Nombre actual del municipio mas cercano a unas coordenadas.
     */
    public function nombrePorCoordenadas(float $latitud, float $longitud): string {
        $fila = $this->porCoordenadas($latitud, $longitud);

        if (!isset($fila[self::COL_NOMBRE_ACTUAL]) || trim($fila[self::COL_NOMBRE_ACTUAL]) === '') {
            throw ApiException::noEncontrado(
                'No se ha podido determinar el municipio a partir de las coordenadas'
            );
        }

        return trim($fila[self::COL_NOMBRE_ACTUAL]);
    }

    /**
     * Fila del MUNICIPIOS.csv para un nombre de municipio, ya convertida a UTF-8.
     *
     * @return array<string,string>
     * @throws ApiException 404 si no hay ningun municipio con ese nombre.
     */
    public function coordenadasPorNombre(string $nombre): array {
        $objetivo = self::normalizar($nombre);

        foreach ($this->filas(
            $this->rutaMunicipios,
            self::SEP_MUNICIPIOS,
            self::COL_NOMBRE_ACTUAL
        ) as $fila) {
            if (self::coincideCon($fila[self::COL_NOMBRE_ACTUAL], $objetivo)) {
                return self::aUtf8($fila);
            }
        }

        throw ApiException::noEncontrado("No se encontro el municipio '$nombre'");
    }

    /**
     * @return array{0: float, 1: float} latitud y longitud en grados
     */
    public function coordenadas(string $nombre): array {
        $fila = $this->coordenadasPorNombre($nombre);

        if (!isset($fila[self::COL_LATITUD], $fila[self::COL_LONGITUD])) {
            throw ApiException::noEncontrado(
                'No se ha podido determinar las coordenadas del municipio'
            );
        }

        return [
            (float) str_replace(',', '.', $fila[self::COL_LATITUD]),
            (float) str_replace(',', '.', $fila[self::COL_LONGITUD]),
        ];
    }

    /**
     * Recorre un CSV de municipios leyendo linea a linea, emparejando cada fila con su
     * encabezado. Valida que existan las columnas requeridas y descarta las filas con
     * filas con un numero de campos distinto al encabezado.
     *
     * @return Generator<int, array<string,string>>
     */
    private function filas(string $ruta, string $separador, string ...$columnas): Generator {
        $manejador = @fopen($ruta, 'r');

        if ($manejador === false) {
            throw new ApiException("No se pudo abrir el fichero de municipios '$ruta'", 500);
        }

        try {
            $encabezados = fgetcsv($manejador, 1000, $separador);

            if ($encabezados === false) {
                throw new ApiException("El fichero de municipios '$ruta' esta vacio", 500);
            }

            foreach ($columnas as $columna) {
                if (!in_array($columna, $encabezados, true)) {
                    throw new ApiException(
                        "El fichero de municipios '$ruta' no tiene la columna '$columna'",
                        500
                    );
                }
            }

            while (($datos = fgetcsv($manejador, 1000, $separador)) !== false) {
                if (count($datos) !== count($encabezados)) {
                    continue;
                }

                /** @var array<string,string> $fila */
                $fila = array_combine($encabezados, $datos);

                yield $fila;
            }
        } finally {
            fclose($manejador);
        }
    }

    /**
     * Un nombre del CSV coincide con el buscado si es igual, o si coincide con alguna
     * de las partes separadas por '/' (por ejemplo 'Agurain/Salvatierra').
     */
    private static function coincideCon(string $nombreCsv, string $nombreNormalizado): bool {
        if (self::normalizar($nombreCsv) === $nombreNormalizado) {
            return true;
        }

        foreach (explode('/', $nombreCsv) as $parte) {
            if (self::normalizar($parte) === $nombreNormalizado) {
                return true;
            }
        }

        return false;
    }

    private static function normalizar(string $nombre): string {
        if (!isset(self::$cacheNormalizados[$nombre])) {
            // El CSV de MUNICIPIOS esta en ISO-8859-15 y el otro en UTF-8; sin convertir,
            // mb_strtolower sustituye los bytes no-UTF8 por '?' y la tilde se pierde.
            $texto = mb_strtolower(Encoding::aUtf8SiHaceFalta(trim($nombre)), 'UTF-8');

            if (class_exists('Normalizer')) {
                $texto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
            }

            self::$cacheNormalizados[$nombre] = preg_replace('/\p{Mn}/u', '', $texto);
        }

        return self::$cacheNormalizados[$nombre];
    }

    /**
     * @param array<string,string> $fila
     * @return array<string,string>
     */
    private static function aUtf8(array $fila): array {
        $convertida = [];

        foreach ($fila as $columna => $valor) {
            $convertida[$columna] = Encoding::aUtf8SiHaceFalta($valor);
        }

        return $convertida;
    }
}