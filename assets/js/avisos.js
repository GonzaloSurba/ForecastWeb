/**
 * Gestiono la ventana de avisos en caso de producirse un error desde aquí
 */

const modalAviso = document.querySelector("#modal-aviso")
const modalAvisoTitulo = document.querySelector("#modal-aviso-titulo")
const modalAvisoMensaje = document.querySelector("#modal-aviso-mensaje")

document.querySelector("#modal-aviso-cerrar").addEventListener("click", (e) => {
    e.preventDefault()
    modalAviso.close()
})

export function mostrarAviso(mensaje, { titulo = "Aviso" } = {}) {
    if (modalAviso.open) return // Evito abrir el modal si ya está abierto
    modalAvisoTitulo.textContent = titulo
    modalAvisoMensaje.textContent = mensaje
    modalAviso.showModal()
}