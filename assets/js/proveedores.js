/* ── Proveedores - Blue-Cat ── */
var _st = null;
document.addEventListener('DOMContentLoaded', function() { loadProv(); });

function $(id) { return document.getElementById(id); }
function fm(n) { return '$' + Math.round(Number(n)).toLocaleString('es-CL'); }
function esc(s) { if(!s)return''; var d=document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }

function toast(msg, t) {
  var el = document.createElement('div');
  el.className = 'toast toast-' + (t==='err'?'err':'ok');
  el.innerHTML = msg;
  document.body.appendChild(el);
  requestAnimationFrame(function() { el.classList.add('show'); });
  setTimeout(function() { el.classList.remove('show'); setTimeout(function() { el.remove(); },300); }, 2500);
}

function ds() { clearTimeout(_st); _st = setTimeout(function() { loadProv(); }, 300); }

/* ── Load ── */
function loadProv() {
  var q = $('sq').value, f = $('sf').value;
  var url = '../assets/api/proveedores.php?q=' + encodeURIComponent(q);
  if (f) url += '&estado=' + f;

  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.onload = function() {
    if (xhr.status !== 200) return;
    var d = JSON.parse(xhr.responseText);
    d = d.items || d;
    renderTable(d);
    updateKPIs(d);
  };
  xhr.send();
}

function updateKPIs(d) {
  var t=0,a=0,b=0,p=0;
  for (var i=0;i<d.length;i++) {
    t++;
    if (d[i].estado==='ACTIVO') a++;
    if (d[i].estado==='BLOQUEADO') b++;
    if (d[i].estado==='PENDIENTE') p++;
  }
  $('kp-total').textContent = t;
  $('kp-activos').textContent = a;
  $('kp-bloqueados').textContent = b;
  $('kp-pendientes').textContent = p;
}

function renderTable(d) {
  var tb = $('ptb'), e = $('pe');
  tb.innerHTML = '';
  if (!d || !d.length) { e.style.display='block'; return; }
  e.style.display='none';

  for (var i=0;i<d.length;i++) {
    var r = d[i];
    var bc = 'badge-' + (r.estado||'ACTIVO');
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><strong>' + esc(r.codigo) + '</strong></td>' +
      '<td>' + esc(r.rut) + '</td>' +
      '<td><a href="#" onclick="showProfile(' + r.id_proveedor + ')" style="color:#4f46e5;text-decoration:none;font-weight:500;">' + esc(r.razon_social) + '</a></td>' +
      '<td>' + esc(r.ciudad) + '</td>' +
      '<td>' + esc(r.telefono) + '</td>' +
      '<td>' + esc(r.correo) + '</td>' +
      '<td><span class="badge ' + bc + '">' + r.estado + '</span></td>' +
      '<td>' +
      '<button class="bi" onclick="showProfile(' + r.id_proveedor + ')" title="Ver perfil"><i class="fas fa-eye"></i></button>' +
      '<button class="bi" onclick="showEdit(' + r.id_proveedor + ')" title="Editar"><i class="fas fa-pen"></i></button>' +
      (r.estado !== 'BLOQUEADO' ? '<button class="bi red" onclick="toggleEstado(' + r.id_proveedor + ',\'BLOQUEADO\')" title="Bloquear"><i class="fas fa-ban"></i></button>' : '<button class="bi green" onclick="toggleEstado(' + r.id_proveedor + ',\'ACTIVO\')" title="Activar"><i class="fas fa-check"></i></button>') +
      '</td>';
    tb.appendChild(tr);
  }
}

/* ── Create ── */
function showCreate() {
  var m = $('cm'), b = $('cbody');
  b.innerHTML =
    '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;"><i class="fas fa-plus-circle" style="color:#4f46e5;"></i> Nuevo Proveedor</h3>' +
    '<div class="gr2">' +
    '<div class="fld"><label>RUT</label><input id="c-rut" placeholder="12.345.678-9"></div>' +
    '<div class="fld"><label>Razón Social *</label><input id="c-razon" placeholder="Nombre legal"></div>' +
    '<div class="fld"><label>Nombre Comercial</label><input id="c-comercial" placeholder="Nombre de fantasía"></div>' +
    '<div class="fld"><label>Giro</label><input id="c-giro"></div>' +
    '<div class="fld"><label>Categoría</label><input id="c-cat" placeholder="Ej: Alimentos, Tecnología"></div>' +
    '<div class="fld"><label>Tipo</label><select id="c-tipo"><option value="NACIONAL">Nacional</option><option value="EXTRANJERO">Extranjero</option></select></div>' +
    '<div class="fld"><label>País</label><input id="c-pais" value="Chile"></div>' +
    '<div class="fld"><label>Región</label><input id="c-region"></div>' +
    '<div class="fld"><label>Ciudad</label><input id="c-ciudad"></div>' +
    '<div class="fld"><label>Comuna</label><input id="c-comuna"></div>' +
    '<div class="fld" style="grid-column:1/-1;"><label>Dirección</label><input id="c-dir"></div>' +
    '<div class="fld"><label>Teléfono</label><input id="c-tel"></div>' +
    '<div class="fld"><label>Correo</label><input id="c-mail" type="email"></div>' +
    '<div class="fld"><label>Sitio Web</label><input id="c-web"></div>' +
    '<div class="fld"><label>Contacto Principal</label><input id="c-contacto"></div>' +
    '<div class="fld"><label>Condición de Pago</label><input id="c-cp" placeholder="Ej: 30 días"></div>' +
    '<div class="fld"><label>Límite de Crédito</label><input id="c-credito" type="number" min="0"></div>' +
    '<div class="fld"><label>Descuento %</label><input id="c-dcto" type="number" min="0" max="100"></div>' +
    '<div class="fld"><label>Tiempo Entrega (días)</label><input id="c-te" type="number" min="0"></div>' +
    '<div class="fld"><label>Pedido Mínimo</label><input id="c-pm" type="number" min="0"></div>' +
    '</div>' +
    '<div class="fld"><label>Notas</label><textarea id="c-notas" rows="2"></textarea></div>' +
    '<div class="mcb"><button class="btn-g" onclick="$(\'cm\').classList.remove(\'show\')">Cancelar</button><button class="btn-p" onclick="saveCreate()"><i class="fas fa-save"></i> Guardar</button></div>';
  m.classList.add('show');
  m.onclick = function(e) { if(e.target===m) m.classList.remove('show'); };
  setTimeout(function() { $('c-razon').focus(); }, 100);
}

function saveCreate() {
  var d = {
    accion: 'crear',
    rut: $('c-rut').value,
    razon_social: $('c-razon').value,
    nombre_comercial: $('c-comercial').value,
    giro: $('c-giro').value,
    categoria: $('c-cat').value,
    tipo: $('c-tipo').value,
    pais: $('c-pais').value,
    region: $('c-region').value,
    ciudad: $('c-ciudad').value,
    comuna: $('c-comuna').value,
    direccion: $('c-dir').value,
    telefono: $('c-tel').value,
    correo: $('c-mail').value,
    sitio_web: $('c-web').value,
    contacto_principal: $('c-contacto').value,
    condicion_pago: $('c-cp').value,
    limite_credito: parseInt($('c-credito').value)||0,
    descuento: parseInt($('c-dcto').value)||0,
    tiempo_entrega: parseInt($('c-te').value)||null,
    pedido_minimo: parseInt($('c-pm').value)||0,
    notas: $('c-notas').value
  };
  if (!d.razon_social) { toast('Razón social es obligatoria','err'); return; }

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status === 201) {
      toast('<i class="fas fa-check-circle"></i> Proveedor creado');
      $('cm').classList.remove('show');
      loadProv();
    } else { try { var e=JSON.parse(xhr.responseText); toast(e.error||'Error','err'); } catch(e2) { toast('Error','err'); } }
  };
  xhr.send(JSON.stringify(d));
}

/* ── Edit ── */
function showEdit(id) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/proveedores.php?id=' + id, true);
  xhr.onload = function() {
    if (xhr.status!==200) return;
    var p = JSON.parse(xhr.responseText);
    var m = $('cm'), b = $('cbody');
    b.innerHTML =
      '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;"><i class="fas fa-pen" style="color:#4f46e5;"></i> Editar Proveedor</h3>' +
      '<div class="gr2">' +
      '<div class="fld"><label>RUT</label><input id="c-rut" value="' + esc(p.rut) + '"></div>' +
      '<div class="fld"><label>Razón Social *</label><input id="c-razon" value="' + esc(p.razon_social) + '"></div>' +
      '<div class="fld"><label>Nombre Comercial</label><input id="c-comercial" value="' + esc(p.nombre_comercial||'') + '"></div>' +
      '<div class="fld"><label>Giro</label><input id="c-giro" value="' + esc(p.giro||'') + '"></div>' +
      '<div class="fld"><label>Categoría</label><input id="c-cat" value="' + esc(p.categoria||'') + '"></div>' +
      '<div class="fld"><label>Tipo</label><select id="c-tipo"><option value="NACIONAL"'+(p.tipo==='NACIONAL'?' selected':'')+'>Nacional</option><option value="EXTRANJERO"'+(p.tipo==='EXTRANJERO'?' selected':'')+'>Extranjero</option></select></div>' +
      '<div class="fld"><label>País</label><input id="c-pais" value="' + esc(p.pais||'Chile') + '"></div>' +
      '<div class="fld"><label>Región</label><input id="c-region" value="' + esc(p.region||'') + '"></div>' +
      '<div class="fld"><label>Ciudad</label><input id="c-ciudad" value="' + esc(p.ciudad||'') + '"></div>' +
      '<div class="fld"><label>Comuna</label><input id="c-comuna" value="' + esc(p.comuna||'') + '"></div>' +
      '<div class="fld" style="grid-column:1/-1;"><label>Dirección</label><input id="c-dir" value="' + esc(p.direccion||'') + '"></div>' +
      '<div class="fld"><label>Teléfono</label><input id="c-tel" value="' + esc(p.telefono||'') + '"></div>' +
      '<div class="fld"><label>Correo</label><input id="c-mail" value="' + esc(p.correo||'') + '" type="email"></div>' +
      '<div class="fld"><label>Sitio Web</label><input id="c-web" value="' + esc(p.sitio_web||'') + '"></div>' +
      '<div class="fld"><label>Contacto Principal</label><input id="c-contacto" value="' + esc(p.contacto_principal||'') + '"></div>' +
      '<div class="fld"><label>Condición Pago</label><input id="c-cp" value="' + esc(p.condicion_pago||'') + '"></div>' +
      '<div class="fld"><label>Límite Crédito</label><input id="c-credito" type="number" value="' + (p.limite_credito||0) + '"></div>' +
      '<div class="fld"><label>Descuento %</label><input id="c-dcto" type="number" value="' + (p.descuento||0) + '"></div>' +
      '<div class="fld"><label>Tiempo Entrega (días)</label><input id="c-te" type="number" value="' + (p.tiempo_entrega||'') + '"></div>' +
      '<div class="fld"><label>Pedido Mínimo</label><input id="c-pm" type="number" value="' + (p.pedido_minimo||0) + '"></div>' +
      '</div>' +
      '<div class="fld"><label>Notas</label><textarea id="c-notas" rows="2">' + esc(p.notas||'') + '</textarea></div>' +
      '<div class="mcb"><button class="btn-g" onclick="$(\'cm\').classList.remove(\'show\')">Cancelar</button><button class="btn-p" onclick="saveEdit(' + id + ')"><i class="fas fa-save"></i> Guardar</button></div>';
    m.classList.add('show');
    m.onclick = function(e) { if(e.target===m) m.classList.remove('show'); };
  };
  xhr.send();
}

function saveEdit(id) {
  var d = {
    accion: 'editar',
    id_proveedor: id,
    rut: $('c-rut').value,
    razon_social: $('c-razon').value,
    nombre_comercial: $('c-comercial').value,
    giro: $('c-giro').value,
    categoria: $('c-cat').value,
    tipo: $('c-tipo').value,
    pais: $('c-pais').value,
    region: $('c-region').value,
    ciudad: $('c-ciudad').value,
    comuna: $('c-comuna').value,
    direccion: $('c-dir').value,
    telefono: $('c-tel').value,
    correo: $('c-mail').value,
    sitio_web: $('c-web').value,
    contacto_principal: $('c-contacto').value,
    condicion_pago: $('c-cp').value,
    limite_credito: parseInt($('c-credito').value)||0,
    descuento: parseInt($('c-dcto').value)||0,
    tiempo_entrega: parseInt($('c-te').value)||null,
    pedido_minimo: parseInt($('c-pm').value)||0,
    notas: $('c-notas').value
  };
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status===200) { toast('Proveedor actualizado'); $('cm').classList.remove('show'); loadProv(); }
    else { try { var e=JSON.parse(xhr.responseText); toast(e.error||'Error','err'); } catch(e2) { toast('Error','err'); } }
  };
  xhr.send(JSON.stringify(d));
}

/* ── Toggle estado ── */
function toggleEstado(id, estado) {
  var msg = estado === 'BLOQUEADO' ? '¿Bloquear este proveedor?' : '¿Activar este proveedor?';
  if (!confirm(msg)) return;
  $('pm').classList.remove('show'); // close profile if open
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status===200) { toast('Estado actualizado'); loadProv(); }
    else { toast('Error','err'); }
  };
  xhr.send(JSON.stringify({accion:'cambiar_estado', id_proveedor:id, estado:estado}));
}

/* ── Profile ── */
var _profileId = 0;

function showProfile(id) {
  _profileId = id;
  var m = $('pm'), b = $('pbody');
  b.innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#4f46e5;"></i></div>';
  m.classList.add('show');
  m.onclick = function(e) { if(e.target===m) m.classList.remove('show'); };

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/proveedores.php?id=' + id, true);
  xhr.onload = function() {
    if (xhr.status!==200) { b.innerHTML='<p>Error</p>'; return; }
    var p = JSON.parse(xhr.responseText);
    renderProfile(p);
  };
  xhr.send();
}

function renderProfile(p) {
  var b = $('pbody');
  var bc = 'badge-' + (p.estado||'ACTIVO');

  b.innerHTML =
    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">' +
    '<h3 style="font-size:18px;font-weight:700;color:#1e293b;"><i class="fas fa-truck" style="color:#4f46e5;"></i> ' + esc(p.razon_social) + '</h3>' +
    '<div><span class="badge ' + bc + '" style="font-size:12px;padding:4px 14px;">' + p.estado + '</span>' +
    '<button class="bi" onclick="showEdit(' + p.id_proveedor + ')" title="Editar"><i class="fas fa-pen"></i></button>' +
    '<button class="bi" onclick="$(\'pm\').classList.remove(\'show\')" title="Cerrar"><i class="fas fa-times"></i></button></div></div>' +
    '<div style="font-size:13px;color:#64748b;margin-bottom:14px;">' + esc(p.codigo) + ' · ' + esc(p.rut) + ' · ' + esc(p.categoria||'Sin categoría') + '</div>' +

    /* Tabs */
    '<div class="tabs" id="ptabs">' +
    '<div class="tab active" data-tab="info" onclick="switchTab(\'info\')"><i class="fas fa-info-circle"></i> General</div>' +
    '<div class="tab" data-tab="contactos" onclick="switchTab(\'contactos\')"><i class="fas fa-address-book"></i> Contactos (' + (p.contactos||[]).length + ')</div>' +
    '<div class="tab" data-tab="bancos" onclick="switchTab(\'bancos\')"><i class="fas fa-university"></i> Bancos</div>' +
    '<div class="tab" data-tab="productos" onclick="switchTab(\'productos\')"><i class="fas fa-box"></i> Productos (' + (p.productos||[]).length + ')</div>' +
    '<div class="tab" data-tab="historial" onclick="switchTab(\'historial\')"><i class="fas fa-history"></i> Historial</div>' +
    '</div>' +

    '<div id="ptab-info" class="tab-pane active">' + infoHtml(p) + '</div>' +
    '<div id="ptab-contactos" class="tab-pane">' + contactosHtml(p) + '</div>' +
    '<div id="ptab-bancos" class="tab-pane">' + bancosHtml(p) + '</div>' +
    '<div id="ptab-productos" class="tab-pane">' + productosHtml(p) + '</div>' +
    '<div id="ptab-historial" class="tab-pane">' + historialHtml(p) + '</div>';
}

function switchTab(tab) {
  var tabs = document.querySelectorAll('#ptabs .tab');
  for (var i=0;i<tabs.length;i++) tabs[i].classList.remove('active');
  document.querySelector('#ptabs .tab[data-tab="'+tab+'"]').classList.add('active');
  var panes = document.querySelectorAll('#pbody .tab-pane');
  for (var j=0;j<panes.length;j++) panes[j].classList.remove('active');
  $('ptab-'+tab).classList.add('active');
}

function infoHtml(p) {
  return '<div class="gr2" style="margin-top:4px;">' +
    '<div class="fld"><label>RUT</label><div style="padding:4px 0;font-weight:500;">' + esc(p.rut||'-') + '</div></div>' +
    '<div class="fld"><label>Giro</label><div style="padding:4px 0;font-weight:500;">' + esc(p.giro||'-') + '</div></div>' +
    '<div class="fld"><label>Dirección</label><div style="padding:4px 0;font-weight:500;">' + esc(p.direccion||'-') + ', ' + esc(p.comuna||'') + ', ' + esc(p.ciudad||'') + '</div></div>' +
    '<div class="fld"><label>Teléfono</label><div style="padding:4px 0;font-weight:500;">' + esc(p.telefono||'-') + '</div></div>' +
    '<div class="fld"><label>Correo</label><div style="padding:4px 0;font-weight:500;">' + esc(p.correo||'-') + '</div></div>' +
    '<div class="fld"><label>Condición Pago</label><div style="padding:4px 0;font-weight:500;">' + esc(p.condicion_pago||'-') + '</div></div>' +
    '<div class="fld"><label>Límite Crédito</label><div style="padding:4px 0;font-weight:500;">' + fm(p.limite_credito) + '</div></div>' +
    '<div class="fld"><label>Descuento</label><div style="padding:4px 0;font-weight:500;">' + (p.descuento||'0') + '%</div></div>' +
    '<div class="fld"><label>Tiempo Entrega</label><div style="padding:4px 0;font-weight:500;">' + (p.tiempo_entrega ? p.tiempo_entrega + ' días' : '-') + '</div></div>' +
    '<div class="fld"><label>Pedido Mínimo</label><div style="padding:4px 0;font-weight:500;">' + (p.pedido_minimo||'0') + '</div></div>' +
    '<div class="fld" style="grid-column:1/-1;"><label>Notas</label><div style="padding:4px 0;font-weight:500;">' + esc(p.notas||'-') + '</div></div>' +
    '</div>';
}

function contactosHtml(p) {
  var cs = p.contactos || [];
  var h = '<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddContacto(' + p.id_proveedor + ')"><i class="fas fa-plus"></i> Agregar Contacto</button></div>';
  if (!cs.length) h += '<p style="color:#94a3b8;">Sin contactos registrados</p>';
  else {
    h += '<table class="w100"><thead><tr><th>Nombre</th><th>Cargo</th><th>Correo</th><th>Teléfono</th><th>Principal</th><th></th></tr></thead><tbody>';
    for (var i=0;i<cs.length;i++) {
      var c = cs[i];
      h += '<tr><td><strong>' + esc(c.nombre) + '</strong></td><td>' + esc(c.cargo||'') + '</td><td>' + esc(c.correo||'') + '</td><td>' + esc(c.telefono||'') + '</td><td>' + (c.principal?'<span class="badge badge-ACTIVO">Principal</span>':'') + '</td>' +
        '<td><button class="bi" onclick="showEditContacto(' + p.id_proveedor + ',' + c.id_contacto + ')" title="Editar"><i class="fas fa-pen"></i></button>' +
        '<button class="bi red" onclick="delContacto(' + c.id_contacto + ')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h += '</tbody></table>';
  }
  return h;
}

function bancosHtml(p) {
  var bs = p.bancos || [];
  var h = '<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddBanco(' + p.id_proveedor + ')"><i class="fas fa-plus"></i> Agregar Cuenta</button></div>';
  if (!bs.length) h += '<p style="color:#94a3b8;">Sin cuentas bancarias</p>';
  else {
    h += '<table class="w100"><thead><tr><th>Banco</th><th>Tipo</th><th>Número</th><th>Titular</th><th>Principal</th><th></th></tr></thead><tbody>';
    for (var i=0;i<bs.length;i++) {
      var b = bs[i];
      h += '<tr><td><strong>' + esc(b.banco) + '</strong></td><td>' + esc(b.tipo_cuenta||'') + '</td><td>' + esc(b.numero_cuenta||'') + '</td><td>' + esc(b.titular||'') + '</td><td>' + (b.principal?'<span class="badge badge-ACTIVO">Sí</span>':'') + '</td>' +
        '<td><button class="bi red" onclick="delBanco(' + b.id_banco + ')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h += '</tbody></table>';
  }
  return h;
}

function productosHtml(p) {
  var prs = p.productos || [];
  var h = '<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddProducto(' + p.id_proveedor + ')"><i class="fas fa-plus"></i> Asociar Producto</button></div>';
  if (!prs.length) h += '<p style="color:#94a3b8;">Sin productos asociados</p>';
  else {
    h += '<table class="w100"><thead><tr><th>Producto</th><th>SKU Proveedor</th><th>SKU Interno</th><th>Precio Compra</th><th></th></tr></thead><tbody>';
    for (var i=0;i<prs.length;i++) {
      var pr = prs[i];
      h += '<tr><td><strong>' + esc(pr.producto) + '</strong></td><td>' + esc(pr.sku_proveedor||'') + '</td><td>' + esc(pr.sku_interno||'') + '</td><td>' + fm(pr.precio_compra) + '</td>' +
        '<td><button class="bi red" onclick="delProductoAsoc(' + pr.id_prov_producto + ')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h += '</tbody></table>';
  }
  return h;
}

function historialHtml(p) {
  var hs = p.historial || [];
  if (!hs.length) return '<p style="color:#94a3b8;">Sin historial</p>';
  var h = '<table class="w100"><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th></tr></thead><tbody>';
  for (var i=0;i<hs.length;i++) {
    var hi = hs[i];
    h += '<tr><td style="font-size:12px;color:#64748b;">' + (hi.fecha||'') + '</td><td>' + esc(hi.usuario||'') + '</td><td><span class="tag">' + esc(hi.accion) + '</span></td><td style="color:#64748b;">' + esc(hi.valor_nuevo||'') + '</td></tr>';
  }
  h += '</tbody></table>';
  return h;
}

/* ── Contacto CRUD ── */
function showAddContacto(idProv) {
  var b = $('pbody');
  var pane = $('ptab-contactos');
  var form = document.createElement('div');
  form.id = 'contacto-form';
  form.style.cssText = 'background:#f8fafc;padding:14px;border-radius:12px;margin-bottom:12px;border:1.5px solid #e2e8f0;';
  form.innerHTML =
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-address-book" style="color:#4f46e5;"></i> Nuevo Contacto</div>' +
    '<div class="gr2">' +
    '<div class="fld"><label>Nombre *</label><input id="con-nombre"></div>' +
    '<div class="fld"><label>Cargo</label><input id="con-cargo"></div>' +
    '<div class="fld"><label>Departamento</label><input id="con-depto"></div>' +
    '<div class="fld"><label>Correo</label><input id="con-correo" type="email"></div>' +
    '<div class="fld"><label>Teléfono</label><input id="con-tel"></div>' +
    '<div class="fld"><label>Celular</label><input id="con-cel"></div>' +
    '<div class="fld"><label>WhatsApp</label><input id="con-whatsapp"></div>' +
    '<div class="fld" style="display:flex;align-items:center;gap:8px;padding-top:18px;"><input type="checkbox" id="con-principal"> <label style="margin:0;">Contacto principal</label></div>' +
    '</div>' +
    '<div class="fld"><label>Notas</label><textarea id="con-notas" rows="1"></textarea></div>' +
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelContactoForm()" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveContacto(' + idProv + ')" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(form, pane.firstChild.nextSibling);
  $('con-nombre').focus();
}

function cancelContactoForm() {
  var f = $('contacto-form');
  if (f) f.remove();
}

function saveContacto(idProv) {
  var d = {
    accion: 'contacto_crear',
    id_proveedor: idProv,
    nombre: $('con-nombre').value,
    cargo: $('con-cargo').value,
    departamento: $('con-depto').value,
    correo: $('con-correo').value,
    telefono: $('con-tel').value,
    celular: $('con-cel').value,
    whatsapp: $('con-whatsapp').value,
    principal: $('con-principal').checked,
    notas: $('con-notas').value
  };
  if (!d.nombre) { toast('Nombre obligatorio','err'); return; }
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status===201) { toast('Contacto agregado'); showProfile(_profileId); }
    else { try { var e=JSON.parse(xhr.responseText); toast(e.error||'Error','err'); } catch(e2) { toast('Error','err'); } }
  };
  xhr.send(JSON.stringify(d));
}

function showEditContacto(idProv, idCon) {
  // Simplified: just refetch profile and show form
  // For now, re-render with inline edit
  toast('Funcionalidad: editar contacto desde el perfil','ok');
}

function delContacto(id) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status===200) { toast('Contacto eliminado'); showProfile(_profileId); }
    else { toast('Error','err'); }
  };
  xhr.send(JSON.stringify({accion:'contacto_eliminar', id_contacto:id}));
}

/* ── Banco CRUD ── */
function showAddBanco(idProv) {
  var pane = $('ptab-bancos');
  var form = document.createElement('div');
  form.id = 'banco-form';
  form.style.cssText = 'background:#f8fafc;padding:14px;border-radius:12px;margin-bottom:12px;border:1.5px solid #e2e8f0;';
  form.innerHTML =
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-university" style="color:#4f46e5;"></i> Nueva Cuenta Bancaria</div>' +
    '<div class="gr2">' +
    '<div class="fld"><label>Banco *</label><input id="b-banco"></div>' +
    '<div class="fld"><label>Tipo Cuenta</label><select id="b-tipo"><option value="">Seleccionar</option><option value="Corriente">Corriente</option><option value="Vista">Vista</option><option value="Ahorro">Ahorro</option><option value="RUT">RUT</option></select></div>' +
    '<div class="fld"><label>Número Cuenta</label><input id="b-num"></div>' +
    '<div class="fld"><label>Titular</label><input id="b-titular"></div>' +
    '<div class="fld"><label>RUT Titular</label><input id="b-rut"></div>' +
    '<div class="fld"><label>Correo Pagos</label><input id="b-mail" type="email"></div>' +
    '<div class="fld" style="display:flex;align-items:center;gap:8px;padding-top:18px;"><input type="checkbox" id="b-principal"> <label style="margin:0;">Cuenta principal</label></div>' +
    '</div>' +
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelBancoForm()" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveBanco(' + idProv + ')" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(form, pane.firstChild.nextSibling);
  $('b-banco').focus();
}

function cancelBancoForm() { var f=$('banco-form'); if(f) f.remove(); }

function saveBanco(idProv) {
  var d = {
    accion: 'banco_crear',
    id_proveedor: idProv,
    banco: $('b-banco').value,
    tipo_cuenta: $('b-tipo').value,
    numero_cuenta: $('b-num').value,
    titular: $('b-titular').value,
    rut_titular: $('b-rut').value,
    correo_pagos: $('b-mail').value,
    principal: $('b-principal').checked
  };
  if (!d.banco) { toast('Banco obligatorio','err'); return; }
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status===201) { toast('Cuenta agregada'); showProfile(_profileId); }
    else { try { var e=JSON.parse(xhr.responseText); toast(e.error||'Error','err'); } catch(e2) { toast('Error','err'); } }
  };
  xhr.send(JSON.stringify(d));
}

function delBanco(id) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status===200) { toast('Cuenta eliminada'); showProfile(_profileId); }
    else { toast('Error','err'); }
  };
  xhr.send(JSON.stringify({accion:'banco_eliminar', id_banco:id}));
}

/* ── Producto asociar ── */
function showAddProducto(idProv) {
  var pane = $('ptab-productos');
  var form = document.createElement('div');
  form.id = 'prod-form';
  form.style.cssText = 'background:#f8fafc;padding:14px;border-radius:12px;margin-bottom:12px;border:1.5px solid #e2e8f0;';
  form.innerHTML =
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-box" style="color:#4f46e5;"></i> Asociar Producto</div>' +
    '<div class="gr2">' +
    '<div class="fld"><label>Producto (ID) *</label><input id="p-id" type="number" placeholder="ID del producto en inventario"></div>' +
    '<div class="fld"><label>SKU Proveedor</label><input id="p-sku"></div>' +
    '<div class="fld"><label>Precio Compra</label><input id="p-precio" type="number" min="0"></div>' +
    '<div class="fld"><label>Marca</label><input id="p-marca"></div>' +
    '</div>' +
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelProdForm()" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveProducto(' + idProv + ')" style="flex:1;"><i class="fas fa-save"></i> Asociar</button></div>';
  pane.insertBefore(form, pane.firstChild.nextSibling);
  $('p-id').focus();
}

function cancelProdForm() { var f=$('prod-form'); if(f) f.remove(); }

function saveProducto(idProv) {
  var d = {
    accion: 'producto_asociar',
    id_proveedor: idProv,
    id_producto: parseInt($('p-id').value),
    sku_proveedor: $('p-sku').value,
    precio_compra: parseInt($('p-precio').value)||0,
    marca: $('p-marca').value
  };
  if (!d.id_producto) { toast('ID de producto obligatorio','err'); return; }
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status===201) { toast('Producto asociado'); showProfile(_profileId); }
    else { try { var e=JSON.parse(xhr.responseText); toast(e.error||'Error','err'); } catch(e2) { toast('Error','err'); } }
  };
  xhr.send(JSON.stringify(d));
}

function delProductoAsoc(id) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/proveedores.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status===200) { toast('Producto desasociado'); showProfile(_profileId); }
    else { toast('Error','err'); }
  };
  xhr.send(JSON.stringify({accion:'producto_eliminar', id_prov_producto:id}));
}