async function getOpenWeatherData(municipio, latitud, longitud) {
    try {
        let url = ""
        if (municipio) {
            url = `api/getClimaMunicipioOpenWeather.php?municipio=${municipio}`
        } else {
            url = `api/getClimaMunicipioOpenWeather.php?latitud=${latitud}&longitud=${longitud}`
        }
        const response = await fetch(url, {
            method: "GET",
            headers: {
                "Content-Type": "application/json"
            }
        });
        if (response.ok) {
            let datos = await response.json()
            console.log(datos)
            return datos
        } else {
            console.error("Error en la respuesta:", response.statusText);
        }
    } catch (error) {
        console.error("Se ha producido un error al obtener el tiempo:", error.message)
    }
}

export async function getAemetData({ municipio = null, latitud = null, longitud = null }) {
    try {
        let url = ""
        if (municipio) {
            url = `api/getClimaMunicipioAemet.php?municipio=${municipio}`
        } else if (latitud && longitud) {
            url = `api/getClimaMunicipioAemet.php?latitud=${latitud}&longitud=${longitud}`
        } else {
            alert("Para buscar un municipio introduce su nombre o geolocaliza tu posición")
            return
        }
        
        const response = await fetch(url, {
            method: "GET",
            headers: {
                "Content-Type": "application/json"
            }
        });
        if (response.ok) {
            let datos = await response.json()
            console.log(datos)
            if (datos["error"]) {
                alert("Se ha producido un error: " + datos["error"])
                return
            }
            let datosOpenWeather = await getOpenWeatherData(municipio, latitud, longitud)
            return {
                "AEMET": datos,
                "OpenWeather": datosOpenWeather
            }
        } else {
            console.error("Error en la respuesta:", response.statusText);
        }
    } catch (error) {
        console.error("Se ha producido un error al obtener el tiempo:", error.message)
    }
}