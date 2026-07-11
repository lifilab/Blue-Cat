var montoTotalEsperado = 0;

function formatDate(date) {
    var options = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' };
    return date.toLocaleDateString('es-ES', options);
}

function formatAmount(value) {
    var number = Number(value || 0);
    return '$' + number.toLocaleString('es-CL');
}

function setText(id, value) {
    var element = document.getElementById(id);
    if (element) {
        element.textContent = value == null || value === '' ? '--' : value;
    }
}

function calcularDiferencia() {
    var inputMontoReal = document.getElementById("monto_real");
    var tdDiferencia = document.getElementById("diferencia");
    var spanDiferencia = document.getElementById("diferencia-calculada");
    if (!inputMontoReal || !tdDiferencia) return;
    var montoReal = Number(inputMontoReal.value || 0);
    var diferencia = montoReal - montoTotalEsperado;

    tdDiferencia.textContent = formatAmount(diferencia);
    if (spanDiferencia) {
        spanDiferencia.textContent = formatAmount(diferencia);
    }
}

document.addEventListener("DOMContentLoaded", function() {
    setText("fecha_cierre", formatDate(new Date()));

    var xhr = new XMLHttpRequest();
    xhr.open("GET", "../assets/PHP/cuadre_de_ventas.php", true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            var data = JSON.parse(xhr.responseText);

            if (!data.ok) {
                alert(data.mensaje || "No se pudo cargar el cuadre.");
                return;
            }

            montoTotalEsperado = Number(data.monto_total || 0);

            setText("empleado", data.empleado);
            setText("nota", data.nota);
            setText("estado_caja", data.estado_caja);
            setText("fecha_apertura", data.fecha_apertura);
            setText("fecha_cierre", data.fecha_cierre || formatDate(new Date()));
            setText("monto_apertura", formatAmount(data.monto_apertura));
            setText("efectivo", formatAmount(data.efectivo));
            setText("tarjeta", formatAmount(data.tarjeta));
            setText("transferencia", formatAmount(data.transferencia));
            setText("monto_total", formatAmount(data.monto_total));

            calcularDiferencia();
        } else {
            alert("Error al cargar el cuadre de ventas.");
        }
    };
    xhr.onerror = function() {
        alert("Error de red al cargar el cuadre de ventas.");
    };
    xhr.send();

    var inputMontoReal = document.getElementById("monto_real");
    if (inputMontoReal) {
        inputMontoReal.addEventListener("input", calcularDiferencia);
    }
});

function openCerrarSesionPopup() {
    document.getElementById("cerrar-sesion-popup").style.display = "block";
}

function cerrarSesion() {
    var xhr = new XMLHttpRequest();

    xhr.onreadystatechange = function () {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                var data;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (error) {
                    data = { ok: false, mensaje: xhr.responseText };
                }

                alert(data.mensaje || xhr.responseText);
                if (data.ok || xhr.responseText.includes('Sesión cerrada correctamente.')) {
                    window.location.href = 'pos.html';
                }
            } else {
                alert('Error al cerrar sesión.');
            }
        }
    };

    xhr.open('POST', '../assets/PHP/cerrar_sesion.php', true);
    xhr.send(new FormData());
    return false;
}

function CloseSesionPopUp() {
    document.getElementById("cerrar-sesion-popup").style.display = "none";
}
