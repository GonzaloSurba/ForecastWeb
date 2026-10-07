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
    public static function texto(string $clave, int $maxLongitud = 100): ?string {
        if (!isset($_GET[$clave])) {
            return null;
        }

        $valor = trim((string) $_GET[$clave]);

        if ($valor === '') {
            return null;
        }

        if (mb_strlen($valor) > $maxLongitud) {
            throw ApiException::peticionInvalida("El parametro '$clave' es demasiado largo (max $maxLongitud caracteres)");
        }

        return $valor;
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

    /** Codigo INE de municipio (5 digitos), o null si no viene. 400 si viene mal formado. */
    public static function codigoINE(string $clave): ?string {
        $valor = self::texto($clave);

        if ($valor === null) {
            return null;
        }

        if (!preg_match('/^\d{5}$/', $valor)) {
            throw ApiException::peticionInvalida("El parametro '$clave' debe ser un codigo INE de 5 digitos");
        }

        return $valor;
    }

    /** Valor dentro de una lista cerrada; si no viene, el defecto. */
    public static function enum(string $clave, array $permitidos, string $defecto): string {
        $valor = self::texto($clave) ?? $defecto;

        if (!in_array($valor, $permitidos, true)) {
            throw ApiException::peticionInvalida(
                "El parametro '$clave' debe ser uno de: " . implode(', ', $permitidos)
            );
        }

        return $valor;
    }
}