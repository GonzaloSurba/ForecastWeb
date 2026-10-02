<?php

namespace App\Services;

use App\Exception\ApiException;

/**
 * Zona Meteoalerta en la que cae un municipio.
 *
 * AEMET no publica ningun fichero con el reparto municipio -> zona, asi que la tabla
 * de config/zonas_meteoalerta.csv se genera con tools/generar-zonas-meteoalerta.php a
 * partir del PDF oficial y se sube al repo ya generada.
 *
 * El codigo de zona son 6 digitos y sus dos primeros son el codigo de CCAA que AEMET
 * usa en la URL de sus avisos ('70' -> Extremadura). De ahi se saca la CCAA sin
 * necesitar una tabla adicional.
 */
final class ZonaMeteoalertaService {

    private const RUTA_ZONAS = __DIR__ . '/../../config/zonas_meteoalerta.csv';
    private const SEPARADOR = ';';

    private const COL_INE = 'COD_INE';
    private const COL_ZONA = 'COD_Z';
    private const COL_NOMBRE = 'NOM_Z';

    /**
     * Tabla indexada por codigo INE, cargada en la primera consulta.
     *
     * @var array<string,array{codigo:string,nombre:string}>|null
     */
    private ?array $zonas = null;

    public function __construct(private readonly string $rutaZonas = self::RUTA_ZONAS) {}

    /**
     * Zona Meteoalerta de un municipio, o null si la tabla no lo cubre.
     *
     * Null es a proposito y no un error: la tabla no incluye todos los municipios. El
     * PDF oficial numera las islas con su propio esquema ('6590' Gran Canaria, '6453'
     * Ibiza) en vez del codigo INE de provincia y las coloca en dos columnas con
     * zonas distintas, que no se pueden separar de forma fiable. Baleares y Canarias
     * quedan fuera hasta que la tabla se genere por otra via.
     *
     * @return array{codigo:string,nombre:string}|null
     */
    public function zonaDe(string $codigoIne): ?array {
        if (strlen($codigoIne) < 5) {
            return null;
        }

        return $this->zonas()[substr($codigoIne, 0, 5)] ?? null;
    }

    /** Codigo de CCAA de una zona, que es lo que espera el RSS de AEMET. */
    public static function ccaaDe(string $codigoZona): string {
        return substr($codigoZona, 0, 2);
    }

    /**
     * @return array<string,array{codigo:string,nombre:string}>
     */
    private function zonas(): array {
        if ($this->zonas !== null) {
            return $this->zonas;
        }

        $manejador = @fopen($this->rutaZonas, 'r');

        if ($manejador === false) {
            throw new ApiException('No se pudo abrir la tabla de zonas Meteoalerta', 500);
        }

        try {
            $encabezados = fgetcsv($manejador, 1000, self::SEPARADOR);

            if ($encabezados === false) {
                throw new ApiException('La tabla de zonas Meteoalerta esta vacia', 500);
            }

            foreach ([self::COL_INE, self::COL_ZONA] as $columna) {
                if (!in_array($columna, $encabezados, true)) {
                    throw new ApiException(
                        "La tabla de zonas Meteoalerta no tiene la columna '$columna'",
                        500
                    );
                }
            }

            $posiciones = array_flip($encabezados);
            $posiciones[self::COL_NOMBRE] ??= null;

            $this->zonas = [];

            while (($datos = fgetcsv($manejador, 1000, self::SEPARADOR)) !== false) {
                if (count($datos) !== count($encabezados)) {
                    continue;
                }

                $this->zonas[trim($datos[$posiciones[self::COL_INE]])] = [
                    'codigo' => trim($datos[$posiciones[self::COL_ZONA]]),
                    'nombre' => $posiciones[self::COL_NOMBRE] === null
                        ? ''
                        : trim($datos[$posiciones[self::COL_NOMBRE]]),
                ];
            }

            return $this->zonas;
        } finally {
            fclose($manejador);
        }
    }
}