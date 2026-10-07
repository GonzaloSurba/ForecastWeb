import { getAemetData, getAvisosData, getIcaData, getMunicipios } from "./api.js";
import { cargarRadar } from "./radar.js";

const horaActual = new Date().getHours()
let datosReporte = null

function calcularFecha(fecha) {
    const fechaObjetivo = new Date(fecha);
    const minutosConCero = String(fechaObjetivo.getMinutes()).padStart(2, '0');
    const horaObjetivo = `${fechaObjetivo.getHours()}:${minutosConCero}`
    const ahora = new Date();
    
    const diferenciaMilisegundos = ahora - fechaObjetivo;

    const segundos = Math.floor(diferenciaMilisegundos / 1000);
    const minutos = Math.floor(segundos / 60);
    const horas = Math.floor(minutos / 60);

    if (horas == 1) {
        return `Actualizado hace ${horas} hora (${horaObjetivo})`
    }
    if (horas > 1) {
        return `Actualizado hace ${horas} horas (${horaObjetivo})`
    }
    if (minutos > 1) {
        return `Actualizado hace ${minutos} min (${horaObjetivo})`
    }
    return `Actualizado hace ${segundos} seg (${horaObjetivo})`
}

function obtenerDatoActual(datosHoy) {
    for (let dato of datosHoy) {
        if (dato.periodo == horaActual) {
            return dato.value
        }
    }
    return null
}

function actualizarBarraTemperaturaMaxMin(valorActual, minimo, maximo) {
    if (maximo === minimo) return;

    const valorClampeado = Math.max(minimo, Math.min(maximo, valorActual));

    const porcentaje = ((valorClampeado - minimo) / (maximo - minimo)) * 100;

    const circulo = document.querySelector(".gauge-marca");
    circulo.style.left = `${porcentaje}%`;
}

function obtenerTemperaturaMaxMin(temperaturasHoy, temperaturaActual) {
    const temperaturasOrdenadas = temperaturasHoy.toSorted((a, b) => a.value - b.value);
    const tempMin = temperaturasOrdenadas[0]
    const tempMax = temperaturasOrdenadas.at(-1)

    actualizarBarraTemperaturaMaxMin(temperaturaActual, tempMin.value, tempMax.value)

    return {
        "tempMin": tempMin,
        "tempMax": tempMax
    }
}

function obtenerHorasLuz(amanecer, atardecer) {
    const convertirEnMinutos = (hora) => {
        const [horas, minutos] = hora.split(':').map(Number);
        return (horas * 60) + minutos;
    };

    const minutosAmanecer = convertirEnMinutos(amanecer);
    const minutosAtardecer = convertirEnMinutos(atardecer);
    let diferenciaMinutos = minutosAmanecer - minutosAtardecer;

    // Manejo el caso si el rango pasa de la medianoche (ej. de 23:00 a 01:00)
    // En España esto no ocurre pero por si se pudieran ver el tiempo de otros paises en el futuro
    if (diferenciaMinutos < 0) {
        diferenciaMinutos += 24 * 60; 
    }

    const horasResultado = Math.floor(diferenciaMinutos / 60);
    const minutosResultado = diferenciaMinutos % 60;

    return `${horasResultado}h ${minutosResultado}m de luz solar`
}

function obtenerClimaActual(climaHoy) {
    for (let estado of climaHoy) {
        if (estado.periodo == horaActual) {
            return {"icono": estado.value, "descripcion": estado.descripcion}
        }
    }
    return {"icono": null, "descripcion": null}
}

function obtenerVientoActual(vientosHoy) {
    for (let viento of vientosHoy) {
        if (viento.periodo == horaActual && viento.direccion) {
            // Si el viento no contiene el campo direccion significa que es es la racha máxima de esa hora
            return viento
        }
    }
}

function descripcionHumedad(humedad) {
    if (humedad < 30) {
        return "Ambiente muy seco"
    } else if (humedad >= 30 && humedad <= 55) {
        return "Ambiente confortable"
    } else if (humedad >= 56 && humedad <= 70) {
        return "Ambiente húmedo"
    } else {
        return "Ambiente muy húmedo"
    }
}

function descripcionVelocidadViento(viento) {
    let velocidadViento = viento["velocidad"][0]
    let direccionViento = viento["direccion"][0]
    if (velocidadViento <= 0 || !velocidadViento) {
        return "Calma"
    } else if (velocidadViento < 2) {
        return `Calma hacia ${direccionViento}`
    } else if (velocidadViento >= 2 && velocidadViento <= 5) {
        return `Aire ligero hacia ${direccionViento}`
    } else if (velocidadViento >= 6 && velocidadViento <= 12) {
        return `Brisa muy débil hacia ${direccionViento}`
    } else if (velocidadViento >= 12 && velocidadViento <= 19) {
        return `Brisa ligera hacia ${direccionViento}`
    } else if (velocidadViento >= 20 && velocidadViento <= 29) {
        return `Brisa moderada hacia ${direccionViento}`
    } else if (velocidadViento >= 30 && velocidadViento <= 39) {
        return `Brisa fresca hacia ${direccionViento}`
    } else if (velocidadViento >= 40 && velocidadViento <= 50) {
        return `Brisa fuerte hacia ${direccionViento}`
    } else if (velocidadViento >= 51 && velocidadViento <= 61) {
        return `Viento fuerte hacia ${direccionViento}`
    } else if (velocidadViento >= 62 && velocidadViento <= 74) {
        return `Temporal de viento hacia ${direccionViento}`
    } else {
        return `Temporal fuerte hacia ${direccionViento}`
    }
}

function descripcionNubosidad(nubosidad) {
    if (nubosidad == 0) {
        return "Despejado"
    } else if (nubosidad >= 1 && nubosidad <= 12) {
        return "Casi despejado"
    } else if (nubosidad >= 13 && nubosidad <= 25) {
        return "Poco nuboso"
    } else if (nubosidad >= 26 && nubosidad <= 38) {
        return "Intervalos nubosos"
    } else if (nubosidad >= 39 && nubosidad <= 50) {
        return "Parcialmente nublado"
    } else if (nubosidad >= 51 && nubosidad <= 62) {
        return "Mayormente nublado"
    } else if (nubosidad >= 63 && nubosidad <= 75) {
        return "Nuboso"
    } else if (nubosidad >= 76 && nubosidad <= 87) {
        return "Casi cubierto"
    } else {
        return "Nublado"
    }
}

function descripcionPresionAtmosferica(presion) {
    if (presion > 1020) {
        return "Presión alta / anticiclón"
    } else if (presion < 1005) {
        return "Baja presión / borrasca"
    } else {
        return "Presión normal / estable"
    }
}

function descripcionVisibilidad(visibilidad) {
    if (visibilidad < 50) {
        return "Visibilidad nula"
    } else if (visibilidad < 200) {
        return "Visibilidad densa"
    } else if (visibilidad < 1000) {
        return "Visibilidad muy mala"
    } else if (visibilidad >= 1000 && visibilidad < 4000) {
        return "Visibilidad reducida"
    } else if (visibilidad >= 4000 && visibilidad < 10000) {
        return "Visibilidad moderada"
    } else if (visibilidad >= 10000 && visibilidad < 20000) {
        return "Visibilidad buena"
    } else if (visibilidad >= 20000 && visibilidad <= 50000) {
        return "Visibilidad excelente"
    } else {
        return "Visibilidad excepcional"
    }
}

function obtenerPrediccionProximas12Horas(dias, fechaReferencia = new Date()) {
    // Asegurar que procesamos un objeto/array
    const data = typeof dias === 'string' ? JSON.parse(dias) : dias;

    if (!data) {
        return [];
    }

    const fechaInicio = new Date(fechaReferencia);
    fechaInicio.setMinutes(0, 0, 0);
    const fechaFin = new Date(fechaInicio.getTime() + 12 * 60 * 60 * 1000);

    // Aplanar y mapear todas las horas de todos los días con su Timestamp real
    const horasIndexadas = [];

    dias.forEach(dia => {
        // Tomamos la fecha base "YYYY-MM-DD"
        const fechaBaseStr = dia.fecha.split('T')[0];

        // Mapear por periodo (hora de 00 a 23)
        dia.temperatura?.forEach(tempItem => {
            const periodo = tempItem.periodo;
            const horaStr = `${fechaBaseStr}T${periodo.padStart(2, '0')}:00:00`;
            const fechaHora = new Date(horaStr);

            // Solo procesamos si entra dentro de la ventana de 12 horas
            if (fechaHora >= fechaInicio && fechaHora <= fechaFin) {

                // 1. Estado del cielo (icono)
                const estadoCieloObj = dia.estadoCielo?.find(item => item.periodo === periodo);
                const estadoCielo = estadoCieloObj ? {
                    icono: estadoCieloObj.value,
                    descripcion: estadoCieloObj.descripcion
                } : null;

                // 2. Temperatura
                const temperatura = tempItem.value;

                // 3. Viento (filtrar solo los objetos que tienen la propiedad 'velocidad')
                const vientoObj = dia.vientoAndRachaMax?.find(item => item.periodo === periodo && item.velocidad);
                const viento = vientoObj ? {
                    velocidad: vientoObj.velocidad[0],
                    direccion: vientoObj.direccion ? vientoObj.direccion[0] : null
                } : null;

                // 4. Probabilidad de precipitación (abarca rangos de horas tipo "0814", "1420", "2002")
                const horaNum = parseInt(periodo, 10);
                const probObj = dia.probPrecipitacion?.find(item => {
                    const p = item.periodo;
                    const inicio = parseInt(p.substring(0, 2), 10);
                    const fin = parseInt(p.substring(2, 4), 10);

                    if (inicio < fin) {
                        return horaNum >= inicio && horaNum < fin;
                    } else {
                        // Caso en el que cruza la medianoche (ej. "2002")
                        return horaNum >= inicio || horaNum < fin;
                    }
                });
                const probPrecipitacion = probObj ? probObj.value : "0";

                horasIndexadas.push({
                    fechaHora: horaStr,
                    hora: `${periodo}:00`,
                    periodo: periodo,
                    estadoCielo,
                    temperatura: `${temperatura}°C`,
                    probPrecipitacion: `${probPrecipitacion}%`,
                    viento
                });
            }
        });
    });

    // Ordenar por hora cronológica
    return horasIndexadas.sort((a, b) => new Date(a.fechaHora) - new Date(b.fechaHora));
}

function actualizarBusquedasRecientes() {
    const listaFrecuentes = document.querySelector(".buscador-frecuentes")
    if (!listaFrecuentes) return

    const itemsBorrar = listaFrecuentes.querySelectorAll("button")
    if (!itemsBorrar) return
    itemsBorrar.forEach(item => item.remove())

    const listaBusquedas = JSON.parse(localStorage.getItem("busquedas"))
    if (!listaBusquedas || listaBusquedas.length == 0) {
        listaFrecuentes.style.display = "none"
        return
    }

    for (let municipio of listaBusquedas) {
        const botonMunicipioFrecuente = document.createElement("button")
        botonMunicipioFrecuente.textContent = municipio
        botonMunicipioFrecuente.addEventListener("click", (e) => {
            e.preventDefault()
            obtenerDatosTiempo({ municipio: municipio })
        })
        listaFrecuentes.append(botonMunicipioFrecuente)
    }

    listaFrecuentes.style.display = "flex"
}

function getGeolocation() {
    navigator.geolocation.getCurrentPosition(function (position) {
        //console.log(position);
        let { latitude: latitud, longitude: longitud } = position.coords;
        console.log(`Latitud: ${latitud}, Longitud: ${longitud}`)
        if (!latitud || !longitud) {
            alert("No se ha podido obtener su localización")
            return
        }
        obtenerDatosTiempo({ latitud: latitud, longitud: longitud })
    })
}

function mostrarResultados(resultados) {
    const desplegable = document.querySelector(".buscador-input-dropdown-content")
    desplegable.innerHTML = ""

    const resultadosUnicos = new Map()

    resultados.forEach((resultado) => {
        // Un municipio puede englobar una o varias poblaciones.
        // Normalmente la población principal se llama igual que el municipio,
        // por lo que aquí evito duplicados
        const nombreMunicipio = resultado["poblacion"] ?? resultado["muni"]

        if (!resultadosUnicos.has(nombreMunicipio)) {
            resultadosUnicos.set(nombreMunicipio, resultado);
        } else {
            // Priorizo población sobre municipio
            const itemExistente = resultadosUnicos.get(nombreMunicipio);
            if (resultado["type"] === "poblacion" && itemExistente["type"] === "Municipio") {
                resultadosUnicos.set(nombreMunicipio, resultado);
            }
        }
    })

    const listResultados = Array.from(resultadosUnicos.values())
    for (let resultado of listResultados) {
        const nombreMunicipio = resultado["poblacion"] ?? resultado["muni"]
        const sugerencia = document.createElement("a")
        sugerencia.textContent = `${nombreMunicipio}, ${resultado["province"]}, ${resultado["comunidadAutonoma"]}`
        sugerencia.addEventListener("click", (e) => {
            e.preventDefault()
            obtenerDatosTiempo({ municipio: nombreMunicipio, codigoINE: resultado["muniCode"], municipioPrincipal: resultado["muni"] })
        })
        desplegable.append(sugerencia)
    }

    desplegable.style.display = "block"
}

async function obtenerDatosTiempo({ municipio = null, latitud = null, longitud = null, codigoINE = null, municipioPrincipal = null }) {
    if (!municipio && !codigoINE && (!latitud || !longitud)) {
        alert("Debes introducir un nombre, codigo INE o usar tu ubicación para buscar el tiempo de un municipio")
        return
    }

    const desplegable = document.querySelector(".buscador-input-dropdown-content")
    desplegable.style.display = "none"

    if (localStorage.getItem("busquedas") && municipio) {
        let busquedas = JSON.parse(localStorage.getItem("busquedas"))
        busquedas = busquedas.filter(m => m != municipio)
        busquedas.unshift(municipio)
        if (busquedas.length > 5) {
            busquedas.pop()
        }
        localStorage.setItem("busquedas", JSON.stringify(busquedas))
    } else if (municipio) {
        let busquedas = []
        busquedas.unshift(municipio)
        localStorage.setItem("busquedas", JSON.stringify(busquedas))
    }

    actualizarBusquedasRecientes()

    const datosTiempo = await getAemetData({ municipio: municipioPrincipal ?? municipio, latitud: latitud, longitud: longitud, codigoINE: codigoINE })
    if (!datosTiempo) return

    const avisosTiempo = await getAvisosData({ municipio: municipioPrincipal ?? municipio, latitud: latitud, longitud: longitud })
    
    mostrarDatosTiempo(datosTiempo["AEMET"], datosTiempo["OpenWeather"], avisosTiempo)

    // El ICA depende de la posicion, no de AEMET: se pide con las coordenadas de
    // OpenWeather si vienen y si no con el nombre, para que tambien funcione al
    // geolocalizar o cuando OpenWeather falla.
    const ica = await getIcaData({
        municipio: municipio,
        latitud: datosTiempo["OpenWeather"]?.coord?.lat ?? latitud,
        longitud: datosTiempo["OpenWeather"]?.coord?.lon ?? longitud,
    })
    mostrarIca(ica)

    datosReporte = {
        fechaExportacion: new Date().toISOString(),
        municipio: municipioPrincipal ?? municipio,
        latitud, longitud,
        AEMET: datosTiempo["AEMET"],
        OpenWeather: datosTiempo["OpenWeather"],
        avisos: avisosTiempo,
        ica,
    }
}

async function buscarMunicipiosYPoblaciones(municipio) {
    const resultados = await getMunicipios(municipio)
    if (!resultados) return
    if (resultados["CPRO"] && resultados["CMUN"]) {
        // En caso de tener una respuesta del CSV en vez de la API
        obtenerDatosTiempo({ municipio: municipio })
        return
    }
    mostrarResultados(resultados)
}

function mostrarDatosTiempo(datosAemet, datosOpenWeather = null, avisosTiempo = null) {
    let datos = datosAemet[0]

    const iconoClimaActual = document.querySelector("#iconoClimaActual")
    const descripcionClimaActual = obtenerClimaActual(datos.prediccion.dia[0].estadoCielo)["descripcion"]
    const temperaturaActualElement = document.querySelectorAll(".temperaturaActual")
    const temperaturaActual = obtenerDatoActual(datos.prediccion.dia[0].temperatura)
    const temperaturaMin = document.querySelectorAll(".tempMin")
    const temperaturaMax = document.querySelectorAll(".tempMax")
    const temperaturasMaxMin = obtenerTemperaturaMaxMin(datos.prediccion.dia[0].temperatura, temperaturaActual)
    const horaAmanecer = datos.prediccion.dia[0].orto
    const horaAtardecer = datos.prediccion.dia[0].ocaso
    const humedad = obtenerDatoActual(datos.prediccion.dia[0].humedadRelativa)
    const viento = datos.prediccion.dia[0].vientoAndRachaMax
    const vientoActual = obtenerVientoActual(viento)
    const descripcionVientoElement = document.querySelectorAll(".descripcionViento")

    // Aviso meteorológico
    if (avisosTiempo && (avisosTiempo?.avisos).length > 0) {
        const fechaInicioAviso = new Date(avisosTiempo.avisos[0].inicio)
        const strFechaInicioAviso = fechaInicioAviso ? 
            `(${fechaInicioAviso.getDate()}/${fechaInicioAviso.getMonth() + 1}/${fechaInicioAviso.getFullYear()})`
            : ""
        document.querySelector("#avisoMeteorologico").textContent = 
            `${avisosTiempo.avisos[0].cabecera}. ${avisosTiempo.avisos[0].descripcion} ${strFechaInicioAviso}`
        document.querySelector(".aviso-tiempo-hoy-section").style.display = "inline"
    }

    // Tarjeta principal
    document.querySelector("#nombreMunicipio").textContent = datos.nombre
    document.querySelector("#nombreProvincia").textContent = datos.provincia
    document.querySelector("#ultimaActualizacion").textContent = calcularFecha(datos.elaborado)
    temperaturaActualElement.forEach((temp, i) => {
        temp.textContent = temperaturaActual
    })
    iconoClimaActual.src = 
        `https://www.aemet.es/imagenes/png/estado_cielo/${obtenerClimaActual(datos.prediccion.dia[0].estadoCielo)["icono"]}_g.png`
    iconoClimaActual.alt = `Icono del clima actual: ${descripcionClimaActual}`
    document.querySelector("#descripcionClimaActual").textContent = descripcionClimaActual

    // Métricas actuales
    document.querySelector("#sensacionTermicaActual").textContent = obtenerDatoActual(datos.prediccion.dia[0].sensTermica)
    temperaturaMin.forEach((temp, i) => {
        temp.textContent = temperaturasMaxMin["tempMin"].value
    })
    document.querySelector("#horaTempMin").textContent = `${temperaturasMaxMin["tempMin"].periodo} h`
    temperaturaMax.forEach((temp, i) => {
        temp.textContent = temperaturasMaxMin["tempMax"].value
    })
    document.querySelector("#horaTempMax").textContent = `${temperaturasMaxMin["tempMax"].periodo} h`
    document.querySelector("#horaAmanecer").textContent = horaAmanecer
    document.querySelector("#horaAtardecer").textContent = horaAtardecer
    document.querySelector("#horasLuzSolar").textContent = obtenerHorasLuz(horaAmanecer, horaAtardecer)

    // Telemetría atmosférica
    document.querySelector("#humedadActual").textContent = `${humedad}%`
    document.querySelector(".gauge-ring").style.setProperty('--gauge-value', `${humedad}`);
    document.querySelector(".descripcionHumedad").textContent = descripcionHumedad(humedad)
    document.querySelector("#velocidadVientoActual").textContent = vientoActual["velocidad"][0]
    actualizarAgujaViento(vientoActual["direccion"][0])
    descripcionVientoElement.forEach((viento, i) => {
        viento.textContent = descripcionVelocidadViento(vientoActual)
    })

    if (datosOpenWeather) {
        const coordenadaLatitud = datosOpenWeather["coord"]["lat"]
        const coordenadaLongitud = datosOpenWeather["coord"]["lon"]
        const nubosidad = datosOpenWeather["clouds"]["all"]
        const nubosidadOctas = Math.round(nubosidad / 12.5)
        const presion = datosOpenWeather["main"]["pressure"]
        const visibilidad = datosOpenWeather["visibility"]

        cargarRadar({ latitud: coordenadaLatitud, longitud: coordenadaLongitud })
        document.querySelector("#radar-lat").textContent = coordenadaLatitud
        document.querySelector("#radar-lon").textContent = coordenadaLongitud
        document.querySelector("#nivelNubosidad").textContent = `${nubosidad}%`
        document.querySelector(".descripcionNubosidad").textContent = descripcionNubosidad(nubosidad)
        document.querySelector("#nubosidadOctas").textContent = nubosidadOctas === 1 ? `${nubosidadOctas} octa` : `${nubosidadOctas} octas`
        document.querySelector("#presionAtmosferica").textContent = presion
        document.querySelector("#descPresion").textContent = descripcionPresionAtmosferica(presion)
        document.querySelector("#distanciaVisionKm").textContent = (visibilidad / 1000).toFixed(2)
        document.querySelector("#distanciaVisionM").textContent = visibilidad
        document.querySelector("#descVisibilidad").textContent = descripcionVisibilidad(visibilidad)
    }

    // Previsión 12 horas
    const padre = document.querySelector(".horaria-slots")
    const plantillaTiempo12h = document.querySelector("#tpl-tiempo12h")
    const tiempoProximas12h = obtenerPrediccionProximas12Horas(datos.prediccion.dia)
    padre.innerHTML = ""
    for (let tiempo of tiempoProximas12h) {
        const clon = plantillaTiempo12h.content.cloneNode(true)
        if (horaActual == tiempo["periodo"]) {
            clon.querySelector(".slot").classList.add("slot-actual")
        }
        clon.querySelector(".slot-hora").textContent = tiempo["hora"]
        clon.querySelector(".slot-icono").src =
            `https://www.aemet.es/imagenes/png/estado_cielo/${tiempo["estadoCielo"]["icono"]}.png`
        clon.querySelector(".slot-icono").alt = tiempo["estadoCielo"]["descripcion"]
        clon.querySelector(".slot-temp").textContent = tiempo["temperatura"]
        clon.querySelector("#probLluvia").textContent = tiempo["probPrecipitacion"]
        clon.querySelector(".slot-viento").textContent = `${tiempo["viento"]["velocidad"]} km/h`
        padre.append(clon)
    }

}

// Variantes de color del pill del ICA, alineadas con las categorias oficiales
// de MITECO y con las clases de estilo.css.
const VARIANTES_ICA = [
    "pill-ica-buena",
    "pill-ica-razonablemente-buena",
    "pill-ica-regular",
    "pill-ica-desfavorable",
    "pill-ica-muy-desfavorable",
    "pill-ica-extremadamente-desfavorable",
    "pill-ica-sin-datos",
]

function mostrarIca(ica) {
    const pill = document.querySelector("#pillIca")
    if (!pill) return

    // Sin estacion dentro del radio no hay nada que informar: se oculta el pill
    // entero en vez de dejar en pantalla el ICA del municipio anterior.
    if (!ica) {
        pill.style.display = "none"
        return
    }

    pill.style.display = ""

    const categoria = ica.categoria.toLowerCase().replace(/\s+/g, "-")
    VARIANTES_ICA.forEach(variante => pill.classList.remove(variante))
    pill.classList.add(`pill-ica-${categoria}`)

    document.querySelector("#nivelIca").textContent = ica.indice
    document.querySelector("#categoriaIca").textContent = ica.categoria

    // La fecha del CSV viene en UTC sin sufijo: se etiqueta como tal para no
    // presentar la hora local del navegador como si fuera la de la medicion.
    const fechaMedicion = new Date(`${ica.fecha}Z`).toLocaleString("es-ES", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        timeZone: "UTC",
    })

    const detalle = [
        `Estación ${ica.estacion.nombre} (${ica.estacion.tipo}) a ${String(ica.distancia_km).replace(".", ",")} km`,
        ica.debido_a ? `contaminante ${ica.debido_a}` : null,
        `medido el ${fechaMedicion} UTC`,
        ica.parcial ? "dato parcial: calculado con menos contaminantes" : null,
    ].filter(Boolean)

    pill.title = detalle.join(" • ")
}

function actualizarReloj() {
    const fecha = new Date();
    
    // Obtenemos hora, minutos y segundos
    let horas = fecha.getHours();
    let minutos = fecha.getMinutes();
    
    // Añadimos un cero delante si el número es menor a 10
    horas = horas < 10 ? "0" + horas : horas;
    minutos = minutos < 10 ? "0" + minutos : minutos;
    
    // Creamos el formato de hora
    const horaActualCompleta = `${horas}:${minutos}`;
    
    // Mostramos la hora en el elemento HTML
    document.querySelector("time").textContent = horaActualCompleta;
}

// Mapa de conversión (acepta tanto abreviaturas como nombres completos)
const windDirections = {
    'N': 0,     'NORTE': 0,
    'NE': 45,   'NORDESTE': 45,
    'E': 90,    'ESTE': 90,
    'SE': 135,  'SUDESTE': 135,
    'S': 180,   'SUR': 180,
    'SO': 225,  'SUROESTE': 225,
    'O': 270,   'OESTE': 270,
    'NO': 315,  'NOROESTE': 315,
    'C': 0,     'CALMA': 0
};

function actualizarAgujaViento(direccionRecibida) {
    const aguja = document.querySelector('.compass-aguja');
    if (!aguja) return;

    // Convertir a mayúsculas y limpiar por si viene "NE/Nordeste" o por separado
    const clave = direccionRecibida.split('/')[0].trim().toUpperCase();
    
    // Obtener los grados (por defecto 0 si no encuentra coincidencia)
    const grados = windDirections[clave] ?? 0;

    // Actualizar la variable CSS en la aguja
    aguja.style.setProperty('--wind-deg', `${grados}deg`);
}

(function () {
    actualizarReloj();
    setInterval(actualizarReloj, 1000);

    obtenerHorasLuz("08:19", "20:09")

    actualizarBusquedasRecientes()

    const modalInicio = document.querySelector("#modal-inicio")
    modalInicio.showModal()

    const cerrarModalInicio = document.querySelector("#modal-inicio-cerrar")
    cerrarModalInicio.addEventListener("click", (e) => {
        e.preventDefault()
        modalInicio.close()
    })

    const limpiarBuscador = document.querySelector(".buscador-x-button")
    limpiarBuscador.addEventListener("click", (e) => {
        e.preventDefault()
        document.querySelector("#municipio").value = ""
    })

    const buscador = document.querySelector("#buscar-municipio")
    buscador.addEventListener("click", (e) => {
        e.preventDefault()
        let municipioABuscar = document.querySelector("#municipio").value
        if (!municipioABuscar) {
            alert("Debes introducir un municipio")
            return
        }
        buscarMunicipiosYPoblaciones(municipioABuscar)
    })

    const geolocalizarButton = document.querySelector(".buscador-ubicacion-button")
    geolocalizarButton.addEventListener("click", getGeolocation)

    document.querySelector(".horaria-link").addEventListener("click", (e) => {
        e.preventDefault()
        if (!datosReporte) {
            alert("Primero busca un municipio para exportar el reporte")
            return
        }
        const blob = new Blob([JSON.stringify(datosReporte, null, 2)], { type: "application/json" })
        const url = URL.createObjectURL(blob)
        const enlace = document.createElement("a")
        enlace.href = url
        enlace.download = `reporte-tiempo-${datosReporte.municipio}-${new Date().toISOString().slice(0, 10)}.json`
        enlace.click()
        URL.revokeObjectURL(url)
    })
})();