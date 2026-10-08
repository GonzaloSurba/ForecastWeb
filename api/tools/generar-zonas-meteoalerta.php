<?php

/**
 * Herramienta de desarrollo. Genera config/zonas_meteoalerta.csv a partir del PDF
 * oficial de AEMET. El servidor nunca ejecuta este fichero: solo se usa cuando hay
 * que rehacer la tabla.
 *
 *   php tools/generar-zonas-meteoalerta.php [--cache=DIR]
 *
 * AEMET no publica ningún fichero con el reparto municipio -> zona, así que la tabla
 * se extrae del PDF con pdftotext -layout. El layout de dos columnas del PDF no es
 * estable entre páginas, por eso en vez de fiarse de la posición se comprueba que el
 * municipio encaje con la provincia de la sección actual.
 */

declare(strict_types=1);

const PDF_MUNICIPIOS = 'https://www.aemet.es/documentos/es/eltiempo/prediccion/avisos/plan_meteoalerta/detalle_municipios_zonas_meteorologicas.pdf';
const PDF_ANEXO_2 = 'https://www.aemet.es/documentos/es/eltiempo/prediccion/avisos/plan_meteoalerta/METEOALERTA_ANEX2_Zonas_aviso.pdf';

/** Número de zonas del plan Meteoalerta vigente (Anexo 2, edición de 31-05-2022). */
const TOTAL_ZONAS_ESPERADO = 182;

/**
 * Provincias insulares. El PDF las numera con su propio esquema (6590 Gran Canaria,
 * 6453 Ibiza) en vez del código INE de provincia, así que la comprobación de
 * coherencia no se les puede aplicar.
 */
const PROVINCIAS_INSULARES = ['07', '35', '38'];

const RUTA_MUNICIPIOS = __DIR__ . '/../config/MUNICIPIOS.csv';

$cache = opcion('--cache=', sys_get_temp_dir() . '/meteoalerta');

$pdfMunicipios = descargar(PDF_MUNICIPIOS, $cache);
$textoMunicipios = extraerTexto($pdfMunicipios);

['pares' => $municipios, 'zonas' => $zonas] = analizar($textoMunicipios);

$referencia = leerZonasAnexo2($cache);

echo 'Zonas en el PDF de municipios: ' . count($zonas) . PHP_EOL;
echo 'Municipios con zona asignada: ' . count($municipios) . PHP_EOL;

$errores = validar($municipios, $zonas, $referencia);

if ($errores !== []) {
    fwrite(STDERR, PHP_EOL . 'La tabla generada no es fiable:' . PHP_EOL);

    foreach ($errores as $error) {
        fwrite(STDERR, '  - ' . $error . PHP_EOL);
    }

    exit(1);
}

$ruta = __DIR__ . '/../config/zonas_meteoalerta.csv';
escribir($ruta, $municipios, $zonas);

echo 'Escrito ' . count($municipios) . ' filas en ' . realpath($ruta) . PHP_EOL;

/**
 * Recorre el texto del PDF y devuelve el reparto municipio -> zona junto con el
 * nombre de cada zona.
 *
 * Cabecera de 4 digitos = CCAA + provincia o CCAA + isla, abre una sección.
 * Cabecera de 6 digitos = zona, cierra la anterior.
 * 5 dígitos = código INE de un municipio.
 *
 * @return array{pares: array<string,string>, zonas: array<string,string>}
 */
function analizar(string $texto): array {
    // El salto de página de pdftotext llega como \f pegado al texto. Si se hiciera
    // split por \n en su lugar, un código de la última línea de una página se
    // pegaría al primero de la siguiente y ambos se leerían como uno solo.
    $lineas = explode("\n", str_replace("\f", '', $texto));

    // \p{L} y no una lista de letras acentuadas: el PDF mezcla castellano y catalán,
    // y si falta una letra en la lista (por ejemplo 'Ò' de Òrrius) el código de la
    // derecha no se detecta y el nombre de la zona arrastra el resto de la línea.
    $patron = '/\d{4,6}(?=\s+\p{L})/u';

    $provincia = null;
    $zona = null;
    $pendientes = [];
    $municipios = [];
    $zonas = [];

    foreach ($lineas as $linea) {
        preg_match_all($patron, $linea, $coincidencias, PREG_OFFSET_CAPTURE);

        $codigos = $coincidencias[0];
        $total = count($codigos);

        for ($i = 0; $i < $total; $i++) {
            $codigo = $codigos[$i][0];
            $fin = $i + 1 < $total ? $codigos[$i + 1][1] : strlen($linea);
            $nombre = limpiar(substr($linea, $codigos[$i][1] + strlen($codigo), $fin - $codigos[$i][1] - strlen($codigo)));

            if (strlen($codigo) === 4) {
                $provincia = $codigo;
                $zona = null;
                $pendientes = [];
            } elseif (strlen($codigo) === 6) {
                $zona = $codigo;
                $zonas[$codigo] ??= $nombre;
                // Los municipios que el PDF imprime antes de la cabecera de zona
                // pertenecen a la primera zona de su provincia.
                foreach ($pendientes as $pendiente) {
                    $municipios[$pendiente] ??= $zona;
                }
                $pendientes = [];
            } elseif (strlen($codigo) === 5 && $provincia !== null) {
                if ($zona !== null && encaja($codigo, $provincia)) {
                    $municipios[$codigo] ??= $zona;
                } elseif ($zona === null) {
                    $pendientes[] = $codigo;
                }
            }
        }
    }

    return ['pares' => $municipios, 'zonas' => $zonas];
}

/**
 * Un municipio pertenece a la sección actual si sus dos primeros dígitos son los de
 * la provincia, o si la sección es insular.
 */
function encaja(string $codigoMunicipio, string $provincia): bool {
    $provinciaIne = substr($provincia, 2, 2);

    return substr($codigoMunicipio, 0, 2) === $provinciaIne
        || in_array($provinciaIne, PROVINCIAS_INSULARES, true);
}

/**
 * Comprobaciones que deben cumplirse antes de fiarse de la tabla generada.
 *
 * @param array<string,string> $municipios
 * @param array<string,string> $zonas
 * @param string[] $referencia
 * @return string[]
 */
function validar(array $municipios, array $zonas, array $referencia): array {
    $errores = [];

    if ($referencia !== []) {
        $faltan = array_diff(array_keys($zonas), $referencia);
        $sobran = array_diff($referencia, array_keys($zonas));

        if ($faltan !== []) {
            $errores[] = 'zonas del PDF ausentes en el Anexo 2 vigente: ' . implode(', ', $faltan);
        }

        if ($sobran !== []) {
            $errores[] = 'zonas del Anexo 2 vigente ausentes en el PDF: ' . implode(', ', $sobran);
        }
    } else {
        fwrite(STDERR, 'Aviso: no se pudo validar contra el Anexo 2, se omite esa comprobacion.' . PHP_EOL);
    }

    if (count($zonas) !== TOTAL_ZONAS_ESPERADO) {
        $errores[] = sprintf(
            'se esperaban %d zonas y se han encontrado %d',
            TOTAL_ZONAS_ESPERADO,
            count($zonas)
        );
    }

    // Si el extractor se deja un código sin ver, su texto se cuela en el nombre de la
    // zona. Un nombre con dígitos delata ese fallo antes de que llegue a la tabla.
    foreach ($zonas as $codigo => $nombre) {
        if (preg_match('/\d/', $nombre) === 1) {
            $errores[] = sprintf('el nombre de la zona %s contiene digitos: "%s"', $codigo, $nombre);
        }
    }

    $provincias = leerProvincias();

    foreach ($municipios as $ine => $zona) {
        $provincia = $provincias[$ine] ?? null;

        if ($provincia === null || in_array($provincia, PROVINCIAS_INSULARES, true)) {
            continue;
        }

        if (substr($zona, 2, 2) !== $provincia) {
            $errores[] = sprintf(
                'el municipio %s (provincia %s) ha quedado en la zona %s, que es de otra provincia',
                $ine,
                $provincia,
                $zona
            );
        }
    }

    // Un cambio en la estructura del PDF puede generar un fallo masivo en la tabla. 
    // En estos casos basta con notificar la presencia de errores, por lo que se limita la salida a los primeros 20.
    if (count($errores) > 20) {
        $total = count($errores);
        $errores = array_slice($errores, 0, 20);
        $errores[] = sprintf('... y %d problemas mas omitidos', $total - 20);
    }

    return $errores;
}

/**
 * Escribe el CSV final, ordenado por código INE para que las diferencias entre
 * regeneraciones sean legibles.
 *
 * @param array<string,string> $municipios
 * @param array<string,string> $zonas
 */
function escribir(string $ruta, array $municipios, array $zonas): void {
    ksort($municipios);

    $manejador = fopen($ruta, 'w');

    if ($manejador === false) {
        fwrite(STDERR, "No se pudo escribir '$ruta'" . PHP_EOL);

        exit(1);
    }

    try {
        fputcsv($manejador, ['COD_INE', 'COD_Z', 'NOM_Z'], ';', '"', "\n");

        foreach ($municipios as $ine => $zona) {
            fputcsv($manejador, [$ine, $zona, $zonas[$zona] ?? ''], ';', '"', "\n");
        }
    } finally {
        fclose($manejador);
    }
}

/** @return array<string,string> código INE de 5 dígitos -> COD_PROV */
function leerProvincias(): array {
    $manejador = fopen(RUTA_MUNICIPIOS, 'r');

    if ($manejador === false) {
        fwrite(STDERR, "No se pudo abrir " . RUTA_MUNICIPIOS . PHP_EOL);

        exit(1);
    }

    try {
        $encabezados = fgetcsv($manejador, 1000, ';');

        if ($encabezados === false) {
            return [];
        }

        $provincias = [];

        while (($datos = fgetcsv($manejador, 1000, ';')) !== false) {
            if (count($datos) !== count($encabezados)) {
                continue;
            }

            $fila = array_combine($encabezados, $datos);
            $ine = trim($fila['COD_INE']);

            if ($ine === '') {
                continue;
            }

            $provincias[substr($ine, 0, 5)] = trim($fila['COD_PROV']);
        }

        return $provincias;
    } finally {
        fclose($manejador);
    }
}

/**
 * Códigos de zona del Anexo 2 vigente, para comprobar que la tabla no se ha
 * desfasado respecto a la lista oficial.
 *
 * @return string[]
 */
function leerZonasAnexo2(string $cache): array {
    try {
        $texto = extraerTexto(descargar(PDF_ANEXO_2, $cache));
    } catch (Throwable) {
        return [];
    }

    preg_match_all('/\b\d{6}\b/', $texto, $coincidencias);

    return array_values(array_unique($coincidencias[0]));
}

/** Descarga el PDF si no está ya en la caché y devuelve su ruta. */
function descargar(string $url, string $cache): string {
    if (!is_dir($cache) && !mkdir($cache, 0o777, true) && !is_dir($cache)) {
        throw new RuntimeException("No se pudo crear la cache '$cache'");
    }

    $ruta = $cache . '/' . basename(parse_url($url, PHP_URL_PATH));

    if (is_file($ruta) && filesize($ruta) > 0) {
        return $ruta;
    }

    $manejador = curl_init($url);

    if ($manejador === false) {
        throw new RuntimeException('No se pudo iniciar la descarga');
    }

    curl_setopt_array($manejador, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $contenido = curl_exec($manejador);
    $error = curl_error($manejador);

    if (!is_string($contenido) || $contenido === '') {
        throw new RuntimeException("No se pudo descargar '$url': $error");
    }

    if (!str_starts_with($contenido, '%PDF')) {
        throw new RuntimeException("La descarga de '$url' no es un PDF");
    }

    file_put_contents($ruta, $contenido);

    return $ruta;
}

/** Convierte el PDF a texto conservando el layout en columnas. */
function extraerTexto(string $rutaPdf): string {
    $rutaTxt = $rutaPdf . '.txt';

    exec(
        sprintf('pdftotext -layout %s %s 2>&1', escapeshellarg($rutaPdf), escapeshellarg($rutaTxt)),
        $salida,
        $codigo
    );

    if ($codigo !== 0 || !is_file($rutaTxt)) {
        throw new RuntimeException("pdftotext fallo: " . implode(' ', $salida));
    }

    return (string) file_get_contents($rutaTxt);
}

function limpiar(string $texto): string {
    return trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
}

function opcion(string $prefijo, string $porDefecto): string {
    foreach ($GLOBALS['argv'] ?? [] as $argumento) {
        if (str_starts_with($argumento, $prefijo)) {
            return substr($argumento, strlen($prefijo));
        }
    }

    return $porDefecto;
}