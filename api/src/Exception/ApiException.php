<?php

namespace App\Exception;

use RuntimeException;

/**
 * Error controlado del backend. Cada instancia lleva consigo el codigo HTTP con el que
 * debe responder el endpoint, de forma que los endpoints no tengan que decidirlo.
 */
class ApiException extends RuntimeException {

    public function __construct(string $mensaje, private readonly int $estadoHttp = 500) {
        parent::__construct($mensaje);
    }

    public function estadoHttp(): int {
        return $this->estadoHttp;
    }

    /** Faltan parametros o son invalidos. */
    public static function peticionInvalida(string $mensaje): self {
        return new self($mensaje, 400);
    }

    /** El municipio o el recurso pedido no existe. */
    public static function noEncontrado(string $mensaje): self {
        return new self($mensaje, 404);
    }

    /**
     * La API de terceros ha fallado. El mensaje nunca debe incluir la URL de la
     * llamada: en OpenWeather la URL lleva la API key en la query string.
     */
    public static function errorExterno(string $mensaje): self {
        return new self($mensaje, 502);
    }
}