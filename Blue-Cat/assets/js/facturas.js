/* ── Facturación - Blue-Cat ── */
var currentPage = 1;
var searchTimer = null;

document.addEventListener('DOMContentLoaded', function() {
  loadKPIs();
  loadFacturas();
  loadClientes();
});

/* ── Toast ── */
function showToast(msg, type) {
  var t = document.createElement('div');
  t.className = 'toast toast-' + (type === 'error' ? 'err' : 'ok');
  t.innerHTML = msg;
  document.body.appendChild(t);
  requestAnimationFrame(function() { t.classList.add('show'); });
  setTimeout(function() { t.classList.remove('show'); setTimeout(function() { t.remove(); }, 300); }, 2500);
}

/* ── Number format ── */
function fm(n) { return '$' + Math.round(Number(n)).toLocaleString('es-CL'); }

/* ── KPIs ── */
function loadKPIs() {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/dashboard.php', true);
  xhr.onload = function() {
    if (xhr.status !== 200) return;
    var d = JSON.parse(xhr.responseText);
    document.getElementById('kpi-hoy-monto').textContent = fm(d.hoy_monto);
    document.getElementById('kpi-hoy-cant').textContent = d.hoy_cantidad + ' factura' + (d.hoy_cantidad !== 1 ? 's' : '');
    document.getElementById('kpi-mes-monto').textContent = fm(d.mes_monto);
    document.getElementById('kpi-mes-cant').textContent = d.mes_cantidad + ' factura' + (d.mes_cantidad !== 1 ? 's' : '');
    document.getElementById('kpi-pendientes-monto').textContent = fm(d.pendientes_monto);
    document.getElementById('kpi-pendientes-cant').textContent = d.pendientes_cantidad + ' factura' + (d.pendientes_cantidad !== 1 ? 's' : '');
    document.getElementById('kpi-vencidas-monto').textContent = fm(d.vencidas_monto);
    document.getElementById('kpi-vencidas-cant').textContent = d.vencidas_cantidad + ' factura' + (d.vencidas_cantidad !== 1 ? 's' : '');
    document.getElementById('kpi-pagadas-monto').textContent = fm(d.pagadas_monto);
    document.getElementById('kpi-pagadas-cant').textContent = d.pagadas_cantidad + ' factura' + (d.pagadas_cantidad !== 1 ? 's' : '');
    document.getElementById('kpi-clientes').textContent = d.total_clientes;
  };
  xhr.send();
}

/* ── Facturas table ── */
function loadFacturas(page) {
  if (page) currentPage = page;
  var q = document.getElementById('search-q').value;
  var estado = document.getElementById('filter-estado').value;
  var desde = document.getElementById('filter-desde').value;
  var hasta = document.getElementById('filter-hasta').value;
  var params = 'page=' + currentPage + '&limit=25';
  if (q) params += '&q=' + encodeURIComponent(q);
  if (estado) params += '&estado=' + encodeURIComponent(estado);
  if (desde) params += '&desde=' + encodeURIComponent(desde);
  if (hasta) params += '&hasta=' + encodeURIComponent(hasta);

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/facturas.php?' + params, true);
  xhr.onload = function() {
    if (xhr.status !== 200) return;
    var r = JSON.parse(xhr.responseText);
    renderTable(r.data, r.total, r.page, r.pages);
  };
  xhr.send();
}

function debounceSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(function() { currentPage = 1; loadFacturas(); }, 300);
}

function renderTable(data, total, page, pages) {
  var tbody = document.getElementById('facturas-tbody');
  var empty = document.getElementById('empty-msg');
  tbody.innerHTML = '';

  if (!data || data.length === 0) {
    empty.style.display = 'block';
    document.getElementById('pagination').innerHTML = '';
    return;
  }
  empty.style.display = 'none';

  for (var i = 0; i < data.length; i++) {
    var f = data[i];
    var badgeClass = 'badge-' + (f.estado || 'borrador').toLowerCase();
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td>' + f.id_factura + '</td>' +
      '<td><strong>' + (f.folio || '-') + '</strong></td>' +
      '<td>' + (f.razon_social || f.cliente_nombre || 'Sin cliente') + '</td>' +
      '<td>' + (f.rut || '-') + '</td>' +
      '<td><strong>' + fm(f.total) + '</strong></td>' +
      '<td>' + fm(f.pagado) + '</td>' +
      '<td>' + fm(f.saldo) + '</td>' +
      '<td><span class="badge ' + badgeClass + '">' + f.estado + '</span></td>' +
      '<td style="font-size:12px;color:#64748b;">' + (f.fecha_emision ? f.fecha_emision.substring(0,10) : '-') + '</td>' +
      '<td class="actions-cell" style="white-space:nowrap;">' +
      '<button class="btn-icon" onclick="showDetail(' + f.id_factura + ')" title="Ver detalle"><i class="fas fa-eye"></i></button>' +
      (f.estado !== 'ANULADA' ? '<button class="btn-icon" onclick="showPayModal(' + f.id_factura + ')" title="Registrar pago"><i class="fas fa-credit-card"></i></button>' : '') +
      (f.estado !== 'ANULADA' ? '<button class="btn-icon danger" onclick="anularFactura(' + f.id_factura + ')" title="Anular"><i class="fas fa-ban"></i></button>' : '') +
      '<button class="btn-icon" onclick="exportFactura(' + f.id_factura + ')" title="Exportar JSON"><i class="fas fa-download"></i></button>' +
      '</td>';
    tbody.appendChild(tr);
  }

  // Pagination
  var pg = document.getElementById('pagination');
  pg.innerHTML = '';
  if (pages <= 1) return;
  pg.innerHTML += '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="loadFacturas(1)"><i class="fas fa-angle-double-left"></i></button>';
  pg.innerHTML += '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="loadFacturas(' + (page - 1) + ')"><i class="fas fa-angle-left"></i></button>';
  for (var p = Math.max(1, page - 2); p <= Math.min(pages, page + 2); p++) {
    pg.innerHTML += '<button class="' + (p === page ? 'active' : '') + '" onclick="loadFacturas(' + p + ')">' + p + '</button>';
  }
  pg.innerHTML += '<button ' + (page >= pages ? 'disabled' : '') + ' onclick="loadFacturas(' + (page + 1) + ')"><i class="fas fa-angle-right"></i></button>';
  pg.innerHTML += '<button ' + (page >= pages ? 'disabled' : '') + ' onclick="loadFacturas(' + pages + ')"><i class="fas fa-angle-double-right"></i></button>';
  pg.innerHTML += '<span style="margin-left:10px;color:#64748b;font-size:12px;">' + total + ' facturas</span>';
}

/* ── Detail ── */
function showDetail(id) {
  var overlay = document.getElementById('detail-modal');
  var content = document.getElementById('detail-content');
  content.innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#4f46e5;"></i></div>';
  overlay.classList.add('show');
  overlay.onclick = function(e) { if (e.target === overlay) overlay.classList.remove('show'); };

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/facturas.php?id=' + id, true);
  xhr.onload = function() {
    if (xhr.status !== 200) { content.innerHTML = '<p>Error al cargar</p>'; return; }
    var f = JSON.parse(xhr.responseText);
    var badgeClass = 'badge-' + (f.estado || 'borrador').toLowerCase();

    var itemsHtml = '';
    for (var i = 0; i < (f.detalle || []).length; i++) {
      var d = f.detalle[i];
      itemsHtml += '<tr><td>' + d.producto + '</td><td>' + d.cantidad + '</td><td>' + fm(d.precio) + '</td><td>' + fm(d.total) + '</td></tr>';
    }
    var pagosHtml = '';
    for (var j = 0; j < (f.pagos || []).length; j++) {
      var p = f.pagos[j];
      pagosHtml += '<div class="historial-item"><span class="hi-action">' + p.metodo + '</span><span>' + fm(p.monto) + '</span><span class="hi-date">' + (p.fecha || '') + '</span></div>';
    }
    var histHtml = '';
    for (var k = 0; k < (f.historial || []).length; k++) {
      var h = f.historial[k];
      histHtml += '<div class="historial-item"><span class="hi-date">' + (h.fecha || '') + '</span><span class="hi-action">' + h.accion + '</span><span class="hi-user">' + (h.usuario || '') + '</span><span class="hi-detail">' + (h.valor_nuevo || '') + '</span></div>';
    }

    content.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
      '<h3 style="font-size:18px;font-weight:700;color:#1e293b;"><i class="fas fa-file-invoice" style="color:#4f46e5;"></i> Factura ' + f.numero + '</h3>' +
      '<span class="badge ' + badgeClass + '" style="font-size:13px;padding:4px 14px;">' + f.estado + '</span>' +
      '</div>' +
      '<div class="detail-grid">' +
      '<div class="detail-section"><h4><i class="fas fa-info-circle"></i> Información General</h4>' +
      '<div class="detail-row"><span class="dl">Folio</span><span class="dv">' + (f.folio || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Número</span><span class="dv">' + (f.numero || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Tipo</span><span class="dv">' + (f.tipo || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Emisión</span><span class="dv">' + (f.fecha_emision || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Vencimiento</span><span class="dv">' + (f.fecha_vencimiento || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Vendedor</span><span class="dv">' + (f.vendedor || '-') + '</span></div>' +
      '</div>' +
      '<div class="detail-section"><h4><i class="fas fa-user"></i> Cliente</h4>' +
      '<div class="detail-row"><span class="dl">RUT</span><span class="dv">' + (f.rut || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Razón Social</span><span class="dv">' + (f.razon_social || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Dirección</span><span class="dv">' + (f.direccion || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Correo</span><span class="dv">' + (f.correo || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Giro</span><span class="dv">' + (f.giro || '-') + '</span></div>' +
      '</div>' +
      '</div>' +
      '<h4 style="margin-top:14px;font-size:13px;font-weight:600;color:#1e293b;"><i class="fas fa-box"></i> Productos</h4>' +
      '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-top:6px;"><thead><tr style="background:#f8fafc;"><th style="padding:6px 8px;text-align:left;">Producto</th><th style="padding:6px 8px;text-align:left;">Cant</th><th style="padding:6px 8px;text-align:left;">Precio</th><th style="padding:6px 8px;text-align:left;">Total</th></tr></thead><tbody>' + itemsHtml + '</tbody></table>' +
      '<div style="text-align:right;margin-top:8px;"><strong style="font-size:16px;color:#4f46e5;">Total: ' + fm(f.total) + '</strong></div>' +
      (f.pagos && f.pagos.length ? '<h4 style="margin-top:14px;font-size:13px;font-weight:600;color:#1e293b;"><i class="fas fa-credit-card"></i> Pagos</h4>' + pagosHtml : '') +
      (f.historial && f.historial.length ? '<h4 style="margin-top:14px;font-size:13px;font-weight:600;color:#1e293b;"><i class="fas fa-history"></i> Historial</h4>' + histHtml : '') +
      '<button class="btn btn-primary" style="width:100%;justify-content:center;margin-top:16px;" onclick="document.getElementById(\'detail-modal\').classList.remove(\'show\')"><i class="fas fa-times"></i> Cerrar</button>';
  };
  xhr.send();
}

/* ── Create factura ── */
function showCreateModal() {
  var overlay = document.getElementById('create-modal');
  var content = document.getElementById('create-content');
  content.innerHTML =
    '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;"><i class="fas fa-plus-circle" style="color:#4f46e5;"></i> Nueva Factura</h3>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Cliente</label>' +
    '<select id="create-cliente" class="input" style="width:100%;"><option value="0">-- Sin cliente --</option></select></div>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Productos (desde POS)</label>' +
    '<select id="create-pedido" class="input" style="width:100%;"><option value="0">-- Seleccionar pedido --</option></select></div>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Método de pago</label>' +
    '<select id="create-pago" class="input" style="width:100%;"><option value="">Seleccionar</option><option value="Efectivo">Efectivo</option><option value="Tarjeta">Tarjeta</option><option value="Transferencia">Transferencia</option></select></div>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Observaciones</label>' +
    '<textarea id="create-obs" class="input" style="width:100%;resize:vertical;" rows="2"></textarea></div>' +
    '<div style="display:flex;gap:8px;margin-top:16px;">' +
    '<button class="modal-btn modal-btn-ghost" onclick="document.getElementById(\'create-modal\').classList.remove(\'show\')" style="flex:1;">Cancelar</button>' +
    '<button class="modal-btn modal-btn-primary" onclick="createFactura()" style="flex:1;"><i class="fas fa-save"></i> Crear Factura</button></div>';
  overlay.classList.add('show');
  overlay.onclick = function(e) { if (e.target === overlay) overlay.classList.remove('show'); };
  loadClientesSelect();
  loadPedidosSelect();
}

function loadClientesSelect() {
  var sel = document.getElementById('create-cliente');
  if (!sel) return;
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/clientes.php', true);
  xhr.onload = function() {
    if (xhr.status !== 200) return;
    var cs = JSON.parse(xhr.responseText);
    sel.innerHTML = '<option value="0">-- Sin cliente --</option>';
    for (var i = 0; i < cs.length; i++) {
      sel.innerHTML += '<option value="' + cs[i].id_cliente + '">' + (cs[i].razon_social || cs[i].nombre) + ' (' + (cs[i].rut || 'sin RUT') + ')</option>';
    }
  };
  xhr.send();
}

function loadPedidosSelect() {
  var sel = document.getElementById('create-pedido');
  if (!sel) return;
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/PHP/listar_ventas.php', true);
  xhr.onload = function() {
    if (xhr.status !== 200) return;
    var ps = JSON.parse(xhr.responseText);
    sel.innerHTML = '<option value="0">-- Seleccionar pedido --</option>';
    for (var i = 0; i < ps.length; i++) {
      sel.innerHTML += '<option value="' + ps[i].id_pedido + '">Pedido #' + ps[i].id_pedido + ' - $' + ps[i].precio_total + ' (' + ps[i].items.length + ' prod)</option>';
    }
  };
  xhr.send();
}

function createFactura() {
  var id_cliente = parseInt(document.getElementById('create-cliente').value);
  var id_pedido = parseInt(document.getElementById('create-pedido').value);
  var metodo_pago = document.getElementById('create-pago').value;
  var observaciones = document.getElementById('create-obs').value;

  if (!id_pedido) { showToast('Seleccione un pedido', 'error'); return; }

  // Get pedido details from the option text - better to fetch from API
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/PHP/listar_ventas.php', true);
  xhr.onload = function() {
    if (xhr.status !== 200) { showToast('Error al obtener pedido', 'error'); return; }
    var pedidos = JSON.parse(xhr.responseText);
    var pedido = null;
    for (var i = 0; i < pedidos.length; i++) {
      if (pedidos[i].id_pedido === id_pedido) { pedido = pedidos[i]; break; }
    }
    if (!pedido) { showToast('Pedido no encontrado', 'error'); return; }

    var items = [];
    for (var j = 0; j < pedido.items.length; j++) {
      var it = pedido.items[j];
      items.push({
        id_producto: it.id_producto,
        producto: it.nombre,
        cantidad: it.cantidad,
        precio: Math.round(it.precio_total / it.cantidad)
      });
    }

    var body = {
      accion: 'crear',
      id_cliente: id_cliente || null,
      id_pedido: id_pedido,
      tipo: 'FACTURA',
      metodo_pago: metodo_pago,
      observaciones: observaciones,
      items: items
    };

    var xhr2 = new XMLHttpRequest();
    xhr2.open('POST', '../assets/api/facturas.php', true);
    xhr2.setRequestHeader('Content-Type', 'application/json');
    xhr2.onload = function() {
      if (xhr2.status === 201) {
        var r = JSON.parse(xhr2.responseText);
        showToast('<i class="fas fa-check-circle"></i> Factura ' + r.numero + ' creada por ' + fm(r.total));
        document.getElementById('create-modal').classList.remove('show');
        loadFacturas();
        loadKPIs();
      } else {
        try { var er = JSON.parse(xhr2.responseText); showToast(er.error || 'Error', 'error'); } catch(e) { showToast('Error al crear factura', 'error'); }
      }
    };
    xhr2.send(JSON.stringify(body));
  };
  xhr.send();
}

/* ── Anular ── */
function anularFactura(id) {
  var overlay = document.getElementById('pay-modal');
  var content = document.getElementById('pay-content');
  content.innerHTML =
    '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:12px;"><i class="fas fa-ban" style="color:#dc2626;"></i> Anular Factura #' + id + '</h3>' +
    '<p style="color:#64748b;font-size:14px;margin-bottom:16px;">¿Estás seguro? Se restaurará el stock de los productos.</p>' +
    '<div style="display:flex;gap:8px;">' +
    '<button class="modal-btn modal-btn-ghost" onclick="document.getElementById(\'pay-modal\').classList.remove(\'show\')" style="flex:1;">Cancelar</button>' +
    '<button class="modal-btn modal-btn-danger" onclick="confirmAnular(' + id + ')" style="flex:1;"><i class="fas fa-ban"></i> Anular</button></div>';
  overlay.classList.add('show');
  overlay.onclick = function(e) { if (e.target === overlay) overlay.classList.remove('show'); };
}

function confirmAnular(id) {
  document.getElementById('pay-modal').classList.remove('show');
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/facturas.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status === 200) {
      var r = JSON.parse(xhr.responseText);
      showToast(r.msg || 'Factura anulada');
      loadFacturas();
      loadKPIs();
    } else {
      try { var er = JSON.parse(xhr.responseText); showToast(er.error || 'Error', 'error'); } catch(e) { showToast('Error', 'error'); }
    }
  };
  xhr.send(JSON.stringify({ accion: 'anular', id_factura: id, motivo: 'Anulación manual' }));
}

/* ── Pagos ── */
function showPayModal(id) {
  var overlay = document.getElementById('pay-modal');
  var content = document.getElementById('pay-content');
  content.innerHTML =
    '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;"><i class="fas fa-credit-card" style="color:#4f46e5;"></i> Registrar Pago - Factura #' + id + '</h3>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Método</label>' +
    '<select id="pay-metodo" class="input" style="width:100%;"><option value="Efectivo">Efectivo</option><option value="Tarjeta">Tarjeta</option><option value="Transferencia">Transferencia</option></select></div>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Monto</label>' +
    '<input type="number" id="pay-monto" class="input" style="width:100%;" min="1"></div>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Referencia</label>' +
    '<input type="text" id="pay-ref" class="input" style="width:100%;"></div>' +
    '<div style="display:flex;gap:8px;margin-top:16px;">' +
    '<button class="modal-btn modal-btn-ghost" onclick="document.getElementById(\'pay-modal\').classList.remove(\'show\')" style="flex:1;">Cancelar</button>' +
    '<button class="modal-btn modal-btn-primary" onclick="registrarPago(' + id + ')" style="flex:1;"><i class="fas fa-check"></i> Registrar</button></div>';
  overlay.classList.add('show');
  overlay.onclick = function(e) { if (e.target === overlay) overlay.classList.remove('show'); };
  document.getElementById('pay-monto').focus();
}

function registrarPago(id) {
  var metodo = document.getElementById('pay-metodo').value;
  var monto = parseInt(document.getElementById('pay-monto').value);
  var ref = document.getElementById('pay-ref').value;
  if (!monto || monto <= 0) { showToast('Ingrese un monto válido', 'error'); return; }

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/facturas.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status === 200) {
      showToast('<i class="fas fa-check-circle"></i> Pago registrado');
      document.getElementById('pay-modal').classList.remove('show');
      loadFacturas();
      loadKPIs();
    } else {
      try { var er = JSON.parse(xhr.responseText); showToast(er.error || 'Error', 'error'); } catch(e) { showToast('Error', 'error'); }
    }
  };
  xhr.send(JSON.stringify({ accion: 'pagar', id_factura: id, metodo: metodo, monto: monto, referencia: ref }));
}

/* ── Export ── */
function exportFactura(id) {
  window.open('../assets/api/exportar.php?id=' + id + '&formato=json', '_blank');
}

function exportCSV() {
  var estado = document.getElementById('filter-estado').value;
  var desde = document.getElementById('filter-desde').value;
  var hasta = document.getElementById('filter-hasta').value;
  var url = '../assets/api/exportar.php?formato=csv&tipo=facturas';
  if (estado) url += '&estado=' + encodeURIComponent(estado);
  if (desde) url += '&desde=' + encodeURIComponent(desde);
  if (hasta) url += '&hasta=' + encodeURIComponent(hasta);
  window.open(url, '_blank');
}

/* ── Client modal ── */
function loadClientes() {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/clientes.php', true);
  xhr.onload = function() {
    if (xhr.status !== 200) return;
    window._clientes = JSON.parse(xhr.responseText);
  };
  xhr.send();
}