export function cargarRadar({ latitud = 38.2926, longitud = -6.2062 }) {
    const radarContainer = document.querySelector(".radar-image-wrapper")
    if (!radarContainer) return

    if (!radarContainer.querySelector("#mapa-radar")) {
        // #mapa-radar es remplazado por los bloques del radar
        // por lo que si quiero que se actualice en una búsqueda posterior a la primera
        // necesito borrar el radar y volver a crear el div #mapa-radar
        const mapaDiv = document.createElement("div")
        mapaDiv.id = "mapa-radar"
        mapaDiv.style = "width: 100%; height: 100%;"
        radarContainer.innerHTML = ""
        radarContainer.append(mapaDiv)
    }

    const map = L.map('mapa-radar').setView([latitud, longitud], 7);

    // Añado mapa base (OpenStreetMap)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);

    // Obtengo la capa del radar más reciente de RainViewer
    fetch('https://api.rainviewer.com/public/weather-maps.json')
        .then(res => res.json())
        .then(data => {
            // Obtener el último timestamp disponible
            const radarPath = data.radar.past[data.radar.past.length - 1].path;

            // Añadir la capa de lluvia sobre OpenStreetMap
            L.tileLayer(`https://tilecache.rainviewer.com${radarPath}/256/{z}/{x}/{y}/2/1_1.png`, {
                opacity: 0.6,
                tileSize: 256
            }).addTo(map);
        });
}