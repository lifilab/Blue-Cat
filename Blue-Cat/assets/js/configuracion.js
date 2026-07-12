var API = '../assets/api/core.php';
function apiCfg(accion, data, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      try { var d = JSON.parse(xhr.responseText); if (xhr.status >= 200 && xhr.status < 300) { if (cb) cb(d); } else { toast(d.error || 'Error', 'error'); } }
      catch (e) { toast('Error de respuesta', 'error'); }
    }
  };
  data = data || {}; data.accion = accion;
  xhr.send(JSON.stringify(data));
}
function toast(msg, type) {
  var t = document.createElement('div'); t.className = 'toast toast-' + (type === 'error' ? 'err' : 'ok');
  t.innerHTML = '<i class="fas fa-' + (type === 'error' ? 'exclamation-circle' : 'check-circle') + '"></i> ' + msg;
  document.body.appendChild(t); requestAnimationFrame(function() { t.classList.add('show'); });
  setTimeout(function() { t.classList.remove('show'); setTimeout(function() { t.remove(); }, 300); }, 2500);
}
function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function num(n) { return parseInt(n) || 0; }
function fmt(n) { return (num(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
function closeCfgModal() { document.getElementById('cfg-modal').style.display = 'none'; }

var _currentSection = 'dashboard', _selectedRol = 0;

function switchCfg(section) {
  _currentSection = section;
  sessionStorage.setItem('cfg-section', section);
  document.querySelectorAll('.section-title').forEach(function(el) { el.classList.remove('active'); });
  document.querySelectorAll('.cfg-sidebar li').forEach(function(el) { el.classList.remove('active'); });
  document.getElementById('cfg-' + section).classList.add('active');
  document.querySelector('.cfg-sidebar li[data-section="' + section + '"]').classList.add('active');
  loadCfg(section);
}

function loadCfg(section) {
  switch (section) {
    case 'dashboard': loadDashboard(); break;
    case 'empresas': loadEmpresas(); break;
    case 'sucursales': loadSucursales(); break;
    case 'usuarios': loadUsuarios(); break;
    case 'roles': loadRoles(); break;
    case 'monedas': loadMonedas(); break;
    case 'impuestos': loadImpuestos(); break;
    case 'numeraciones': loadNumeraciones(); break;
    case 'boletas': cargarConfigBoleta(); break;
    case 'parametros': loadParametros(); break;
    case 'planes': loadPlanes(); break;
    case 'suscripciones': loadSuscripciones(); break;
    case 'modulos': loadModulos(); break;
    case 'sesiones': loadSesiones(); break;
    case 'auditoria': loadAuditoria(); break;
  }
}

// ═══ DASHBOARD ═══
function loadDashboard() {
  apiCfg('dashboard', {}, function(d) {
    var el = document.getElementById('cfg-stats');
    el.innerHTML =
      '<div class="stat"><span class="stat-icon blue"><i class="fas fa-building"></i></span><div><div class="stat-num">' + d.empresas + '</div><div class="stat-label">Empresas</div></div></div>' +
      '<div class="stat"><span class="stat-icon green"><i class="fas fa-store-alt"></i></span><div><div class="stat-num">' + d.sucursales + '</div><div class="stat-label">Sucursales</div></div></div>' +
      '<div class="stat"><span class="stat-icon blue"><i class="fas fa-users"></i></span><div><div class="stat-num">' + d.usuarios_activos + '</div><div class="stat-label">Usuarios</div></div></div>' +
      '<div class="stat"><span class="stat-icon green"><i class="fas fa-shield-alt"></i></span><div><div class="stat-num">' + d.roles + '</div><div class="stat-label">Roles</div></div></div>' +
      '<div class="stat"><span class="stat-icon blue"><i class="fas fa-coins"></i></span><div><div class="stat-num">' + d.monedas + '</div><div class="stat-label">Monedas</div></div></div>' +
      '<div class="stat"><span class="stat-icon amber"><i class="fas fa-percent"></i></span><div><div class="stat-num">' + d.impuestos + '</div><div class="stat-label">Impuestos</div></div></div>' +
      '<div class="stat"><span class="stat-icon green"><i class="fas fa-history"></i></span><div><div class="stat-num">' + d.auditoria_hoy + '</div><div class="stat-label">Logs hoy</div></div></div>' +
      (d.errores_hoy > 0 ? '<div class="stat"><span class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></span><div><div class="stat-num">' + d.errores_hoy + '</div><div class="stat-label">Errores hoy</div></div></div>' : '');
    document.getElementById('t-ultimos-accesos').innerHTML = (d.ultimos_accesos || []).map(function(u) { return '<tr><td>' + esc(u.nombre_completo || u.nombre) + '</td><td>' + (u.ultimo_acceso || '-') + '</td></tr>'; }).join('');
    document.getElementById('t-ultimos-logs').innerHTML = (d.ultimos_logs || []).map(function(l) { return '<tr><td>' + l.accion + '</td><td>' + l.entidad + (l.id_entidad ? '#' + l.id_entidad : '') + '</td><td>' + l.created_at + '</td></tr>'; }).join('');
  });
}

// ═══ EMPRESAS ═══
function loadEmpresas() {
  apiCfg('empresas', {}, function(items) {
    var t = document.getElementById('t-empresas');
    t.innerHTML = items.map(function(e) {
      return '<tr><td>' + esc(e.rut) + '</td><td><strong>' + esc(e.razon_social) + '</strong></td><td>' + esc(e.nombre_comercial || '') + '</td><td>' + esc(e.ciudad || '') + '</td><td>' + esc(e.telefono || '') + '</td><td>' + (e.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-gray">Inactivo</span>') + '</td><td><button class="btn btn-outline btn-xs" onclick="editEmpresa(' + e.id_empresa + ')"><i class="fas fa-edit"></i></button></td></tr>';
    }).join('') || '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-building"></i><p>Sin empresas</p></div></td></tr>';
  });
}

function showCfgForm(type) {
  var h = '';
  if (type === 'empresa') {
    h = '<h3><i class="fas fa-building"></i> Nueva Empresa</h3><form onsubmit="saveEmpresa(event)"><div class="form-grid">' +
      '<div><label>Razón Social *</label><input id="f-razon" required></div><div><label>RUT *</label><input id="f-rut" required></div>' +
      '<div><label>Nombre Comercial</label><input id="f-ncom"></div><div><label>Giro</label><input id="f-giro"></div>' +
      '<div><label>Dirección</label><input id="f-dir"></div><div><label>Ciudad</label><input id="f-ciudad"></div>' +
      '<div><label>Teléfono</label><input id="f-tel"></div><div><label>Correo</label><input id="f-correo"></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Crear</button></form>';
  } else if (type === 'sucursal') {
    h = '<h3><i class="fas fa-store-alt"></i> Nueva Sucursal</h3><form onsubmit="saveSucursal(event)"><div class="form-grid">' +
      '<div><label>Nombre *</label><input id="f-nombre" required></div><div><label>Código</label><input id="f-codigo"></div>' +
      '<div><label>Responsable</label><input id="f-resp"></div><div><label>Teléfono</label><input id="f-tel"></div>' +
      '<div class="full"><label>Dirección</label><input id="f-dir"></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Crear</button></form>';
  } else if (type === 'usuario') {
    h = '<h3><i class="fas fa-user-plus"></i> Nuevo Usuario</h3><form onsubmit="saveUsuario(event)"><div class="form-grid">' +
      '<div><label>Usuario *</label><input id="f-usuario" required></div><div><label>Correo *</label><input id="f-correo" type="email" required></div>' +
      '<div><label>Contraseña *</label><input id="f-pass" type="password" required></div><div><label>Nombre Completo</label><input id="f-ncom"></div>' +
      '<div><label>Cargo</label><input id="f-cargo"></div><div><label>Teléfono</label><input id="f-tel"></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Crear</button></form>';
  } else if (type === 'rol') {
    h = '<h3><i class="fas fa-shield-alt"></i> Nuevo Rol</h3><form onsubmit="saveRol(event)"><div class="form-grid">' +
      '<div class="full"><label>Nombre *</label><input id="f-nombre" required></div><div class="full"><label>Descripción</label><textarea id="f-desc" rows="2"></textarea></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Crear</button></form>';
  } else if (type === 'moneda') {
    h = '<h3><i class="fas fa-coins"></i> Nueva Moneda</h3><form onsubmit="saveMoneda(event)"><div class="form-grid">' +
      '<div><label>Código *</label><input id="f-codigo" required></div><div><label>Nombre *</label><input id="f-nombre" required></div>' +
      '<div><label>Símbolo</label><input id="f-simbolo"></div><div><label>Decimales</label><input type="number" id="f-dec" value="0"></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Crear</button></form>';
  } else if (type === 'impuesto') {
    h = '<h3><i class="fas fa-percent"></i> Nuevo Impuesto</h3><form onsubmit="saveImpuesto(event)"><div class="form-grid">' +
      '<div><label>Nombre *</label><input id="f-nombre" required></div><div><label>Código *</label><input id="f-codigo" required></div>' +
      '<div><label>Tasa (%)</label><input type="number" id="f-tasa" step="0.01" value="0"></div><div><label>Tipo</label><select id="f-tipo"><option>IVA</option><option>EXENTO</option><option>RETENCION</option></select></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Crear</button></form>';
  } else if (type === 'plan') {
    h = '<h3><i class="fas fa-layer-group"></i> Nuevo Plan</h3><form onsubmit="savePlan(event)"><div class="form-grid">' +
      '<div><label>Nombre *</label><input id="f-nombre" required></div><div><label>Precio/mes</label><input type="number" id="f-precio" value="0"></div>' +
      '<div><label>Max Empresas</label><input type="number" id="f-me" value="1"></div><div><label>Max Sucursales</label><input type="number" id="f-ms" value="1"></div>' +
      '<div><label>Max Usuarios</label><input type="number" id="f-mu" value="5"></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Crear</button></form>';
  } else if (type === 'suscripcion') {
    h = '<h3><i class="fas fa-id-card"></i> Nueva Suscripción</h3><form onsubmit="saveSuscripcion(event)"><div class="form-grid">' +
      '<div><label>Empresa *</label><select id="f-empresa"></select></div><div><label>Plan *</label><select id="f-plan"></select></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Crear</button></form>';
    // Populate selects
    apiCfg('empresas', {}, function(items) {
      var s = document.getElementById('f-empresa'); s.innerHTML = items.map(function(e) { return '<option value="' + e.id_empresa + '">' + esc(e.razon_social) + '</option>'; }).join('');
    });
    apiCfg('planes', {}, function(items) {
      var s = document.getElementById('f-plan'); s.innerHTML = items.map(function(p) { return '<option value="' + p.id_plan + '">' + esc(p.nombre) + '</option>'; }).join('');
    });
  }
  document.getElementById('cfg-modal-body').innerHTML = h;
  document.getElementById('cfg-modal').style.display = 'block';
}

function saveEmpresa(e) { e.preventDefault();
  apiCfg('empresa_crear', { razon_social: q('f-razon'), nombre_comercial: q('f-ncom'), rut: q('f-rut'), giro: q('f-giro'), direccion: q('f-dir'), ciudad: q('f-ciudad'), telefono: q('f-tel'), correo: q('f-correo') }, function() { toast('Empresa creada'); closeCfgModal(); loadEmpresas(); }); }
function saveSucursal(e) { e.preventDefault();
  apiCfg('sucursal_crear', { nombre: q('f-nombre'), codigo: q('f-codigo'), responsable: q('f-resp'), telefono: q('f-tel'), direccion: q('f-dir') }, function() { toast('Sucursal creada'); closeCfgModal(); loadSucursales(); }); }
function saveUsuario(e) { e.preventDefault();
  apiCfg('usuario_crear', { nombre: q('f-usuario'), correo: q('f-correo'), password: q('f-pass'), nombre_completo: q('f-ncom'), cargo: q('f-cargo'), telefono: q('f-tel') }, function() { toast('Usuario creado'); closeCfgModal(); loadUsuarios(); }); }
function saveRol(e) { e.preventDefault();
  apiCfg('rol_crear', { nombre: q('f-nombre'), descripcion: q('f-desc') }, function() { toast('Rol creado'); closeCfgModal(); loadRoles(); }); }
function saveMoneda(e) { e.preventDefault();
  apiCfg('moneda_crear', { codigo: q('f-codigo'), nombre: q('f-nombre'), simbolo: q('f-simbolo'), decimales: parseInt(q('f-dec')) || 0 }, function() { toast('Moneda creada'); closeCfgModal(); loadMonedas(); }); }
function saveImpuesto(e) { e.preventDefault();
  apiCfg('impuesto_crear', { nombre: q('f-nombre'), codigo: q('f-codigo'), tasa: parseFloat(q('f-tasa')) || 0, tipo: q('f-tipo') }, function() { toast('Impuesto creado'); closeCfgModal(); loadImpuestos(); }); }
function savePlan(e) { e.preventDefault();
  apiCfg('plan_crear', { nombre: q('f-nombre'), precio: parseInt(q('f-precio'))||0, max_empresas: parseInt(q('f-me'))||1, max_sucursales: parseInt(q('f-ms'))||1, max_usuarios: parseInt(q('f-mu'))||5 }, function() { toast('Plan creado'); closeCfgModal(); loadPlanes(); }); }
function saveSuscripcion(e) { e.preventDefault();
  apiCfg('suscripcion_crear', { id_empresa: parseInt(q('f-empresa')), id_plan: parseInt(q('f-plan')) }, function() { toast('Suscripción creada'); closeCfgModal(); loadSuscripciones(); }); }

function editEmpresa(id) {
  apiCfg('empresas', {}, function(items) {
    var e = items.find(function(x) { return x.id_empresa == id; }); if (!e) return;
    document.getElementById('cfg-modal-body').innerHTML = '<h3><i class="fas fa-edit"></i> Editar Empresa</h3><form onsubmit="saveEmpresaEdit(event,' + id + ')"><div class="form-grid">' +
      '<div><label>Razón Social</label><input id="f-razon" value="' + esc(e.razon_social) + '"></div><div><label>RUT</label><input id="f-rut" value="' + esc(e.rut) + '"></div>' +
      '<div><label>Teléfono</label><input id="f-tel" value="' + esc(e.telefono || '') + '"></div><div><label>Correo</label><input id="f-correo" value="' + esc(e.correo || '') + '"></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Guardar</button></form>';
    document.getElementById('cfg-modal').style.display = 'block';
  });
}
function saveEmpresaEdit(e, id) { e.preventDefault();
  apiCfg('empresa_editar', { id: id, razon_social: q('f-razon'), rut: q('f-rut'), telefono: q('f-tel'), correo: q('f-correo') }, function() { toast('Actualizado'); closeCfgModal(); loadEmpresas(); }); }

function q(id) { var el = document.getElementById(id); return el ? el.value : ''; }

// ═══ SUCURSALES ═══
function loadSucursales() {
  apiCfg('sucursales', {}, function(items) {
    document.getElementById('t-sucursales').innerHTML = items.map(function(s) {
      return '<tr><td>' + esc(s.codigo) + '</td><td><strong>' + esc(s.nombre) + '</strong></td><td>' + esc(s.empresa) + '</td><td>' + esc(s.responsable || '') + '</td><td>' + (s.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-gray">Inactivo</span>') + '</td><td><button class="btn btn-outline btn-xs" onclick="editSucursal(' + s.id_sucursal + ')"><i class="fas fa-edit"></i></button></td></tr>';
    }).join('') || '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-store-alt"></i><p>Sin sucursales</p></div></td></tr>';
  });
}

function editSucursal(id) {
  apiCfg('sucursales', {}, function(items) {
    var s = items.find(function(x) { return x.id_sucursal == id; }); if (!s) return;
    document.getElementById('cfg-modal-body').innerHTML = '<h3><i class="fas fa-edit"></i> Editar Sucursal</h3><form onsubmit="saveSucursalEdit(event,' + id + ')"><div class="form-grid">' +
      '<div><label>Nombre</label><input id="f-nombre" value="' + esc(s.nombre) + '"></div><div><label>Código</label><input id="f-codigo" value="' + esc(s.codigo) + '"></div>' +
      '<div><label>Responsable</label><input id="f-resp" value="' + esc(s.responsable || '') + '"></div><div><label>Teléfono</label><input id="f-tel" value="' + esc(s.telefono || '') + '"></div>' +
      '<div class="full"><label>Dirección</label><input id="f-dir" value="' + esc(s.direccion || '') + '"></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Guardar</button></form>';
    document.getElementById('cfg-modal').style.display = 'block';
  });
}
function saveSucursalEdit(e, id) { e.preventDefault();
  apiCfg('sucursal_editar', { id: id, nombre: q('f-nombre'), codigo: q('f-codigo'), responsable: q('f-resp'), telefono: q('f-tel'), direccion: q('f-dir') }, function() { toast('Actualizado'); closeCfgModal(); loadSucursales(); }); }

// ═══ USUARIOS ═══
function loadUsuarios() {
  apiCfg('usuarios', {}, function(items) {
    var html = items.map(function(u) {
      var ultimoAcceso = u.ultimo_acceso ? new Date(u.ultimo_acceso).toLocaleDateString('es-CL') : 'Nunca';
      var fechaCreacion = u.fecha_creacion ? new Date(u.fecha_creacion).toLocaleDateString('es-CL') : '-';
      
      return '<tr>' +
        '<td><strong>' + esc(u.nombre) + '</strong></td>' +
        '<td>' + esc(u.nombre_completo || '-') + '</td>' +
        '<td>' + esc(u.correo) + '</td>' +
        '<td>' + esc(u.telefono || '-') + '</td>' +
        '<td>' + esc(u.cargo || '-') + '</td>' +
        '<td>' + esc(u.roles || 'Sin roles') + '</td>' +
        '<td>' + fechaCreacion + '</td>' +
        '<td>' + ultimoAcceso + '</td>' +
        '<td>' + (u.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>') + '</td>' +
        '<td style="white-space:nowrap;">' +
          '<button class="btn btn-outline btn-xs" onclick="verDetalleUsuario(' + u.id_user + ')" title="Ver detalles"><i class="fas fa-eye"></i></button> ' +
          '<button class="btn btn-outline btn-xs" onclick="editUsuarioRoles(' + u.id_user + ',\'' + esc(u.nombre) + '\')" title="Gestionar roles"><i class="fas fa-shield-alt"></i></button> ' +
          '<button class="btn btn-outline btn-xs" onclick="cambiarPassword(' + u.id_user + ',\'' + esc(u.nombre) + '\')" title="Cambiar contraseña"><i class="fas fa-key"></i></button>' +
        '</td>' +
        '</tr>';
    }).join('');
    
    document.getElementById('t-usuarios').innerHTML = html || '<tr><td colspan="10"><div class="empty-state"><i class="fas fa-users"></i><p>Sin usuarios</p></div></td></tr>';
  });
}

function verDetalleUsuario(uid) {
  apiCfg('usuarios', {}, function(items) {
    var u = items.find(function(user) { return user.id_user == uid; });
    if (!u) {
      toast('Usuario no encontrado', 'error');
      return;
    }
    
    var ultimoAcceso = u.ultimo_acceso ? new Date(u.ultimo_acceso).toLocaleString('es-CL') : 'Nunca';
    var fechaCreacion = u.fecha_creacion ? new Date(u.fecha_creacion).toLocaleString('es-CL') : '-';
    
    var html = '<h3><i class="fas fa-user"></i> Detalles de ' + esc(u.nombre) + '</h3>' +
      '<div style="margin-top:16px;">' +
        '<table style="width:100%;font-size:13px;">' +
          '<tr><td style="padding:8px 0;font-weight:600;width:150px;">Nombre de usuario:</td><td>' + esc(u.nombre) + '</td></tr>' +
          '<tr><td style="padding:8px 0;font-weight:600;">Nombre completo:</td><td>' + esc(u.nombre_completo || '-') + '</td></tr>' +
          '<tr><td style="padding:8px 0;font-weight:600;">Correo:</td><td>' + esc(u.correo) + '</td></tr>' +
          '<tr><td style="padding:8px 0;font-weight:600;">Teléfono:</td><td>' + esc(u.telefono || '-') + '</td></tr>' +
          '<tr><td style="padding:8px 0;font-weight:600;">Cargo:</td><td>' + esc(u.cargo || '-') + '</td></tr>' +
          '<tr><td style="padding:8px 0;font-weight:600;">Roles:</td><td>' + esc(u.roles || 'Sin roles') + '</td></tr>' +
          '<tr><td style="padding:8px 0;font-weight:600;">Fecha creación:</td><td>' + fechaCreacion + '</td></tr>' +
          '<tr><td style="padding:8px 0;font-weight:600;">Último acceso:</td><td>' + ultimoAcceso + '</td></tr>' +
          '<tr><td style="padding:8px 0;font-weight:600;">Estado:</td><td>' + (u.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>') + '</td></tr>' +
        '</table>' +
      '</div>' +
      '<div style="margin-top:20px;display:flex;gap:8px;justify-content:flex-end;">' +
        '<button class="btn btn-outline btn-sm" onclick="closeCfgModal()">Cerrar</button>' +
        '<button class="btn btn-primary btn-sm" onclick="editUsuarioRoles(' + u.id_user + ',\'' + esc(u.nombre) + '\');closeCfgModal();"><i class="fas fa-shield-alt"></i> Gestionar Roles</button>' +
      '</div>';
    
    document.getElementById('cfg-modal-body').innerHTML = html;
    document.getElementById('cfg-modal').style.display = 'block';
  });
}

function cambiarPassword(uid, uname) {
  // Verificar permiso
  if (!hasPermissionConfig('usuarios', 'editar_cuentas')) {
    toast('No tiene permiso para editar cuentas', 'error');
    return;
  }
  
  var html = '<h3><i class="fas fa-key"></i> Cambiar Contraseña</h3>' +
    '<p style="color:#64748b;font-size:13px;margin:12px 0;">Cambiar contraseña para: <strong>' + esc(uname) + '</strong></p>' +
    '<form onsubmit="guardarNuevaPassword(event,' + uid + ')">' +
      '<div style="margin-bottom:12px;">' +
        '<label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Nueva Contraseña *</label>' +
        '<input type="password" id="np-password" required minlength="6" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;">' +
      '</div>' +
      '<div style="margin-bottom:12px;">' +
        '<label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;">Confirmar Contraseña *</label>' +
        '<input type="password" id="np-confirm" required minlength="6" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:6px;">' +
      '</div>' +
      '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">' +
        '<button type="button" class="btn btn-outline btn-sm" onclick="closeCfgModal()">Cancelar</button>' +
        '<button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Cambiar Contraseña</button>' +
      '</div>' +
    '</form>';
  
  document.getElementById('cfg-modal-body').innerHTML = html;
  document.getElementById('cfg-modal').style.display = 'block';
}

function guardarNuevaPassword(event, uid) {
  event.preventDefault();
  
  var password = document.getElementById('np-password').value;
  var confirm = document.getElementById('np-confirm').value;
  
  if (password !== confirm) {
    toast('Las contraseñas no coinciden', 'error');
    return;
  }
  
  if (password.length < 6) {
    toast('La contraseña debe tener al menos 6 caracteres', 'error');
    return;
  }
  
  apiCfg('usuario_cambiar_password', { id_user: uid, password: password }, function(r) {
    if (r.success) {
      toast('Contraseña cambiada exitosamente', 'success');
      closeCfgModal();
    } else {
      toast(r.error || 'Error al cambiar contraseña', 'error');
    }
  });
}

function hasPermissionConfig(modulo, accion) {
  // Por ahora retornamos true, pero aquí se podría verificar contra los permisos del usuario
  return true;
}

function editUsuarioRoles(uid, uname) {
  apiCfg('usuario_roles', { id_user: uid }, function(items) {
    var list = items.map(function(r) {
      return '<div class="perm-row' + (r.asignado ? ' active' : '') + '" onclick="toggleUsuarioRol(' + uid + ',' + r.id_rol + ',this)"><div class="perm-check"></div>' + esc(r.nombre) + '</div>';
    }).join('');
    document.getElementById('cfg-modal-body').innerHTML = '<h3><i class="fas fa-shield-alt"></i> Roles de ' + esc(uname) + '</h3><div style="max-height:50vh;overflow-y:auto;">' + list + '</div>';
    document.getElementById('cfg-modal').style.display = 'block';
  });
}

function toggleUsuarioRol(uid, rid, el) {
  apiCfg('usuario_rol_toggle', { id_user: uid, id_rol: rid }, function(r) {
    if (r.estado === 'asignado') { el.classList.add('active'); toast('Rol asignado'); }
    else { el.classList.remove('active'); toast('Rol quitado'); }
    loadUsuarios();
  });
}

// ═══ ROLES Y PERMISOS ═══
var _matrixRoles = [], _matrixPerms = [], _selectedRolId = 0, _permModulos = {};

function loadRoles() {
  apiCfg('roles', {}, function(roles) {
    _matrixRoles = roles;
    renderRoleList();
    // Auto-select first role if none selected
    if (!_selectedRolId && roles.length > 0) {
      _selectedRolId = roles[0].id_rol;
    }
    if (_selectedRolId) selectMatrixRol(_selectedRolId);
  });
}

function renderRoleList() {
  var h = '';
  _matrixRoles.forEach(function(r) {
    h += '<div class="perm-row' + (_selectedRolId == r.id_rol ? ' active' : '') + '" onclick="selectMatrixRol(' + r.id_rol + ')" style="margin-bottom:2px;padding:8px 10px;display:flex;justify-content:space-between;align-items:center;">' +
      '<span style="font-weight:600;font-size:13px;">' + esc(r.nombre) + '</span>' +
      '<span style="font-size:11px;color:#64748b;">' + (r.usuarios || 0) + ' usr</span></div>';
  });
  document.getElementById('t-roles-list').innerHTML = h || '<p style="color:#94a3b8;font-size:13px;text-align:center;">Sin roles</p>';
}

function selectMatrixRol(rid) {
  _selectedRolId = rid;
  document.querySelectorAll('#t-roles-list .perm-row').forEach(function(el, i) {
    el.classList.toggle('active', _matrixRoles[i] && _matrixRoles[i].id_rol == rid);
  });
  var rol = _matrixRoles.find(function(r) { return r.id_rol == rid; });
  document.getElementById('matrix-title').innerHTML = '<i class="fas fa-key"></i> Permisos: <strong>' + esc(rol ? rol.nombre : '') + '</strong>';
  
  apiCfg('permisos', {}, function(perms) {
    _matrixPerms = perms;
    _permModulos = {};
    perms.forEach(function(p) { if (!_permModulos[p.modulo]) _permModulos[p.modulo] = []; _permModulos[p.modulo].push(p); });
    apiCfg('rol_permisos', { id_rol: rid }, function(items) {
      var map = {}; items.forEach(function(p) { if (parseInt(p.asignado) === 1) map[p.id_permiso] = true; });
      _matrixPerms.forEach(function(p) { p._asignado = !!map[p.id_permiso]; });
      renderMatrix();
    });
  });
}

function renderMatrix() {
  var h = '';
  Object.keys(_permModulos).forEach(function(mod) {
    var modPerms = _permModulos[mod];
    h += '<div style="margin-bottom:6px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">' +
      '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0;">' +
        '<strong style="font-size:13px;color:#1e293b;text-transform:uppercase;">' + esc(mod) + '</strong>' +
        '<div style="display:flex;gap:4px;">' +
          '<button class="btn btn-outline btn-xs" onclick="toggleModulo(\'' + esc(mod) + '\',true)" title="Activar todos los permisos de ' + esc(mod) + '" style="font-size:10px;padding:2px 8px;">Todos</button>' +
          '<button class="btn btn-outline btn-xs" onclick="toggleModulo(\'' + esc(mod) + '\',false)" title="Desactivar todos los permisos de ' + esc(mod) + '" style="font-size:10px;padding:2px 8px;">Ninguno</button>' +
        '</div>' +
      '</div>' +
      '<div style="padding:6px 10px;display:flex;flex-wrap:wrap;gap:2px 6px;">';
    modPerms.forEach(function(p) {
      var cls = p._asignado ? 'perm-row active' : 'perm-row';
      h += '<div class="' + cls + '" onclick="togglePermisoCell(' + p.id_permiso + ',this)" style="font-size:12px;padding:3px 8px;border-radius:4px;cursor:pointer;display:flex;align-items:center;gap:4px;" title="' + esc(p.descripcion || p.accion) + '">' +
        '<span class="perm-check"></span>' + esc(p.accion) + '</div>';
    });
    h += '</div></div>';
  });
  document.getElementById('t-perm-matrix').innerHTML = h || '<div style="text-align:center;padding:40px;color:#94a3b8;">Sin permisos definidos</div>';
}

function togglePermisoCell(pid, el) {
  if (!_selectedRolId) return;
  var perm = _matrixPerms.find(function(p) { return p.id_permiso == pid; });
  if (!perm) return;
  var newState = !perm._asignado;
  perm._asignado = newState;
  el.classList.toggle('active', newState);
  apiCfg('rol_permiso_toggle', { id_rol: _selectedRolId, id_permiso: pid }, function() {
    toast((newState ? '✓ ' : '✗ ') + esc(perm.modulo) + '.' + esc(perm.accion) + (newState ? ' asignado' : ' quitado'));
  });
}

function toggleModulo(modulo, on) {
  if (!_selectedRolId) return;
  var modPerms = _permModulos[modulo] || [];
  modPerms.forEach(function(p) {
    if (p._asignado !== on) {
      apiCfg('rol_permiso_toggle', { id_rol: _selectedRolId, id_permiso: p.id_permiso });
      p._asignado = on;
    }
  });
  renderMatrix();
  toast((on ? '✓ ' : '✗ ') + 'Módulo ' + esc(modulo) + ': ' + (on ? 'todos activados' : 'todos desactivados'));
}

function toggleAllPerms(on) {
  if (!_selectedRolId) return;
  _matrixPerms.forEach(function(p) {
    if (p._asignado !== on) {
      apiCfg('rol_permiso_toggle', { id_rol: _selectedRolId, id_permiso: p.id_permiso });
      p._asignado = on;
    }
  });
  renderMatrix();
  toast((on ? '✓ Todos los permisos asignados' : '✗ Todos los permisos quitados'));
}

function cloneRol() {
  var list = _matrixRoles.map(function(r) { return '<option value="'+r.id_rol+'">'+esc(r.nombre)+'</option>'; }).join('');
  document.getElementById('cfg-modal-body').innerHTML =
    '<h3><i class="fas fa-clone"></i> Clonar Rol</h3>' +
    '<p style="font-size:13px;color:#64748b;margin-bottom:12px;">Seleccione el rol origen y el nombre del nuevo rol.</p>' +
    '<div class="fld"><label>Rol origen</label><select id="clone-origen">'+list+'</select></div>' +
    '<div class="fld"><label>Nombre nuevo rol *</label><input id="clone-nombre" required></div>' +
    '<button class="btn btn-primary" onclick="doCloneRol()" style="width:100%;margin-top:8px;"><i class="fas fa-clone"></i> Clonar</button>';
  document.getElementById('cfg-modal').style.display = 'block';
}

function doCloneRol() {
  var id_origen = parseInt(document.getElementById('clone-origen').value);
  var nombre = document.getElementById('clone-nombre').value;
  if (!nombre) { toast('Nombre requerido', 'error'); return; }
  apiCfg('rol_crear', { nombre: nombre }, function(r) {
    var new_rid = r.id;
    apiCfg('rol_permisos', { id_rol: id_origen }, function(items) {
      var count = 0;
      items.forEach(function(p) {
        if (p.asignado) {
          apiCfg('rol_permiso_toggle', { id_rol: new_rid, id_permiso: p.id_permiso });
          count++;
        }
      });
      toast('Rol clonado con ' + count + ' permisos');
      closeCfgModal();
      loadRoles();
    });
  });
}

// ═══ MONEDAS ═══
function loadMonedas() {
  apiCfg('monedas', {}, function(items) {
    document.getElementById('t-monedas').innerHTML = items.map(function(m) {
      return '<tr><td><strong>' + esc(m.codigo) + '</strong></td><td>' + esc(m.nombre) + '</td><td>' + esc(m.simbolo || '') + '</td><td>' + m.decimales + '</td><td>' + (m.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-gray">Inactivo</span>') + '</td></tr>';
    }).join('') || '<tr><td colspan="5"><div class="empty-state"><i class="fas fa-coins"></i><p>Sin monedas</p></div></td></tr>';
  });
}

// ═══ IMPUESTOS ═══
function loadImpuestos() {
  apiCfg('impuestos', {}, function(items) {
    document.getElementById('t-impuestos').innerHTML = items.map(function(i) {
      return '<tr><td>' + esc(i.codigo) + '</td><td>' + esc(i.nombre) + '</td><td>' + i.tasa + '%</td><td><span class="badge badge-info">' + i.tipo + '</span></td><td>' + (i.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-gray">Inactivo</span>') + '</td></tr>';
    }).join('') || '<tr><td colspan="5"><div class="empty-state"><i class="fas fa-percent"></i><p>Sin impuestos</p></div></td></tr>';
  });
}

// ═══ NUMERACIONES ═══
function loadNumeraciones() {
  apiCfg('numeraciones', {}, function(items) {
    document.getElementById('t-numeraciones').innerHTML = items.map(function(n) {
      return '<tr><td>' + esc(n.tipo_documento) + '</td><td>' + esc(n.prefijo || '') + '</td><td contenteditable class="cell-editable" id="num-' + n.id_numeracion + '" onblur="updateNumeracion(' + n.id_numeracion + ')">' + n.siguiente_numero + '</td><td>' + esc(n.formato || '') + '</td><td>' + (n.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-gray">Inactivo</span>') + '</td><td><button class="btn btn-outline btn-xs" onclick="updateNumeracion(' + n.id_numeracion + ')"><i class="fas fa-save"></i></button></td></tr>';
    }).join('') || '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-sort-numeric-up"></i><p>Sin numeraciones</p></div></td></tr>';
  });
}
function updateNumeracion(id) {
  var el = document.getElementById('num-' + id);
  var val = parseInt(el.textContent) || 1;
  apiCfg('numeracion_editar', { id: id, siguiente_numero: val }, function() { toast('Numeración actualizada'); });
}

// ═══ PARÁMETROS ═══
function loadParametros() {
  apiCfg('parametros', {}, function(items) {
    document.getElementById('t-parametros').innerHTML = items.map(function(p) {
      return '<tr><td><strong>' + esc(p.clave) + '</strong></td><td contenteditable class="cell-editable" id="par-' + p.id_parametro + '" onblur="updateParametro(' + p.id_parametro + ')">' + esc(p.valor || '') + '</td><td>' + esc(p.tipo) + '</td><td style="font-size:11px;color:#64748b;">' + esc(p.descripcion || '') + '</td><td><button class="btn btn-outline btn-xs" onclick="updateParametro(' + p.id_parametro + ')"><i class="fas fa-save"></i></button></td></tr>';
    }).join('') || '<tr><td colspan="5"><div class="empty-state"><i class="fas fa-sliders-h"></i><p>Sin parámetros</p></div></td></tr>';
  });
}
function updateParametro(id) {
  var el = document.getElementById('par-' + id);
  apiCfg('parametro_editar', { id: id, valor: el.textContent }, function() { toast('Parámetro actualizado'); });
}

// ═══ AUDITORÍA ═══
function loadAuditoria() {
  var nivel = document.getElementById('filter-nivel').value;
  apiCfg('auditoria', { nivel: nivel, limit: 200 }, function(r) {
    document.getElementById('t-auditoria').innerHTML = (r.items || []).map(function(a) {
      var nc = a.nivel === 'ERROR' ? 'badge-danger' : (a.nivel === 'WARNING' ? 'badge-danger' : 'badge-info');
      return '<tr><td>' + a.created_at + '</td><td>' + esc(a.user_nombre || '') + '</td><td>' + a.accion + '</td><td>' + a.entidad + '</td><td>' + (a.id_entidad || '') + '</td><td><span class="badge ' + nc + '">' + a.nivel + '</span></td></tr>';
    }).join('') || '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-history"></i><p>Sin registros</p></div></td></tr>';
  });
}

// ═══ PLANES ═══
function loadPlanes() {
  apiCfg('planes', {}, function(items) {
    document.getElementById('t-planes').innerHTML = items.map(function(p) {
      return '<tr><td><strong>' + esc(p.nombre) + '</strong></td><td>$' + fmt(p.precio) + '/mes</td><td>' + p.max_empresas + '</td><td>' + p.max_sucursales + '</td><td>' + p.max_usuarios + '</td><td>' + (p.activo==1?'<span class="badge badge-success">Activo</span>':'<span class="badge badge-gray">Inactivo</span>') + '</td></tr>';
    }).join('') || '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-layer-group"></i><p>Sin planes</p></div></td></tr>';
  });
}

// ═══ SUSCRIPCIONES ═══
function loadSuscripciones() {
  apiCfg('suscripciones', {}, function(items) {
    document.getElementById('t-suscripciones').innerHTML = items.map(function(s) {
      var badge = s.estado==='activa'?'badge-success':(s.estado==='suspendida'?'badge-danger':'badge-gray');
      return '<tr><td>' + esc(s.empresa) + '</td><td>' + esc(s.plan_nombre) + '</td><td>' + (s.fecha_inicio||'') + '</td><td><span class="badge ' + badge + '">' + s.estado + '</span></td><td><button class="btn btn-outline btn-xs" onclick="editSuscripcion(' + s.id_suscripcion + ')"><i class="fas fa-edit"></i></button></td></tr>';
    }).join('') || '<tr><td colspan="5"><div class="empty-state"><i class="fas fa-id-card"></i><p>Sin suscripciones</p></div></td></tr>';
  });
}

function editSuscripcion(id) {
  apiCfg('suscripciones', {}, function(items) {
    var s = items.find(function(x) { return x.id_suscripcion == id; }); if (!s) return;
    var h = '<h3><i class="fas fa-edit"></i> Editar Suscripción</h3><form onsubmit="saveSuscripcionEdit(event,' + id + ')"><div class="form-grid">' +
      '<div><label>Estado</label><select id="f-estado"><option value="activa"' + (s.estado==='activa'?' selected':'') + '>Activa</option><option value="suspendida"' + (s.estado==='suspendida'?' selected':'') + '>Suspendida</option><option value="cancelada"' + (s.estado==='cancelada'?' selected':'') + '>Cancelada</option></select></div>' +
    '</div><button class="btn btn-primary" style="width:100%;margin-top:8px;"><i class="fas fa-save"></i> Guardar</button></form>';
    document.getElementById('cfg-modal-body').innerHTML = h;
    document.getElementById('cfg-modal').style.display = 'block';
  });
}

function saveSuscripcionEdit(e, id) { e.preventDefault();
  apiCfg('suscripcion_editar', { id: id, estado: q('f-estado') }, function() { toast('Actualizado'); closeCfgModal(); loadSuscripciones(); }); }

// ═══ MÓDULOS ═══
function loadModulos() {
  apiCfg('planes', {}, function(items) {
    var sel = document.getElementById('filter-plan-modulos');
    sel.innerHTML = '<option value="">Seleccionar plan...</option>';
    items.forEach(function(p) { sel.innerHTML += '<option value="' + p.id_plan + '">' + esc(p.nombre) + '</option>'; });
  });
}

function loadPlanModulos() {
  var id_plan = document.getElementById('filter-plan-modulos').value;
  if (!id_plan) return;
  apiCfg('plan_modulos', { id_plan: parseInt(id_plan) }, function(items) {
    document.getElementById('t-modulos-grid').innerHTML = items.map(function(m) {
      return '<div class="perm-row' + (m.asignado ? ' active' : '') + '" onclick="togglePlanModulo(' + id_plan + ',' + m.id_modulo + ',this)"><div class="perm-check"></div><i class="fas ' + esc(m.icono||'fa-cube') + '" style="width:16px;text-align:center;"></i> ' + esc(m.nombre) + ' <span style="font-size:10px;color:#94a3b8;">' + esc(m.codigo) + '</span></div>';
    }).join('');
  });
}

function togglePlanModulo(id_plan, id_modulo, el) {
  apiCfg('plan_modulo_toggle', { id_plan: id_plan, id_modulo: id_modulo }, function(r) {
    if (r.estado === 'asignado') el.classList.add('active'); else el.classList.remove('active');
  });
}

// ═══ SESIONES ═══
function loadSesiones() {
  apiCfg('sesiones_activas', {}, function(items) {
    document.getElementById('t-sesiones').innerHTML = items.map(function(s) {
      return '<tr><td>' + esc(s.nombre_completo || s.nombre || '') + '</td><td>' + s.accion + '</td><td>' + esc(s.ip||'') + '</td><td>' + s.created_at + '</td><td><button class="btn btn-outline btn-xs" onclick="cerrarSesionRemota('+s.id_sesion_log+')"><i class="fas fa-times"></i></button></td></tr>';
    }).join('') || '<tr><td colspan="5"><div class="empty-state"><i class="fas fa-plug"></i><p>Sin sesiones recientes</p></div></td></tr>';
  });
}
function cerrarSesionRemota(id) {
  apiCfg('sesion_cerrar', { id_sesion: id }, function() { toast('Sesión cerrada'); loadSesiones(); });
}

// ═══ CONFIGURACIÓN DE BOLETAS ═══
function cargarConfigBoleta() {
  apiCfg('config_boleta', {}, function(d) {
    document.getElementById('b-nombre-empresa').value = d.nombre_empresa || '';
    document.getElementById('b-rut-empresa').value = d.rut_empresa || '';
    document.getElementById('b-direccion').value = d.direccion || '';
    document.getElementById('b-telefono').value = d.telefono || '';
    document.getElementById('b-email').value = d.email || '';
    document.getElementById('b-mensaje-pie').value = d.mensaje_pie || '';
    document.getElementById('b-mensaje-agradecimiento').value = d.mensaje_agradecimiento || '';
    document.getElementById('b-iva').value = d.iva_porcentaje || 19;
    document.getElementById('b-mostrar-rut-cliente').checked = d.mostrar_rut_cliente == 1;
    document.getElementById('b-mostrar-iva').checked = d.mostrar_desglose_iva == 1;
    document.getElementById('b-mostrar-descuento').checked = d.mostrar_descuento == 1;
    
    if (d.logo) {
      document.getElementById('b-logo-preview').innerHTML = '<img src="' + d.logo + '" style="max-width:150px;max-height:150px;border-radius:8px;border:1px solid #e2e8f0;">';
    }
  });
}

function cargarLogo(event) {
  var file = event.target.files[0];
  if (!file) return;
  
  var reader = new FileReader();
  reader.onload = function(e) {
    document.getElementById('b-logo-preview').innerHTML = '<img src="' + e.target.result + '" style="max-width:150px;max-height:150px;border-radius:8px;border:1px solid #e2e8f0;">';
    document.getElementById('b-logo-preview').dataset.logo = e.target.result;
  };
  reader.readAsDataURL(file);
}

function guardarConfigBoleta(event) {
  event.preventDefault();
  
  var logo = '';
  var preview = document.getElementById('b-logo-preview');
  if (preview.dataset.logo) {
    logo = preview.dataset.logo;
  } else if (preview.querySelector('img')) {
    logo = preview.querySelector('img').src;
  }
  
  var data = {
    nombre_empresa: document.getElementById('b-nombre-empresa').value,
    rut_empresa: document.getElementById('b-rut-empresa').value,
    direccion: document.getElementById('b-direccion').value,
    telefono: document.getElementById('b-telefono').value,
    email: document.getElementById('b-email').value,
    logo: logo,
    mensaje_pie: document.getElementById('b-mensaje-pie').value,
    mensaje_agradecimiento: document.getElementById('b-mensaje-agradecimiento').value,
    iva_porcentaje: parseFloat(document.getElementById('b-iva').value) || 19,
    mostrar_rut_cliente: document.getElementById('b-mostrar-rut-cliente').checked ? 1 : 0,
    mostrar_desglose_iva: document.getElementById('b-mostrar-iva').checked ? 1 : 0,
    mostrar_descuento: document.getElementById('b-mostrar-descuento').checked ? 1 : 0
  };
  
  apiCfg('config_boleta_guardar', data, function(r) {
    toast('Configuración de boleta guardada');
  });
}

// Init
document.addEventListener('DOMContentLoaded', function() {
  var saved = sessionStorage.getItem('cfg-section');
  if (saved && document.getElementById('cfg-' + saved)) {
    switchCfg(saved);
  } else {
    loadDashboard();
  }
});
