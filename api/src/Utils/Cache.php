<?php

namespace App\Utils;

/**
 * Caché TTL en disco de las respuestas de las apis externas, con el mismo patrón que
 * IcaService: contenido fresco mientras no caduque, escritura con temporal + rename
 * atómico y posibilidad de servir el contenido caducado cuando el upstream falla.
 *
 * El nombre del fichero es sha1 de la clave, así que una entrada de usuario nunca
 * llega al nombre de fichero. Si el directorio temporal no es escribible (hosting
 * compartido) la caché falla en abierto: obtener() devuelve null y guardar() no hace
 * nada, jamás rompe la petición.
 */
final class Cache {

    private const PREFIJO = 'webtiempo-cache-';

    /** HIT | MISS | STALE si este request ha pasado por la caché, null si no. */
    private static ?string $estado = null;

    private function __construct() {}

    /**
     * Contenido fresco de la clave, o null si no existe, está caducado o no se puede
     * leer.
     */
    public static function obtener(string $clave, int $ttl): ?string {
        $ruta = self::ruta($clave);
        $contenido = @file_get_contents($ruta);

        if ($contenido === false) {
            self::$estado = 'MISS';

            return null;
        }

        if (@filemtime($ruta) < time() - $ttl) {
            self::$estado = 'MISS';

            return null;
        }

        self::$estado = 'HIT';

        return $contenido;
    }

    /**
     * Contenido de la clave sin mirar el TTL, para servir datos viejos cuando la API
     * externa falla. Null si no hay nada guardado.
     */
    public static function obtenerCaduco(string $clave): ?string {
        $contenido = @file_get_contents(self::ruta($clave));

        if ($contenido === false) {
            return null;
        }

        self::$estado = 'STALE';

        return $contenido;
    }

    /**
     * Guarda el contenido con escritura atómica. Silencioso si no hay permisos.
     */
    public static function guardar(string $clave, string $contenido): void {
        $ruta = self::ruta($clave);
        $temporal = $ruta . '.' . getmypid();

        if (@file_put_contents($temporal, $contenido) === false) {
            return;
        }

        if (!@rename($temporal, $ruta)) {
            @unlink($temporal);
        }
    }

    /**
     * Estado del último paso por la caché para la cabecera X-Cache.
     */
    public static function estado(): ?string {
        return self::$estado;
    }

    private static function ruta(string $clave): string {
        return sys_get_temp_dir() . '/' . self::PREFIJO . sha1($clave);
    }
}
