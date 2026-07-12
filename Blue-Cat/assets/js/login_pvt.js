// Función para abrir el pop-up
function openPopup() {
    console.log("Abriendo el pop-up...");

    // Realizar una solicitud AJAX para obtener el valor de validar_sesion del servidor
    fetch("../assets/api/auth.php?accion=estado")
        .then(response => {
            if (!response.ok) {
                throw new Error("La solicitud no fue exitosa: " + response.status);
            }
            return response.json();
        })
        .then(data => {
            var popupEl = document.getElementById("popup");
            if (!popupEl) return;
            console.log("Datos recibidos:", data);

            // Verificar el valor de validar_sesion
            if (data && typeof data.validar_sesion !== "undefined") {
                console.log("Valor de validar_sesion:", data.validar_sesion);
                if (data.validar_sesion === 1) {
                    // Si validar_sesion es verdadero, mostrar el popup
                    console.log("Mostrando el pop-up");
                    popupEl.style.display = "block";
                } else {
                    // Si validar_sesion es falso, no mostrar el popup
                    console.log("No se muestra el pop-up");
                }
            } else {
                throw new Error("El formato de los datos recibidos no es válido.");
            }
        })
        .catch(error => {
            console.error("Error al obtener validar_sesion:", error);
        });
}

// Esperar a que el DOM esté completamente cargado
document.addEventListener("DOMContentLoaded", function () {
    // Llamar a openPopup después de que el DOM esté listo
    openPopup();
});



// Función para cerrar el pop-up
function closePopup() {
    document.getElementById("popup").style.display = "none";
}


function apertura() {
    var monto = parseInt(document.getElementById('monto').value, 10) || 0;
    var empleado = document.getElementById('empleado').value || 'Caja Principal';
    var nota = document.getElementById('nota').value || '';
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '../assets/api/pos.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        var data = {};
        try { data = JSON.parse(xhr.responseText); } catch (e) {}
        if (xhr.status >= 200 && xhr.status < 300 && data.success) {
            closePopup();
            showToast('Caja abierta correctamente');
        } else {
            showToast(data.message || data.error || 'No fue posible abrir la caja', 'error');
        }
    };
    xhr.send(JSON.stringify({ accion: 'caja_abrir', monto_apertura: monto, nombre: empleado, nota: nota }));
    return false;
}

// Función para formatear la fecha y hora en formato legible
function formatDate(date) {
    var options = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' };
    return date.toLocaleDateString('es-ES', options);
}


// Función para abrir el popup de cerrar sesión
function openCerrarSesionPopup() {
    document.getElementById("cerrar-sesion-popup").style.display = "block";
}

// Función para cerrar sesión
function showFarewellLoader(msg) {
  var el = document.getElementById('farewell-loader');
  if (!el) {
    el = document.createElement('div');
    el.id = 'farewell-loader';
    el.className = 'loader-overlay';
    el.innerHTML =
      '<div class="loader-box">' +
      '<img src="../assets/img/Blue-Cat_logo-removebg.png" alt="Blue-Cat" class="loader-logo">' +
      '<p class="loader-text" id="farewell-loader-text">' + msg + '</p>' +
      '</div>';
    document.body.appendChild(el);
  }
  document.getElementById('farewell-loader-text').textContent = msg;
  el.style.display = 'flex';
}

function hideFarewellLoader() {
  var el = document.getElementById('farewell-loader');
  if (el) el.style.display = 'none';
}

// Función para cerrar sesión
function cerrarSesion() {
    showFarewellLoader("Cerrando sesión...");
    var formData = new FormData();
    var xhr = new XMLHttpRequest();
    xhr.onreadystatechange = function () {
        if (xhr.readyState === XMLHttpRequest.DONE) {
            if (xhr.status === 200) {
                var txt = document.getElementById('farewell-loader-text');
                if (txt) txt.textContent = '¡Hasta pronto!';
                setTimeout(function () { window.location.href = '../index.html'; }, 1200);
            } else {
                hideFarewellLoader();
                console.error('Error al enviar la solicitud:', xhr.status);
            }
        }
    };
    xhr.open('POST', '../assets/api/auth.php?accion=logout', true);
    xhr.send(formData);
    return false;
}

// Función para cerrar el popup
function CloseSesionPopUp() {
    document.getElementById("cerrar-sesion-popup").style.display = "none";
}

function showToast(msg, type) {
  var t = document.createElement('div');
  t.className = 'toast toast-' + (type === 'error' ? 'err' : 'ok');
  t.innerHTML = msg;
  document.body.appendChild(t);
  requestAnimationFrame(function() { t.classList.add('show'); });
  setTimeout(function() { t.classList.remove('show'); setTimeout(function() { t.remove(); }, 300); }, 2500);
}
