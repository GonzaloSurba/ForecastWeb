<?php

namespace App\Http;

use App\Exception\ApiException;

/**
 * Lectura y validacion de los parametros de $_GET. Cada endpoint repite casi la misma
 * comprobacion de "municipio o coordenadas", asi que vive aqui una sola vez.
 */
final class Request {

    private function __construct() {}

    /** Parametro de texto no vacio, o null si no viene. */
    public static function texto(string $clave): ?string {
        if (!isset($_GET[$clave])) {
            return null;
        }

        $valor = trim((string) $_GET[$clave]);

        return $valor === '' ? null : $valor;
    }

    /** Igual que texto(), pero con un valor por defecto. */
    public static function textoConDefecto(string $clave, string $defecto): string {
        return self::texto($clave) ?? $defecto;
    }

    /** Lanza ApiException 400 si el parametro no es un numero valido. */
    public static function numero(string $clave): float {
        $valor = self::texto($clave);

        if ($valor === null || !is_numeric($valor)) {
            throw ApiException::peticionInvalida("El parametro '$clave' debe ser un numero");
        }

        return (float) $valor;
    }
}