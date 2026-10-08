<?php

namespace App\Utils;

/**
 * Utilidades de codificación de texto.
 *
 * Los ficheros de configuración del proyecto no comparten codificación:
 *   - config/MUNICIPIOS.csv      -> ISO-8859-15
 *   - config/diccionario26.csv   -> UTF-8
 * Por eso las conversiones se hacen siempre de forma condicional y nunca "a pelo".
 */
final class Encoding {

    private function __construct() {}

    /**
     * AEMET mezcla codificaciones en la misma respuesta: el bloque "origen" viene en
     * ISO-8859-15 y el de datos en UTF-8. Convertir todo desde ISO rompe los acentos
     * que ya venían en UTF-8, así que solo convertimos los bytes sueltos.
     */
    public static function aUtf8Mixtos(string $texto): string {
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

    /**
     * Convierte a UTF-8 solo si aún no lo es. Necesario porque un mismo
     * proyecto consume un CSV en ISO-8859-15 y otro en UTF-8: convertir el que ya es
     * UTF-8 desde ISO produciría texto basura.
     */
    public static function aUtf8SiHaceFalta(string $texto): string {
        if (mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-15');
    }
}