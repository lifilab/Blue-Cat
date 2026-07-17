var API_CRM = '../assets/api/crm.php';

function apiCrm(accion, data, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API_CRM, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      if (xhr.status >= 200 && xhr.status < 300) {
        try { cb(JSON.parse(xhr.responseText)); } catch(e) { cb(xhr.responseText); }
      } else {
        try { var e = JSON.parse(xhr.responseText); toast(e.error || 'Error', 'error'); } catch(ex) { toast('Error de conexión', 'error'); }
      }
    }
  };
  data = data || {};
  data.accion = accion;
  xhr.send(JSON.stringify(data));
}

function toast(msg, type) {
  var t = document.createElement('div');
  t.className = 'toast toast-' + (type === 'error' ? 'err' : 'ok');
  BlueCatSecurity.renderToast(t, msg, type);
  document.body.appendChild(t);
  requestAnimationFrame(function() { t.classList.add('show'); });
  setTimeout(function() { t.classList.remove('show'); setTimeout(function() { t.remove(); }, 300); }, 2500);
}

function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function num(n) { return parseInt(n) || 0; }

function fmt(n) { return (num(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

function closeCrmModal() { document.getElementById('crm-modal').style.display = 'none'; }

function openCrmModal(html) {
  document.getElementById('crm-modal-body').innerHTML = html;
  document.getElementById('crm-modal').style.display = 'block';
}

function closeCrmPerfil() { document.getElementById('crm-perfil-modal').style.display = 'none'; }

function openCrmPerfil(html) {
  document.getElementById('crm-perfil-body').innerHTML = html;
  document.getElementById('crm-perfil-modal').style.display = 'block';
}

// ========== SECTION SWITCHER ==========
function switchCrm(section) {
  document.querySelectorAll('.section-title').forEach(function(el) { el.classList.remove('active'); });
  document.querySelectorAll('.crm-sidebar li').forEach(function(el) { el.classList.remove('active'); });
  document.getElementById('crm-' + section).classList.add('active');
  document.querySelector('.crm-sidebar li[data-section="' + section + '"]').classList.add('active');
  switch(section) {
    case 'dashboard': loadDashboard(); break;
    case 'clientes': loadFiltros(); loadClientes(); break;
    case 'actividades': loadActividades(); break;
    case 'creditos': loadCreditos(); break;
    case 'reportes': switchReporte('abc'); break;
    case 'auditoria': loadAuditoria(); break;
  }
}

// ========== DASHBOARD ==========
function loadDashboard() {
  apiCrm('dashboard', {}, function(d) {
    var el = document.getElementById('stats-bar');
    el.innerHTML =
      '<div class="stat"><span class="stat-icon blue"><i class="fas fa-user-tie"></i></span><div><div class="stat-num">' + fmt(d.total_clientes || 0) + '</div><div class="stat-label">Clientes</div></div></div>' +
      '<div class="stat"><span class="stat-icon green"><i class="fas fa-check-circle"></i></span><div><div class="stat-num">' + fmt(d.clientes_activos || 0) + '</div><div class="stat-label">Activos</div></div></div>' +
      '<div class="stat"><span class="stat-icon amber"><i class="fas fa-exclamation-triangle"></i></span><div><div class="stat-num">' + fmt((d.total_clientes || 0) - (d.clientes_activos || 0)) + '</div><div class="stat-label">Inactivos</div></div></div>' +
      '<div class="stat"><span class="stat-icon red"><i class="fas fa-ban"></i></span><div><div class="stat-num">' + fmt(d.clientes_bloqueados || 0) + '</div><div class="stat-label">Bloqueados</div></div></div>' +
      '<div class="stat"><span class="stat-icon purple"><i class="fas fa-dollar-sign"></i></span><div><div class="stat-num">$' + fmt(d.total_facturado || 0) + '</div><div class="stat-label">Facturado</div></div></div>' +
      '<div class="stat"><span class="stat-icon green"><i class="fas fa-tasks"></i></span><div><div class="stat-num">' + fmt(d.pendientes || 0) + '</div><div class="stat-label">Act. Pendientes</div></div></div>';

    document.getElementById('t-ultimos-clientes').innerHTML = (d.ultimos_clientes || []).map(function(c) {
      return '<tr><td><a href="#" onclick="showClientePerfil(' + c.id_cliente + ');return false;" style="color:#4f46e5;font-weight:600;">' + esc(c.nombre) + '</a></td><td>' + esc(c.razon_social || '') + '</td><td>' + esc(c.ciudad || '') + '</td><td>' + (c.fecha_creacion || '-') + '</td></tr>';
    }).join('') || '<tr><td colspan="4"><div class="empty-state"><i class="fas fa-user"></i><p>Sin clientes</p></div></td></tr>';

    document.getElementById('t-top-clientes').innerHTML = (d.clientes_top || []).map(function(c) {
      return '<tr><td><a href="#" onclick="showClientePerfil(' + c.id_cliente + ');return false;" style="color:#4f46e5;font-weight:600;">' + esc(c.nombre) + '</a></td><td><strong>$' + fmt(c.total_mes || 0) + '</strong></td><td>' + (c.visitas || 0) + '</td></tr>';
    }).join('') || '<tr><td colspan="3"><div class="empty-state"><i class="fas fa-star"></i><p>Sin datos</p></div></td></tr>';
  });
}

// ========== CLIENTES ==========
function loadFiltros() {
  apiCrm('filtros_clientes', {}, function(d) {
    var t = document.getElementById('filter-tipo');
    t.innerHTML = '<option value="">Todos los tipos</option>' + (d.tipos || []).map(function(tp) { return '<option value="' + esc(tp) + '">' + esc(tp) + '</option>'; }).join('');
    var c = document.getElementById('filter-ciudad');
    c.innerHTML = '<option value="">Todas las ciudades</option>' + (d.ciudades || []).map(function(ci) { return '<option value="' + esc(ci) + '">' + esc(ci) + '</option>'; }).join('');
  });
}

function loadClientes() {
  var search = document.getElementById('search-clientes').value;
  var estado = document.getElementById('filter-estado').value;
  var tipo = document.getElementById('filter-tipo').value;
  var ciudad = document.getElementById('filter-ciudad').value;
  apiCrm('clientes', { search: search, estado: estado, tipo: tipo, ciudad: ciudad, limit: 200 }, function(r) {
    var items = Array.isArray(r) ? r : (r.items || []);
    document.getElementById('client-count').textContent = items.length + ' clientes';
    document.getElementById('tbody-clientes').innerHTML = items.map(function(c) {
      var estBadge = c.estado === 'activo' ? '<span class="badge badge-success">Activo</span>' :
        (c.estado === 'inactivo' ? '<span class="badge badge-warning">Inactivo</span>' :
        '<span class="badge badge-danger">Bloqueado</span>');
      return '<tr>' +
        '<td>' + (c.codigo || c.id_cliente) + '</td>' +
        '<td><a href="#" onclick="showClientePerfil(' + c.id_cliente + ');return false;" style="color:#4f46e5;font-weight:600;">' + esc(c.nombre) + '</a></td>' +
        '<td>' + esc(c.razon_social || '') + '</td>' +
        '<td>' + esc(c.rut || '') + '</td>' +
        '<td>' + esc(c.ciudad || '') + '</td>' +
        '<td><strong>$' + fmt(c.total_compras || 0) + '</strong></td>' +
        '<td>' + estBadge + '</td>' +
        '<td style="white-space:nowrap;">' +
          '<button class="btn btn-outline btn-xs" onclick="editCliente(' + c.id_cliente + ')" title="Editar"><i class="fas fa-edit"></i></button> ' +
          '<button class="btn btn-outline btn-xs" onclick="deleteCliente(' + c.id_cliente + ')" title="Eliminar" style="color:#dc2626;"><i class="fas fa-trash"></i></button>' +
        '</td>' +
        '</tr>';
    }).join('') || '<tr><td colspan="8"><div class="empty-state"><i class="fas fa-user-tie"></i><p>Sin clientes</p></div></td></tr>';
  });
}

function showClienteForm() {
  var h = '<h3><i class="fas fa-user-plus"></i> Nuevo Cliente</h3>' +
    '<form onsubmit="saveCliente(event)"><div class="form-grid">' +
    '<div class="full"><label>Razón Social *</label><input id="fc-razon" required></div>' +
    '<div><label>RUT</label><input id="fc-rut"></div>' +
    '<div><label>Nombre / Fantasía</label><input id="fc-nombre"></div>' +
    '<div class="full"><label>Dirección</label><input id="fc-dir"></div>' +
    '<div><label>Ciudad</label><input id="fc-ciudad"></div>' +
    '<div><label>Correo</label><input id="fc-correo" type="email"></div>' +
    '<div><label>Teléfono</label><input id="fc-tel"></div>' +
    '<div><label>Tipo</label><select id="fc-tipo"><option value="">Seleccione</option><option value="Minorista">Minorista</option><option value="Mayorista">Mayorista</option><option value="Corporativo">Corporativo</option><option value="Gobierno">Gobierno</option></select></div>' +
    '<div><label>Categoría</label><input id="fc-categoria"></div>' +
    '<div><label>Origen</label><select id="fc-origen"><option value="">Seleccione</option><option value="Web">Web</option><option value="Referido">Referido</option><option value="Feria">Feria</option><option value="Llamada">Llamada</option><option value="Otro">Otro</option></select></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:10px;"><i class="fas fa-save"></i> Guardar</button></form>';
  openCrmModal(h);
}

function saveCliente(event) {
  event.preventDefault();
  var data = {
    razon_social: document.getElementById('fc-razon').value,
    rut: document.getElementById('fc-rut').value,
    nombre: document.getElementById('fc-nombre').value,
    direccion: document.getElementById('fc-dir').value,
    ciudad: document.getElementById('fc-ciudad').value,
    correo: document.getElementById('fc-correo').value,
    telefono: document.getElementById('fc-tel').value,
    tipo: document.getElementById('fc-tipo').value,
    categoria: document.getElementById('fc-categoria').value,
    origen: document.getElementById('fc-origen').value
  };
  apiCrm('cliente_crear', data, function(r) {
    toast('Cliente creado');
    closeCrmModal();
    loadClientes();
  });
}

function editCliente(id) {
  apiCrm('cliente_obtener', { id_cliente: id }, function(c) {
    var h = '<h3><i class="fas fa-edit"></i> Editar Cliente</h3>' +
      '<form onsubmit="saveClienteEdit(event,' + id + ')"><div class="form-grid">' +
      '<div class="full"><label>Razón Social *</label><input id="fe-razon" value="' + esc(c.razon_social || '') + '" required></div>' +
      '<div><label>RUT</label><input id="fe-rut" value="' + esc(c.rut || '') + '"></div>' +
      '<div><label>Nombre / Fantasía</label><input id="fe-nombre" value="' + esc(c.nombre || '') + '"></div>' +
      '<div class="full"><label>Dirección</label><input id="fe-dir" value="' + esc(c.direccion || '') + '"></div>' +
      '<div><label>Ciudad</label><input id="fe-ciudad" value="' + esc(c.ciudad || '') + '"></div>' +
      '<div><label>Correo</label><input id="fe-correo" type="email" value="' + esc(c.correo || '') + '"></div>' +
      '<div><label>Teléfono</label><input id="fe-tel" value="' + esc(c.telefono || '') + '"></div>' +
      '<div><label>Tipo</label><select id="fe-tipo">' +
        ['Minorista','Mayorista','Corporativo','Gobierno'].map(function(tp) { return '<option value="' + tp + '"' + (c.tipo === tp ? ' selected' : '') + '>' + tp + '</option>'; }).join('') +
      '</select></div>' +
      '<div><label>Categoría</label><input id="fe-categoria" value="' + esc(c.categoria || '') + '"></div>' +
      '<div><label>Origen</label><select id="fe-origen">' +
        ['Web','Referido','Feria','Llamada','Otro'].map(function(o) { return '<option value="' + o + '"' + (c.origen === o ? ' selected' : '') + '>' + o + '</option>'; }).join('') +
      '</select></div>' +
      '<div><label>Estado</label><select id="fe-estado">' +
        '<option value="activo"' + (c.estado === 'activo' ? ' selected' : '') + '>Activo</option>' +
        '<option value="inactivo"' + (c.estado === 'inactivo' ? ' selected' : '') + '>Inactivo</option>' +
        '<option value="bloqueado"' + (c.estado === 'bloqueado' ? ' selected' : '') + '>Bloqueado</option>' +
      '</select></div>' +
      '</div><button class="btn btn-primary" style="width:100%;margin-top:10px;"><i class="fas fa-save"></i> Guardar Cambios</button></form>';
    openCrmModal(h);
  });
}

function saveClienteEdit(event, id) {
  event.preventDefault();
  var data = {
    id_cliente: id,
    razon_social: document.getElementById('fe-razon').value,
    rut: document.getElementById('fe-rut').value,
    nombre: document.getElementById('fe-nombre').value,
    direccion: document.getElementById('fe-dir').value,
    ciudad: document.getElementById('fe-ciudad').value,
    correo: document.getElementById('fe-correo').value,
    telefono: document.getElementById('fe-tel').value,
    tipo: document.getElementById('fe-tipo').value,
    categoria: document.getElementById('fe-categoria').value,
    origen: document.getElementById('fe-origen').value,
    estado: document.getElementById('fe-estado').value
  };
  apiCrm('cliente_editar', data, function(r) {
    toast('Cliente actualizado');
    closeCrmModal();
    loadClientes();
  });
}

function deleteCliente(id) {
  if (!confirm('¿Eliminar este cliente? Esta acción no se puede deshacer.')) return;
  apiCrm('cliente_eliminar', { id_cliente: id }, function(r) {
    toast('Cliente eliminado');
    loadClientes();
  });
}

// ========== PERFIL 360° ==========
function showClientePerfil(id) {
  apiCrm('cliente_obtener', { id_cliente: id }, function(c) {
    var h = '<h3 style="margin-bottom:12px;"><i class="fas fa-id-card"></i> ' + esc(c.nombre || c.razon_social) + ' <span style="font-size:12px;color:#94a3b8;font-weight:400;">#' + id + '</span></h3>' +
      '<div class="profile-tabs">' +
        '<span class="profile-tab active" onclick="switchProfileTab(event,\'general\',' + id + ')">General</span>' +
        '<span class="profile-tab" onclick="switchProfileTab(event,\'contactos\',' + id + ')">Contactos</span>' +
        '<span class="profile-tab" onclick="switchProfileTab(event,\'direcciones\',' + id + ')">Direcciones</span>' +
        '<span class="profile-tab" onclick="switchProfileTab(event,\'credito\',' + id + ')">Crédito</span>' +
        '<span class="profile-tab" onclick="switchProfileTab(event,\'historial\',' + id + ')">Historial</span>' +
        '<span class="profile-tab" onclick="switchProfileTab(event,\'facturas\',' + id + ')">Facturas</span>' +
      '</div>' +
      '<div id="profile-pane-general" class="profile-pane active"></div>' +
      '<div id="profile-pane-contactos" class="profile-pane"></div>' +
      '<div id="profile-pane-direcciones" class="profile-pane"></div>' +
      '<div id="profile-pane-credito" class="profile-pane"></div>' +
      '<div id="profile-pane-historial" class="profile-pane"></div>' +
      '<div id="profile-pane-facturas" class="profile-pane"></div>';
    openCrmPerfil(h);
    renderProfileGeneral(c);
    loadProfileContactos(id);
    loadProfileDirecciones(id);
    loadProfileCredito(id);
    loadProfileHistorial(id);
    loadProfileFacturas(id);
  });
}

function switchProfileTab(e, tab, id) {
  document.querySelectorAll('.profile-tab').forEach(function(el) { el.classList.remove('active'); });
  e.target.classList.add('active');
  document.querySelectorAll('.profile-pane').forEach(function(el) { el.classList.remove('active'); });
  document.getElementById('profile-pane-' + tab).classList.add('active');
}

function renderProfileGeneral(c) {
  var el = document.getElementById('profile-pane-general');
  el.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">' +
    '<div><strong>Razón Social:</strong> ' + esc(c.razon_social || '-') + '</div>' +
    '<div><strong>RUT:</strong> ' + esc(c.rut || '-') + '</div>' +
    '<div><strong>Nombre / Fantasía:</strong> ' + esc(c.nombre || '-') + '</div>' +
    '<div><strong>Tipo:</strong> ' + esc(c.tipo || '-') + '</div>' +
    '<div><strong>Dirección:</strong> ' + esc(c.direccion || '-') + '</div>' +
    '<div><strong>Ciudad:</strong> ' + esc(c.ciudad || '-') + '</div>' +
    '<div><strong>Correo:</strong> ' + esc(c.correo || '-') + '</div>' +
    '<div><strong>Teléfono:</strong> ' + esc(c.telefono || '-') + '</div>' +
    '<div><strong>Categoría:</strong> ' + esc(c.categoria || '-') + '</div>' +
    '<div><strong>Origen:</strong> ' + esc(c.origen || '-') + '</div>' +
    '<div><strong>Total Compras:</strong> $' + fmt(c.total_compras || 0) + '</div>' +
    '<div><strong>Estado:</strong> ' + (c.estado === 'activo' ? '<span class="badge badge-success">Activo</span>' : (c.estado === 'inactivo' ? '<span class="badge badge-warning">Inactivo</span>' : '<span class="badge badge-danger">Bloqueado</span>')) + '</div>' +
    '</div>';
}

function loadProfileContactos(id) {
  apiCrm('contactos', { id_cliente: id }, function(items) {
    var el = document.getElementById('profile-pane-contactos');
    var rows = (items || []).map(function(co) {
      return '<tr><td>' + esc(co.nombre) + '</td><td>' + esc(co.cargo || '') + '</td><td>' + esc(co.correo || '') + '</td><td>' + esc(co.telefono || '') + '</td></tr>';
    }).join('') || '<tr><td colspan="4"><div class="empty-state"><i class="fas fa-address-book"></i><p>Sin contactos</p></div></td></tr>';
    el.innerHTML =
      '<div class="table-container" style="margin-bottom:16px;"><table><thead><tr><th>Nombre</th><th>Cargo</th><th>Correo</th><th>Teléfono</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<strong style="font-size:13px;"><i class="fas fa-plus-circle"></i> Agregar Contacto</strong>' +
      '<form onsubmit="saveContacto(event,' + id + ')" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;">' +
      '<input id="pco-nombre" placeholder="Nombre *" required style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">' +
      '<input id="pco-cargo" placeholder="Cargo" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">' +
      '<input id="pco-correo" placeholder="Correo" type="email" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">' +
      '<input id="pco-tel" placeholder="Teléfono" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">' +
      '<button class="btn btn-primary btn-xs" style="grid-column:1/-1;">Guardar Contacto</button>' +
      '</form>';
  });
}

function saveContacto(event, id_cliente) {
  event.preventDefault();
  apiCrm('contacto_crear', {
    id_cliente: id_cliente,
    nombre: document.getElementById('pco-nombre').value,
    cargo: document.getElementById('pco-cargo').value,
    correo: document.getElementById('pco-correo').value,
    telefono: document.getElementById('pco-tel').value
  }, function(r) {
    toast('Contacto agregado');
    loadProfileContactos(id_cliente);
  });
}

function loadProfileDirecciones(id) {
  apiCrm('direcciones', { id_cliente: id }, function(items) {
    var el = document.getElementById('profile-pane-direcciones');
    var rows = (items || []).map(function(di) {
      return '<tr><td>' + esc(di.direccion) + '</td><td>' + esc(di.ciudad || '') + '</td><td>' + esc(di.referencia || '') + '</td><td>' + (di.principal == 1 ? '<span class="badge badge-info">Principal</span>' : '') + '</td></tr>';
    }).join('') || '<tr><td colspan="4"><div class="empty-state"><i class="fas fa-map-marker-alt"></i><p>Sin direcciones</p></div></td></tr>';
    el.innerHTML =
      '<div class="table-container" style="margin-bottom:16px;"><table><thead><tr><th>Dirección</th><th>Ciudad</th><th>Referencia</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<strong style="font-size:13px;"><i class="fas fa-plus-circle"></i> Agregar Dirección</strong>' +
      '<form onsubmit="saveDireccion(event,' + id + ')" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;">' +
      '<input id="pdi-dir" placeholder="Dirección *" required style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;grid-column:1/-1;">' +
      '<input id="pdi-ciudad" placeholder="Ciudad" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">' +
      '<input id="pdi-ref" placeholder="Referencia" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;">' +
      '<button class="btn btn-primary btn-xs" style="grid-column:1/-1;">Guardar Dirección</button>' +
      '</form>';
  });
}

function saveDireccion(event, id_cliente) {
  event.preventDefault();
  apiCrm('direccion_crear', {
    id_cliente: id_cliente,
    direccion: document.getElementById('pdi-dir').value,
    ciudad: document.getElementById('pdi-ciudad').value,
    referencia: document.getElementById('pdi-ref').value
  }, function(r) {
    toast('Dirección agregada');
    loadProfileDirecciones(id_cliente);
  });
}

function loadProfileCredito(id) {
  apiCrm('credito_obtener', { id_cliente: id }, function(cred) {
    var el = document.getElementById('profile-pane-credito');
    if (!cred || !cred.id_credito) {
      el.innerHTML = '<div class="empty-state"><i class="fas fa-credit-card"></i><p>Sin crédito asignado</p></div>' +
        '<strong style="font-size:13px;">Crear Crédito</strong>' +
        '<form onsubmit="saveCredito(event,' + id + ')" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;">' +
        '<div><label style="font-size:11px;">Límite *</label><input id="pcr-limite" type="number" required style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;"></div>' +
        '<div><label style="font-size:11px;">Días Crédito</label><input id="pcr-dias" type="number" value="30" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;"></div>' +
        '<div class="full" style="grid-column:1/-1;"><label style="font-size:11px;">Condiciones</label><input id="pcr-cond" placeholder="Ej: Pago a 30 días" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;"></div>' +
        '<button class="btn btn-primary btn-xs" style="grid-column:1/-1;">Guardar Crédito</button>' +
        '</form>';
      return;
    }
    el.innerHTML =
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;margin-bottom:16px;">' +
      '<div><strong>Límite:</strong> $' + fmt(cred.limite || 0) + '</div>' +
      '<div><strong>Días:</strong> ' + (cred.dias || 0) + ' días</div>' +
      '<div><strong>Utilizado:</strong> $' + fmt(cred.utilizado || 0) + '</div>' +
      '<div><strong>Disponible:</strong> <span style="color:' + ((cred.disponible || 0) > 0 ? '#059669' : '#dc2626') + ';">$' + fmt(cred.disponible || 0) + '</span></div>' +
      '<div class="full" style="grid-column:1/-1;"><strong>Condiciones:</strong> ' + esc(cred.condiciones || '-') + '</div>' +
      '<div><strong>Estado:</strong> ' + (cred.bloqueado == 1 ? '<span class="badge badge-danger">Bloqueado</span>' : '<span class="badge badge-success">Activo</span>') + '</div>' +
      '</div>' +
      '<button class="btn btn-outline btn-sm" onclick="showCreditoEditForm(' + id + ')"><i class="fas fa-edit"></i> Editar Crédito</button>';
  });
}

function saveCredito(event, id_cliente) {
  event.preventDefault();
  apiCrm('credito_crear', {
    id_cliente: id_cliente,
    limite_credito: parseInt(document.getElementById('pcr-limite').value) || 0,
    dias_credito: parseInt(document.getElementById('pcr-dias').value) || 0,
    condiciones_pago: document.getElementById('pcr-cond').value
  }, function(r) {
    toast('Crédito creado');
    loadProfileCredito(id_cliente);
  });
}

function showCreditoEditForm(id_cliente) {
  apiCrm('credito_obtener', { id_cliente: id_cliente }, function(cred) {
    var h = '<h3><i class="fas fa-credit-card"></i> Editar Crédito</h3>' +
      '<form onsubmit="saveCreditoEdit(event,' + id_cliente + ')"><div class="form-grid">' +
      '<div><label>Límite</label><input id="ce-limite" type="number" value="' + (cred.limite || 0) + '" required></div>' +
      '<div><label>Días Crédito</label><input id="ce-dias" type="number" value="' + (cred.dias || 30) + '"></div>' +
      '<div class="full"><label>Condiciones</label><input id="ce-cond" value="' + esc(cred.condiciones || '') + '"></div>' +
      '<div><label>Bloqueado</label><select id="ce-bloqueado"><option value="0"' + (cred.bloqueado == 1 ? '' : ' selected') + '>No</option><option value="1"' + (cred.bloqueado == 1 ? ' selected' : '') + '>Sí</option></select></div>' +
      '</div><button class="btn btn-primary" style="width:100%;margin-top:10px;"><i class="fas fa-save"></i> Guardar</button></form>';
    openCrmModal(h);
  });
}

function saveCreditoEdit(event, id_cliente) {
  event.preventDefault();
  apiCrm('credito_editar', {
    id_cliente: id_cliente,
    limite_credito: parseInt(document.getElementById('ce-limite').value) || 0,
    dias_credito: parseInt(document.getElementById('ce-dias').value) || 0,
    condiciones_pago: document.getElementById('ce-cond').value,
    bloqueado: parseInt(document.getElementById('ce-bloqueado').value) || 0
  }, function(r) {
    toast('Crédito actualizado');
    closeCrmModal();
  });
}

function loadProfileHistorial(id) {
  apiCrm('actividades', { id_cliente: id }, function(items) {
    var el = document.getElementById('profile-pane-historial');
    el.innerHTML = '<div class="table-container"><table><thead><tr><th>Tipo</th><th>Asunto</th><th>Fecha</th><th>Estado</th></tr></thead><tbody>' +
      ((items || []).map(function(a) {
        var ba = a.estado === 'completada' ? 'badge-success' : (a.estado === 'cancelada' ? 'badge-danger' : 'badge-warning');
        return '<tr><td>' + esc(a.tipo) + '</td><td>' + esc(a.asunto) + '</td><td>' + (a.fecha_planificada || '') + '</td><td><span class="badge ' + ba + '">' + esc(a.estado) + '</span></td></tr>';
      }).join('') || '<tr><td colspan="4"><div class="empty-state"><i class="fas fa-history"></i><p>Sin actividades</p></div></td></tr>') +
      '</tbody></table></div>';
  });
}

function loadProfileFacturas(id) {
  apiCrm('facturas_cliente', { id_cliente: id }, function(items) {
    var el = document.getElementById('profile-pane-facturas');
    el.innerHTML = '<div class="table-container"><table><thead><tr><th>N°</th><th>Fecha</th><th>Total</th><th>Estado</th></tr></thead><tbody>' +
      ((items || []).map(function(f) {
        var ba = f.estado === 'pagada' ? 'badge-success' : (f.estado === 'anulada' ? 'badge-danger' : 'badge-warning');
        return '<tr><td>' + esc(f.numero || f.id_factura) + '</td><td>' + (f.fecha || '') + '</td><td><strong>$' + fmt(f.total || 0) + '</strong></td><td><span class="badge ' + ba + '">' + esc(f.estado) + '</span></td></tr>';
      }).join('') || '<tr><td colspan="4"><div class="empty-state"><i class="fas fa-file-invoice"></i><p>Sin facturas</p></div></td></tr>') +
      '</tbody></table></div>';
  });
}

// ========== ACTIVIDADES ==========
function loadActividades() {
  var estado = document.getElementById('filter-act-estado').value;
  apiCrm('actividades', { estado: estado, limit: 200 }, function(r) {
    var items = Array.isArray(r) ? r : (r.items || []);
    document.getElementById('tbody-actividades').innerHTML = items.map(function(a) {
      var ba = a.estado === 'completada' ? 'badge-success' : (a.estado === 'cancelada' ? 'badge-danger' : 'badge-warning');
      return '<tr>' +
        '<td>' + esc(a.tipo) + '</td>' +
        '<td>' + esc(a.asunto) + '</td>' +
        '<td>' + esc(a.cliente_nombre || '') + '</td>' +
        '<td>' + (a.fecha_planificada || '') + '</td>' +
        '<td><span class="badge ' + ba + '">' + esc(a.estado) + '</span></td>' +
        '<td>' +
          (a.estado === 'pendiente' ? '<button class="btn btn-outline btn-xs" onclick="completarActividad(' + a.id_actividad + ')" title="Completar"><i class="fas fa-check"></i></button> ' : '') +
          '<button class="btn btn-outline btn-xs" onclick="deleteActividad(' + a.id_actividad + ')" title="Eliminar" style="color:#dc2626;"><i class="fas fa-trash"></i></button>' +
        '</td>' +
        '</tr>';
    }).join('') || '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-tasks"></i><p>Sin actividades</p></div></td></tr>';
  });
}

function showActividadForm() {
  apiCrm('clientes_select', {}, function(clientes) {
    var opts = (clientes || []).map(function(c) { return '<option value="' + c.id_cliente + '">' + esc(c.nombre || c.razon_social) + '</option>'; }).join('');
    var h = '<h3><i class="fas fa-plus-circle"></i> Nueva Actividad</h3>' +
      '<form onsubmit="saveActividad(event)"><div class="form-grid">' +
      '<div class="full"><label>Cliente *</label><select id="fa-cliente" required><option value="">Seleccione...</option>' + opts + '</select></div>' +
      '<div><label>Tipo *</label><select id="fa-tipo" required><option value="">Seleccione...</option><option value="Llamada">Llamada</option><option value="Reunión">Reunión</option><option value="Correo">Correo</option><option value="Visita">Visita</option><option value="Cotización">Cotización</option><option value="Seguimiento">Seguimiento</option><option value="Otro">Otro</option></select></div>' +
      '<div><label>Fecha Planificada</label><input id="fa-fecha" type="date"></div>' +
      '<div class="full"><label>Asunto *</label><input id="fa-asunto" required></div>' +
      '<div class="full"><label>Descripción</label><textarea id="fa-desc" rows="3"></textarea></div>' +
      '</div><button class="btn btn-primary" style="width:100%;margin-top:10px;"><i class="fas fa-save"></i> Guardar</button></form>';
    openCrmModal(h);
  });
}

function saveActividad(event) {
  event.preventDefault();
  apiCrm('actividad_crear', {
    id_cliente: num(document.getElementById('fa-cliente').value),
    tipo: document.getElementById('fa-tipo').value,
    asunto: document.getElementById('fa-asunto').value,
    descripcion: document.getElementById('fa-desc').value,
    fecha_planificada: document.getElementById('fa-fecha').value
  }, function(r) {
    toast('Actividad creada');
    closeCrmModal();
    loadActividades();
  });
}

function completarActividad(id) {
  apiCrm('actividad_completar', { id_actividad: id }, function(r) {
    toast('Actividad completada');
    loadActividades();
  });
}

function deleteActividad(id) {
  if (!confirm('¿Eliminar esta actividad?')) return;
  apiCrm('actividad_eliminar', { id_actividad: id }, function(r) {
    toast('Actividad eliminada');
    loadActividades();
  });
}

// ========== CRÉDITOS ==========
function loadCreditos() {
  apiCrm('creditos', {}, function(r) {
    var items = Array.isArray(r) ? r : (r.items || []);
    document.getElementById('tbody-creditos').innerHTML = items.map(function(cred) {
      var usado = num(cred.utilizado || 0);
      var limite = num(cred.limite || 0);
      var disp = limite - usado;
      var pct = limite > 0 ? Math.round((usado / limite) * 100) : 0;
      var colorEst = cred.bloqueado == 1 ? '#dc2626' : (pct >= 90 ? '#d97706' : '#059669');
      var labelEst = cred.bloqueado == 1 ? 'Bloqueado' : (pct >= 90 ? 'Casi límite' : 'Activo');
      var badgeEst = cred.bloqueado == 1 ? 'badge-danger' : (pct >= 90 ? 'badge-warning' : 'badge-success');
      return '<tr>' +
        '<td><strong>' + esc(cred.cliente_nombre || cred.razon_social || '') + '</strong></td>' +
        '<td>$' + fmt(limite) + '</td>' +
        '<td>$' + fmt(usado) + '</td>' +
        '<td style="color:' + colorEst + ';font-weight:600;">$' + fmt(disp) + '</td>' +
        '<td><span class="badge ' + badgeEst + '">' + labelEst + '</span></td>' +
        '<td><button class="btn btn-outline btn-xs" onclick="showCreditoEdit(' + cred.id_cliente + ')"><i class="fas fa-edit"></i></button></td>' +
        '</tr>';
    }).join('') || '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-credit-card"></i><p>Sin créditos asignados</p></div></td></tr>';
  });
}

function showCreditoEdit(id_cliente) {
  showCreditoEditForm(id_cliente);
}

// ========== REPORTES ==========
function switchReporte(tipo, btn) {
  document.querySelectorAll('.tab-btn').forEach(function(el) { el.classList.remove('active'); });
  if (btn) btn.classList.add('active');
  else document.querySelector('.tab-btn[onclick*="' + tipo + '"]').classList.add('active');

  if (tipo === 'abc') loadReporteABC();
  else if (tipo === 'morosidad') loadReporteMorosidad();
}

function loadReporteABC() {
  document.getElementById('reporte-title').innerHTML = '<i class="fas fa-sort-amount-down"></i> Reporte ABC de Clientes';
  apiCrm('reporte_abc', {}, function(r) {
    var items = Array.isArray(r) ? r : (r.items || []);
    document.getElementById('reporte-thead').innerHTML = '<tr><th>Cliente</th><th>Total Compras</th><th>% del Total</th><th>% Acum.</th><th>Clasificación</th></tr>';
    document.getElementById('tbody-reporte').innerHTML = items.map(function(ro) {
      var ba = ro.clasificacion === 'A' ? 'badge-success' : (ro.clasificacion === 'B' ? 'badge-info' : 'badge-gray');
      return '<tr><td>' + esc(ro.razon_social || ro.nombre) + '</td><td><strong>$' + fmt(ro.total_compras || 0) + '</strong></td><td>' + (ro.porcentaje || 0) + '%</td><td>' + (ro.porcentaje_acumulado || 0) + '%</td><td><span class="badge ' + ba + '">' + ro.clasificacion + '</span></td></tr>';
    }).join('') || '<tr><td colspan="5"><div class="empty-state"><i class="fas fa-chart-bar"></i><p>Sin datos</p></div></td></tr>';
  });
}

function loadReporteMorosidad() {
  document.getElementById('reporte-title').innerHTML = '<i class="fas fa-clock"></i> Reporte de Morosidad';
  apiCrm('reporte_morosidad', {}, function(r) {
    var items = Array.isArray(r) ? r : (r.items || []);
    document.getElementById('reporte-thead').innerHTML = '<tr><th>Cliente</th><th>Factura</th><th>Total</th><th>Saldo</th><th>Días Vencidos</th><th>Estado</th></tr>';
    document.getElementById('tbody-reporte').innerHTML = items.map(function(m) {
      var ba = m.estado === 'Al día' ? 'badge-success' : (m.estado === 'Moroso' ? 'badge-warning' : 'badge-danger');
      return '<tr><td>' + esc(m.razon_social) + '</td><td>' + esc(m.numero || m.id_factura) + '</td><td><strong>$' + fmt(m.total || 0) + '</strong></td><td>$' + fmt(m.saldo || 0) + '</td><td>' + (m.dias_vencidos || 0) + '</td><td><span class="badge ' + ba + '">' + esc(m.estado) + '</span></td></tr>';
    }).join('') || '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-clock"></i><p>Sin datos de morosidad</p></div></td></tr>';
  });
}

function loadReportes() {
  switchReporte('abc');
}

// ========== AUDITORÍA ==========
function loadAuditoria() {
  var nivel = document.getElementById('filter-nivel').value;
  apiCrm('auditoria', { nivel: nivel, limit: 200 }, function(r) {
    var items = Array.isArray(r) ? r : (r.items || []);
    document.getElementById('tbody-auditoria-crm').innerHTML = items.map(function(a) {
      var nc = a.nivel === 'ERROR' ? 'badge-danger' : (a.nivel === 'WARNING' ? 'badge-warning' : 'badge-info');
      return '<tr><td>' + (a.created_at || a.fecha || '') + '</td><td>' + esc(a.user_nombre || a.usuario || '') + '</td><td>' + esc(a.accion) + '</td><td>' + esc(a.entidad) + '</td><td>' + (a.id_entidad || '') + '</td><td style="font-size:12px;color:#64748b;">' + esc(a.detalle || '') + '</td><td><span class="badge ' + nc + '">' + (a.nivel || '') + '</span></td></tr>';
    }).join('') || '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-history"></i><p>Sin registros</p></div></td></tr>';
  });
}

// ========== INIT ==========
document.addEventListener('DOMContentLoaded', function() { loadDashboard(); });
