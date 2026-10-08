<?php

/**
 * Arranque común de todos los endpoints: carga el autoloader de Composer y el .env
 * del proyecto. Se incluye una única vez y define ROOT_API.
 *
 * También aplica aquí la seguridad transversal (origen permitido y límite de
 * peticiones) para que ningún endpoint pueda olvidarse de ella.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Http\Origen;
use App\Http\RateLimiter;

define('ROOT_API', dirname(__DIR__));

Dotenv\Dotenv::createImmutable(ROOT_API . '/..')->safeLoad();

// Oculta la versión de PHP: solo le aporta información a quien escanea el servidor.
header_remove('X-Powered-By');

Origen::exigir();
RateLimiter::exigir();