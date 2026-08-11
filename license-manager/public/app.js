/**
 * LOGICA DEL PANEL ADMINISTRADOR - LICENCEGUARD
 */

document.addEventListener('DOMContentLoaded', () => {
  // Estado Global
  let token = localStorage.getItem('lg_admin_token');
  let clientsData = [];
  let autoRefreshInterval = null;

  // DOM Elements - Vistas
  const loginView = document.getElementById('login-view');
  const dashboardView = document.getElementById('dashboard-view');

  // DOM Elements - Formulario Login
  const loginForm = document.getElementById('login-form');
  const loginUsernameInput = document.getElementById('login-username');
  const loginPasswordInput = document.getElementById('login-password');
  const btnTogglePass = document.getElementById('btn-toggle-pass');
  const loginError = document.getElementById('login-error');

  // DOM Elements - Header & Logout
  const displayAdminUsername = document.getElementById('display-admin-username');
  const btnLogout = document.getElementById('btn-logout');
  const btnOpenChangePass = document.getElementById('btn-open-change-pass');

  // DOM Elements - Metrics
  const statTotalClients = document.getElementById('stat-total-clients');
  const statActiveLicenses = document.getElementById('stat-active-licenses');
  const statSuspendedLicenses = document.getElementById('stat-suspended-licenses');
  const statOnlineSessions = document.getElementById('stat-online-sessions');

  // DOM Elements - Table & Toolbar
  const searchInput = document.getElementById('search-input');
  const filterStatus = document.getElementById('filter-status');
  const btnRefreshData = document.getElementById('btn-refresh-data');
  const clientsTableBody = document.getElementById('clients-table-body');

  // DOM Elements - Modal Agregar Cliente
  const modalAddClient = document.getElementById('modal-add-client');
  const btnOpenAddModal = document.getElementById('btn-open-add-modal');
  const btnCloseAddModal = document.getElementById('btn-close-add-modal');
  const btnCancelAdd = document.getElementById('btn-cancel-add');
  const formAddClient = document.getElementById('form-add-client');
  const btnGenRandomKey = document.getElementById('btn-gen-random-key');
  const addLicenseKeyInput = document.getElementById('add-license-key');
  const addClientError = document.getElementById('add-client-error');

  // DOM Elements - Modal Cambiar Contraseña
  const modalChangePass = document.getElementById('modal-change-pass');
  const btnClosePassModal = document.getElementById('btn-close-pass-modal');
  const btnCancelPass = document.getElementById('btn-cancel-pass');
  const formChangePass = document.getElementById('form-change-pass');
  const changePassError = document.getElementById('change-pass-error');
  const changePassSuccess = document.getElementById('change-pass-success');

  // Initialization
  if (token) {
    showDashboardView();
  } else {
    showLoginView();
  }

  // ==========================================
  // MANEJO DE AUTENTICACIÓN Y NAVEGACIÓN
  // ==========================================

  function showLoginView() {
    token = null;
    localStorage.removeItem('lg_admin_token');
    localStorage.removeItem('lg_admin_username');
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    loginView.classList.add('active');
    dashboardView.classList.remove('active');
  }

  function showDashboardView() {
    loginView.classList.remove('active');
    dashboardView.classList.add('active');
    const savedUser = localStorage.getItem('lg_admin_username') || 'admin';
    displayAdminUsername.textContent = savedUser;

    fetchDashboardData();
    // Auto-actualizar datos cada 10 segundos para ver conexiones en vivo
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(fetchDashboardData, 10000);
  }

  // Toggle visibilidad contraseña login
  btnTogglePass.addEventListener('click', () => {
    const type = loginPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
    loginPasswordInput.setAttribute('type', type);
    btnTogglePass.innerHTML = type === 'password' ? '<i class="ri-eye-line"></i>' : '<i class="ri-eye-off-line"></i>';
  });

  // Login Submit
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    loginError.classList.add('hidden');
    
    const username = loginUsernameInput.value.trim();
    const password = loginPasswordInput.value.trim();

    try {
      const res = await fetch('/api/admin/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password })
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || 'Fallo al iniciar sesión');
      }

      token = data.token;
      localStorage.setItem('lg_admin_token', token);
      localStorage.setItem('lg_admin_username', data.username);
      showToast('Inicio de sesión exitoso', 'success');
      showDashboardView();
    } catch (err) {
      loginError.textContent = err.message;
      loginError.classList.remove('hidden');
    }
  });

  // Logout
  btnLogout.addEventListener('click', () => {
    showToast('Sesión cerrada correctamente', 'success');
    showLoginView();
  });

  // ==========================================
  // CARGA DE DATOS Y RENDERIZADO
  // ==========================================

  async function fetchDashboardData() {
    if (!token) return;

    try {
      // 1. Cargar Estadísticas
      const statsRes = await fetch('/api/admin/stats', {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (statsRes.status === 401) return showLoginView();
      const stats = await statsRes.json();

      statTotalClients.textContent = stats.total_clients || 0;
      statActiveLicenses.textContent = stats.active_licenses || 0;
      statSuspendedLicenses.textContent = stats.suspended_licenses || 0;
      statOnlineSessions.textContent = stats.online_sessions || 0;

      // 2. Cargar Clientes
      const clientsRes = await fetch('/api/admin/clients', {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (clientsRes.status === 401) return showLoginView();
      clientsData = await clientsRes.json();

      renderClientsTable();
    } catch (err) {
      console.error("Error cargando dashboard:", err);
    }
  }

  btnRefreshData.addEventListener('click', () => {
    btnRefreshData.querySelector('i').classList.add('spin');
    fetchDashboardData().then(() => {
      setTimeout(() => btnRefreshData.querySelector('i').classList.remove('spin'), 500);
      showToast('Datos actualizados', 'success');
    });
  });

  // Renderizar Tabla con Filtros y Búsqueda
  function renderClientsTable() {
    const query = searchInput.value.toLowerCase().trim();
    const filter = filterStatus.value;

    const filtered = clientsData.filter(item => {
      const matchSearch = 
        !query ||
        item.name.toLowerCase().includes(query) ||
        item.email.toLowerCase().includes(query) ||
        (item.license_key && item.license_key.toLowerCase().includes(query)) ||
        (item.phone && item.phone.toLowerCase().includes(query));

      let matchFilter = true;
      if (filter === 'active') matchFilter = item.license_status === 'active';
      if (filter === 'suspended') matchFilter = item.license_status === 'suspended';
      if (filter === 'online') matchFilter = item.is_online === 1;

      return matchSearch && matchFilter;
    });

    if (filtered.length === 0) {
      clientsTableBody.innerHTML = `
        <tr>
          <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
            <i class="ri-user-search-line" style="font-size: 32px; display: block; margin-bottom: 8px;"></i>
            No se encontraron clientes registrados con los filtros seleccionados.
          </td>
        </tr>
      `;
      return;
    }

    clientsTableBody.innerHTML = filtered.map(client => {
      const isOnline = client.is_online === 1;
      const statusBadge = client.license_status === 'active' 
        ? `<span class="badge badge-active"><i class="ri-check-line"></i> Activa</span>`
        : `<span class="badge badge-suspended"><i class="ri-close-line"></i> Suspendida</span>`;

      const onlineStatus = isOnline 
        ? `<span class="online-tag online"><span class="pulse-dot"></span> Online (${client.ip_address || 'Conectado'})</span>`
        : `<span class="online-tag offline"><i class="ri-moon-line"></i> Desconectado</span>`;

      const hwidDisplay = client.hwid 
        ? `<div style="font-size:11px; color:var(--text-muted); margin-top:2px;">HWID: ${client.hwid}</div>`
        : `<div style="font-size:11px; color:var(--accent-amber); margin-top:2px;">(Pendiente 1er inicio)</div>`;

      return `
        <tr>
          <td class="client-info-cell">
            <strong>${escapeHtml(client.name)}</strong>
            <span>${escapeHtml(client.email)} ${client.phone ? '• ' + escapeHtml(client.phone) : ''}</span>
          </td>
          <td>
            <div class="license-key-badge">
              <span>${client.license_key || 'N/A'}</span>
              <button class="btn-copy-key" onclick="copyToClipboard('${client.license_key}')" title="Copiar Clave">
                <i class="ri-file-copy-line"></i>
              </button>
            </div>
          </td>
          <td>
            ${statusBadge}
          </td>
          <td>
            ${onlineStatus}
            ${hwidDisplay}
          </td>
          <td>
            <span style="font-size:13px; color:var(--text-secondary);">${escapeHtml(client.payment_reference || 'N/A')}</span>
          </td>
          <td>
            <div class="table-actions">
              <a href="/api/admin/licenses/${client.license_id}/package?token=${token}" 
                 class="btn btn-secondary btn-sm" 
                 title="Descargar Paquete ZIP para Entregar al Cliente"
                 target="_blank">
                <i class="ri-download-cloud-line"></i> Paquete .ZIP
              </a>

              <button class="btn btn-secondary btn-sm" 
                      onclick="copyInstallerLink()" 
                      title="Copiar Enlace Directo de Descarga del Instalador BlueCat-Server-Setup.exe">
                <i class="ri-file-download-line"></i> Copiar Enlace EXE
              </button>

              <button class="btn btn-secondary btn-sm" 
                      onclick="sendEmailToClient(${client.license_id}, '${escapeHtml(client.email)}')" 
                      title="Enviar Paquete ZIP e Instalador EXE por Correo al Cliente">
                <i class="ri-send-plane-line"></i> Enviar Correo
              </button>

              <button class="btn ${client.license_status === 'active' ? 'btn-danger' : 'btn-secondary'} btn-sm" 
                      onclick="toggleLicenseStatus(${client.license_id}, '${client.license_status === 'active' ? 'suspended' : 'active'}')"
                      title="${client.license_status === 'active' ? 'Suspender Licencia Instantáneamente' : 'Activar Licencia'}">
                <i class="${client.license_status === 'active' ? 'ri-pause-circle-line' : 'ri-play-circle-line'}"></i>
                ${client.license_status === 'active' ? 'Suspender' : 'Activar'}
              </button>

              ${client.hwid ? `
                <button class="btn btn-secondary btn-sm" onclick="resetHwid(${client.license_id})" title="Resetear HWID (Permite registrar en nuevo PC)">
                  <i class="ri-restart-line"></i>
                </button>
              ` : ''}

              <button class="btn btn-secondary btn-sm" onclick="editClient(${client.client_id})" title="Editar Datos de Cliente y Licencia">
                <i class="ri-edit-line"></i> Editar
              </button>

              <button class="btn btn-danger btn-sm" onclick="deleteClient(${client.client_id}, '${escapeHtml(client.name)}')" title="Eliminar Cliente">
                <i class="ri-delete-bin-line"></i>
              </button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  // Filtros de búsqueda
  searchInput.addEventListener('input', renderClientsTable);
  filterStatus.addEventListener('change', renderClientsTable);

  // ==========================================
  // ACCIONES SOBRE LICENCIAS Y CLIENTES
  // ==========================================

  // Generar Clave Aleatoria
  btnGenRandomKey.addEventListener('click', () => {
    const bytes = Array.from(crypto.getRandomValues(new Uint8Array(8)))
      .map(b => b.toString(16).padStart(2, '0'))
      .join('')
      .toUpperCase();
    addLicenseKeyInput.value = `${bytes.slice(0,4)}-${bytes.slice(4,8)}-${bytes.slice(8,12)}-${bytes.slice(12,16)}`;
  });

  // Modal Nuevo Cliente
  btnOpenAddModal.addEventListener('click', () => {
    formAddClient.reset();
    btnGenRandomKey.click();
    addClientError.classList.add('hidden');
    modalAddClient.classList.remove('hidden');
  });

  const closeAddModal = () => modalAddClient.classList.add('hidden');
  btnCloseAddModal.addEventListener('click', closeAddModal);
  btnCancelAdd.addEventListener('click', closeAddModal);

  formAddClient.addEventListener('submit', async (e) => {
    e.preventDefault();
    addClientError.classList.add('hidden');

    const payload = {
      name: document.getElementById('add-name').value.trim(),
      email: document.getElementById('add-email').value.trim(),
      phone: document.getElementById('add-phone').value.trim(),
      payment_reference: document.getElementById('add-payment').value.trim(),
      custom_license_key: addLicenseKeyInput.value.trim(),
      notes: document.getElementById('add-notes').value.trim()
    };

    try {
      const res = await fetch('/api/admin/clients', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Error creando cliente');

      showToast(`Licencia emitida para ${payload.name}`, 'success');
      closeAddModal();
      fetchDashboardData();
    } catch (err) {
      addClientError.textContent = err.message;
      addClientError.classList.remove('hidden');
    }
  });

  // Modal Editar Cliente
  const modalEditClient = document.getElementById('modal-edit-client');
  const btnCloseEditModal = document.getElementById('btn-close-edit-modal');
  const btnCancelEdit = document.getElementById('btn-cancel-edit');
  const formEditClient = document.getElementById('form-edit-client');
  const editClientError = document.getElementById('edit-client-error');

  const closeEditModal = () => modalEditClient.classList.add('hidden');
  btnCloseEditModal.addEventListener('click', closeEditModal);
  btnCancelEdit.addEventListener('click', closeEditModal);

  window.editClient = (clientId) => {
    const client = clientsData.find(c => c.client_id === clientId);
    if (!client) return;

    document.getElementById('edit-client-id').value = client.client_id;
    document.getElementById('edit-name').value = client.name || '';
    document.getElementById('edit-email').value = client.email || '';
    document.getElementById('edit-phone').value = client.phone || '';
    document.getElementById('edit-payment').value = client.payment_reference || '';
    document.getElementById('edit-notes').value = client.notes || '';

    editClientError.classList.add('hidden');
    modalEditClient.classList.remove('hidden');
  };

  formEditClient.addEventListener('submit', async (e) => {
    e.preventDefault();
    editClientError.classList.add('hidden');

    const clientId = document.getElementById('edit-client-id').value;
    const payload = {
      name: document.getElementById('edit-name').value.trim(),
      email: document.getElementById('edit-email').value.trim(),
      phone: document.getElementById('edit-phone').value.trim(),
      payment_reference: document.getElementById('edit-payment').value.trim(),
      notes: document.getElementById('edit-notes').value.trim()
    };

    try {
      const res = await fetch(`/api/admin/clients/${clientId}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);

      showToast('Datos del cliente actualizados', 'success');
      closeEditModal();
      fetchDashboardData();
    } catch (err) {
      editClientError.textContent = err.message;
      editClientError.classList.remove('hidden');
    }
  });

  // Toggle Estado Licencia (Global Scope Window functions)
  window.toggleLicenseStatus = async (licenseId, newStatus) => {
    try {
      const res = await fetch(`/api/admin/licenses/${licenseId}/toggle`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ status: newStatus })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);

      showToast(data.message, 'success');
      fetchDashboardData();
    } catch (err) {
      showToast(err.message, 'error');
    }
  };

  // Reset HWID
  window.resetHwid = async (licenseId) => {
    if (!confirm("¿Deseas resetear la vinculación de equipo (HWID) de esta licencia? El cliente podrá activarla en un nuevo PC.")) return;
    try {
      const res = await fetch(`/api/admin/licenses/${licenseId}/reset-hwid`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);

      showToast(data.message, 'success');
      fetchDashboardData();
    } catch (err) {
      showToast(err.message, 'error');
    }
  };

  // Eliminar Cliente
  window.deleteClient = async (clientId, clientName) => {
    if (!confirm(`¿Estás seguro de eliminar al cliente ${clientName}? Se revocará su licencia de forma permanente.`)) return;
    try {
      const res = await fetch(`/api/admin/clients/${clientId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);

      showToast('Cliente eliminado', 'success');
      fetchDashboardData();
    } catch (err) {
      showToast(err.message, 'error');
    }
  };

  // Copiar al Portapapeles
  window.copyToClipboard = (text) => {
    navigator.clipboard.writeText(text);
    showToast('Clave de licencia copiada al portapapeles', 'success');
  };

  // ==========================================
  // CAMBIAR CONTRASEÑA ADMIN
  // ==========================================
  btnOpenChangePass.addEventListener('click', () => {
    formChangePass.reset();
    changePassError.classList.add('hidden');
    changePassSuccess.classList.add('hidden');
    modalChangePass.classList.remove('hidden');
  });

  const closePassModal = () => modalChangePass.classList.add('hidden');
  btnClosePassModal.addEventListener('click', closePassModal);
  btnCancelPass.addEventListener('click', closePassModal);

  formChangePass.addEventListener('submit', async (e) => {
    e.preventDefault();
    changePassError.classList.add('hidden');
    changePassSuccess.classList.add('hidden');

    const currentPassword = document.getElementById('pass-current').value;
    const newPassword = document.getElementById('pass-new').value;

    try {
      const res = await fetch('/api/admin/change-password', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ currentPassword, newPassword })
      });

      const data = await res.json();
      if (!res.ok) throw new Error(data.error);

      changePassSuccess.textContent = data.message;
      changePassSuccess.classList.remove('hidden');
      setTimeout(closePassModal, 1500);
    } catch (err) {
      changePassError.textContent = err.message;
      changePassError.classList.remove('hidden');
    }
  });

  // Copiar Enlace Directo del Instalador EXE
  window.copyInstallerLink = () => {
    const url = `${window.location.origin}/api/download/bluecat-installer`;
    navigator.clipboard.writeText(url).then(() => {
      showToast('¡Enlace directo del Instalador BlueCat (.exe) copiado al portapapeles!', 'success');
    }).catch(() => {
      prompt('Copia el enlace de descarga para enviarlo al cliente:', url);
    });
  };

  // Enviar Licencia por Correo
  window.sendEmailToClient = async (licenseId, defaultEmail) => {
    const targetEmail = prompt('Confirmar o ingresar correo electrónico del cliente para enviar el paquete ZIP:', defaultEmail);
    if (!targetEmail || !targetEmail.trim()) return;

    showToast(`Enviando paquete ZIP a ${targetEmail.trim()}...`, 'info');
    try {
      const res = await fetch(`/api/admin/licenses/${licenseId}/send-email`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({ target_email: targetEmail.trim() })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);

      if (data.is_test_mode) {
        showToast(data.message, 'info');
        if (data.preview_url) {
          if (confirm(`El correo se procesó en MODO PRUEBA (Servidor Simulado Ethereal).\n\n¿Deseas ver la vista previa del correo y archivo ZIP generado?\n(Para enviar a bandejas reales de Gmail, configura tus credenciales en el botón 'Servidor SMTP').`)) {
            window.open(data.preview_url, '_blank');
          }
        }
      } else {
        showToast(data.message, 'success');
      }
    } catch (err) {
      showToast(err.message, 'error');
    }
  };

  // Configuración SMTP
  const btnOpenSmtpModal = document.getElementById('btn-open-smtp-modal');
  const modalSmtpSettings = document.getElementById('modal-smtp-settings');
  const btnCloseSmtpModal = document.getElementById('btn-close-smtp-modal');
  const btnCancelSmtp = document.getElementById('btn-cancel-smtp');
  const formSmtpSettings = document.getElementById('form-smtp-settings');
  const smtpError = document.getElementById('smtp-error');
  const smtpSuccess = document.getElementById('smtp-success');

  btnOpenSmtpModal.addEventListener('click', async () => {
    smtpError.classList.add('hidden');
    smtpSuccess.classList.add('hidden');
    try {
      const res = await fetch('/api/admin/smtp-settings', {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      const config = await res.json();
      document.getElementById('smtp-host').value = config.host || '';
      document.getElementById('smtp-port').value = config.port || '587';
      document.getElementById('smtp-user').value = config.user || '';
      document.getElementById('smtp-pass').value = config.pass || '';
      document.getElementById('smtp-from').value = config.from || '';
    } catch (err) {}
    modalSmtpSettings.classList.remove('hidden');
  });

  const closeSmtpModal = () => modalSmtpSettings.classList.add('hidden');
  btnCloseSmtpModal.addEventListener('click', closeSmtpModal);
  btnCancelSmtp.addEventListener('click', closeSmtpModal);

  formSmtpSettings.addEventListener('submit', async (e) => {
    e.preventDefault();
    smtpError.classList.add('hidden');
    smtpSuccess.classList.add('hidden');

    const payload = {
      host: document.getElementById('smtp-host').value.trim(),
      port: document.getElementById('smtp-port').value.trim(),
      user: document.getElementById('smtp-user').value.trim(),
      pass: document.getElementById('smtp-pass').value.trim(),
      from: document.getElementById('smtp-from').value.trim()
    };

    try {
      const res = await fetch('/api/admin/smtp-settings', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error);

      smtpSuccess.textContent = data.message;
      smtpSuccess.classList.remove('hidden');
      setTimeout(closeSmtpModal, 1500);
    } catch (err) {
      smtpError.textContent = err.message;
      smtpError.classList.remove('hidden');
    }
  });

  // Toast Notification Helper
  function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
      <i class="${type === 'success' ? 'ri-checkbox-circle-fill' : (type === 'info' ? 'ri-information-fill' : 'ri-error-warning-fill')}"></i>
      <span>${escapeHtml(message)}</span>
    `;
    container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[m]);
  }
});
