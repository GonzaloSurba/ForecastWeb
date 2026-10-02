<?php

//error_reporting(E_ALL);
//ini_set("display_errors", 1);

include_once('loadEnv.php');
include_once('getMunicipio.php');

/**
 * AEMET mezcla codificaciones en la misma respuesta: el bloque "origen" viene en
 * ISO-8859-15 y el de datos en UTF-8. Convertir todo desde ISO rompe los acentos
 * que ya venían en UTF-8, así que solo convertimos los bytes sueltos.
 */
function aUtf8Mixtos(string $texto): string {
    $salida = '';
    $longitud = strlen($texto);
    $i = 0;

    while ($i < $longitud) {
        $byte = ord($texto[$i]);

        if ($byte < 0x80) {
            $salida .= $texto[$i];
            $i++;
            continue;
        }

        $tam = match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default       => 0
        };

        if ($tam > 0) {
            $secuencia = substr($texto, $i, $tam);
            if (mb_check_encoding($secuencia, 'UTF-8')) {
                $salida .= $secuencia;
                $i += $tam;
                continue;
            }
        }

        $salida .= mb_convert_encoding($texto[$i], 'UTF-8', 'ISO-8859-15');
        $i++;
    }

    return $salida;
}

if (!isset($_GET["municipio"]) || empty($_GET["municipio"])) {
    if ((!isset($_GET["latitud"]) || empty($_GET["latitud"]) || !isset($_GET["longitud"]) || empty($_GET["longitud"]))) {
        echo json_encode(["error" => "Faltan los parámetros 'municipio' o 'latitud' y 'longitud'"]);
        exit();
    } else {
        $datosMunicipio = json_decode(buscarPorLatLon(__DIR__ . '/MUNICIPIOS.csv', $_GET["latitud"], $_GET["longitud"]));

        // buscarPorLatLon devuelve {"error": "..."} o [] si no encuentra municipio
        if (isset($datosMunicipio[0]->error)) {
            echo json_encode(["error" => $datosMunicipio[0]->error]);
            exit();
        }
        if (!isset($datosMunicipio[0]->NOMBRE_ACTUAL)) {
            echo json_encode(["error" => "No se ha podido determinar el municipio a partir de las coordenadas"]);
            exit();
        }

        $municipio = $datosMunicipio[0]->NOMBRE_ACTUAL;
    }
} else {
    $municipio = $_GET["municipio"];
}

$archivo = __DIR__ . '/config/diccionario26.csv';

$municipioBuscado = json_decode(buscarPorNombre($archivo, $municipio));

if (!isset($municipioBuscado[0]->CPRO, $municipioBuscado[0]->CMUN)) {
    echo json_encode(["error" => "No se encontró el municipio '" . $municipio . "'"]);
    exit();
}

$codMunicipio = $municipioBuscado[0]->CPRO . $municipioBuscado[0]->CMUN;

header('Content-Type: application/json; charset=utf-8');

try {
    $datos = obtenerApiKeyAemet();
    $apikey = $datos["apikey"];
    $url = $datos["url"];
    $urlCompleta = str_replace('{municipio}', $codMunicipio, $url);

    // Inicio curl
    $ch = curl_init($urlCompleta);

    // Configuro curl
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Devolver el resultado como texto
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $apikey,
        'Content-Type: application/json'
    ]);

    // Ejecuto la petición
    $respuesta = curl_exec($ch);

    // Compruebo si hubo errores
    if (curl_errno($ch)) {
        echo "Error: " . curl_error($ch);
    }

    // Convierto el JSON recibido a un array de PHP
    $datos = json_decode($respuesta, true);

    if (!isset($datos) || empty($datos)) {
        echo json_encode(["error" => "No se obtuvieron datos", "url" => $urlCompleta]);
        exit();
    }

    // Muestro los datos para testeo
    //print_r($datos);
    // El json $datos es así si todo sale bien: 
    /*{
        "descripcion": "exito",
        "estado": 200,
        "datos": "https://opendata.aemet.es/opendata/sh/a15e753d",
        "metadatos": "https://opendata.aemet.es/opendata/sh/93a7c63d"
    }*/

    if (isset($datos["estado"]) && str_starts_with((string)$datos["estado"], '4')) {
        echo json_encode(["error" => $datos["descripcion"], "url" => $urlCompleta]);
        exit();
    }

    $url = $datos["datos"];
    $ch = curl_init($url);

    // Configuro curl
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Devolver el resultado como texto

    // Ejecuto la petición
    $respuesta = curl_exec($ch);   // ya viene en $respuesta

    if ($respuesta === false || trim($respuesta) === '') {
        echo json_encode(["error" => "No se obtuvieron datos de la segunda url", "url" => $url]);
        exit();
    }

    echo aUtf8Mixtos($respuesta);

} catch (Exception $e) {
    echo json_encode(["error" => "Se ha producido un error: " . $e]);
}
