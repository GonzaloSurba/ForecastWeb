<?php

namespace App\Services;

use App\Exception\ApiException;
use App\Utils\Cache;
use App\Utils\HttpClient;
use DateTimeImmutable;
use SimpleXMLElement;
use Throwable;

/**
 * Avisos Meteoalerta de AEMET para una zona concreta.
 *
 * El RSS es la lista de avisos de una CCAA y cada <item> enlaza al XML CAP con el
 * detalle del aviso. El RSS solo da las fechas como texto ('de 15:00 04-10-2026 CEST
 * a 21:59 04-10-2026 CEST'), insuficiente para decidir si un aviso esta activo, así que
 * el CAP se descarga únicamente para los avisos de la zona pedida, que suelen ser
 * ninguno o muy pocos.
 */
final class AemetAvisosService {

    private const RSS = 'https://www.aemet.es/documentos_d/eltiempo/prediccion/avisos/rss/CAP_AFAC{ccaa}_RSS.xml';

    /** El CAP llega duplicado en español e ingles; solo interesan los avisos en español. */
    private const IDIOMA = 'es-ES';

    /** AEMET publica avisos a lo largo del día; 15 min rinde bien y no retrasa casi nada. */
    private const TTL_CACHE_SEGUNDOS = 900;

    private const CODIGO_NIVEL = 'AEMET-Meteoalerta nivel';
    private const CODIGO_PARAMETRO = 'AEMET-Meteoalerta parametro';
    private const CODIGO_PROBABILIDAD = 'AEMET-Meteoalerta probabilidad';
    private const CODIGO_FENOMENO = 'AEMET-Meteoalerta fenomeno';

    /** El campo del valor del que sale el umbral o el fenómeno dentro de la cadena. */
    private const CAMPO_UMBRAL = 2;
    private const CAMPO_FENOMENO = 1;

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    /**
     * Avisos de la zona, con los que están en vigor primero y después los más cercanos.
     *
     * @return array<int,array<string,mixed>>
     * @throws ApiException 502 si AEMET no responde o devuelve algo ilegible.
     */
    public function avisos(string $codigoZona, ?DateTimeImmutable $ahora = null): array {
        $ahora ??= new DateTimeImmutable();

        $clave = 'avisos-' . $codigoZona;
        $cacheada = Cache::obtener($clave, self::TTL_CACHE_SEGUNDOS);

        if ($cacheada !== null) {
            $avisos = HttpClient::aArray($cacheada);

            if ($avisos !== null) {
                return self::ordenar(self::refrescarActivos($avisos, $ahora));
            }
        }

        try {
            $avisos = $this->descargar($codigoZona, $ahora);
        } catch (ApiException $e) {
            $caducada = Cache::obtenerCaduco($clave);

            if ($caducada !== null) {
                $avisos = HttpClient::aArray($caducada);

                if ($avisos !== null) {
                    error_log('[WebTiempo] Avisos: sin datos frescos, se sirve la cache caducada: ' . $e->getMessage());

                    return self::ordenar(self::refrescarActivos($avisos, $ahora));
                }
            }

            throw $e;
        }

        $json = json_encode($avisos, JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            Cache::guardar($clave, $json);
        }

        return self::ordenar($avisos);
    }

    /**
     * Recupera los avisos de la zona descargando el RSS de su CCAA y el CAP de cada
     * enlace, sin ordenar todavía (el orden depende de la hora, que puede cambiar
     * entre la descarga y una lectura posterior de la caché).
     *
     * @return array<int,array<string,mixed>>
     */
    private function descargar(string $codigoZona, DateTimeImmutable $ahora): array {
        $rss = str_replace('{ccaa}', ZonaMeteoalertaService::ccaaDe($codigoZona), self::RSS);

        $avisos = [];

        foreach ($this->enlacesDeLaZona($this->http->get($rss), $codigoZona) as $enlace) {
            $aviso = $this->avisoDe($enlace, $codigoZona, $ahora);

            if ($aviso !== null) {
                $avisos[] = $aviso;
            }
        }

        return $avisos;
    }

    /**
     * Recalcula 'activo' contra la hora actual. El aviso se guarda con el flag del
     * momento de la descarga, y dentro del TTL un aviso puede caducar: sin esto
     * aparecería como activo de más hasta la siguiente llamada a AEMET.
     *
     * @param array<int,array<string,mixed>> $avisos
     * @return array<int,array<string,mixed>>
     */
    private function refrescarActivos(array $avisos, DateTimeImmutable $ahora): array {
        foreach ($avisos as $indice => $aviso) {
            $inicio = $this->instante($aviso['inicio'] ?? null);
            $fin = $this->instante($aviso['fin'] ?? null);

            $avisos[$indice]['activo'] = $inicio !== null && $fin !== null && $inicio <= $ahora && $ahora <= $fin;
        }

        return $avisos;
    }

    /**
     * Con los que están en vigor primero y después los más cercanos.
     *
     * @param array<int,array<string,mixed>> $avisos
     * @return array<int,array<string,mixed>>
     */
    private static function ordenar(array $avisos): array {
        usort($avisos, static function (array $a, array $b): int {
            if ($a['activo'] !== $b['activo']) {
                return $a['activo'] ? -1 : 1;
            }

            // Sin fecha de inicio no se puede ordenar, así que ese aviso va al final.
            if ($a['inicio'] === null || $b['inicio'] === null) {
                return ($a['inicio'] === null ? 1 : 0) <=> ($b['inicio'] === null ? 1 : 0);
            }

            return strcmp($a['inicio'], $b['inicio']);
        });

        return $avisos;
    }

    /**
     * URL del CAP de cada aviso de la zona, segun el RSS de su CCAA.
     *
     * @return string[]
     */
    private function enlacesDeLaZona(string $rss, string $codigoZona): array {
        $canal = $this->cargarXml($rss)->channel;
        $enlaces = [];

        foreach ($canal->item as $item) {
            $enlace = trim((string) $item->link);

            // El primer item del RSS no es un aviso sino el tar.gz con todos los de
            // la CCAA, por eso se descarta por extensión y no por su título.
            if ($enlace === '' || str_ends_with($enlace, '.tar.gz')) {
                continue;
            }

            if (str_contains(basename($enlace), 'AFAZ' . $codigoZona)) {
                $enlaces[] = $enlace;
            }
        }

        return $enlaces;
    }

    /**
     * Traduce el CAP de un aviso. Devuelve null si no es utilizable, para que un solo
     * aviso ilegible no tire la respuesta entera.
     *
     * @return array<string,mixed>|null
     */
    private function avisoDe(string $enlace, string $codigoZona, DateTimeImmutable $ahora): ?array {
        $xml = $this->cargarXml($this->http->get($enlace));
        $info = $this->infoEnEspanol($xml);

        if ($info === null) {
            error_log("[WebTiempo] Aviso sin bloque en español, se omite: $enlace");

            return null;
        }

        $geocodigo = trim((string) $info->area->geocode->value);

        // El enlace ya viene filtrado por zona. El geocode del CAP se contrasta
        // cuando trae un código de 6 dígitos, que es el único formato conocido; si
        // AEMET lo cambiara, el aviso no se descarta por ello.
        if (preg_match('/^\d{6}$/', $geocodigo) === 1 && $geocodigo !== $codigoZona) {
            error_log("[WebTiempo] Aviso de la zona $geocodigo filtrado como $codigoZona: $enlace");

            return null;
        }

        $parametros = $this->parametros($info);
        $inicio = $this->instante($info->onset);
        $fin = $this->instante($info->expires);

        return [
            'nivel' => $this->valorDe($parametros, self::CODIGO_NIVEL),
            'fenomeno' => $this->parteDe($this->eventCode($info), self::CODIGO_FENOMENO, self::CAMPO_FENOMENO),
            'cabecera' => trim((string) $info->headline),
            'descripcion' => trim((string) $info->description),
            'zona' => trim((string) $info->area->areaDesc),
            // AEMET lo expresa como 'P1;Precipitación acumulada en una hora;15 mm'.
            'umbral' => $this->parteDe($parametros, self::CODIGO_PARAMETRO, self::CAMPO_UMBRAL),
            'probabilidad' => $this->valorDe($parametros, self::CODIGO_PROBABILIDAD),
            'inicio' => $inicio?->format(DATE_ATOM),
            'fin' => $fin?->format(DATE_ATOM),
            'activo' => $inicio !== null && $fin !== null && $inicio <= $ahora && $ahora <= $fin,
        ];
    }

    /**
     * El CAP se declara con un namespace por defecto (urn:oasis:...:cap:1.2). SimpleXML
     * lo resuelve sin más porque todos los elementos pertenecen a ese namespace.
     */
    private static function infoEnEspanol(SimpleXMLElement $xml): ?SimpleXMLElement {
        foreach ($xml->info as $info) {
            if ((string) $info->language === self::IDIOMA) {
                return $info;
            }
        }

        return null;
    }

    /**
     * Los <parameter> del aviso, indexados por su valueName.
     *
     * @return array<string,string>
     */
    private static function parametros(SimpleXMLElement $info): array {
        $parametros = [];

        foreach ($info->parameter as $parametro) {
            $parametros[(string) $parametro->valueName] = (string) $parametro->value;
        }

        return $parametros;
    }

    /**
     * Los <eventCode> del aviso, indexados por su valueName. El fenómeno viene aquí y
     * no entre los <parameter>.
     *
     * @return array<string,string>
     */
    private static function eventCode(SimpleXMLElement $info): array {
        $codigos = [];

        foreach ($info->eventCode as $codigo) {
            $codigos[(string) $codigo->valueName] = (string) $codigo->value;
        }

        return $codigos;
    }

    /**
     * @param array<string,string> $valores
     */
    private static function valorDe(array $valores, string $clave): ?string {
        $valor = trim($valores[$clave] ?? '');

        return $valor === '' ? null : $valor;
    }

    /**
     * Campo suelto de un valor de AEMET, contando desde cero: el fenomeno llega como
     * 'PR;Lluvias' y el parámetro como 'P1;Precipitación acumulada en una hora;15 mm'.
     *
     * @param array<string,string> $valores
     */
    private static function parteDe(array $valores, string $clave, int $indice): ?string {
        $valor = trim($valores[$clave] ?? '');

        if ($valor === '') {
            return null;
        }

        $partes = explode(';', $valor);

        return isset($partes[$indice]) && trim($partes[$indice]) !== ''
            ? trim($partes[$indice])
            : null;
    }

    private function instante(mixed $valor): ?DateTimeImmutable {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($texto);
        } catch (Throwable) {
            error_log("[WebTiempo] Fecha de aviso ilegible: $texto");

            return null;
        }
    }

    /**
     * @throws ApiException 502 si el cuerpo no es XML válido.
     */
    private function cargarXml(string $contenido): SimpleXMLElement {
        $anterior = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($contenido);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        if ($xml === false) {
            throw ApiException::errorExterno('AEMET ha devuelto un XML ilegible');
        }

        return $xml;
    }
}