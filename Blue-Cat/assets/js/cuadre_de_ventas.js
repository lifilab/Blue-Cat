var API_VENTAS = '../assets/api/ventas.php';
var API_POS = '../assets/api/pos.php';
var currentCuadreSessionId = 0;
var cuadrePermissions = { editar: false, eliminar: false };

/* ── Init ── */
document.addEventListener("DOMContentLoaded", function () {
  var fechaHoraActual = new Date();
  document.getElementById("fecha_cierre").textContent = formatDate(fechaHoraActual);
  loadCuadrePermissions();
  loadCuadre();
  setupCierreListener();
});

function loadCuadrePermissions() {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/core.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function () {
    try {
      var d = JSON.parse(xhr.responseText);
      var permisos = d.permisos && d.permisos.ventas ? d.permisos.ventas : [];
      cuadrePermissions.editar = permisos.indexOf('editar') !== -1;
      cuadrePermissions.eliminar = permisos.indexOf('eliminar') !== -1;
      renderSalesTable();
    } catch (e) { cuadrePermissions = { editar: false, eliminar: false }; }
  };
  xhr.send(JSON.stringify({ accion: 'sidebar' }));
}

function apiGet(url, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open("GET", url, true);
  xhr.onload = function () {
    if (xhr.status >= 200 && xhr.status < 300) {
      try { cb(JSON.parse(xhr.responseText)); } catch (e) { console.error('Parse error', xhr.responseText); }
    } else {
      try { var err = JSON.parse(xhr.responseText); showToast('<i class="fas fa-exclamation-circle"></i> ' + (err.error || 'Error')); }
      catch (e2) { showToast('<i class="fas fa-exclamation-circle"></i> Error ' + xhr.status); }
    }
  };
  xhr.onerror = function () { showToast('<i class="fas fa-exclamation-circle"></i> Error de conexión'); };
  xhr.send();
}

function apiPost(url, data, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open("POST", url, true);
  xhr.setRequestHeader("Content-Type", "application/json");
  xhr.onload = function () {
    try {
      var d = JSON.parse(xhr.responseText);
      if (xhr.status >= 200 && xhr.status < 300) {
        if (typeof cb === 'function') cb(d);
      } else {
        if (window.SupervisorApproval && window.SupervisorApproval.handle(d, function(token) {
          data.supervisor_token = token; apiPost(url, data, cb);
        })) return;

        showToast('<i class="fas fa-exclamation-circle"></i> ' + (d.error || 'Error del servidor'));
      }
    } catch (e) { showToast('<i class="fas fa-exclamation-circle"></i> Error al procesar respuesta'); }
  };
  xhr.onerror = function () { showToast('<i class="fas fa-exclamation-circle"></i> Error de conexión'); };
  xhr.send(JSON.stringify(data));
}

function loadCuadre() {
  apiGet(API_VENTAS + '?accion=cuadre', function (data) {
    document.getElementById("empleado").textContent = data.empleado || "--";
    document.getElementById("nota").textContent = data.nota || "--";
    document.getElementById("fecha_apertura").textContent = data.fecha_apertura || "--";
    setMoney("monto_apertura", data.monto_apertura);
    setMoney("efectivo", data.efectivo);
    setMoney("tarjeta", data.tarjeta);
    setMoney("credito", data.credito);
    setMoney("debito", data.debito);
    setMoney("transferencia", data.transferencia);
    setMoney("ingresos_efectivo", data.ingresos_efectivo);
    setMoney("retiros_efectivo", data.retiros_efectivo);
    setMoney("reversas_efectivo", data.reversas_efectivo);
    setMoney("monto_ventas", data.monto_ventas);
    document.getElementById("cantidad_ventas").textContent = data.cantidad_ventas || 0;
    setMoney("monto_total", data.monto_total);
    currentCuadreSessionId = data.id_sesion || 0;
    refreshSales();
    var montoReal = document.getElementById("monto-real-cierre");
    if (montoReal) montoReal.value = data.monto_total || 0;
    calcDiferenciaCierre();
  });
}

/* ── Difference inputs ── */
function setupCierreListener() {
  var inputDiferencia = document.getElementById("inputDiferencia");
  if (inputDiferencia) {
    inputDiferencia.addEventListener("input", function () {
      var valorInput = parseInt(inputDiferencia.value) || 0;
      var montoText = document.getElementById("monto_total").textContent.replace('$', '').replace(/\./g, '').replace(/,/g, '');
      var valorMontoTotal = parseInt(montoText) || 0;
      var diff = valorInput - valorMontoTotal;
      document.getElementById("diferencia").textContent = '$' + diff;
      document.getElementById("diferencia-calculada").textContent = '$' + diff;
    });
  }
  var montoReal = document.getElementById("monto-real-cierre");
  if (montoReal) {
    montoReal.addEventListener("input", calcDiferenciaCierre);
  }
}

function calcDiferenciaCierre() {
  var montoReal = parseInt(document.getElementById("monto-real-cierre").value) || 0;
  var montoText = document.getElementById("monto_total").textContent.replace('$', '').replace(/\./g, '').replace(/,/g, '');
  var montoTotal = parseInt(montoText) || 0;
  var diff = montoReal - montoTotal;
  var el = document.getElementById("diferencia-cierre");
  if (el) {
    if (diff === 0) {
      el.textContent = 'Cuadre perfecto';
      el.style.color = '#059669';
    } else if (diff > 0) {
      el.textContent = 'Sobrante: $' + diff;
      el.style.color = '#d97706';
    } else {
      el.textContent = 'Faltante: $' + Math.abs(diff);
      el.style.color = '#dc2626';
    }
  }
}

/* ── Helpers ── */
function formatDate(date) {
  var options = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' };
  return date.toLocaleDateString('es-ES', options);
}

function setMoney(id, val) {
  var el = document.getElementById(id);
  if (el) el.textContent = (val !== null && val !== undefined && val !== '') ? '$' + Math.round(Number(val)).toLocaleString('es-CL') : '$0';
}

function showToast(msg) {
  var t = document.createElement('div');
  t.className = 'pos-toast';
  t.innerHTML = msg;
  document.body.appendChild(t);
  requestAnimationFrame(function () { t.classList.add('show'); });
  setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2500);
}

/* ── Sales table ── */
var salesData = [];

function refreshSales() {
  var sessionFilter = currentCuadreSessionId ? '&id_sesion=' + encodeURIComponent(currentCuadreSessionId) : '';
  apiGet(API_VENTAS + '?accion=listar&limit=200' + sessionFilter, function (r) {
    salesData = r.ventas || [];
    renderSalesTable();
  });
}

function renderSalesTable() {
  var tbody = document.getElementById("sales-tbody");
  var noMsg = document.getElementById("no-sales-msg");
  tbody.innerHTML = "";

  if (!salesData || salesData.length === 0) {
    noMsg.style.display = "block";
    return;
  }
  noMsg.style.display = "none";

  for (var i = 0; i < salesData.length; i++) {
    var v = salesData[i];
    var tr = document.createElement("tr");
    tr.setAttribute("data-id", v.id_pedido);

    var itemsHtml = "";
    var items = v.items || [];
    for (var j = 0; j < items.length; j++) {
      var it = items[j];
      itemsHtml += '<small><strong>' + escapeHtml(it.nombre_producto || it.nombre || 'Producto') + '</strong> x' + (it.cantidad_pedida || it.cantidad || 1) + ' = $' + (it.precio_total || 0) + '</small>';
    }

    var pagosHtml = "";
    var pagos = v.pagos || [];
    for (var k = 0; k < pagos.length; k++) {
      var pg = pagos[k];
      pagosHtml += '<small>' + formatPaymentMethod(pg.nombre_metodo_pago || pg.metodo || 'Pago') + ': $' + (pg.monto || 0) + '</small>';
    }

    var anulado = v.anulado === 1 || v.anulado === '1';
    var anuladoStyle = anulado ? ' style="text-decoration:line-through;color:#94a3b8;"' : '';
    var anuladoBadge = anulado ? ' <span style="background:#fef2f2;color:#dc2626;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;">ANULADA</span>' : '';

    // A confirmed sale is immutable. Corrections use anulation or return so
    // stock, payments, cash and audit always move together.
    var canEdit = false;

    tr.innerHTML =
      '<td' + anuladoStyle + '>' + v.id_pedido + anuladoBadge + ' <span style="font-size:10px;color:#94a3b8;">' + (v.cliente_nombre || 'CF') + '</span></td>' +
      '<td class="items-cell"' + anuladoStyle + '>' + itemsHtml + '</td>' +
      '<td' + anuladoStyle + '><strong>$' + (v.precio_total || 0) + '</strong></td>' +
      '<td class="pagos-cell"' + anuladoStyle + '>' + pagosHtml + '</td>' +
      '<td style="font-size:12px;color:#64748b;">' + formatDateString(v.fecha) + '</td>' +
      '<td class="actions-cell">' +
        (canEdit ? '<button class="btn-sm btn-sm-edit" onclick="openEditModal(' + v.id_pedido + ')" title="Editar venta"><i class="fas fa-pen"></i> Editar</button>' : '') +
        (!anulado ? '<button class="btn-sm btn-sm-delete" onclick="solicitarAnulacion(' + v.id_pedido + ')" title="Requiere Supervisor"><i class="fas fa-user-shield"></i> Anular</button>' : '') +
        (!anulado && !(v.devuelto === 1 || v.devuelto === '1') ? '<button class="btn-sm btn-sm-return" onclick="solicitarDevolucionTotal(' + v.id_pedido + ')" title="Requiere Supervisor"><i class="fas fa-undo"></i> Devolver</button>' : '') +
      '</td>';

    tbody.appendChild(tr);
  }
}

function escapeHtml(str) {
  if (!str) return "";
  var div = document.createElement("div");
  div.appendChild(document.createTextNode(str));
  return div.innerHTML;
}

function formatPaymentMethod(method) {
  var labels = { EFECTIVO:'Efectivo', TARJETA_CREDITO:'Tarjeta crédito', TARJETA_DEBITO:'Tarjeta débito', TRANSFERENCIA:'Transferencia', OTRO:'Otro' };
  return labels[String(method || '').toUpperCase()] || escapeHtml(method || 'Pago');
}

function formatDateString(dateStr) {
  if (!dateStr) return "--";
  try {
    var d = new Date(dateStr);
    return d.toLocaleDateString('es-ES', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
  } catch (e) { return dateStr; }
}

/* ── Edit Sale ── */
var editingId = null;
var editItems = [];

function openEditModal(id) {
  if (!cuadrePermissions.editar) { showToast('<i class="fas fa-lock"></i> No tiene permiso para editar ventas'); return; }
  editingId = id;
  document.getElementById("edit-venta-id").textContent = id;

  var v = null;
  for (var i = 0; i < salesData.length; i++) {
    if (salesData[i].id_pedido === id) { v = salesData[i]; break; }
  }
  if (!v) return;

  editItems = [];
  var items = v.items || [];
  for (var j = 0; j < items.length; j++) {
    editItems.push({
      id_producto: items[j].id_producto,
      nombre: items[j].nombre_producto || items[j].nombre || 'Producto',
      cantidad: items[j].cantidad_pedida || items[j].cantidad || 1,
      precio_total: items[j].precio_total || 0,
      _removed: false
    });
  }

  renderEditItems();
  renderEditPagos(v.pagos || []);

  document.getElementById("edit-venta-modal").style.display = "flex";
  requestAnimationFrame(function () {
    var box = document.querySelector("#edit-venta-modal .pos-modal-box");
    if (box) box.classList.add("show");
  });
  document.getElementById("edit-venta-modal").addEventListener("click", function closeOut(e) {
    if (e.target === this) {
      this.style.display = "none";
      this.removeEventListener("click", closeOut);
    }
  });
}

function renderEditItems() {
  var list = document.getElementById("edit-items-list");
  list.innerHTML = "";
  var total = 0;

  for (var i = 0; i < editItems.length; i++) {
    var item = editItems[i];
    if (!item._removed) total += item.precio_total;

    var div = document.createElement("div");
    div.className = "edit-item-row" + (item._removed ? " edit-item-removed" : "");
    div.setAttribute("data-idx", i);

    div.innerHTML =
      '<span class="edit-item-name" title="' + escapeHtml(item.nombre) + '">' + escapeHtml(item.nombre) + '</span>' +
      '<div class="edit-item-qty-wrap"><span class="edit-item-qty-label">Cant</span><input type="number" class="edit-input edit-item-qty" value="' + item.cantidad + '" min="0" ' + (item._removed ? 'disabled' : '') + ' onchange="onEditItemChange(' + i + ')"></div>' +
      '<div class="edit-item-price-wrap"><span class="edit-item-price-label">Total $</span><input type="number" class="edit-input edit-item-price" value="' + item.precio_total + '" min="0" ' + (item._removed ? 'disabled' : '') + ' onchange="onEditItemChange(' + i + ')"></div>' +
      '<button class="edit-item-remove" onclick="toggleRemoveItem(' + i + ')" title="' + (item._removed ? 'Restaurar' : 'Devolver/Eliminar') + '"><i class="fas fa-' + (item._removed ? 'undo' : 'times') + '"></i></button>';

    list.appendChild(div);
  }

  var totalDiv = document.createElement("div");
  totalDiv.className = "edit-total-row";
  totalDiv.innerHTML = 'Total: <span class="total-value" id="edit-items-total">$' + total + '</span>';
  list.appendChild(totalDiv);
}

function onEditItemChange(idx) {
  var rows = document.querySelectorAll("#edit-items-list .edit-item-row");
  var row = rows[idx];
  if (!row || editItems[idx]._removed) return;

  var qty = parseInt(row.querySelector(".edit-item-qty").value) || 0;
  var price = parseInt(row.querySelector(".edit-item-price").value) || 0;

  editItems[idx].cantidad = qty;
  editItems[idx].precio_total = price;

  var total = 0;
  for (var i = 0; i < editItems.length; i++) {
    if (!editItems[i]._removed) total += editItems[i].precio_total;
  }
  document.getElementById("edit-items-total").textContent = '$' + total;
}

function toggleRemoveItem(idx) {
  editItems[idx]._removed = !editItems[idx]._removed;
  renderEditItems();
  onEditItemChange(idx);
}

function renderEditPagos(originalPagos) {
  var list = document.getElementById("edit-pagos-list");
  list.innerHTML = "";
  var metodos = ["Efectivo", "Tarjeta", "Transferencia"];

  for (var m = 0; m < metodos.length; m++) {
    var nombre = metodos[m];
    var existing = 0;
    for (var p = 0; p < originalPagos.length; p++) {
      var met = (originalPagos[p].nombre_metodo_pago || originalPagos[p].metodo || '').toLowerCase();
      if (met === nombre.toLowerCase()) { existing = originalPagos[p].monto; break; }
    }
    var div = document.createElement("div");
    div.style.cssText = "display:flex;align-items:center;gap:8px;margin-bottom:6px;";
    div.innerHTML =
      '<span style="min-width:100px;font-size:13px;font-weight:500;color:#475569;">' + nombre + '</span>' +
      '<input type="number" class="edit-input pago-input" data-metodo="' + nombre + '" value="' + existing + '" style="flex:1;" min="0">';
    list.appendChild(div);
  }
}

document.getElementById("edit-save-btn").addEventListener("click", function () {
  var itemsToKeep = [];
  var itemsToRemove = [];
  for (var i = 0; i < editItems.length; i++) {
    var it = editItems[i];
    if (it._removed) {
      itemsToRemove.push({ id_producto: it.id_producto, cantidad: it.cantidad });
    } else {
      itemsToKeep.push({ id_producto: it.id_producto, cantidad: it.cantidad, precio_total: it.precio_total });
    }
  }

  if (itemsToKeep.length === 0) {
    showToast('<i class="fas fa-exclamation-circle"></i> Debe quedar al menos un producto');
    return;
  }

  var pagoInputs = document.querySelectorAll(".pago-input");
  var pagos = [];
  for (var j = 0; j < pagoInputs.length; j++) {
    var inp = pagoInputs[j];
    var monto = parseInt(inp.value) || 0;
    if (monto > 0) {
      pagos.push({ metodo: inp.getAttribute("data-metodo"), monto: monto });
    }
  }

  if (pagos.length === 0) {
    showToast('<i class="fas fa-exclamation-circle"></i> Debe haber al menos un método de pago');
    return;
  }

  apiPost(API_VENTAS, {
    accion: 'editar',
    id_pedido: editingId,
    items_keep: itemsToKeep,
    items_remove: itemsToRemove,
    pagos: pagos,
    motivo: 'Edición desde cuadre'
  }, function () {
    document.getElementById("edit-venta-modal").style.display = "none";
    showToast('<i class="fas fa-check-circle"></i> Venta #' + editingId + ' actualizada');
    refreshSales();
    loadCuadre();
  });
});

/* ── Excepciones auditables con Supervisor ── */
function findSale(id) {
  for (var i=0;i<salesData.length;i++) if (Number(salesData[i].id_pedido)===Number(id)) return salesData[i];
  return null;
}

function solicitarAnulacion(id) {
  if (!confirm('¿Solicitar la anulación completa de la venta #' + id + '?\n\nEl Supervisor deberá ingresar su PIN o escanear su tarjeta.')) return;
  apiPost(API_POS, { accion:'venta_anular', id_pedido:id }, function () {
    showToast('<i class="fas fa-check-circle"></i> Venta #' + id + ' anulada con autorización de Supervisor');
    refreshSales(); loadCuadre();
  });
}

function solicitarDevolucionTotal(id) {
  var sale=findSale(id);
  if (!sale) { showToast('<i class="fas fa-exclamation-circle"></i> Venta no encontrada'); return; }
  if (!confirm('¿Solicitar la devolución total de la venta #' + id + '?\n\nUse Anular cuando fue un error de caja. Use Devolver cuando el cliente devuelve productos.')) return;
  var items=(sale.items||[]).map(function(it){
    var qty=parseFloat(it.cantidad_disponible_devolucion !== undefined ? it.cantidad_disponible_devolucion : (it.cantidad_pedida||it.cantidad||0))||0;
    return qty>0 ? {id_detalle_pedido:parseInt(it.id_detalle_pedido||0),id_producto:parseInt(it.id_producto),cantidad:qty} : null;
  }).filter(Boolean);
  if (!items.length) { showToast('<i class="fas fa-exclamation-circle"></i> La venta no tiene productos'); return; }
  apiPost(API_POS, { accion:'devolucion_crear', id_pedido:id, tipo:'TOTAL', motivo:'Devolución total autorizada desde cuadre', items:items }, function (d) {
    showToast('<i class="fas fa-check-circle"></i> Devolución procesada: $' + (d.monto_devuelto||0));
    refreshSales(); loadCuadre();
  });
}

/* ── Delete Sale ── */
var deletingId = null;

function openDeleteModal(id) {
  if (!cuadrePermissions.eliminar) { showToast('<i class="fas fa-lock"></i> No tiene permiso para eliminar ventas'); return; }
  deletingId = id;
  document.getElementById("delete-venta-id").textContent = id;
  document.getElementById("delete-venta-modal").style.display = "flex";
  requestAnimationFrame(function () {
    var box = document.querySelector("#delete-venta-modal .pos-modal-box");
    if (box) box.classList.add("show");
  });
}

document.getElementById("delete-confirm-btn").addEventListener("click", function () {
  apiPost(API_VENTAS, {
    accion: 'eliminar',
    id_pedido: deletingId,
    motivo: 'Eliminación desde cuadre'
  }, function () {
    document.getElementById("delete-venta-modal").style.display = "none";
    showToast('<i class="fas fa-check-circle"></i> Venta #' + deletingId + ' eliminada. Stock restaurado.');
    refreshSales();
    loadCuadre();
  });
});

/* ── CERRAR CAJA ── */
function cerrarCajaFinal() {
  var montoReal = parseInt(document.getElementById("monto-real-cierre").value) || 0;
  var obs = document.getElementById("obs-cierre").value || '';
  var btn = document.getElementById("btn-cerrar-caja");

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cerrando caja...';

  apiPost(API_POS, {
    accion: 'caja_cerrar',
    monto_real: montoReal,
    observaciones: obs
  }, function (d) {
    var msg = 'Caja cerrada.';
    if (d.esperado !== undefined) msg += ' Esperado: $' + d.esperado + ', Real: $' + d.monto_real;
    if (d.diferencia && d.diferencia !== 0) msg += ' Diferencia: $' + d.diferencia;
    showToast('<i class="fas fa-check-circle"></i> ' + msg);

    btn.innerHTML = '<i class="fas fa-check-circle"></i> Caja cerrada. Redirigiendo...';

    setTimeout(function () {
      showFarewellLoader("Cerrando sesión...");
      var xhr = new XMLHttpRequest();
      xhr.onreadystatechange = function () {
        if (xhr.readyState === XMLHttpRequest.DONE) {
          if (xhr.status === 200) {
            var txt = document.getElementById('farewell-loader-text');
            if (txt) txt.textContent = '¡Hasta pronto!';
            setTimeout(function () { window.location.href = '../index.php'; }, 1200);
          } else {
            window.location.href = '../index.php';
          }
        }
      };
      xhr.open('POST', '../assets/api/auth.php?accion=logout', true);
      xhr.send(new FormData());
    }, 1500);
  });
}

/* ── Session popup ── */
function openCerrarSesionPopup() {
  document.getElementById("cerrar-sesion-popup").style.display = "block";
}

function showFarewellLoader(msg) {
  var el = document.getElementById('farewell-loader');
  if (!el) {
    el = document.createElement('div');
    el.id = 'farewell-loader';
    el.className = 'loader-overlay';
    el.innerHTML =
      '<div class="loader-box">' +
      '<img src="../assets/img/Blue-Cat_logo-removebg.png" alt="Blue-Cat" class="loader-logo">' +
      '<p class="loader-text" id="farewell-loader-text">' + msg + '</p>' +
      '</div>';
    document.body.appendChild(el);
  }
  document.getElementById('farewell-loader-text').textContent = msg;
  el.style.display = 'flex';
}

function cerrarSesion() {
  showFarewellLoader("Cerrando sesión...");
  var xhr = new XMLHttpRequest();
  xhr.onreadystatechange = function () {
    if (xhr.readyState === XMLHttpRequest.DONE) {
      if (xhr.status === 200) {
        var txt = document.getElementById('farewell-loader-text');
        if (txt) txt.textContent = '¡Hasta pronto!';
        setTimeout(function () { window.location.href = '../index.php'; }, 1200);
      }
    }
  };
  xhr.open('POST', '../assets/api/auth.php?accion=logout', true);
  xhr.send(new FormData());
  return false;
}

function CloseSesionPopUp() {
  document.getElementById("cerrar-sesion-popup").style.display = "none";
}
