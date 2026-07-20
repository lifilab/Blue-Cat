var API_VENTAS = '../assets/api/ventas.php';
var API_POS = '../assets/api/pos.php';
var currentCuadreSessionId = 0;
var cuadrePermissions = { solicitarCorreccion: false };

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
      var response = JSON.parse(xhr.responseText);
      var posPermissions = response.permisos && response.permisos.pos ? response.permisos.pos : [];
      cuadrePermissions.solicitarCorreccion = posPermissions.indexOf('ver') !== -1;
    } catch (e) {
      cuadrePermissions.solicitarCorreccion = false;
    }
    if (salesData.length) renderSalesTable();
  };
  xhr.onerror = function () { cuadrePermissions.solicitarCorreccion = false; };
  xhr.send(JSON.stringify({ accion: 'sidebar' }));
}

function apiGet(url, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open("GET", url, true);
  xhr.onload = function () {
    if (xhr.status >= 200 && xhr.status < 300) {
      try { cb(JSON.parse(xhr.responseText)); } catch (e) { console.error('Parse error', xhr.responseText); }
    } else {
      try {
        var err = JSON.parse(xhr.responseText);
        var message = err.message || (typeof err.error === 'string' ? err.error : 'Error del servidor');
        showToast('<i class="fas fa-exclamation-circle"></i> ' + message);
      }
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

        var message = d.message || (typeof d.error === 'string' ? d.error : 'Error del servidor');
        showToast('<i class="fas fa-exclamation-circle"></i> ' + message);
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
  BlueCatSecurity.renderToast(t, msg, 'error');
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
    var puedeCorregir = cuadrePermissions.solicitarCorreccion && !anulado;

    tr.innerHTML =
      '<td' + anuladoStyle + '>' + Number(v.id_pedido || 0) + anuladoBadge + ' <span style="font-size:10px;color:#94a3b8;">' + escapeHtml(v.cliente_nombre || 'CF') + '</span></td>' +
      '<td class="items-cell"' + anuladoStyle + '>' + itemsHtml + '</td>' +
      '<td' + anuladoStyle + '><strong>$' + (v.precio_total || 0) + '</strong></td>' +
      '<td class="pagos-cell"' + anuladoStyle + '>' + pagosHtml + '</td>' +
      '<td style="font-size:12px;color:#64748b;">' + formatDateString(v.fecha) + '</td>' +
      '<td class="actions-cell">' +
        (puedeCorregir ? '<button class="btn-sm btn-sm-delete" onclick="solicitarAnulacion(' + v.id_pedido + ')" title="Anular con autorización"><i class="fas fa-user-shield"></i> Anular</button>' : '') +
        (puedeCorregir && !(v.devuelto === 1 || v.devuelto === '1') ? '<button class="btn-sm btn-sm-return" onclick="solicitarDevolucionTotal(' + v.id_pedido + ')" title="Devolver con autorización"><i class="fas fa-undo"></i> Devolver</button>' : '') +
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

/* ── Excepciones auditables con Supervisor ── */
function findSale(id) {
  for (var i=0;i<salesData.length;i++) if (Number(salesData[i].id_pedido)===Number(id)) return salesData[i];
  return null;
}

function solicitarMotivoCorreccion(etiqueta) {
  var motivo=window.prompt(etiqueta);
  if (motivo===null) return null;
  motivo=motivo.trim();
  if (motivo.length<3) {
    showToast('<i class="fas fa-exclamation-circle"></i> El motivo debe tener al menos 3 caracteres');
    return null;
  }
  return motivo;
}

function solicitarAnulacion(id) {
  if (!cuadrePermissions.solicitarCorreccion) {
    showToast('<i class="fas fa-lock"></i> Esta cuenta solo puede consultar ventas');
    return;
  }
  var motivo=solicitarMotivoCorreccion('Motivo real de la anulación:');
  if (motivo===null) return;
  if (!confirm('¿Solicitar la anulación completa de la venta #' + id + '?\n\nEl Supervisor deberá ingresar su PIN o escanear su tarjeta.')) return;
  apiPost(API_POS, { accion:'venta_anular', id_pedido:id, motivo:motivo }, function () {
    showToast('<i class="fas fa-check-circle"></i> Venta #' + id + ' anulada con autorización de Supervisor');
    refreshSales(); loadCuadre();
  });
}

function solicitarDevolucionTotal(id) {
  if (!cuadrePermissions.solicitarCorreccion) {
    showToast('<i class="fas fa-lock"></i> Esta cuenta solo puede consultar ventas');
    return;
  }
  var sale=findSale(id);
  if (!sale) { showToast('<i class="fas fa-exclamation-circle"></i> Venta no encontrada'); return; }
  if (!confirm('¿Solicitar la devolución total de la venta #' + id + '?\n\nUse Anular cuando fue un error de caja. Use Devolver cuando el cliente devuelve productos.')) return;
  var items=(sale.items||[]).map(function(it){
    var qty=parseFloat(it.cantidad_disponible_devolucion !== undefined ? it.cantidad_disponible_devolucion : (it.cantidad_pedida||it.cantidad||0))||0;
    return qty>0 ? {id_detalle_pedido:parseInt(it.id_detalle_pedido||0),id_producto:parseInt(it.id_producto),cantidad:qty} : null;
  }).filter(Boolean);
  if (!items.length) { showToast('<i class="fas fa-exclamation-circle"></i> La venta no tiene productos'); return; }
  var motivo=solicitarMotivoCorreccion('Motivo real de la devolución:');
  if (motivo===null) return;
  apiPost(API_POS, { accion:'devolucion_crear', id_pedido:id, tipo:'TOTAL', motivo:motivo, items:items }, function (d) {
    showToast('<i class="fas fa-check-circle"></i> Devolución procesada: $' + (d.monto_devuelto||0));
    refreshSales(); loadCuadre();
  });
}

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
