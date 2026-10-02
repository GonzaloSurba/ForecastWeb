<?php

//error_reporting(E_ALL);
//ini_set("display_errors", 1);

include_once('loadEnv.php');
include_once('getMunicipio.php');

$archivo = __DIR__ . '/config/MUNICIPIOS.csv';

if (isset($_GET["municipio"])) {
    $datosMunicipio = json_decode(buscarPorNombreLatLon($archivo, $_GET["municipio"]));

    // buscarPorLatLon devuelve {"error": "..."} o [] si no encuentra municipio
    if (isset($datosMunicipio[0]->error)) {
        echo json_encode(["error" => $datosMunicipio[0]->error]);
        exit();
    }
    if ((!isset($datosMunicipio[0]->LATITUD_ETRS89_REGCAN95)) || (!isset($datosMunicipio[0]->LONGITUD_ETRS89_REGCAN95))) {
        echo json_encode(["error" => "No se ha podido determinar las coordenadas del municipio"]);
        exit();
    }

    $latitud = $datosMunicipio[0]->LATITUD_ETRS89_REGCAN95;
    $longitud = $datosMunicipio[0]->LONGITUD_ETRS89_REGCAN95;
} else {
    if (!isset($_GET["latitud"]) || empty($_GET["latitud"])) {
        echo json_encode(["error" => "Falta el parámetro 'latitud'"]);
        exit();
    }
    if (!isset($_GET["longitud"]) || empty($_GET["longitud"])) {
        echo json_encode(["error" => "Falta el parámetro 'longitud'"]);
        exit();
    }
    $latitud = $_GET["latitud"];
    $longitud = $_GET["longitud"];
}

$lang = $_GET["lang"] ?? "es";
$units = $_GET["units"] ?? "metric";

header('Content-Type: application/json; charset=utf-8');

try {
    $datos = obtenerApiKeyOpenWeather();
    $apikey = $datos["apikey"];
    $url = $datos["url"];
    $urlCompleta = str_replace('{lat}', $latitud, $url);
    $urlCompleta = str_replace('{lon}', $longitud, $urlCompleta);
    $urlCompleta = str_replace('{APIkey}', $apikey, $urlCompleta);
    $urlCompleta = str_replace('{lang}', $lang, $urlCompleta);
    $urlCompleta = str_replace('{units}', $units, $urlCompleta);

    // Inicio curl
    $ch = curl_init($urlCompleta);

    // Configuro curl
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Devolver el resultado como texto

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

    echo $respuesta;

} catch (Exception $e) {
    echo json_encode(["error" => "Se ha producido un error: " . $e]);
}
