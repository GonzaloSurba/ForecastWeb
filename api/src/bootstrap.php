<?php

/**
 * Arranque comun de todos los endpoints: carga el autoloader de Composer y el .env
 * del proyecto. Se incluye una unica vez y define ROOT_API.
 *
 * Tambien aplica aqui la seguridad transversal (origen permitido y limite de
 * peticiones) para que ningun endpoint pueda olvidarse de ella.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Http\Origen;
use App\Http\RateLimiter;

define('ROOT_API', dirname(__DIR__));

Dotenv\Dotenv::createImmutable(ROOT_API . '/..')->safeLoad();

// Oculta la version de PHP: solo le aporta informacion a quien escanea el servidor.
header_remove('X-Powered-By');

Origen::exigir();
RateLimiter::exigir();