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
    var response = await fetch('assets/PHP/login.php', {
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

async function createAccount() {
  var nombre = document.getElementById('new-username').value.trim();
  var correo = document.getElementById('e-mail').value.trim();
  var password = document.getElementById('new-password').value;
  var confirmPassword = document.getElementById('confirm-password').value;
  var errorEl = document.getElementById('account-error');

  if (!nombre || !correo || !password || !confirmPassword) {
    showError(errorEl, 'Complete todos los campos');
    return;
  }

  if (password !== confirmPassword) {
    showError(errorEl, 'Las contraseñas no coinciden');
    return;
  }

  if (password.length < 6) {
    showError(errorEl, 'La contraseña debe tener al menos 6 caracteres');
    return;
  }

  hideError(errorEl);

  var formData = new URLSearchParams();
  formData.append('new-username', nombre);
  formData.append('e-mail', correo);
  formData.append('new-password', password);
  formData.append('confirm-password', confirmPassword);

  try {
    var response = await fetch('assets/PHP/create_account.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: formData.toString()
    });
    var data = await safeJson(response);

    if (!response.ok || !data.ok) {
      showError(errorEl, data.mensaje || 'No se pudo crear la cuenta.');
      return;
    }

    showAccountSuccess();
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

function showAccountSuccess() {
  var popup = document.getElementById('create-account-popup');
  if (popup) popup.style.display = 'none';

  var successEl = document.getElementById('account-success');
  if (successEl) {
    successEl.style.display = 'flex';
    setTimeout(function() {
      successEl.style.display = 'none';
    }, 3000);
  }

  var username = document.getElementById('new-username');
  var email = document.getElementById('e-mail');
  var password = document.getElementById('new-password');
  var confirm = document.getElementById('confirm-password');
  if (username) username.value = '';
  if (email) email.value = '';
  if (password) password.value = '';
  if (confirm) confirm.value = '';
}

function showCreateAccountPopup() {
  var popup = document.getElementById('create-account-popup');
  if (popup) popup.style.display = 'block';
}

function hideCreateAccountPopup() {
  var popup = document.getElementById('create-account-popup');
  if (popup) popup.style.display = 'none';
}
