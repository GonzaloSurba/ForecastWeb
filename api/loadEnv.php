<?php
//error_reporting(E_ALL);
//ini_set("display_errors",1);

// Cargar el autoloader de Composer
require_once __DIR__ . '/vendor/autoload.php';

// Cargar el archivo .env desde la ruta actual
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

function obtenerApiKeyAemet() {
    $datos = [
        "apikey" => $_ENV['AEMET_API_KEY'],
        "url" => $_ENV['AEMET_API_URL']
    ];
    return $datos;
}

function obtenerApiKeyOpenWeather() {
    $datos = [
        "apikey" => $_ENV['OPEN_WEATHER_API_KEY'],
        "url" => $_ENV['OPEN_WEATHER_API_URL']
    ];
    return $datos;
}

?>