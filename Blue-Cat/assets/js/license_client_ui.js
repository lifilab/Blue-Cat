/**
 * Blue-Cat ERP — Cliente de Validación de Licencia de 1 Uso en Frontend
 */

(function () {
  'use strict';

  let heartbeatInterval = null;

  // Iniciar al cargar el DOM
  document.addEventListener('DOMContentLoaded', () => {
    injectLicenseModalHTML();
    checkLicenseState();

    // Heartbeat constante cada 15 segundos
    heartbeatInterval = setInterval(checkLicenseState, 15000);
  });

  function checkLicenseState() {
    fetch('assets/api/auth.php?accion=estado_licencia')
      .then(res => res.json())
      .then(data => {
        if (data.ok && data.license && data.license.active) {
          hideLicenseModal();
          updateLicenseBadge(true, data.license.client_name || 'Licencia Comercial');
        } else {
          showLicenseModal(data.license ? data.license.message : 'Licencia no activa.');
          updateLicenseBadge(false, 'Licencia Inactiva');
        }
      })
      .catch(err => {
        console.warn('[LicenseGuard] Error consultando estado:', err);
      });
  }

  function updateLicenseBadge(active, label) {
    let badge = document.getElementById('bc-license-badge');
    if (!badge) {
      const topBar = document.querySelector('.topbar-right, .header-right, header');
      if (topBar) {
        badge = document.createElement('div');
        badge.id = 'bc-license-badge';
        badge.style.cssText = 'display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; margin-right:12px;';
        topBar.insertBefore(badge, topBar.firstChild);
      }
    }

    if (badge) {
      if (active) {
        badge.style.background = 'rgba(16, 185, 129, 0.15)';
        badge.style.color = '#10b981';
        badge.style.border = '1px solid rgba(16, 185, 129, 0.3)';
        badge.innerHTML = `<i class="fa fa-shield"></i> ${escapeHtml(label)}`;
      } else {
        badge.style.background = 'rgba(239, 68, 68, 0.15)';
        badge.style.color = '#ef4444';
        badge.style.border = '1px solid rgba(239, 68, 68, 0.3)';
        badge.innerHTML = `<i class="fa fa-exclamation-triangle"></i> Sin Licencia`;
      }
    }
  }

  function injectLicenseModalHTML() {
    if (document.getElementById('modal-license-activation')) return;

    const modalHTML = `
      <div id="modal-license-activation" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); backdrop-filter:blur(10px); z-index:99999; align-items:center; justify-content:center; font-family:sans-serif;">
        <div style="background:#111827; border:1px solid rgba(255,255,255,0.1); border-radius:16px; width:90%; max-width:440px; padding:32px; color:#f3f4f6; box-shadow:0 25px 50px -12px rgba(0,0,0,0.7);">
          <div style="text-align:center; margin-bottom:24px;">
            <div style="width:56px; height:56px; background:linear-gradient(135deg, #3b82f6, #8b5cf6); border-radius:14px; display:inline-flex; align-items:center; justify-content:center; font-size:28px; color:#fff; margin-bottom:12px; box-shadow:0 10px 20px rgba(59,130,246,0.4);">
              🔒
            </div>
            <h2 style="font-size:20px; font-weight:700; margin:0 0 6px 0; color:#fff;">Activación de Licencia de 1 Uso</h2>
            <p style="font-size:13px; color:#9ca3af; margin:0;">Blue-Cat ERP requiere una licencia comercial activa verificada en línea.</p>
          </div>

          <form id="form-activate-license">
            <div style="margin-bottom:16px;">
              <label style="display:block; font-size:12px; font-weight:600; color:#9ca3af; margin-bottom:6px;">CORREO ELECTRÓNICO REGISTRADO</label>
              <input type="email" id="lic-email" placeholder="cliente@empresa.com" required style="width:100%; box-sizing:border-box; padding:12px; background:#1f2937; border:1px solid #374151; border-radius:8px; color:#fff; font-size:14px; outline:none;">
            </div>

            <div style="margin-bottom:20px;">
              <label style="display:block; font-size:12px; font-weight:600; color:#9ca3af; margin-bottom:6px;">CLAVE DE LICENCIA (1 USO)</label>
              <input type="text" id="lic-key" placeholder="XXXX-XXXX-XXXX-XXXX" required style="width:100%; box-sizing:border-box; padding:12px; background:#1f2937; border:1px solid #374151; border-radius:8px; color:#f59e0b; font-size:14px; font-family:monospace; font-weight:600; letter-spacing:1px; outline:none;">
            </div>

            <div id="lic-error-msg" style="display:none; padding:10px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.4); color:#ef4444; border-radius:8px; font-size:13px; margin-bottom:16px; text-align:center;"></div>

            <button type="submit" style="width:100%; padding:14px; background:linear-gradient(135deg, #3b82f6, #2563eb); border:none; border-radius:8px; color:#fff; font-size:14px; font-weight:600; cursor:pointer; transition:opacity 0.2s;">
              Validar y Activar Software
            </button>
          </form>
        </div>
      </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHTML);

    document.getElementById('form-activate-license').addEventListener('submit', (e) => {
      e.preventDefault();
      const email = document.getElementById('lic-email').value.trim();
      const licenseKey = document.getElementById('lic-key').value.trim();
      const errorDiv = document.getElementById('lic-error-msg');
      errorDiv.style.display = 'none';

      const formData = new FormData();
      formData.append('email', email);
      formData.append('license_key', licenseKey);

      fetch('assets/api/auth.php?accion=activar_licencia', {
        method: 'POST',
        body: formData
      })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            hideLicenseModal();
            checkLicenseState();
            alert('¡Licencia activada exitosamente!');
          } else {
            errorDiv.textContent = data.message || data.mensaje || 'Error al activar la licencia.';
            errorDiv.style.display = 'block';
          }
        })
        .catch(err => {
          errorDiv.textContent = 'Error de conexión con el servidor.';
          errorDiv.style.display = 'block';
        });
    });
  }

  function showLicenseModal(reason) {
    const modal = document.getElementById('modal-license-activation');
    if (modal) {
      modal.style.display = 'flex';
      const errorDiv = document.getElementById('lic-error-msg');
      if (reason && errorDiv) {
        errorDiv.textContent = reason;
        errorDiv.style.display = 'block';
      }
    }
  }

  function hideLicenseModal() {
    const modal = document.getElementById('modal-license-activation');
    if (modal) modal.style.display = 'none';
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[m]);
  }
})();
