var productosCache = [];
var productoSeleccionado = null;

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

function closeStockPopup() {
    document.getElementById("stock-popup").style.display = "none";
    productoSeleccionado = null;
}

function closePricePopup() {
    document.getElementById("price-popup").style.display = "none";
    productoSeleccionado = null;
}

function parseResponse(xhr) {
    try {
        return JSON.parse(xhr.responseText);
    } catch (error) {
        return { ok: false, mensaje: xhr.responseText || "Respuesta invalida del servidor." };
    }
}

function formatAmount(value) {
    var number = Number(value || 0);
    return "$" + number.toLocaleString("es-CL");
}

function appendCell(row, value) {
    var cell = document.createElement("td");
    cell.textContent = value == null ? "" : value;
    row.appendChild(cell);
}

function createActionButton(label, className, onClick) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = className;
    button.textContent = label;
    button.addEventListener("click", onClick);
    return button;
}

function appendActionsCell(row, producto) {
    var cell = document.createElement("td");
    cell.className = "inventory-actions";

    cell.appendChild(createActionButton("Stock", "table-action stock-action", function() {
        openStockPopup(producto);
    }));
    cell.appendChild(createActionButton("Precio", "table-action price-action", function() {
        openPricePopup(producto);
    }));

    row.appendChild(cell);
}

function renderProductos(productos) {
    var tbody = document.querySelector("#product-table tbody");
    tbody.textContent = "";

    productos.forEach(function(producto) {
        var tr = document.createElement("tr");
        tr.dataset.productId = producto.id_producto;

        appendCell(tr, producto.id_producto);
        appendCell(tr, producto.nombre_producto);
        appendCell(tr, producto.codigo_de_barras);
        appendCell(tr, formatAmount(producto.precio_venta));
        appendCell(tr, producto.cantidad);
        appendCell(tr, producto.categoria);
        appendActionsCell(tr, producto);

        tbody.appendChild(tr);
    });

    if (typeof searchProducts === "function") {
        searchProducts();
    }
}

function actualizarProductoEnCache(productoActualizado) {
    productosCache = productosCache.map(function(producto) {
        if (Number(producto.id_producto) !== Number(productoActualizado.id_producto)) {
            return producto;
        }

        return Object.assign({}, producto, productoActualizado);
    });

    renderProductos(productosCache);
}

function mostrarProductos() {
    var xhr = new XMLHttpRequest();

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                productosCache = parseResponse(xhr);
                renderProductos(productosCache);
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

function openStockPopup(producto) {
    productoSeleccionado = producto;
    document.getElementById("stock-product-id").value = producto.id_producto;
    document.getElementById("stock-product-name").textContent = producto.nombre_producto || "";
    document.getElementById("stock-current").value = producto.cantidad;
    document.getElementById("stock-new").value = producto.cantidad;
    document.getElementById("stock-popup").style.display = "block";
    document.getElementById("stock-new").focus();
}

function openPricePopup(producto) {
    productoSeleccionado = producto;
    document.getElementById("price-product-id").value = producto.id_producto;
    document.getElementById("price-product-name").textContent = producto.nombre_producto || "";
    document.getElementById("price-current").value = formatAmount(producto.precio_venta);
    document.getElementById("price-new").value = producto.precio_venta;
    document.getElementById("price-popup").style.display = "block";
    document.getElementById("price-new").focus();
}

function submitInventoryUpdate(form, endpoint, closeCallback) {
    var formData = new FormData(form);
    var xhr = new XMLHttpRequest();

    xhr.open("POST", endpoint, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var data = parseResponse(xhr);

            if (xhr.status === 200 && data.ok) {
                if (data.producto) {
                    actualizarProductoEnCache(data.producto);
                } else {
                    mostrarProductos();
                }
                closeCallback();
            }

            alert(data.mensaje || xhr.responseText);
        }
    };

    xhr.send(formData);
}

function submitStock(event) {
    event.preventDefault();

    var input = document.getElementById("stock-new");
    var value = Number(input.value);
    if (!Number.isFinite(value) || value < 0) {
        alert("El stock debe ser un numero no negativo.");
        return;
    }

    submitInventoryUpdate(
        document.getElementById("stock-form"),
        "../assets/PHP/actualizar_stock_producto.php",
        closeStockPopup
    );
}

function submitPrice(event) {
    event.preventDefault();

    var input = document.getElementById("price-new");
    var value = Number(input.value);
    if (!Number.isFinite(value) || value < 0) {
        alert("El precio debe ser un numero no negativo.");
        return;
    }

    submitInventoryUpdate(
        document.getElementById("price-form"),
        "../assets/PHP/actualizar_precio_producto.php",
        closePricePopup
    );
}

document.addEventListener("DOMContentLoaded", function() {
    var form = document.getElementById("add-product-form");
    var stockForm = document.getElementById("stock-form");
    var priceForm = document.getElementById("price-form");

    if (form) {
        form.addEventListener("submit", agregarProducto);
    }

    if (stockForm) {
        stockForm.addEventListener("submit", submitStock);
    }

    if (priceForm) {
        priceForm.addEventListener("submit", submitPrice);
    }

    mostrarProductos();
});
