<?php

namespace App\Http;

/**
 * Control de acceso por origen. Solo pueden llamar a la API los origenes
 * declarados: el frontend del propio proyecto en desarrollo (localhost) y los
 * que se añadan en la variable de entorno API_ORIGENES_PERMITIDOS.
 *
 * El navegador envia siempre Origin o Referer en las peticiones del frontend,
 * de modo que una pagina de otro sitio no puede usar la API desde el navegador
 * de un visitante (ni consumir la cuota de AEMET/OpenWeather). Un cliente sin
 * navegador (curl, scripts) puede forzar las cabeceras, pero esto no sustituye
 * a la autenticacion: protege el lado que no se puede esconder, el navegador.
 */
final class Origen {

    /** Origenes aceptados siempre, para desarrollo local con LAMPP. */
    private const POR_DEFECTO = [
        'http://localhost',
        'http://127.0.0.1',
    ];

    private function __construct() {}

    /** Responde 403 y termina si la peticion no viene de un origen permitido. */
    public static function exigir(): void {
        $origen = self::origenDePeticion();

        if ($origen === null || !in_array($origen, self::permitidos(), true)) {
            ApiResponse::error('La peticion no proviene de un origen permitido', 403);
        }
    }

    /** @return string[] */
    private static function permitidos(): array {
        $valor = $_ENV['API_ORIGENES_PERMITIDOS'] ?? getenv('API_ORIGENES_PERMITIDOS');
        $configurados = is_string($valor) ? $valor : '';
        $extra = array_filter(array_map('trim', explode(',', $configurados)));

        return array_map('strtolower', array_merge(self::POR_DEFECTO, $extra));
    }

    /** Origin de la peticion; si no viene, el del Referer; null si no hay ninguno util. */
    private static function origenDePeticion(): ?string {
        $origen = strtolower(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));

        if ($origen !== '' && $origen !== 'null') {
            return $origen;
        }

        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return null;
        }

        $partes = parse_url($referer);

        if ($partes === false || !isset($partes['scheme'], $partes['host'])) {
            return null;
        }

        // El puerto por defecto no forma parte del origen: http://localhost:80
        // y http://localhost son el mismo origen.
        $puerto = $partes['port'] ?? null;
        $esPorDefecto = ($partes['scheme'] === 'http' && $puerto === 80)
            || ($partes['scheme'] === 'https' && $puerto === 443);

        return strtolower($partes['scheme'] . '://' . $partes['host'])
            . ($puerto !== null && !$esPorDefecto ? ':' . $puerto : '');
    }
}
