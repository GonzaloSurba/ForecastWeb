<?php

/**
 * Arranque comun de todos los endpoints: carga el autoloader de Composer y el .env
 * del proyecto. Se incluye una unica vez y define ROOT_API.
 */

require_once __DIR__ . '/../vendor/autoload.php';

define('ROOT_API', dirname(__DIR__));

Dotenv\Dotenv::createImmutable(ROOT_API . '/..')->safeLoad();