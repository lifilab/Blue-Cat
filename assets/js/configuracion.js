var logoActual = null;
var logoRemovido = false;

function setEstado(text) {
    document.getElementById("estado_config").textContent = text || "--";
}

function setValue(id, value) {
    var element = document.getElementById(id);
    if (element) {
        element.value = value == null ? "" : value;
    }
}

function setChecked(id, value) {
    var element = document.getElementById(id);
    if (element) {
        element.checked = Number(value || 0) === 1;
    }
}

function mostrarLogo(logo) {
    var preview = document.getElementById("logo_preview");
    logoActual = logo || null;

    if (logoActual) {
        preview.src = logoActual;
        preview.style.display = "block";
    } else {
        preview.removeAttribute("src");
        preview.style.display = "none";
    }
}

function cargarConfig() {
    setEstado("Cargando...");

    fetch("../assets/api/pos.php?action=config_boleta", {
        credentials: "same-origin"
    })
        .then(function(response) {
            return response.json().then(function(data) {
                return { ok: response.ok, data: data };
            });
        })
        .then(function(result) {
            if (!result.ok) {
                setEstado(result.data.message || "No se pudo cargar la configuracion.");
                return;
            }

            var config = result.data.config || {};
            setValue("nombre_empresa", config.nombre_empresa || "");
            setValue("rut_empresa", config.rut_empresa || "");
            setValue("direccion", config.direccion || "");
            setValue("telefono", config.telefono || "");
            setValue("email", config.email || "");
            setValue("iva_porcentaje", config.iva_porcentaje || "19.00");
            setValue("mensaje_pie", config.mensaje_pie || "");
            setValue("mensaje_agradecimiento", config.mensaje_agradecimiento || "");
            setChecked("mostrar_rut_cliente", config.mostrar_rut_cliente);
            setChecked("mostrar_desglose_iva", config.mostrar_desglose_iva == null ? 1 : config.mostrar_desglose_iva);
            setChecked("mostrar_descuento", config.mostrar_descuento == null ? 1 : config.mostrar_descuento);
            mostrarLogo(config.logo || null);
            setEstado("Listo");
        })
        .catch(function() {
            setEstado("Error de red al cargar configuracion.");
        });
}

function leerLogoSeleccionado() {
    var file = document.getElementById("logo_file").files[0];

    if (!file) {
        return Promise.resolve(logoRemovido ? "" : null);
    }

    if (!file.type.match(/^image\/(png|jpeg|webp|gif)$/)) {
        return Promise.reject(new Error("Formato de logo no permitido."));
    }

    if (file.size > 2000000) {
        return Promise.reject(new Error("Logo demasiado grande."));
    }

    return new Promise(function(resolve, reject) {
        var reader = new FileReader();
        reader.onload = function() {
            resolve(reader.result);
        };
        reader.onerror = function() {
            reject(new Error("No se pudo leer el logo."));
        };
        reader.readAsDataURL(file);
    });
}

function buildPayload(logo) {
    return {
        action: "config_boleta_guardar",
        nombre_empresa: document.getElementById("nombre_empresa").value.trim(),
        rut_empresa: document.getElementById("rut_empresa").value.trim(),
        direccion: document.getElementById("direccion").value.trim(),
        telefono: document.getElementById("telefono").value.trim(),
        email: document.getElementById("email").value.trim(),
        iva_porcentaje: Number(document.getElementById("iva_porcentaje").value || 19),
        mensaje_pie: document.getElementById("mensaje_pie").value.trim(),
        mensaje_agradecimiento: document.getElementById("mensaje_agradecimiento").value.trim(),
        mostrar_rut_cliente: document.getElementById("mostrar_rut_cliente").checked ? 1 : 0,
        mostrar_desglose_iva: document.getElementById("mostrar_desglose_iva").checked ? 1 : 0,
        mostrar_descuento: document.getElementById("mostrar_descuento").checked ? 1 : 0,
        logo: logo
    };
}

function guardarConfig(event) {
    event.preventDefault();
    setEstado("Guardando...");

    leerLogoSeleccionado()
        .then(function(logo) {
            return fetch("../assets/api/pos.php", {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(buildPayload(logo))
            });
        })
        .then(function(response) {
            return response.json().then(function(data) {
                return { ok: response.ok && (data.ok || data.success), data: data };
            });
        })
        .then(function(result) {
            if (!result.ok) {
                setEstado(result.data.message || "No se pudo guardar.");
                alert(result.data.message || "No se pudo guardar.");
                return;
            }

            logoRemovido = false;
            document.getElementById("logo_file").value = "";
            setEstado(result.data.message || "Guardado correctamente.");
            alert(result.data.message || "Guardado correctamente.");
            cargarConfig();
        })
        .catch(function(error) {
            setEstado(error.message || "Error al guardar.");
            alert(error.message || "Error al guardar.");
        });
}

document.addEventListener("DOMContentLoaded", function() {
    document.getElementById("boleta-form").addEventListener("submit", guardarConfig);
    document.getElementById("logo_file").addEventListener("change", function() {
        var file = this.files[0];
        if (!file) {
            return;
        }

        var reader = new FileReader();
        reader.onload = function() {
            logoRemovido = false;
            mostrarLogo(reader.result);
        };
        reader.readAsDataURL(file);
    });
    document.getElementById("quitar_logo").addEventListener("click", function() {
        logoRemovido = true;
        document.getElementById("logo_file").value = "";
        mostrarLogo(null);
    });

    cargarConfig();
});
