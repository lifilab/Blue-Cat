var API_VENTAS = '../assets/api/ventas.php';
var API_CORE = '../assets/api/core.php';
var API_POS = '../assets/api/pos.php';
var _currentPage = 1;
var _currentPeriodo = 'hoy';
var _currentView = 'lista';
var _searchTimer = null;
var _permisos = { exportar: false, solicitarCorreccion: false };
var _ventasData = [];

document.addEventListener('DOMContentLoaded', function () {
  loadPermisos();
  setPeriodo('hoy');
  loadFilters();
  loadSesiones();
});

function apiVentasGet(accion, params, cb) {
  var url = API_VENTAS + '?accion=' + encodeURIComponent(accion);
  var keys = Object.keys(params || {});
  for (var i = 0; i < keys.length; i++) {
    var k = keys[i];
    var v = params[k];
    if (v !== null && v !== undefined && v !== '') {
      url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
    }
  }
  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.onload = function () {
    if (xhr.status >= 200 && xhr.status < 300) {
      try { cb(JSON.parse(xhr.responseText)); } catch (e) { toast('Error al procesar respuesta', 'err'); }
    } else {
      try {
        var err = JSON.parse(xhr.responseText);
        toast(err.error || err.mensaje || 'Error ' + xhr.status, 'err');
      } catch (e2) { toast('Error ' + xhr.status, 'err'); }
    }
  };
  xhr.onerror = function () { toast('Error de conexión', 'err'); };
  xhr.send();
}

function apiPosPost(data, cb) {
  var payload = Object.assign({}, data || {});
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API_POS, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function () {
    var response = {};
    try { response = JSON.parse(xhr.responseText); } catch (e) {
      toast('Error al procesar respuesta', 'err');
      return;
    }
    if (xhr.status >= 200 && xhr.status < 300) {
      if (typeof cb === 'function') cb(response);
      return;
    }
    if (window.SupervisorApproval && window.SupervisorApproval.handle(response, function (token) {
      payload.supervisor_token = token;
      apiPosPost(payload, cb);
    })) return;
    toast(response.message || response.error || response.mensaje || 'Error del servidor', 'err');
  };
  xhr.onerror = function () { toast('Error de conexión', 'err'); };
  xhr.send(JSON.stringify(payload));
}

function toast(msg, type) {
  var t = document.createElement('div');
  t.className = 'toast toast-' + (type === 'err' ? 'err' : 'ok');
  BlueCatSecurity.renderToast(t, msg, type);
  document.body.appendChild(t);
  requestAnimationFrame(function () { t.classList.add('show'); });
  setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2500);
}

function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function num(n) { return parseInt(n) || 0; }
function fmt(n) { if (n === null || n === undefined) return '$0'; return '$' + Math.round(Number(n)).toLocaleString('es-CL'); }

function $(id) { return document.getElementById(id); }

function loadPermisos() {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API_CORE, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function () {
    try {
      var d = JSON.parse(xhr.responseText);
      var ventasPermisos = (d.permisos && d.permisos.ventas) ? d.permisos.ventas : [];
      var posPermisos = (d.permisos && d.permisos.pos) ? d.permisos.pos : [];
      _permisos.exportar = ventasPermisos.indexOf('exportar') !== -1;
      // El backend POS exige pos.ver antes de iniciar cualquier corrección.
      // Quien no tenga ese acceso conserva esta pantalla estrictamente de lectura.
      _permisos.solicitarCorreccion = posPermisos.indexOf('ver') !== -1;
      applyPermisos();
    } catch (e) { applyPermisos(); }
  };
  xhr.onerror = function () { applyPermisos(); };
  xhr.send(JSON.stringify({ accion: 'sidebar' }));
}

function applyPermisos() {
  var exportBar = $('export-bar');
  if (exportBar) exportBar.style.display = _permisos.exportar ? 'flex' : 'none';
  // Permisos y listado se cargan en paralelo. Si las ventas llegaron primero,
  // vuelve a pintar las acciones sin exigir una recarga manual.
  if (_ventasData.length) renderTable(_ventasData);
}

function setPeriodo(periodo) {
  _currentPeriodo = periodo;
  _currentPage = 1;

  var btns = document.querySelectorAll('.period-btn');
  for (var i = 0; i < btns.length; i++) {
    btns[i].classList.remove('active');
    if (btns[i].getAttribute('data-periodo') === periodo) btns[i].classList.add('active');
  }

  var custom = $('custom-dates');
  if (periodo === 'personalizado') {
    custom.classList.add('show');
  } else {
    custom.classList.remove('show');
    var ahora = new Date();
    var desde = '';
    var hasta = '';
    switch (periodo) {
      case 'ayer':
        var ayer = new Date(ahora);
        ayer.setDate(ayer.getDate() - 1);
        desde = formatDate(ayer);
        hasta = formatDate(ayer);
        break;
      case 'esta_semana':
        var diaSem = ahora.getDay() || 7;
        var lun = new Date(ahora);
        lun.setDate(ahora.getDate() - diaSem + 1);
        desde = formatDate(lun);
        hasta = formatDate(ahora);
        break;
      case 'este_mes':
        desde = ahora.getFullYear() + '-' + pad(ahora.getMonth() + 1) + '-01';
        hasta = formatDate(ahora);
        break;
      case 'este_ano':
        desde = ahora.getFullYear() + '-01-01';
        hasta = formatDate(ahora);
        break;
      default:
        desde = formatDate(ahora);
        hasta = formatDate(ahora);
        break;
    }
    $('fecha-desde').value = desde;
    $('fecha-hasta').value = hasta;
  }

  loadVentas();
}

function pad(n) { return n < 10 ? '0' + n : '' + n; }

function formatDate(d) {
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

function doSearch() {
  clearTimeout(_searchTimer);
  _searchTimer = setTimeout(function () { _currentPage = 1; loadVentas(); }, 300);
}

function loadVentas(page) {
  if (page) _currentPage = page;

  var busqueda = ($('search-ventas').value || '').trim();
  var id_user = $('filter-empleado').value || '';
  var metodo = $('filter-metodo').value || '';
  var estado = $('filter-estado').value || '';
  var desde = $('fecha-desde').value || '';
  var hasta = $('fecha-hasta').value || '';

  var params = {
    page: _currentPage,
    limit: 25,
    periodo: _currentPeriodo,
    busqueda: busqueda,
    id_user: id_user,
    metodo: metodo,
    estado: estado,
    desde: desde,
    hasta: hasta
  };

  apiVentasGet('listar', params, function (r) {
    var ventas = r.ventas || [];
    var total = r.total || 0;
    var pagina = r.pagina || _currentPage;
    var paginas = r.paginas || 1;
    _ventasData = ventas;
    renderTable(ventas);
    renderSummary(r.resumen || {});
    renderPagination(total, pagina, paginas);
    if (_currentView === 'tarjetas') renderCardsView(ventas);
    if (_currentView === 'graficos') renderChartsView(ventas, r.resumen || {});
  });
}

function loadSummary() {
  var desde = $('fecha-desde').value || '';
  var hasta = $('fecha-hasta').value || '';
  var id_user = $('filter-empleado').value || '';
  var estado = $('filter-estado').value || '';

  apiVentasGet('resumen', { periodo: _currentPeriodo, desde: desde, hasta: hasta, id_user: id_user, estado: estado }, function (r) {
    renderSummary(r);
  });
}

function renderTable(items) {
  var tbody = $('ventas-tbody');
  var empty = $('ventas-empty');
  tbody.innerHTML = '';

  if (!items || items.length === 0) {
    empty.style.display = 'block';
    return;
  }
  empty.style.display = 'none';

  for (var i = 0; i < items.length; i++) {
    var v = items[i];
    var isAnulada = v.anulado === 1 || v.anulado === '1';
    var rowClass = 'main-row' + (isAnulada ? ' anulada' : '');
    var strikeClass = isAnulada ? ' strike' : '';
    var id = v.id_pedido || v.id_venta || v.id;

    var itemsCount = (v.items || []).length;
    var itemsPreview = '';
    var itemsList = v.items || [];
    for (var j = 0; j < Math.min(itemsList.length, 2); j++) {
      var it = itemsList[j];
      itemsPreview += '<span style="display:block;font-size:12px;' + (isAnulada ? 'text-decoration:line-through;' : '') + '">' + esc(it.nombre_producto || it.nombre || it.producto || it.descripcion) + ' x' + (it.cantidad_pedida || it.cantidad || 1) + '</span>';
    }
    if (itemsList.length > 2) {
      itemsPreview += '<span style="font-size:11px;color:#94a3b8;' + (isAnulada ? 'text-decoration:line-through;' : '') + '">+' + (itemsList.length - 2) + ' más</span>';
    }

    var pagosPreview = '';
    var pagosList = v.pagos || [];
    for (var k = 0; k < pagosList.length; k++) {
      var pg = pagosList[k];
      pagosPreview += '<span style="display:block;font-size:12px;' + (isAnulada ? 'text-decoration:line-through;' : '') + '">' + (pg.nombre_metodo_pago || pg.metodo || pg.metodo_pago || '—') + ': ' + fmt(pg.monto) + '</span>';
    }

    var estadoBadge = '';
    if (isAnulada) estadoBadge = '<span class="badge-anulada">ANULADA</span>';
    else estadoBadge = '<span class="badge badge-success">COMPLETADA</span>';

    var fechaHora = '';
    if (v.fecha) {
      try {
        var fd = new Date(v.fecha);
        fechaHora = fd.toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' +
                    fd.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
      } catch (e) { fechaHora = v.fecha; }
    }

    var puedeCorregir = _permisos.solicitarCorreccion && !isAnulada;
    var totalmenteDevuelta = v.devuelto === 1 || v.devuelto === '1';

    var tr = document.createElement('tr');
    tr.className = rowClass;
    tr.setAttribute('data-id', id);
    tr.onclick = function (e) {
      if (e.target.closest('button') || e.target.closest('a') || e.target.closest('.btn')) return;
      toggleDetail(this.getAttribute('data-id'));
    };

    tr.innerHTML =
      '<td class="' + strikeClass + '"><strong>' + esc(String(id || '—')) + '</strong></td>' +
      '<td class="' + strikeClass + '" style="font-size:12px;color:#64748b;">' + fechaHora + '</td>' +
      '<td class="' + strikeClass + '">' + esc(v.empleado || v.nombre_empleado || v.vendedor || '—') + '</td>' +
      '<td class="' + strikeClass + '">' + esc(v.cliente_nombre || v.cliente || v.nombre_cliente || 'Consumidor Final') + '</td>' +
      '<td class="' + strikeClass + '">' + itemsPreview + '</td>' +
      '<td class="' + strikeClass + '"><strong>' + fmt(v.precio_total || v.total || v.monto_total) + '</strong></td>' +
      '<td class="' + strikeClass + '">' + pagosPreview + '</td>' +
      '<td>' + estadoBadge + '</td>' +
      '<td style="white-space:nowrap;">' +
        (puedeCorregir ? '<button class="btn-icon danger" title="Anular con autorización" aria-label="Anular venta con autorización" onclick="event.stopPropagation();solicitarAnulacion(\'' + esc(String(id)) + '\')"><i class="fas fa-user-shield"></i></button>' : '') +
        (puedeCorregir && !totalmenteDevuelta ? '<button class="btn-icon" title="Devolver con autorización" aria-label="Devolver venta con autorización" onclick="event.stopPropagation();solicitarDevolucionTotal(\'' + esc(String(id)) + '\')"><i class="fas fa-undo"></i></button>' : '') +
        '<button class="btn-icon" title="Ver detalle" onclick="event.stopPropagation();toggleDetail(\'' + esc(String(id)) + '\')"><i class="fas fa-chevron-down"></i></button>' +
      '</td>';

    tbody.appendChild(tr);

    var detailTr = document.createElement('tr');
    detailTr.className = 'detail-row';
    detailTr.setAttribute('data-detail-id', id);
    detailTr.innerHTML = '<td colspan="9"><div class="detail-inner">' +
      '<div class="detail-box">' +
        '<h4><i class="fas fa-box"></i> Items (' + itemsCount + ')</h4>' +
        '<table><thead><tr><th>Producto</th><th>Cant</th><th>Precio Unit</th><th>Subtotal</th></tr></thead><tbody>' +
          buildItemsRows(itemsList) +
        '</tbody></table>' +
      '</div>' +
      '<div class="detail-box">' +
        '<h4><i class="fas fa-credit-card"></i> Pagos</h4>' +
        '<table><thead><tr><th>Método</th><th>Monto</th></tr></thead><tbody>' +
          buildPagosRows(pagosList) +
        '</tbody></table>' +
      '</div>' +
    '</div></td>';
    tbody.appendChild(detailTr);
  }
}

function buildItemsRows(items) {
  if (!items || !items.length) return '<tr><td colspan="4" style="color:#94a3b8;">Sin items</td></tr>';
  var html = '';
  for (var i = 0; i < items.length; i++) {
    var it = items[i];
    var nombre = it.nombre_producto || it.nombre || it.producto || it.descripcion || '—';
    var cant = it.cantidad_pedida || it.cantidad || 1;
    var subtotal = it.precio_total || it.total || it.subtotal || 0;
    var unitario = it.precio_unitario || it.precio || (cant > 0 ? Math.round(num(subtotal) / cant) : 0);
    html += '<tr>' +
      '<td>' + esc(nombre) + '</td>' +
      '<td>' + cant + '</td>' +
      '<td>' + fmt(unitario) + '</td>' +
      '<td><strong>' + fmt(subtotal) + '</strong></td>' +
    '</tr>';
  }
  return html;
}

function buildPagosRows(pagos) {
  if (!pagos || !pagos.length) return '<tr><td colspan="2" style="color:#94a3b8;">Sin pagos registrados</td></tr>';
  var html = '';
  for (var i = 0; i < pagos.length; i++) {
    var p = pagos[i];
    html += '<tr>' +
      '<td>' + esc(p.nombre_metodo_pago || p.metodo || p.metodo_pago || '—') + '</td>' +
      '<td><strong>' + fmt(p.monto) + '</strong></td>' +
    '</tr>';
  }
  return html;
}

function toggleDetail(id) {
  var row = document.querySelector('tr[data-detail-id="' + id + '"]');
  if (!row) return;
  if (row.classList.contains('show')) {
    row.classList.remove('show');
  } else {
    var allRows = document.querySelectorAll('tr.detail-row');
    for (var i = 0; i < allRows.length; i++) allRows[i].classList.remove('show');
    row.classList.add('show');
  }
}

function renderSummary(resumen) {
  if (!resumen) return;

  var totalVentas = num(resumen.total_ventas || 0);
  var totalMonto = num(resumen.total_monto || 0);
  var promedioTicket = num(resumen.promedio_ticket || 0);
  var anuladas = num(resumen.anulado_cant || 0);

  var porMetodo = resumen.por_metodo || {};
  var efectivo = num(porMetodo.Efectivo || porMetodo.EFECTIVO || 0);
  var tarjeta = num(porMetodo.Tarjeta || porMetodo.TARJETA || porMetodo['Crédito'] || porMetodo['Débito'] || porMetodo.CREDITO || porMetodo.DEBITO || 0);
  var transferencia = num(porMetodo.Transferencia || porMetodo.TRANSFERENCIA || 0);

  $('kpi-total').textContent = totalVentas;
  $('kpi-monto').textContent = fmt(totalMonto);
  $('kpi-ticket').textContent = fmt(promedioTicket);
  $('kpi-anuladas').textContent = anuladas;
  $('kpi-efectivo').textContent = fmt(efectivo);
  $('kpi-tarjeta').textContent = fmt(tarjeta);
  $('kpi-transferencia').textContent = fmt(transferencia);
}

function renderPagination(total, page, pages) {
  var pg = $('pagination');
  pg.innerHTML = '';
  if (pages <= 0) return;

  var start = (page - 1) * 25 + 1;
  var end = Math.min(page * 25, total);

  pg.innerHTML += '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="loadVentas(1)"><i class="fas fa-angle-double-left"></i></button>';
  pg.innerHTML += '<button ' + (page <= 1 ? 'disabled' : '') + ' onclick="loadVentas(' + (page - 1) + ')"><i class="fas fa-angle-left"></i></button>';
  pg.innerHTML += '<span>' + start + '–' + end + ' de ' + total + '</span>';
  pg.innerHTML += '<button ' + (page >= pages ? 'disabled' : '') + ' onclick="loadVentas(' + (page + 1) + ')"><i class="fas fa-angle-right"></i></button>';
  pg.innerHTML += '<button ' + (page >= pages ? 'disabled' : '') + ' onclick="loadVentas(' + pages + ')"><i class="fas fa-angle-double-right"></i></button>';
  pg.innerHTML += '<span style="margin-left:4px;">Pág ' + page + '/' + pages + '</span>';
}

function findSale(id) {
  for (var i = 0; i < _ventasData.length; i++) {
    if (Number(_ventasData[i].id_pedido) === Number(id)) return _ventasData[i];
  }
  return null;
}

function solicitarMotivoCorreccion(etiqueta) {
  var motivo = window.prompt(etiqueta);
  if (motivo === null) return null;
  motivo = motivo.trim();
  if (motivo.length < 3) {
    toast('El motivo debe tener al menos 3 caracteres', 'err');
    return null;
  }
  return motivo;
}

function solicitarAnulacion(id) {
  if (!_permisos.solicitarCorreccion) {
    toast('Esta cuenta solo puede consultar ventas', 'err');
    return;
  }
  var motivo = solicitarMotivoCorreccion('Motivo real de la anulación:');
  if (motivo === null) return;
  if (!confirm('¿Anular completamente la venta #' + id + '?\n\nLa operación requiere autorización y quedará auditada.')) return;
  apiPosPost({ accion: 'venta_anular', id_pedido: Number(id), motivo: motivo }, function (response) {
    toast(response.message || 'Venta anulada correctamente', 'ok');
    loadVentas();
  });
}

function solicitarDevolucionTotal(id) {
  if (!_permisos.solicitarCorreccion) {
    toast('Esta cuenta solo puede consultar ventas', 'err');
    return;
  }
  var sale = findSale(id);
  if (!sale) { toast('Venta no encontrada', 'err'); return; }
  var items = (sale.items || []).map(function (item) {
    var available = parseFloat(item.cantidad_disponible_devolucion !== undefined
      ? item.cantidad_disponible_devolucion
      : (item.cantidad_pedida || item.cantidad || 0)) || 0;
    if (available <= 0) return null;
    return {
      id_detalle_pedido: parseInt(item.id_detalle_pedido || 0),
      id_producto: parseInt(item.id_producto || 0),
      cantidad: available
    };
  }).filter(Boolean);
  if (!items.length) { toast('La venta no tiene productos pendientes de devolución', 'err'); return; }
  var motivo = solicitarMotivoCorreccion('Motivo real de la devolución:');
  if (motivo === null) return;
  if (!confirm('¿Procesar la devolución total pendiente de la venta #' + id + '?\n\nLa operación requiere autorización y quedará auditada.')) return;
  apiPosPost({
    accion: 'devolucion_crear',
    id_pedido: Number(id),
    tipo: 'TOTAL',
    motivo: motivo,
    items: items
  }, function (response) {
    toast('Devolución procesada: ' + fmt(response.monto_devuelto || 0), 'ok');
    loadVentas();
  });
}

function exportCSV() {
  if (!_permisos.exportar) { toast('No tiene permiso para exportar', 'err'); return; }
  var desde = $('fecha-desde').value || '';
  var hasta = $('fecha-hasta').value || '';
  var busqueda = ($('search-ventas').value || '').trim();
  var id_user = $('filter-empleado').value || '';
  var metodo = $('filter-metodo').value || '';
  var estado = $('filter-estado').value || '';

  var params = 'accion=exportar&periodo=' + encodeURIComponent(_currentPeriodo) +
    '&desde=' + encodeURIComponent(desde) +
    '&hasta=' + encodeURIComponent(hasta) +
    '&busqueda=' + encodeURIComponent(busqueda) +
    '&id_user=' + encodeURIComponent(id_user) +
    '&metodo=' + encodeURIComponent(metodo) +
    '&estado=' + encodeURIComponent(estado);

  var a = document.createElement('a');
  a.href = API_VENTAS + '?' + params;
  a.download = 'ventas_' + _currentPeriodo + '_' + (new Date().toISOString().substring(0, 10)) + '.xls';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

function loadSesiones() {
  apiVentasGet('sesiones', {}, function (d) {
    var sel = $('filter-empleado');
    var list = d || [];
    if (!Array.isArray(list)) return;
    for (var i = 0; i < list.length; i++) {
      var s = list[i];
      sel.innerHTML += '<option value="' + esc(s.id_user || s.id_sesion) + '">' + esc(s.empleado || 'Sesión ' + s.id_sesion || '') + '</option>';
    }
  });
}

function loadFilters() {
  var selMetodo = $('filter-metodo');
  var metodos = ['Efectivo', 'Tarjeta', 'Transferencia'];
  for (var i = 0; i < metodos.length; i++) {
    selMetodo.innerHTML += '<option value="' + metodos[i] + '">' + metodos[i] + '</option>';
  }
}

var closePopup = function () { $('cerrar-sesion-popup').style.display = 'none'; };

/* ═══ VIEW SWITCHER ═══ */
function switchView(view) {
  _currentView = view;
  var btns = document.querySelectorAll('.view-btn');
  for (var i = 0; i < btns.length; i++) {
    btns[i].classList.remove('active');
    if (btns[i].getAttribute('data-view') === view) btns[i].classList.add('active');
  }
  document.getElementById('view-lista').classList.remove('active');
  document.getElementById('view-graficos').classList.remove('active');
  document.getElementById('view-tarjetas').classList.remove('active');
  document.getElementById('view-' + view).classList.add('active');
  if (view === 'tarjetas') renderCardsView(_ventasData);
  if (view === 'graficos') renderChartsView(_ventasData, {});
}

/* ═══ CARDS VIEW ═══ */
function renderCardsView(ventas) {
  var container = $('view-tarjetas');
  if (!ventas || ventas.length === 0) {
    container.innerHTML = '<div class="empty-state"><i class="fas fa-shopping-cart"></i><p>No se encontraron ventas</p></div>';
    return;
  }
  var html = '';
  for (var i = 0; i < ventas.length; i++) {
    var v = ventas[i];
    var isAnulada = v.anulado === 1 || v.anulado === '1';
    var statusClass = isAnulada ? 'anulada' : 'completada';
    var statusText = isAnulada ? 'Anulada' : 'Completada';
    var itemsText = '';
    if (v.items && v.items.length > 0) {
      for (var j = 0; j < Math.min(v.items.length, 3); j++) {
        itemsText += (v.items[j].nombre_producto || 'Producto') + ' x' + v.items[j].cantidad_pedida;
        if (j < Math.min(v.items.length, 3) - 1) itemsText += ', ';
      }
      if (v.items.length > 3) itemsText += '...';
    }
    var paymentsHtml = '';
    if (v.pagos && v.pagos.length > 0) {
      for (var k = 0; k < v.pagos.length; k++) {
        paymentsHtml += '<span>' + esc(v.pagos[k].nombre_metodo_pago || '') + '</span>';
      }
    }
    var fecha = new Date(v.fecha);
    var fechaStr = fecha.toLocaleDateString('es-CL') + ' ' + fecha.toLocaleTimeString('es-CL', {hour: '2-digit', minute:'2-digit'});
    html += '<div class="sale-card' + (isAnulada ? ' anulada' : '') + '" onclick="openDetailModal(' + v.id_pedido + ')">' +
      '<div class="sale-card-header">' +
        '<span class="sale-card-id">#' + v.id_pedido + '</span>' +
        '<span class="sale-card-status ' + statusClass + '">' + statusText + '</span>' +
      '</div>' +
      '<div class="sale-card-client">' + esc(v.cliente_nombre || 'Consumidor Final') + '</div>' +
      '<div class="sale-card-date"><i class="far fa-clock"></i> ' + fechaStr + '</div>' +
      '<div class="sale-card-items">' + esc(itemsText || 'Sin items') + '</div>' +
      '<div class="sale-card-footer">' +
        '<span class="sale-card-total">' + fmt(v.precio_total) + '</span>' +
        '<div class="sale-card-payments">' + paymentsHtml + '</div>' +
      '</div>' +
    '</div>';
  }
  container.innerHTML = html;
}

/* ═══ CHARTS VIEW ═══ */
function renderChartsView(ventas, resumen) {
  renderVentasPorDia(ventas);
  renderMetodosPago(ventas);
  renderTopProductos(ventas);
  renderVentasPorEmpleado(ventas);
}

function renderVentasPorDia(ventas) {
  var container = $('chart-ventas-dia');
  if (!ventas || ventas.length === 0) {
    container.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px;">Sin datos</div>';
    return;
  }
  var dias = {};
  for (var i = 0; i < ventas.length; i++) {
    var fecha = new Date(ventas[i].fecha);
    var key = fecha.toLocaleDateString('es-CL', {day: '2-digit', month: '2-digit'});
    if (!dias[key]) dias[key] = {total: 0, count: 0};
    dias[key].total += parseInt(ventas[i].precio_total) || 0;
    dias[key].count++;
  }
  var keys = Object.keys(dias).slice(-7);
  var maxTotal = 0;
  for (var j = 0; j < keys.length; j++) {
    if (dias[keys[j]].total > maxTotal) maxTotal = dias[keys[j]].total;
  }
  var html = '';
  for (var k = 0; k < keys.length; k++) {
    var height = maxTotal > 0 ? Math.round((dias[keys[k]].total / maxTotal) * 180) : 0;
    html += '<div class="bar-item">' +
      '<div class="bar-value">' + fmt(dias[keys[k]].total) + '</div>' +
      '<div class="bar" style="height:' + height + 'px;" title="' + dias[keys[k]].count + ' ventas"></div>' +
      '<div class="bar-label">' + keys[k] + '</div>' +
    '</div>';
  }
  container.innerHTML = html;
}

function renderMetodosPago(ventas) {
  var container = $('chart-metodos-pago');
  if (!ventas || ventas.length === 0) {
    container.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px;">Sin datos</div>';
    return;
  }
  var metodos = {};
  var colors = {'Efectivo': '#10b981', 'Tarjeta': '#3b82f6', 'Transferencia': '#8b5cf6', 'Débito': '#f59e0b'};
  for (var i = 0; i < ventas.length; i++) {
    if (ventas[i].pagos) {
      for (var j = 0; j < ventas[i].pagos.length; j++) {
        var metodo = ventas[i].pagos[j].nombre_metodo_pago || 'Otro';
        if (!metodos[metodo]) metodos[metodo] = 0;
        metodos[metodo] += parseInt(ventas[i].pagos[j].monto) || 0;
      }
    }
  }
  var total = 0;
  var keys = Object.keys(metodos);
  for (var k = 0; k < keys.length; k++) total += metodos[keys[k]];
  var html = '<div style="position:relative;width:150px;height:150px;">' +
    '<svg viewBox="0 0 100 100" style="transform:rotate(-90deg);">' +
    '<circle cx="50" cy="50" r="40" fill="none" stroke="#e2e8f0" stroke-width="20"/>';
  var offset = 0;
  for (var m = 0; m < keys.length; m++) {
    var pct = total > 0 ? (metodos[keys[m]] / total) * 100 : 0;
    var color = colors[keys[m]] || '#6b7280';
    html += '<circle cx="50" cy="50" r="40" fill="none" stroke="' + color + '" stroke-width="20" ' +
      'stroke-dasharray="' + pct + ' ' + (100 - pct) + '" ' +
      'stroke-dashoffset="' + (-offset) + '"/>';
    offset += pct;
  }
  html += '</svg></div>';
  html += '<div class="donut-legend">';
  for (var n = 0; n < keys.length; n++) {
    var c = colors[keys[n]] || '#6b7280';
    var pctTotal = total > 0 ? Math.round((metodos[keys[n]] / total) * 100) : 0;
    html += '<div class="legend-item">' +
      '<div class="legend-color" style="background:' + c + ';"></div>' +
      '<span>' + keys[n] + ' - ' + fmt(metodos[keys[n]]) + ' (' + pctTotal + '%)</span>' +
    '</div>';
  }
  html += '</div>';
  container.innerHTML = html;
}

function renderTopProductos(ventas) {
  var container = $('chart-top-productos');
  if (!ventas || ventas.length === 0) {
    container.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px;">Sin datos</div>';
    return;
  }
  var productos = {};
  for (var i = 0; i < ventas.length; i++) {
    if (ventas[i].items) {
      for (var j = 0; j < ventas[i].items.length; j++) {
        var nombre = ventas[i].items[j].nombre_producto || 'Producto';
        if (!productos[nombre]) productos[nombre] = {cantidad: 0, total: 0};
        productos[nombre].cantidad += parseInt(ventas[i].items[j].cantidad_pedida) || 0;
        productos[nombre].total += parseInt(ventas[i].items[j].precio_total) || 0;
      }
    }
  }
  var items = Object.keys(productos).map(function(k) { return {nombre: k, cantidad: productos[k].cantidad, total: productos[k].total}; });
  items.sort(function(a, b) { return b.cantidad - a.cantidad; });
  items = items.slice(0, 5);
  var html = '';
  for (var k = 0; k < items.length; k++) {
    html += '<div class="stat-item">' +
      '<div><div class="stat-item-label">' + esc(items[k].nombre) + '</div>' +
      '<div style="font-size:11px;color:#94a3b8;">' + items[k].cantidad + ' unidades</div></div>' +
      '<div class="stat-item-value">' + fmt(items[k].total) + '</div>' +
    '</div>';
  }
  container.innerHTML = html || '<div style="text-align:center;color:#94a3b8;padding:40px;">Sin productos</div>';
}

function renderVentasPorEmpleado(ventas) {
  var container = $('chart-ventas-empleado');
  if (!ventas || ventas.length === 0) {
    container.innerHTML = '<div style="text-align:center;color:#94a3b8;padding:40px;">Sin datos</div>';
    return;
  }
  var empleados = {};
  for (var i = 0; i < ventas.length; i++) {
    var emp = ventas[i].empleado || 'Sin empleado';
    if (!empleados[emp]) empleados[emp] = {count: 0, total: 0};
    empleados[emp].count++;
    empleados[emp].total += parseInt(ventas[i].precio_total) || 0;
  }
  var items = Object.keys(empleados).map(function(k) { return {nombre: k, count: empleados[k].count, total: empleados[k].total}; });
  items.sort(function(a, b) { return b.total - a.total; });
  var html = '';
  for (var j = 0; j < items.length; j++) {
    html += '<div class="stat-item">' +
      '<div><div class="stat-item-label">' + esc(items[j].nombre) + '</div>' +
      '<div style="font-size:11px;color:#94a3b8;">' + items[j].count + ' ventas</div></div>' +
      '<div class="stat-item-value">' + fmt(items[j].total) + '</div>' +
    '</div>';
  }
  container.innerHTML = html || '<div style="text-align:center;color:#94a3b8;padding:40px;">Sin empleados</div>';
}

function openDetailModal(id) {
  window.location.href = '../public/ventas.html?id=' + id;
}
