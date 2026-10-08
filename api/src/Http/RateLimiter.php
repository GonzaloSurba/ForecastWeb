<?php

namespace App\Http;

/**
 * Límite de peticiones por IP con ventana deslizante, guardado en un fichero
 * temporal y protegido con flock para que aguante peticiones simultáneas.
 *
 * La API hace llamadas caras a AEMET, OpenWeather y MITECO: sin este corte,
 * cualquier script puede agotar la cuota del proyecto en segundos.
 */
final class RateLimiter {

    /** Máximo de peticiones admitidas por IP en la ventana deslizante. */
    private const LIMITE = 30;

    /** Longitud de la ventana deslizante, en segundos. */
    private const VENTANA_SEGUNDOS = 60;

    private const PREFIJO_FICHERO = 'webtiempo-rate-';

    private function __construct() {}

    /** Responde 429 y termina si la IP ha superado el límite de la ventana. */
    public static function exigir(): void {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'sin-ip');
        $ruta = sys_get_temp_dir() . '/' . self::PREFIJO_FICHERO . sha1($ip);
        $ahora = time();

        // Si el temporal no se puede abrir no se bloquea la API: perder el
        // límite es preferible a dejar la web inutilizable.
        $manejador = @fopen($ruta, 'c+');
        if ($manejador === false) {
            return;
        }

        $excedido = false;
        $segundosReintento = self::VENTANA_SEGUNDOS;

        if (flock($manejador, LOCK_EX)) {
            $marcas = self::marcasValidas($manejador, $ahora);

            if (count($marcas) >= self::LIMITE) {
                $excedido = true;
                $segundosReintento = max(1, min($marcas) + self::VENTANA_SEGUNDOS - $ahora);
            } else {
                $marcas[] = $ahora;
                rewind($manejador);
                ftruncate($manejador, 0);
                fwrite($manejador, json_encode($marcas));
                fflush($manejador);
            }

            flock($manejador, LOCK_UN);
        }

        fclose($manejador);

        if ($excedido) {
            header('Retry-After: ' . $segundosReintento);
            ApiResponse::error(
                'Demasiadas peticiones desde esta conexion, vuelve a intentarlo en unos segundos',
                429
            );
        }
    }

    /**
     * Marcas de tiempo de la ventana actual, descartando las caducadas y
     * cualquier contenido que no sea un array de enteros (fichero corrompido).
     *
     * @return int[]
     */
    private static function marcasValidas($manejador, int $ahora): array {
        rewind($manejador);
        $contenido = trim((string) stream_get_contents($manejador));
        $leidas = $contenido === '' ? [] : json_decode($contenido, true);

        if (!is_array($leidas)) {
            return [];
        }

        return array_values(array_filter(
            $leidas,
            static fn($marca) => is_int($marca) && $marca > $ahora - self::VENTANA_SEGUNDOS
        ));
    }
}
