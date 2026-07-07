function openPopup() {
    document.getElementById("create-product-popup").style.display = "block";
}

function closePopup() {
    document.getElementById("create-product-popup").style.display = "none";
}

function openImportPopup() {
    document.getElementById("import-popup").style.display = "block";
}

function closeImportPopup() {
    document.getElementById("import-popup").style.display = "none";
}

function parseResponse(xhr) {
    try {
        return JSON.parse(xhr.responseText);
    } catch (error) {
        return { ok: false, mensaje: xhr.responseText || "Respuesta invalida del servidor." };
    }
}

function appendCell(row, value) {
    var cell = document.createElement("td");
    cell.textContent = value == null ? "" : value;
    row.appendChild(cell);
}

function renderProductos(productos) {
    var tbody = document.querySelector("#product-table tbody");
    tbody.textContent = "";

    productos.forEach(function(producto) {
        var tr = document.createElement("tr");
        appendCell(tr, producto.id_producto);
        appendCell(tr, producto.nombre_producto);
        appendCell(tr, producto.codigo_de_barras);
        appendCell(tr, producto.precio_venta);
        appendCell(tr, producto.cantidad);
        appendCell(tr, producto.categoria);
        tbody.appendChild(tr);
    });
}

function mostrarProductos() {
    var xhr = new XMLHttpRequest();

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                renderProductos(parseResponse(xhr));
            } else {
                alert("No se pudo cargar el inventario.");
            }
        }
    };

    xhr.open("GET", "../assets/PHP/obtener_productos.php", true);
    xhr.send();
}

function agregarProducto(event) {
    if (event) {
        event.preventDefault();
    }

    var form = document.getElementById("add-product-form");
    var formData = new URLSearchParams(new FormData(form));
    var xhr = new XMLHttpRequest();

    xhr.open("POST", "../assets/PHP/agregar_productos.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var data = parseResponse(xhr);
            alert(data.mensaje || xhr.responseText);

            if (xhr.status === 200 && data.ok) {
                form.reset();
                closePopup();
                mostrarProductos();
            }
        }
    };

    xhr.send(formData);
}

function uploadFile() {
    var fileInput = document.getElementById("file-input");
    var file = fileInput.files[0];

    if (!file) {
        alert("Seleccione un archivo CSV.");
        return;
    }

    var formData = new FormData();
    formData.append("file", file);

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "../assets/PHP/subir_archivo.php", true);

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var data = parseResponse(xhr);
            alert(data.mensaje || xhr.responseText);

            if (xhr.status === 200 && data.ok) {
                fileInput.value = "";
                closeImportPopup();
                mostrarProductos();
            }
        }
    };

    xhr.send(formData);
}

document.addEventListener("DOMContentLoaded", function() {
    var form = document.getElementById("add-product-form");

    if (form) {
        form.addEventListener("submit", agregarProducto);
    }

    mostrarProductos();
});
