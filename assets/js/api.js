/**
 * Cliente del backend. Solo construye URLs y muestra avisos: no interpreta los datos,
 * eso lo hace miscript.js.
 */

async function pedirDatos(url, { avisar = true } = {}) {
    const response = await fetch(url, {
        method: "GET",
        headers: {
            "Content-Type": "application/json"
        }
    });

    // El cuerpo se lee tanto si la respuesta es correcta como si no: el backend
    // devuelve los errores con su propio codigo HTTP y el mensaje va en el JSON.
    let datos = null
    try {
        datos = await response.json()
    } catch {
        datos = null
    }

    if (!response.ok || datos?.error) {
        const mensaje = datos?.error
            ?? `el servidor respondió ${response.status} ${response.statusText}`
        if (avisar) {
            alert("Se ha producido un error: " + mensaje)
        }
        return null
    }

    return datos
}

async function getOpenWeatherData(municipio, latitud, longitud) {
    try {
        const parametros = municipio
            ? `municipio=${encodeURIComponent(municipio)}`
            : `latitud=${encodeURIComponent(latitud)}&longitud=${encodeURIComponent(longitud)}`

        // No se avisa de los fallos aqui: OpenWeather es un dato complementario y un
        // fallo suyo no debe interrumpir la prediccion de AEMET, que ya se ha pintado.
        return await pedirDatos(`api/getClimaMunicipioOpenWeather.php?${parametros}`, { avisar: false })
    } catch (error) {
        console.error("Se ha producido un error al obtener el tiempo:", error.message)
        return null
    }
}

/**
 * Avisos Meteoalerta del municipio. Devuelve null si la consulta falla, lo cual no es
 * lo mismo que un municipio sin avisos: quien lo llame tiene que poder distinguirlos.
 */
export async function getAvisosData({ municipio = null, latitud = null, longitud = null }) {
    let url
    if (municipio) {
        url = `api/getAvisosMunicipio.php?municipio=${encodeURIComponent(municipio)}`
    } else if (latitud && longitud) {
        url = `api/getAvisosMunicipio.php?latitud=${encodeURIComponent(latitud)}&longitud=${encodeURIComponent(longitud)}`
    } else {
        return
    }

    try {
        return await pedirDatos(url)
    } catch (error) {
        console.error("Se ha producido un error al obtener los avisos:", error.message)
        return null
    }
}

export async function getAemetData({ municipio = null, latitud = null, longitud = null, codigoINE = null }) {
    let url
    if (codigoINE) {
        url = `api/getClimaMunicipioAemet.php?codine=${encodeURIComponent(codigoINE)}`
    } else if (municipio) {
        url = `api/getClimaMunicipioAemet.php?municipio=${encodeURIComponent(municipio)}`
    } else if (latitud && longitud) {
        url = `api/getClimaMunicipioAemet.php?latitud=${encodeURIComponent(latitud)}&longitud=${encodeURIComponent(longitud)}`
    } else {
        alert("Para buscar un municipio introduce su nombre o geolocaliza tu posición")
        return
    }

    try {
        const datos = await pedirDatos(url)

        if (!datos) return

        return {
            "AEMET": datos,
            "OpenWeather": await getOpenWeatherData(municipio, latitud, longitud)
        }
    } catch (error) {
        console.error("Se ha producido un error al obtener el tiempo:", error.message)
    }
}

export async function getMunicipios(municipio) {
    try {
        const datos = await pedirDatos(`api/getMunicipios.php?municipio=${encodeURIComponent(municipio)}`, { avisar: true })

        if (!datos) return

        return datos
    } catch (error) {
        console.error("Se ha producido un error al obtener los municipios:", error.message)
        return null
    }
}