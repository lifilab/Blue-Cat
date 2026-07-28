/* ── Facturación - Blue-Cat ── */
var currentPage = 1;
var searchTimer = null;
var pendingPaymentAttempts = Object.create(null);
var pendingNoteAttempts = Object.create(null);

document.addEventListener('DOMContentLoaded', function() {
  loadKPIs();
  loadFacturas();
  loadClientes();
  applyFacturaPermissions();
});
document.addEventListener('bluecat:permissions-ready', function() {
  applyFacturaPermissions();
  loadFacturas();
});

function hasFacturaPermission(action) {
  return typeof window.blueCatHasPermission === 'function' &&
    window.blueCatHasPermission('facturas', action);
}

function applyFacturaPermissions() {
  var createButton = document.getElementById('btn-create-factura');
  var exportButton = document.getElementById('btn-export-facturas');
  var legacyButton = document.getElementById('btn-legacy-reconciliation');
  if (createButton) createButton.hidden = !hasFacturaPermission('crear');
  if (exportButton) exportButton.hidden = !hasFacturaPermission('exportar');
  if (legacyButton) legacyButton.hidden = !hasFacturaPermission('editar');
}

/* ── Toast ── */
function showToast(msg, type) {
  var t = document.createElement('div');
  t.className = 'toast toast-' + (type === 'error' ? 'err' : 'ok');
  BlueCatSecurity.renderToast(t, msg, type);
  document.body.appendChild(t);
  requestAnimationFrame(function() { t.classList.add('show'); });
  setTimeout(function() { t.classList.remove('show'); setTimeout(function() { t.remove(); }, 300); }, 2500);
}

/* ── Number format ── */
function fm(n) {
  var value = Number(n || 0);
  if (!Number.isFinite(value)) value = 0;
  var hasCents = Math.round(value * 100) % 100 !== 0;
  return value.toLocaleString('es-CL', {
    style: 'currency',
    currency: 'CLP',
    minimumFractionDigits: hasCents ? 2 : 0,
    maximumFractionDigits: 2
  });
}
function esc(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
function apiErrorMessage(response, fallback) {
  if (response && typeof response.message === 'string' && response.message) return response.message;
  if (response && typeof response.error === 'string' && response.error) return response.error;
  return fallback || 'Error';
}

/* ── Conciliación administrativa de notas fiscales legacy ── */
function showLegacyReconciliation() {
  if (!hasFacturaPermission('editar')) {
    showToast('No tiene permiso para conciliar notas fiscales legacy', 'error');
    return;
  }
  var overlay = document.getElementById('legacy-modal');
  var content = document.getElementById('legacy-content');
  content.innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#4f46e5;"></i></div>';
  overlay.classList.add('show');
  overlay.onclick = function(event) {
    if (event.target === overlay) overlay.classList.remove('show');
  };

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/facturas.php?accion=legacy_pendientes', true);
  xhr.onload = function() {
    var response;
    try { response = JSON.parse(xhr.responseText || '{}'); } catch (error) { response = {}; }
    if (xhr.status !== 200) {
      content.innerHTML = '<p style="color:#dc2626;">' + esc(apiErrorMessage(response, 'No se pudieron cargar las conciliaciones pendientes')) + '</p>';
      return;
    }
    renderLegacyReconciliation(response.data || [], response.origenes || []);
  };
  xhr.onerror = function() {
    content.innerHTML = '<p style="color:#dc2626;">No fue posible conectar con el servidor local.</p>';
  };
  xhr.send();
}

function legacyOriginLabel(origin) {
  return '#' + Number(origin.id_factura || 0) + ' · ' +
    String(origin.numero || origin.folio || 'Sin número') + ' · ' +
    String(origin.razon_social || 'Sin cliente') + ' · ' + fm(origin.total);
}

function renderLegacyReconciliation(pendientes, origenes) {
  var content = document.getElementById('legacy-content');
  content.innerHTML = '';
  var header = document.createElement('div');
  header.style.cssText = 'display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px;';
  header.innerHTML =
    '<div><h3 style="font-size:18px;font-weight:700;color:#1e293b;margin:0 0 4px;"><i class="fas fa-link" style="color:#4f46e5;"></i> Conciliación fiscal legacy</h3>' +
    '<p style="font-size:12px;color:#64748b;margin:0;">Vincule solamente con evidencia verificable o confirme expresamente que la nota no tiene documento de origen.</p></div>' +
    '<button type="button" class="btn-icon" aria-label="Cerrar"><i class="fas fa-times"></i></button>';
  header.querySelector('button').addEventListener('click', function() {
    document.getElementById('legacy-modal').classList.remove('show');
  });
  content.appendChild(header);

  if (!pendientes.length) {
    var empty = document.createElement('div');
    empty.style.cssText = 'padding:30px;text-align:center;background:#f8fafc;border-radius:12px;color:#64748b;';
    empty.innerHTML = '<i class="fas fa-check-circle" style="display:block;font-size:28px;color:#059669;margin-bottom:8px;"></i>No hay notas legacy pendientes de conciliación.';
    content.appendChild(empty);
    return;
  }

  pendientes.forEach(function(note) {
    var noteId = Number(note.id_factura_nota || 0);
    var card = document.createElement('section');
    card.className = 'legacy-card';
    var candidates = Array.isArray(note.candidatos) ? note.candidatos : [];
    card.innerHTML =
      '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">' +
      '<div><strong>' + esc(note.tipo || 'NOTA') + ' ' + esc(note.numero || note.folio || ('#' + noteId)) + '</strong>' +
      '<div style="font-size:12px;color:#64748b;margin-top:3px;">Documento #' + noteId + ' · ' + esc(note.fecha_emision || '-') + ' · ' + fm(note.total) + '</div></div>' +
      '<span class="badge badge-parcial">' + esc(note.estado || 'PENDIENTE') + '</span></div>' +
      '<div style="font-size:12px;color:#475569;margin-top:9px;">' + esc(note.detalle || 'Revisión manual requerida') + '</div>' +
      '<label class="legacy-label" for="legacy-origin-' + noteId + '">FACTURA de origen</label>' +
      '<select class="input legacy-origin" id="legacy-origin-' + noteId + '"><option value="">Seleccione una factura...</option></select>' +
      '<div style="font-size:11px;color:#64748b;margin-top:4px;">Candidatos encontrados: ' + candidates.length + '. Una NC se rechazará si el detalle no es inequívoco o excede el original; una ND solo mapeará coincidencias únicas.</div>' +
      '<label class="legacy-label" for="legacy-reason-' + noteId + '">Motivo y evidencia de la decisión *</label>' +
      '<textarea class="input" id="legacy-reason-' + noteId + '" rows="2" maxlength="1000" placeholder="Indique la evidencia revisada y el motivo administrativo"></textarea>' +
      '<div class="legacy-actions"><button type="button" class="modal-btn modal-btn-ghost legacy-without">Confirmar sin origen</button>' +
      '<button type="button" class="modal-btn modal-btn-primary legacy-link"><i class="fas fa-link"></i> Vincular</button></div>';

    var select = card.querySelector('.legacy-origin');
    var added = Object.create(null);
    candidates.concat(origenes).forEach(function(origin) {
      var originId = Number(origin.id_factura_origen || origin.id_factura || 0);
      if (!originId || added[originId]) return;
      added[originId] = true;
      var option = document.createElement('option');
      option.value = String(originId);
      option.textContent = (origin.id_factura_origen ? 'Candidato · ' : '') + legacyOriginLabel({
        id_factura: originId,
        numero: origin.numero,
        folio: origin.folio,
        razon_social: origin.razon_social,
        total: origin.total
      });
      select.appendChild(option);
    });
    if (candidates.length === 1) {
      select.value = String(candidates[0].id_factura_origen || '');
    }
    card.querySelector('.legacy-link').addEventListener('click', function() {
      submitLegacyReconciliation(noteId, 'vincular');
    });
    card.querySelector('.legacy-without').addEventListener('click', function() {
      submitLegacyReconciliation(noteId, 'sin_origen');
    });
    content.appendChild(card);
  });
}

function submitLegacyReconciliation(noteId, decision) {
  if (!hasFacturaPermission('editar')) {
    showToast('No tiene permiso para conciliar notas fiscales legacy', 'error');
    return;
  }
  var reason = String(document.getElementById('legacy-reason-' + noteId).value || '').trim();
  var origin = Number(document.getElementById('legacy-origin-' + noteId).value || 0);
  if (!reason) {
    showToast('Debe registrar el motivo y la evidencia de la conciliación', 'error');
    return;
  }
  if (decision === 'vincular' && !origin) {
    showToast('Seleccione la FACTURA de origen', 'error');
    return;
  }
  if (decision === 'sin_origen' && !window.confirm('Esta decisión dejará la nota sin documento de origen. ¿Confirma que revisó la evidencia?')) {
    return;
  }
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/facturas.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    var response;
    try { response = JSON.parse(xhr.responseText || '{}'); } catch (error) { response = {}; }
    if (xhr.status >= 200 && xhr.status < 300 && response.success) {
      showToast(decision === 'vincular' ? 'Nota legacy vinculada correctamente' : 'Nota legacy confirmada sin origen', 'success');
      showLegacyReconciliation();
      loadFacturas();
      return;
    }
    showToast(apiErrorMessage(response, 'No se pudo conciliar la nota legacy'), 'error');
  };
  xhr.onerror = function() { showToast('No fue posible conectar con el servidor local', 'error'); };
  xhr.send(JSON.stringify({
    accion: 'legacy_conciliar',
    id_factura_nota: noteId,
    decision: decision,
    id_factura_origen: decision === 'vincular' ? origin : null,
    motivo: reason
  }));
}

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
    var actions = '';
    if (hasFacturaPermission('ver')) {
      actions += '<button class="btn-icon" onclick="showDetail(' + f.id_factura + ')" title="Ver detalle"><i class="fas fa-eye"></i></button>';
    }
    if (hasFacturaPermission('editar') && f.estado !== 'ANULADA' && Number(f.saldo || 0) > 0) {
      actions += '<button class="btn-icon" onclick="showPayModal(' + f.id_factura + ')" title="Registrar pago"><i class="fas fa-credit-card"></i></button>';
    }
    if (String(f.tipo || '').toUpperCase() === 'FACTURA' && f.estado !== 'ANULADA') {
      if (hasFacturaPermission('nota_credito')) {
        actions += '<button class="btn-icon" onclick="showCreditModal(' + f.id_factura + ')" title="Emitir nota de crédito"><i class="fas fa-undo-alt"></i></button>';
      }
      if (hasFacturaPermission('nota_debito')) {
        actions += '<button class="btn-icon" onclick="showDebitModal(' + f.id_factura + ')" title="Emitir nota de débito"><i class="fas fa-plus-circle"></i></button>';
      }
    }
    if (hasFacturaPermission('eliminar') && f.estado !== 'ANULADA') {
      actions += '<button class="btn-icon danger" onclick="anularFactura(' + f.id_factura + ')" title="Anular"><i class="fas fa-ban"></i></button>';
    }
    if (hasFacturaPermission('exportar')) {
      actions += '<button class="btn-icon" onclick="exportFactura(' + f.id_factura + ')" title="Exportar JSON"><i class="fas fa-download"></i></button>';
    }
    tr.innerHTML =
      '<td>' + f.id_factura + '</td>' +
      '<td><strong>' + esc(f.folio || '-') + '</strong></td>' +
      '<td>' + esc(f.razon_social || f.cliente_nombre || 'Sin cliente') + '</td>' +
      '<td>' + esc(f.rut || '-') + '</td>' +
      '<td><strong>' + fm(f.total) + '</strong></td>' +
      '<td>' + fm(f.pagado) + '</td>' +
      '<td>' + fm(f.saldo) + '</td>' +
      '<td><span class="badge ' + badgeClass + '">' + esc(f.estado) + '</span></td>' +
      '<td style="font-size:12px;color:#64748b;">' + (f.fecha_emision ? f.fecha_emision.substring(0,10) : '-') + '</td>' +
      '<td class="actions-cell" style="white-space:nowrap;">' +
      actions +
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
      itemsHtml += '<tr><td>' + esc(d.producto) + '</td><td>' + Number(d.cantidad || 0) + '</td><td>' + fm(d.precio_unitario !== undefined ? d.precio_unitario : d.precio) + '</td><td>' + fm(d.total) + '</td></tr>';
    }
    var pagosHtml = '';
    for (var j = 0; j < (f.pagos || []).length; j++) {
      var p = f.pagos[j];
      pagosHtml += '<div class="historial-item"><span class="hi-action">' + esc(p.metodo) + '</span><span>' + fm(p.monto) + '</span><span class="hi-date">' + esc(p.fecha || '') + '</span></div>';
    }
    var histHtml = '';
    for (var k = 0; k < (f.historial || []).length; k++) {
      var h = f.historial[k];
      histHtml += '<div class="historial-item"><span class="hi-date">' + esc(h.fecha || '') + '</span><span class="hi-action">' + esc(h.accion) + '</span><span class="hi-user">' + esc(h.usuario || '') + '</span><span class="hi-detail">' + esc(h.valor_nuevo || '') + '</span></div>';
    }

    content.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
      '<h3 style="font-size:18px;font-weight:700;color:#1e293b;"><i class="fas fa-file-invoice" style="color:#4f46e5;"></i> Factura ' + esc(f.numero) + '</h3>' +
      '<span class="badge ' + badgeClass + '" style="font-size:13px;padding:4px 14px;">' + esc(f.estado) + '</span>' +
      '</div>' +
      '<div class="detail-grid">' +
      '<div class="detail-section"><h4><i class="fas fa-info-circle"></i> Información General</h4>' +
      '<div class="detail-row"><span class="dl">Folio</span><span class="dv">' + esc(f.folio || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Número</span><span class="dv">' + esc(f.numero || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Tipo</span><span class="dv">' + esc(f.tipo || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Emisión</span><span class="dv">' + esc(f.fecha_emision || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Vencimiento</span><span class="dv">' + esc(f.fecha_vencimiento || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Vendedor</span><span class="dv">' + esc(f.vendedor || '-') + '</span></div>' +
      '</div>' +
      '<div class="detail-section"><h4><i class="fas fa-user"></i> Cliente</h4>' +
      '<div class="detail-row"><span class="dl">RUT</span><span class="dv">' + esc(f.rut || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Razón Social</span><span class="dv">' + esc(f.razon_social || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Dirección</span><span class="dv">' + esc(f.direccion || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Correo</span><span class="dv">' + esc(f.correo || '-') + '</span></div>' +
      '<div class="detail-row"><span class="dl">Giro</span><span class="dv">' + esc(f.giro || '-') + '</span></div>' +
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
    '<select id="create-pedido" class="input" style="width:100%;" onchange="syncFacturaCliente()"><option value="0">-- Seleccionar pedido --</option></select></div>' +
    '<div style="margin-bottom:10px;padding:10px 12px;background:#f8fafc;border-radius:8px;color:#64748b;font-size:12px;">El método de pago se obtiene automáticamente desde la venta POS.</div>' +
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
    var response = JSON.parse(xhr.responseText);
    var cs = Array.isArray(response) ? response : (response.items || []);
    replaceSelectOptions(sel, [{ value: '0', label: '-- Sin cliente --' }]);
    for (var i = 0; i < cs.length; i++) {
      appendSelectOption(
        sel,
        String(cs[i].id_cliente),
        String(cs[i].razon_social || cs[i].nombre || 'Cliente') + ' (' + String(cs[i].rut || 'sin RUT') + ')'
      );
    }
    syncFacturaCliente();
  };
  xhr.send();
}

function appendSelectOption(select, value, label, data) {
  var option = document.createElement('option');
  option.value = String(value);
  option.textContent = String(label);
  if (data) {
    Object.keys(data).forEach(function(key) {
      option.dataset[key] = String(data[key]);
    });
  }
  select.appendChild(option);
  return option;
}

function replaceSelectOptions(select, options) {
  while (select.firstChild) select.removeChild(select.firstChild);
  for (var i = 0; i < options.length; i++) {
    appendSelectOption(select, options[i].value, options[i].label, options[i].data);
  }
}

function loadPedidosSelect() {
  var sel = document.getElementById('create-pedido');
  if (!sel) return;
  replaceSelectOptions(sel, [{ value: '0', label: 'Cargando ventas pendientes...' }]);

  loadPedidosMaterializados(function(materializados, error) {
    if (error) {
      replaceSelectOptions(sel, [{ value: '0', label: 'No se pudo verificar qué ventas ya están facturadas' }]);
      return;
    }
    loadVentasElegibles(sel, materializados);
  });
}

function loadPedidosMaterializados(done, page, ids) {
  page = page || 1;
  ids = ids || Object.create(null);
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/facturas.php?tipo=FACTURA&limit=100&page=' + page, true);
  xhr.onload = function() {
    if (xhr.status !== 200) {
      done(ids, true);
      return;
    }

    var response;
    try {
      response = JSON.parse(xhr.responseText);
    } catch (e) {
      done(ids, true);
      return;
    }

    var facturas = response.data || [];
    for (var i = 0; i < facturas.length; i++) {
      var idPedido = Number(facturas[i].id_pedido || 0);
      if (idPedido > 0) ids[idPedido] = true;
    }

    if (page < Number(response.pages || 1)) {
      loadPedidosMaterializados(done, page + 1, ids);
      return;
    }
    done(ids, false);
  };
  xhr.onerror = function() { done(ids, true); };
  xhr.send();
}

function loadVentasElegibles(sel, materializados) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/ventas.php?accion=listar&limit=200', true);
  xhr.onload = function() {
    if (xhr.status !== 200) {
      replaceSelectOptions(sel, [{ value: '0', label: 'No se pudieron cargar las ventas' }]);
      return;
    }
    var response;
    try {
      response = JSON.parse(xhr.responseText);
    } catch (e) {
      replaceSelectOptions(sel, [{ value: '0', label: 'La respuesta de ventas no es válida' }]);
      return;
    }
    var ps = response.ventas || [];
    replaceSelectOptions(sel, [{ value: '0', label: '-- Seleccionar venta FACTURA --' }]);
    var disponibles = 0;
    for (var i = 0; i < ps.length; i++) {
      var idPedido = Number(ps[i].id_pedido || 0);
      if (
        String(ps[i].tipo_documento || '').toUpperCase() !== 'FACTURA' ||
        Number(ps[i].anulado || 0) === 1 ||
        materializados[idPedido]
      ) continue;
      disponibles++;
      appendSelectOption(
        sel,
        String(idPedido),
        String(ps[i].numero_documento || ('FACTURA #' + idPedido)) + ' - $' + String(ps[i].precio_total) + ' (' + (ps[i].items || []).length + ' prod)',
        { cliente: ps[i].id_cliente || 0 }
      );
    }
    if (!disponibles) replaceSelectOptions(sel, [{ value: '0', label: 'No hay ventas FACTURA pendientes' }]);
    syncFacturaCliente();
  };
  xhr.onerror = function() {
    replaceSelectOptions(sel, [{ value: '0', label: 'No se pudieron cargar las ventas' }]);
  };
  xhr.send();
}

function syncFacturaCliente() {
  var pedido = document.getElementById('create-pedido');
  var cliente = document.getElementById('create-cliente');
  if (!pedido || !cliente) return;
  var option = pedido.options[pedido.selectedIndex];
  var clientePedido = option ? parseInt(option.getAttribute('data-cliente') || '0', 10) : 0;
  if (clientePedido > 0) cliente.value = String(clientePedido);
  cliente.disabled = clientePedido > 0;
  cliente.title = clientePedido > 0 ? 'Cliente asociado a la venta POS' : '';
}

function createFactura() {
  var id_cliente = parseInt(document.getElementById('create-cliente').value);
  var id_pedido = parseInt(document.getElementById('create-pedido').value);
  var observaciones = document.getElementById('create-obs').value;

  if (!id_pedido) { showToast('Seleccione un pedido', 'error'); return; }

  var body = {
    accion: 'crear',
    id_cliente: id_cliente || null,
    id_pedido: id_pedido,
    tipo: 'FACTURA',
    observaciones: observaciones
  };

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/facturas.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status === 200 || xhr.status === 201) {
      var r = JSON.parse(xhr.responseText);
      showToast('<i class="fas fa-check-circle"></i> Factura ' + r.numero + ' creada por ' + fm(r.total));
      document.getElementById('create-modal').classList.remove('show');
      loadFacturas();
      loadKPIs();
    } else {
      try { var er = JSON.parse(xhr.responseText); showToast(apiErrorMessage(er, 'Error al crear factura'), 'error'); } catch(e) { showToast('Error al crear factura', 'error'); }
    }
  };
  xhr.send(JSON.stringify(body));
}

/* ── Anular ── */
function anularFactura(id) {
  var overlay = document.getElementById('pay-modal');
  var content = document.getElementById('pay-content');
  content.innerHTML =
    '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:12px;"><i class="fas fa-ban" style="color:#dc2626;"></i> Anular Factura #' + id + '</h3>' +
    '<p style="color:#64748b;font-size:14px;margin-bottom:16px;">¿Estás seguro? Se anulará el documento fiscal. El stock no cambia; una devolución de mercadería debe registrarse desde POS.</p>' +
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
      try { var er = JSON.parse(xhr.responseText); showToast(apiErrorMessage(er, 'Error al anular'), 'error'); } catch(e) { showToast('Error al anular', 'error'); }
    }
  };
  xhr.send(JSON.stringify({ accion: 'anular', id_factura: id, motivo: 'Anulación manual' }));
}

/* ── Notas de crédito y débito ── */
function showCreditModal(id) {
  var overlay = document.getElementById('pay-modal');
  var content = document.getElementById('pay-content');
  content.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i> Cargando factura...</div>';
  overlay.classList.add('show');
  overlay.onclick = function(e) { if (e.target === overlay) overlay.classList.remove('show'); };

  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/facturas.php?id=' + id, true);
  xhr.onload = function() {
    if (xhr.status !== 200) {
      content.innerHTML = '<p>No se pudo cargar el detalle de la factura.</p>';
      return;
    }
    var invoice = JSON.parse(xhr.responseText);
    var rows = '';
    var details = invoice.detalle || [];
    for (var i = 0; i < details.length; i++) {
      var detailId = Number(details[i].id_factura_detalle || 0);
      if (!detailId) continue;
      rows +=
        '<div style="display:grid;grid-template-columns:1fr 120px;gap:10px;align-items:end;margin-bottom:10px;">' +
        '<div><strong style="font-size:13px;">' + esc(details[i].producto || 'Producto') + '</strong>' +
        '<div style="font-size:11px;color:#64748b;">Vendido: ' + esc(details[i].cantidad) + ' · Total: ' + fm(details[i].total) + '</div></div>' +
        '<div><label style="font-size:11px;color:#475569;">Cantidad a acreditar</label>' +
        '<input class="input credit-quantity" data-detail="' + detailId + '" type="number" min="0" max="' + esc(details[i].cantidad) + '" step="0.001" value="0" style="width:100%;"></div></div>';
    }
    content.innerHTML =
      '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:14px;"><i class="fas fa-undo-alt" style="color:#4f46e5;"></i> Nota de crédito</h3>' +
      rows +
      '<div style="margin-top:12px;"><label style="font-size:12px;font-weight:600;color:#475569;">Motivo</label>' +
      '<textarea id="credit-reason" class="input" rows="2" maxlength="1000" style="width:100%;resize:vertical;">Devolución parcial</textarea></div>' +
      '<div style="display:flex;gap:8px;margin-top:16px;">' +
      '<button class="modal-btn modal-btn-ghost" onclick="document.getElementById(\'pay-modal\').classList.remove(\'show\')" style="flex:1;">Cancelar</button>' +
      '<button class="modal-btn modal-btn-primary" onclick="confirmCredit(' + id + ')" style="flex:1;"><i class="fas fa-check"></i> Emitir NC</button></div>';
  };
  xhr.send();
}

function showDebitModal(id) {
  var overlay = document.getElementById('pay-modal');
  var content = document.getElementById('pay-content');
  content.innerHTML =
    '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:14px;"><i class="fas fa-plus-circle" style="color:#4f46e5;"></i> Nota de débito</h3>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;">Monto</label>' +
    '<input id="debit-amount" class="input" type="number" min="0.01" step="0.01" inputmode="decimal" style="width:100%;"></div>' +
    '<div><label style="font-size:12px;font-weight:600;color:#475569;">Motivo</label>' +
    '<textarea id="debit-reason" class="input" rows="2" maxlength="1000" style="width:100%;resize:vertical;">Cargo adicional documentado</textarea></div>' +
    '<div style="display:flex;gap:8px;margin-top:16px;">' +
    '<button class="modal-btn modal-btn-ghost" onclick="document.getElementById(\'pay-modal\').classList.remove(\'show\')" style="flex:1;">Cancelar</button>' +
    '<button class="modal-btn modal-btn-primary" onclick="confirmDebit(' + id + ')" style="flex:1;"><i class="fas fa-check"></i> Emitir ND</button></div>';
  overlay.classList.add('show');
  overlay.onclick = function(e) { if (e.target === overlay) overlay.classList.remove('show'); };
  document.getElementById('debit-amount').focus();
}

function mutationIdempotencyKey(prefix) {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return prefix + '-' + window.crypto.randomUUID();
  }
  if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    var randomHex = Array.prototype.map.call(bytes, function(value) {
      return value.toString(16).padStart(2, '0');
    }).join('');
    return prefix + '-' + randomHex;
  }
  return prefix + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
}

function noteAttempt(type, id, fingerprint) {
  var key = type + ':' + id + ':' + fingerprint;
  if (!pendingNoteAttempts[key]) pendingNoteAttempts[key] = mutationIdempotencyKey(type);
  return pendingNoteAttempts[key];
}

function confirmCredit(id) {
  var inputs = document.querySelectorAll('.credit-quantity[data-detail]');
  var items = [];
  for (var i = 0; i < inputs.length; i++) {
    var raw = inputs[i].value.trim();
    if (raw === '' || Number(raw) === 0) continue;
    if (!/^\d+(?:\.\d{1,3})?$/.test(raw) || Number(raw) <= 0) {
      showToast('Las cantidades admiten hasta 3 decimales', 'error');
      return;
    }
    items.push({ id_factura_detalle: Number(inputs[i].dataset.detail), cantidad: raw });
  }
  if (!items.length) {
    showToast('Indique al menos una cantidad a acreditar', 'error');
    return;
  }
  var reason = document.getElementById('credit-reason').value.trim() || 'Nota de crédito';
  var fingerprint = JSON.stringify({ items: items, motivo: reason });
  var key = noteAttempt('nc', id, fingerprint);
  sendFiscalNote({
    accion: 'nota_credito',
    id_factura: id,
    items: items,
    motivo: reason,
    idempotency_key: key
  }, 'nc:' + id + ':' + fingerprint, 'Nota de crédito emitida');
}

function confirmDebit(id) {
  var amount = document.getElementById('debit-amount').value.trim();
  if (!/^\d+(?:\.\d{1,2})?$/.test(amount) || Number(amount) < 0.01) {
    showToast('Ingrese un monto válido con máximo 2 decimales', 'error');
    return;
  }
  var normalizedAmount = Number(amount).toFixed(2);
  var reason = document.getElementById('debit-reason').value.trim() || 'Nota de débito';
  var fingerprint = JSON.stringify({ monto: normalizedAmount, motivo: reason });
  var key = noteAttempt('nd', id, fingerprint);
  sendFiscalNote({
    accion: 'nota_debito',
    id_factura: id,
    monto: normalizedAmount,
    motivo: reason,
    idempotency_key: key
  }, 'nd:' + id + ':' + fingerprint, 'Nota de débito emitida');
}

function sendFiscalNote(body, pendingKey, successMessage) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/facturas.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    var response = {};
    try { response = JSON.parse(xhr.responseText); } catch (e) {}
    if (xhr.status === 200 || xhr.status === 201) {
      delete pendingNoteAttempts[pendingKey];
      document.getElementById('pay-modal').classList.remove('show');
      showToast(successMessage + ': ' + esc(response.numero || ''));
      loadFacturas();
      loadKPIs();
      return;
    }
    showToast(response.error || response.message || 'No se pudo emitir la nota fiscal', 'error');
  };
  xhr.onerror = function() {
    showToast('No se pudo confirmar la nota. Reintente; no se duplicará.', 'error');
  };
  xhr.send(JSON.stringify(body));
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
    '<input type="number" id="pay-monto" class="input" style="width:100%;" min="0.01" step="0.01" inputmode="decimal"></div>' +
    '<div style="margin-bottom:10px;"><label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Referencia</label>' +
    '<input type="text" id="pay-ref" class="input" style="width:100%;"></div>' +
    '<div style="display:flex;gap:8px;margin-top:16px;">' +
    '<button class="modal-btn modal-btn-ghost" onclick="document.getElementById(\'pay-modal\').classList.remove(\'show\')" style="flex:1;">Cancelar</button>' +
    '<button class="modal-btn modal-btn-primary" onclick="registrarPago(' + id + ')" style="flex:1;"><i class="fas fa-check"></i> Registrar</button></div>';
  overlay.classList.add('show');
  overlay.onclick = function(e) { if (e.target === overlay) overlay.classList.remove('show'); };
  document.getElementById('pay-monto').focus();
}

function paymentAttempt(id, metodo, monto, referencia) {
  var invoiceKey = String(id);
  var fingerprint = JSON.stringify({ metodo: metodo, monto: monto.toFixed(2), referencia: referencia });
  if (!pendingPaymentAttempts[invoiceKey]) pendingPaymentAttempts[invoiceKey] = Object.create(null);
  if (!pendingPaymentAttempts[invoiceKey][fingerprint]) {
    pendingPaymentAttempts[invoiceKey][fingerprint] = mutationIdempotencyKey('pay');
  }
  return pendingPaymentAttempts[invoiceKey][fingerprint];
}

function registrarPago(id) {
  var metodo = document.getElementById('pay-metodo').value;
  var montoRaw = document.getElementById('pay-monto').value.trim();
  var monto = parseFloat(montoRaw);
  var ref = document.getElementById('pay-ref').value.trim();
  if (!/^\d+(?:\.\d{1,2})?$/.test(montoRaw) || !Number.isFinite(monto) || monto < 0.01) {
    showToast('Ingrese un monto válido con máximo 2 decimales', 'error');
    return;
  }
  monto = Math.round(monto * 100) / 100;
  var idempotencyKey = paymentAttempt(id, metodo, monto, ref);

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/facturas.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status === 200) {
      delete pendingPaymentAttempts[String(id)];
      showToast('<i class="fas fa-check-circle"></i> Pago registrado');
      document.getElementById('pay-modal').classList.remove('show');
      loadFacturas();
      loadKPIs();
    } else {
      try { var er = JSON.parse(xhr.responseText); showToast(apiErrorMessage(er, 'Error al registrar el pago'), 'error'); } catch(e) { showToast('Error al registrar el pago', 'error'); }
    }
  };
  xhr.onerror = function() {
    showToast('No se pudo confirmar el pago. Reintente; no se duplicará.', 'error');
  };
  xhr.send(JSON.stringify({ accion: 'pagar', id_factura: id, metodo: metodo, monto: monto, referencia: ref, idempotency_key: idempotencyKey }));
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
    var response = JSON.parse(xhr.responseText);
    window._clientes = Array.isArray(response) ? response : (response.items || []);
  };
  xhr.send();
}
