async function validateLogin() {
  var username = document.getElementById('username').value.trim();
  var password = document.getElementById('password').value;
  var errorEl = document.getElementById('login-error');

  if (!username || !password) {
    showError(errorEl, 'Ingrese usuario y contraseña');
    return;
  }

  hideError(errorEl);

  var formData = new URLSearchParams();
  formData.append('username', username);
  formData.append('password', password);

  try {
    var response = await fetch('assets/api/auth.php?accion=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: formData.toString()
    });
    var data = await safeJson(response);

    if (!response.ok || !data.ok) {
      showError(errorEl, data.mensaje || 'No se pudo iniciar sesión.');
      return;
    }

    sessionStorage.setItem('user_name', data.nombre || username);
    showLoader();
    setTimeout(function() {
      window.location.href = 'public/Inicio.html';
    }, 700);
  } catch (error) {
    showError(errorEl, 'Error de conexión con el servidor.');
  }
}

async function safeJson(response) {
  var text = await response.text();
  try {
    return text ? JSON.parse(text) : {};
  } catch (error) {
    return { ok: false, mensaje: text || 'Respuesta inválida del servidor.' };
  }
}

function showError(el, msg) {
  if (!el) return;
  el.textContent = msg;
  el.style.display = 'flex';
}

function hideError(el) {
  if (!el) return;
  el.style.display = 'none';
}

function showLoader() {
  var overlay = document.getElementById('loader-overlay');
  if (overlay) overlay.style.display = 'flex';
}
