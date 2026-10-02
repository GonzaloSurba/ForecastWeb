<?php

header('Content-Type: application/json; charset=utf-8');

function normalizarNombre(string $nombre): string {
    // El CSV de MUNICIPIOS está en ISO-8859-15; sin convertir, mb_strtolower
    // sustituye los bytes no-UTF8 por '?' y la tilde se pierde antes de normalizar.
    if (!mb_check_encoding($nombre, "UTF-8")) {
        $nombre = mb_convert_encoding($nombre, "UTF-8", "ISO-8859-15");
    }
    $nombre = mb_strtolower(trim($nombre), "UTF-8");
    if (class_exists("Normalizer")) {
        $nombre = Normalizer::normalize($nombre, Normalizer::FORM_D);
    }
    return preg_replace("/\p{Mn}/u", "", $nombre);
}

/**
 * Busca el municipio en diccionario26.csv y devuelve su fila, para obtener su id de municipio.
 *
 * @param string $rutaArchivo Ruta del CSV con el diccionario de municipios.
 * @param string $nombreBuscado Nombre del municipio que queremos buscar.
 * @return object|string|false Retorna toda la fila del CSV correspondiente con el municipio o nada si no lo encontró.
 */
function buscarPorNombre(string $rutaArchivo, string $nombreBuscado) {
    $resultados = [];
    $nombreNormalizado = normalizarNombre($nombreBuscado);

    // Abrir el archivo en modo lectura
    if (($archivo = fopen($rutaArchivo, "r")) !== FALSE) {
        
        // Obtener la primera línea (los encabezados/columnas)
        $encabezados = fgetcsv($archivo, 1000, ",");
        
        // Buscar el índice de la columna "NOMBRE"
        $indiceNombre = array_search('NOMBRE', $encabezados);

        if ($indiceNombre === FALSE) {
            return json_encode(["error" => "No se encontró la columna 'nombre'"]);
        }

        // Leer línea por línea
        while (($datos = fgetcsv($archivo, 1000, ",")) !== FALSE) {
            $nombreCsv = $datos[$indiceNombre];
            $csvNorm = normalizarNombre($nombreCsv);
            $coincide = ($csvNorm === $nombreNormalizado);

            if (!$coincide) {
                // Comprobar cada parte separada por '/'
                $partes = explode('/', $nombreCsv);
                foreach ($partes as $parte) {
                    if (normalizarNombre($parte) === $nombreNormalizado) {
                        $coincide = true;
                        break;
                    }
                }
            }

            if ($coincide) {
                // Combinar los encabezados con los datos de la fila para crear un array asociativo
                $resultados[] = array_combine($encabezados, $datos);
            }
        }
        
        // Cerrar el archivo
        fclose($archivo);
    }

    // Retornar los resultados en formato JSON
    return json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Busca el municipio a través de su latitud y longitud en MUNICIPIOS.csv y devuelve su fila, para obtener su nombre.
 *
 * @param string $rutaArchivo Ruta del CSV con el diccionario de municipios.
 * @param float $latitud Latitud geográfica del municipio.
 * @param float $longitud Longitud geográfica del municipio.
 * @return object|string|false Retorna toda la fila del CSV correspondiente con el municipio o nada si no lo encontró.
 */
function buscarPorLatLon(string $rutaArchivo, float $latitud, float $longitud) {
    $resultados = [];

    // Abrir el archivo en modo lectura
    if (($archivo = fopen($rutaArchivo, "r")) !== FALSE) {
        
        // Obtener la primera línea (los encabezados/columnas)
        $encabezados = fgetcsv($archivo, 1000, ";");
        
        // Buscar el índice de la columna "LATITUD_ETRS89_REGCAN95"
        $indiceLat = array_search('LATITUD_ETRS89_REGCAN95', $encabezados);
        $indiceLon = array_search('LONGITUD_ETRS89_REGCAN95', $encabezados);

        if ($indiceLat === FALSE) {
            return json_encode(["error" => "No se encontró la columna 'LATITUD_ETRS89_REGCAN95'"]);
        }
        if ($indiceLon === FALSE) {
            return json_encode(["error" => "No se encontró la columna 'LONGITUD_ETRS89_REGCAN95'"]);
        }

        // Comparar (insensible a mayúsculas/minúsculas usando strcasecmp)
        $radioGrados = 0.05;  // ~5,5 km

        $mejorDistancia = PHP_FLOAT_MAX;
        $encontrado = null;

        while (($datos = fgetcsv($archivo, 1000, ";")) !== FALSE) {
            $lat = (float) str_replace(',', '.', $datos[$indiceLat]);
            $lon = (float) str_replace(',', '.', $datos[$indiceLon]);
            $distancia = sqrt(($lat - $latitud) ** 2 + ($lon - $longitud) ** 2);
            if ($distancia < $mejorDistancia) {
                $mejorDistancia = $distancia;
                $encontrado = array_combine($encabezados, $datos);
            }
        }

        if ($encontrado === null || $mejorDistancia > $radioGrados) {
            return json_encode(["error" => "No hay ningún municipio cerca de esas coordenadas"]);
        }

        $resultados[] = array_map(
            fn($valor) => mb_convert_encoding($valor, 'UTF-8', 'ISO-8859-15'),
            $encontrado
        );
        
        // Cerrar el archivo
        fclose($archivo);
    }

    // Retornar los resultados en formato JSON
    $json = json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return json_encode(["error" => "Error al codificar: " . json_last_error_msg()]);
    }
    return $json;
}

/**
 * Busca el municipio a través de su nombre en MUNICIPIOS.csv y devuelve su fila, para obtener su latitud y longitud geográfica.
 *
 * @param string $rutaArchivo Ruta del CSV con el diccionario de municipios.
 * @param string $nombreBuscado Nombre del municipio que queremos buscar.
 * @return object|string|false Retorna toda la fila del CSV correspondiente con el municipio o nada si no lo encontró.
 */
function buscarPorNombreLatLon(string $rutaArchivo, string $nombreBuscado) {
    $resultados = [];
    $nombreNormalizado = normalizarNombre($nombreBuscado);

    // Abrir el archivo en modo lectura
    if (($archivo = fopen($rutaArchivo, "r")) !== FALSE) {
        
        // Obtener la primera línea (los encabezados/columnas)
        $encabezados = fgetcsv($archivo, 1000, ";");
        
        // Buscar el índice de la columna "NOMBRE_ACTUAL"
        $indiceNombre = array_search('NOMBRE_ACTUAL', $encabezados);

        if ($indiceNombre === FALSE) {
            return json_encode(["error" => "No se encontró la columna 'NOMBRE_ACTUAL'"]);
        }

        $encontrado = null;

        while (($datos = fgetcsv($archivo, 1000, ";")) !== FALSE) {
            $nombreCsv = $datos[$indiceNombre];
            $csvNorm = normalizarNombre($nombreCsv);
            $coincide = ($csvNorm === $nombreNormalizado);

            if (!$coincide) {
                foreach (explode('/', $nombreCsv) as $parte) {
                    if (normalizarNombre($parte) === $nombreNormalizado) {
                        $coincide = true;
                        break;
                    }
                }
            }

            if ($coincide) {
                $encontrado = array_combine($encabezados, $datos);
                break;
            }
        }

        if ($encontrado === null) {
            return json_encode(["error" => "No se encontró el municipio '" . $nombreBuscado . "'"]);
        }
        
        $resultados[] = array_map(
            fn($valor) => mb_convert_encoding($valor, 'UTF-8', 'ISO-8859-15'),
            $encontrado
        );

        // Cerrar el archivo
        fclose($archivo);
    }

    // Retornar los resultados en formato JSON
    $json = json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return json_encode(["error" => "Error al codificar: " . json_last_error_msg()]);
    }
    return $json;
}

// Mostrar los resultados
//echo "<pre>";
//print_r(buscarPorLatLon(__DIR__ . '/MUNICIPIOS.csv', str_replace(".", ",", 38.42634413), str_replace(".", ",", -6.418558803)));
//echo "</pre>";

?>