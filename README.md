# WeatherApp

Aplicación web de predicción meteorológica para municipios de España: pronóstico horario de AEMET, tiempo actual, calidad del aire, avisos de Meteoalerta y radar de lluvia, con una API PHP propia que unifica y protege las fuentes de datos.

**Demo:** https://eltiempo.infinityfreeapp.com/

## Características

- **Búsqueda de municipios** por nombre (autocompletado con municipios y poblaciones vía CartoCiudad), por código INE de 5 dígitos o por geolocalización del navegador, con historial de búsquedas recientes.
- **Predicción horaria de AEMET** por municipio: temperatura, estado del cielo, viento y rachas, humedad, sensación térmica, orto/ocaso y horas de luz.
- **Previsión de las próximas 12 horas** en tarjetas horarias, con la hora actual resaltada.
- **Telemetría atmosférica** de OpenWeather: nubosidad (y octas), presión con interpretación, visibilidad y viento con aguja de dirección.
- **Índice de Calidad del Aire (ICA)** de la estación MITECO más cercana (radio de 50 km), con categoría, contaminante responsable, distancia y marca de dato parcial.
- **Avisos de Meteoalerta** vigentes para la zona meteorológica del municipio.
- **Radar de lluvia** sobre mapa base de OpenStreetMap con capa de precipitaciones de RainViewer.
- **Exportación del reporte completo en JSON** (AEMET + OpenWeather + avisos + ICA) con un clic.

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Frontend | HTML5, CSS3 y JavaScript moderno (ES modules), sin frameworks |
| Mapas | Leaflet 1.9.4, OpenStreetMap, RainViewer |
| Backend | PHP 8.1+ puro (sin framework), arquitectura en capas `Http` / `Services` / `Exception` / `Utils` |
| Configuración | Composer + `vlucas/phpdotenv` |
| Fuentes de datos | AEMET, OpenWeather, CartoCiudad, MITECO, RainViewer |
| Infraestructura | Apache (LAMPP en desarrollo, InfinityFree en producción) |

La API REST está escrita a mano deliberadamente: sin framework, cada endpoint es un fichero pequeño que solo valida la petición y delega en los `Services`. Es la parte del proyecto de la que más se aprende mirando el código.

## Estructura del proyecto

```
├── index.html               Interfaz completa (secciones, plantillas, modal)
├── .env.example             Variables de entorno documentadas (las claves van en .env)
├── .htaccess                Seguridad global: cabeceras, dotfiles, sin listados
├── assets/
│   ├── css/estilo.css
│   ├── img/
│   └── js/
│       ├── miscript.js      Lógica de UI: búsqueda, pintado, exportación
│       ├── api.js           Cliente HTTP: solo construye URLs y maneja errores
│       └── radar.js         Mapa Leaflet, radar de lluvia y recorte
└── api/
    ├── .htaccess            Bloquea src/, vendor/, tools/, config/ y composer.*
    ├── get*.php             Endpoints GET (sin lógica de negocio)
    ├── config/              CSVs (municipios INE, zonas Meteoalerta, ICA)
    ├── src/
    │   ├── bootstrap.php    Autoload + .env + seguridad transversal
    │   ├── Config.php       Lectura tipada de variables de entorno
    │   ├── Http/            Request (validación), ApiResponse, Origen, RateLimiter
    │   ├── Services/        Aemet, OpenWeather, CartoCiudad, ICA, Meteoalerta...
    │   └── Utils/           HttpClient (cURL endurecido), Encoding
    ├── tools/               Scripts CLI (generación de CSVs)
    └── vendor/              Dependencias de Composer (no se sube a git)
```

## API

Todas las respuestas son JSON con su código HTTP correspondiente. Se pueden llamar con `curl` añadiendo `Referer` u `Origin` del propio sitio.

| Endpoint | Parámetros | Devuelve |
|---|---|---|
| `api/getMunicipios.php` | `municipio` | Coincidencias de nombre (CartoCiudad, fallback al CSV del INE) |
| `api/getClimaMunicipioAemet.php` | `codine` + `municipio` \| `municipio` \| `latitud` + `longitud` | Predicción horaria de AEMET |
| `api/getClimaMunicipioOpenWeather.php` | `municipio` \| `latitud` + `longitud`, `lang`, `units` | Tiempo actual de OpenWeather |
| `api/getAvisosMunicipio.php` | `codine` + `municipio` \| `municipio` \| `latitud` + `longitud` | Zona Meteoalerta y avisos vigentes |
| `api/getIcaMunicipio.php` | `latitud` + `longitud` \| `municipio` | ICA de la estación más cercana, o `null` si no hay ninguna en radio |

Ejemplo:

```bash
curl -H "Referer: https://tu-dominio/" \
  "https://tu-dominio/api/getAvisosMunicipio.php?codine=06158&municipio=Zafra"
```

## Instalación local

Requisitos: Apache con `AllowOverride All` (LAMPP/XAMPP/WAMP sirve), PHP 8.1 o superior y Composer.

```bash
git clone git@github.com:GonzaloSurba/ForecastWeb.git
cd ForecastWeb
composer install -d api          # instala phpdotenv y genera api/vendor
cp .env.example .env             # y rellena las claves
```

Variables obligatorias en `.env`:

| Variable | Contenido |
|---|---|
| `AEMET_API_KEY` | Clave de la API de AEMET |
| `AEMET_API_URL` | Endpoint de AEMET |
| `OPEN_WEATHER_API_KEY` | Clave de OpenWeather |
| `OPEN_WEATHER_API_URL` | Endpoint de OpenWeather |
| `CARTO_CIUDAD_API_URL` | Endpoint de búsqueda de municipios |
| `ICA_ULTIMA_HORA_URL` | Endpoint CSV del ICA de MITECO |
| `API_ORIGENES_PERMITIDOS` | Orígenes extra permitidos (en local no hace falta: `localhost` ya está incluido) |

Después basta con servir la raíz del proyecto (`http://localhost/personal/WebTiempo/` en LAMPP) y buscar un municipio.

## Despliegue

En hosting compartido **no hay Composer ni SSH**: lo que se sube es el resultado ya construido.

1. Subir el proyecto completo **incluyendo `api/vendor/`** (se genera en local con `composer install` y usa rutas relativas).
2. Crear `.env` en la raíz del servidor (git lo excluye a propósito) con las claves reales y, en producción, `API_ORIGENES_PERMITIDOS=https://tudominio.com`.
3. Subir los dos `.htaccess` (raíz y `api/`), conservando el bloque que gestione el propio hosting.

## Seguridad

El backend está endurecido de fábrica, no como añadido:

- **Control de origen**: solo responden los orígenes declarados (`localhost` + `API_ORIGENES_PERMITIDOS`); el resto recibe 403.
- **Rate limit**: 30 peticiones por IP y minuto con ventana deslizante y `flock` (429 + `Retry-After`).
- **Validación de entrada**: `Request::texto()` con tope de longitud, `Request::codigoINE()` (5 dígitos), `Request::enum()` con listas cerradas y `Request::numero()`.
- **SSRF**: el `HttpClient` restringe `file://`, `gopher://` y redirecciones a protocolos no-HTTP, y las URLs devueltas por AEMET se validan contra `*.aemet.es`.
- **Cabeceras**: `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options` y retirada de `X-Powered-By`.
- **`.htaccess`**: bloqueo de `.env*`, `.git*` y demás ficheros ocultos, sin listado de directorios, y denegación de `api/src`, `api/vendor`, `api/tools`, `api/config` y `composer.*`.
- **Secretos fuera de git** (`.env` en `.gitignore`), mensajes de error genéricos y sanitización del log de errores.

Nota: `Referrer-Policy` debe ser `strict-origin-when-cross-origin` (no `no-referrer` ni `same-origin`), porque la API identifica el origen por la cabecera `Referer` y los tiles de OpenStreetMap exigen `Referer` para no bloquear el mapa.

## Fuentes de datos y atribuciones

- [AEMET](https://www.aemet.es) — predicción horaria (© Agencia Estatal de Meteorología).
- [OpenWeather](https://openweathermap.org) — tiempo actual y telemetría.
- [CartoCiudad](https://www.cartociudad.es/web/portal) — geocodificación y búsqueda de municipios.
- [MITECO](https://www.miteco.gob.es) — índice de calidad del aire.
- [RainViewer](https://www.rainviewer.com) — capa de radar de precipitaciones.
- [OpenStreetMap](https://www.openstreetmap.org) — mapa base, © OpenStreetMap contributors.

## Licencia

[CC BY-SA 4.0](LICENSE) — Gonzalo Surba.
