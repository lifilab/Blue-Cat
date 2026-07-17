/* ── POS - Blue-Cat ── */
var cart = [], products = [], categories = [], currentCat = '';
var cajaState = null, selectedClient = null, promoApplied = null;
var _debounceTimer = null;
var configBoleta = null; // Configuración de boletas
var userPermissions = {}; // Permisos del usuario
var _saleRequestKey = null;
var _paymentSubmitting = false;
var _pendingCotizacionId = 0;

document.addEventListener('DOMContentLoaded', init);

function $(id) { return document.getElementById(id); }
function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
function fm(n) { if (n === null || n === undefined) return '$0'; return '$' + Math.round(Number(n)).toLocaleString('es-CL'); }
function toast(msg, t) {
  var el = document.createElement('div');
  el.className = 'toast toast-' + (t === 'err' ? 'err' : 'ok');
  el.setAttribute('role', 'alert');
  el.setAttribute('aria-live', 'assertive');
  el.innerHTML = msg; document.body.appendChild(el);
  requestAnimationFrame(function () { el.classList.add('show'); });
  setTimeout(function () { el.classList.remove('show'); setTimeout(function () { el.remove(); }, 300); }, 2500);
}

function init() {
  loadUserPermissions(); // Cargar permisos del usuario
  loadConfigBoleta(); // Cargar configuración de boletas
  loadDashboard();
  loadCajaState();
  loadClientes();
  loadPromociones();
  // Product search with debounce
  $('search-input').addEventListener('input', function () {
    clearTimeout(_debounceTimer);
    _debounceTimer = setTimeout(loadProducts, 300);
  });
  $('search-input').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') handleBarcode();
  });
  loadProducts();
  setupKeyboard();
}

function loadUserPermissions() {
  apiGet({ accion: 'permisos_usuario' }, function(d) {
    userPermissions = {};
    (d.permisos || []).forEach(function(p) {
      userPermissions[p.modulo] = (p.acciones || []).map(function(a) { return typeof a === 'string' ? a : a.accion; });
    });
  });
}

function hasPermission(modulo, accion) {
  if (!userPermissions[modulo]) return false;
  return userPermissions[modulo].indexOf(accion) !== -1;
}

function loadConfigBoleta() {
  apiGet({ accion: 'config_boleta' }, function(d) {
    configBoleta = d.config || null;
  });
}

/* ── API call helper ── */
function api(method, data, cb, errCb) {
  var xhr = new XMLHttpRequest();
  var url = '../assets/api/pos.php';
  data = data || {};
  // Keep the API contract consistent with the backend router.
  if (data.accion && !data.action) {
    data.action = data.accion;
    delete data.accion;
  }
  if (method === 'GET' && data) {
    url += '?' + Object.keys(data).map(function (k) { return k + '=' + encodeURIComponent(data[k]); }).join('&');
  }
  xhr.open(method, url, true);
  if (method === 'POST') xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function () {
    if (xhr.status >= 200 && xhr.status < 300) {
      try { var d = JSON.parse(xhr.responseText); } catch (e) { console.error('POS API PARSE ERROR [' + xhr.status + ' ' + url + ']', xhr.responseText.substring(0, 300)); toast('Error API: ' + xhr.status, 'err'); return; }
      try { if (cb) cb(d); } catch (e) { console.error('POS API CALLBACK ERROR', e); }
    } else {
      try {
        var e = JSON.parse(xhr.responseText);
        if (window.SupervisorApproval && window.SupervisorApproval.handle(e, function(token) {
          data.supervisor_token = token; api(method, data, cb, errCb);
        })) return;

        toast(e.message || (typeof e.error === 'string' ? e.error : 'Error ' + xhr.status), 'err');
        console.error('POS API ERROR [' + xhr.status + ' ' + url + ']', e);
        if (errCb) errCb(e, xhr.status);
      }
      catch (e2) { toast('Error del servidor', 'err'); if (errCb) errCb(e2, xhr.status); }
    }
  };
  xhr.onerror = function () { toast('Error de conexión', 'err'); if (errCb) errCb(new Error('network'), 0); };
  if (method === 'POST') xhr.send(JSON.stringify(data));
  else xhr.send();
}

function apiGet(params, cb) { api('GET', params, cb); }
function apiPost(data, cb, errCb) { api('POST', data, cb, errCb); }

/* ═══════════════════════════════════════════
   DASHBOARD
   ═══════════════════════════════════════════ */
function loadDashboard() {
  apiGet({ accion: 'dashboard' }, function (d) {
    var setText = function(id, val) { var el = $(id); if (el) el.textContent = val; };
    if (d.ventas_hoy) {
      setText('db-ventas-hoy', fm(d.ventas_hoy.total));
      setText('db-cant-hoy', d.ventas_hoy.cant + ' ventas');
    }
    if (d.ventas_mes) {
      setText('db-ventas-mes', fm(d.ventas_mes.total));
      setText('db-cant-mes', d.ventas_mes.cant + ' ventas');
    }
    setText('db-cajas', d.cajas_abiertas + ' abierta(s)');
    setText('db-clientes', (d.total_clientes || 0) + '');
    setText('db-stock-bajo', (d.stock_bajo || 0) + ' bajo');
    setText('db-sin-stock', (d.sin_stock || 0) + ' sin stock');
    if (d.promociones_activas) setText('db-promos', d.promociones_activas + ' activa(s)');
    if (d.devoluciones_hoy) setText('db-devo', fm(d.devoluciones_hoy.total) + ' (' + d.devoluciones_hoy.cant + ')');
    if (d.anuladas_hoy !== undefined) setText('db-anuladas', d.anuladas_hoy);
  });
}

/* ═══════════════════════════════════════════
   CAJA
   ═══════════════════════════════════════════ */
function loadCajaState() {
  apiGet({ accion: 'caja_estado' }, function (d) {
    cajaState = d.abierta && d.caja ? d.caja : null;
    if (cajaState && cajaState.estado === 'ABIERTA') {
      $('caja-status').innerHTML = '<span class="badge badge-ABIERTA">Abierta</span>';
      $('caja-monto').textContent = fm(cajaState.monto_actual);
      $('caja-codigo').textContent = cajaState.codigo || cajaState.nombre || 'Caja';
      $('caja-action-btn').innerHTML = '<i class="fas fa-times"></i> Cerrar Caja';
      $('caja-action-btn').onclick = showCerrarCaja;
      $('caja-mov-btn').style.display = 'block';
    } else {
      $('caja-status').innerHTML = '<span class="badge badge-CERRADA">Cerrada</span>';
      $('caja-monto').textContent = '$0';
      $('caja-codigo').textContent = 'Sin caja activa';
      $('caja-action-btn').innerHTML = '<i class="fas fa-plus"></i> Abrir Caja';
      $('caja-action-btn').onclick = showAbrirCaja;
      $('caja-mov-btn').style.display = 'none';
    }
  });
}

function showAbrirCaja() {
  var nombreUsuario = sessionStorage.getItem('user_name') || 'Usuario';
  var codigoCaja = localStorage.getItem('bluecat_pos_caja_codigo') || 'CAJA-01';
  var m = showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:16px;"><i class="fas fa-cash-register" style="color:#4f46e5;"></i> Abrir Caja</h3>
    <div class="gr2">
      <div class="fld"><label>Monto Apertura *</label><input id="ac-monto" type="number" min="0" value="0"></div>
      <div class="fld"><label>Código caja física *</label><input id="ac-codigo" value="${esc(codigoCaja)}" maxlength="40"></div>
      <div class="fld"><label>Nombre Caja</label><input id="ac-nombre" value="Caja Principal"></div>
      <div class="fld"><label>Empleado</label><input id="ac-emp" value="${nombreUsuario}" readonly style="background:#f1f5f9;cursor:not-allowed;"></div>
      <div class="fld"><label>Sucursal</label><input id="ac-suc" value="Principal"></div>
    </div>
    <div class="fld"><label>Nota</label><textarea id="ac-nota" rows="1"></textarea></div>
    <div class="mcb"><button class="btn-g" onclick="closeModal()">Cancelar</button><button class="btn-p" onclick="abrirCaja()"><i class="fas fa-check"></i> Abrir Caja</button></div>
  `);
  setTimeout(function () { $('ac-monto').focus(); $('ac-monto').select(); }, 100);
}

function abrirCaja() {
  var monto = parseInt($('ac-monto').value) || 0;
  var codigo = ($('ac-codigo').value || '').trim().toUpperCase();
  if (!codigo) { toast('Indique el código de la caja física', 'err'); return; }
  apiPost({
    accion: 'caja_abrir', monto_apertura: monto, codigo: codigo,
    nombre_caja: $('ac-nombre').value, empleado: $('ac-emp').value,
    sucursal: $('ac-suc').value, nota: $('ac-nota').value
  }, function (d) {
    localStorage.setItem('bluecat_pos_caja_codigo', codigo);
    toast('<i class="fas fa-check-circle"></i> Caja abierta: ' + (d.caja ? d.caja.codigo : codigo));
    closeModal();
    loadCajaState();
  });
}

function showCerrarCaja() {
  window.location.href = '../public/cuadre_de_ventas.html';
}

function cerrarCaja() {
  var monto = parseInt($('cc-monto').value) || 0;
  apiPost({ accion: 'caja_cerrar', monto_real: monto, observaciones: $('cc-obs').value }, function (d) {
    var msg = 'Caja cerrada. Esperado: ' + fm(d.esperado) + ', Real: ' + fm(d.monto_real);
    if (d.diferencia !== 0) msg += ', Diferencia: <strong>' + fm(d.diferencia) + '</strong>';
    toast('<i class="fas fa-check-circle"></i> ' + msg);
    closeModal();
    loadDashboard();
    loadCajaState();
  });
}

function showMovimiento(tipo) {
  var m = showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:16px;"><i class="fas fa-${tipo === 'INGRESO' ? 'arrow-down' : 'arrow-up'}" style="color:#4f46e5;"></i> ${tipo === 'INGRESO' ? 'Ingreso' : 'Egreso'} de Caja</h3>
    <div class="gr2">
      <div class="fld"><label>Monto *</label><input id="mv-monto" type="number" min="0"></div>
      <div class="fld"><label>Método</label><select id="mv-metodo" disabled><option value="EFECTIVO">Efectivo</option></select></div>
    </div>
    <div class="fld"><label>Concepto *</label><input id="mv-concepto"></div>
    <div class="fld"><label>Referencia</label><input id="mv-ref"></div>
    <div class="mcb"><button class="btn-g" onclick="closeModal()">Cancelar</button><button class="btn-p" onclick="guardarMovimiento('${tipo}')"><i class="fas fa-save"></i> Registrar</button></div>
  `);
  setTimeout(function () { $('mv-monto').focus(); }, 100);
}

function guardarMovimiento(tipo) {
  var monto = parseInt($('mv-monto').value) || 0;
  var concepto = $('mv-concepto').value;
  if (!monto || !concepto) { toast('Completa los campos requeridos', 'err'); return; }
  apiPost({
    accion: 'caja_movimiento', tipo: tipo,
    monto: monto, concepto: concepto,
    metodo: $('mv-metodo').value, referencia: $('mv-ref').value
  }, function () {
    toast('Movimiento registrado');
    closeModal();
    loadCajaState();
  });
}

/* ═══════════════════════════════════════════
   PRODUCTOS
   ═══════════════════════════════════════════ */
function loadProducts() {
  var q = $('search-input').value;
  var params = { accion: 'productos' };
  if (q) params.q = q;
  if (currentCat) params.cat = currentCat;
  apiGet(params, function (d) {
    products = d.productos || [];
    if (d.categorias && d.categorias.length) {
      categories = d.categorias;
      renderCategories();
    }
    renderProducts();
  });
}

function renderCategories() {
  var cont = $('cat-filters');
  if (!cont) return;
  var h = '<button class="cat-btn' + (!currentCat ? ' active' : '') + '" onclick="setCat(\'\')">Todas</button>';
  for (var i = 0; i < categories.length; i++) {
    h += '<button class="cat-btn' + (currentCat === categories[i] ? ' active' : '') + '" onclick="setCat(\'' + esc(categories[i]) + '\')">' + esc(categories[i]) + '</button>';
  }
  cont.innerHTML = h;
}

function setCat(cat) {
  currentCat = cat;
  renderCategories();
  loadProducts();
}

function renderProducts() {
  var grid = $('product-grid');
  if (!products.length) { grid.innerHTML = '<div class="loading"><i class="fas fa-box-open"></i>Sin productos</div>'; return; }
  var h = '';
  for (var i = 0; i < products.length; i++) {
    var p = products[i];
    var stk = parseFloat(p.cantidad) || 0;
    var stkCls = stk > 5 ? 'ok' : (stk > 0 ? 'low' : 'out');
    var stkLbl = stk > 5 ? stk + ' disp.' : (stk > 0 ? '¡Solo ' + stk + '!' : 'Sin stock');
    var esPeso = p.tipo_venta === 'PESO' || p.tipo_venta === 'VOLUMEN';
    var unidad = p.unidad_abrev || 'u';
    var precioLabel = esPeso ? fm(p.precio_venta) + '/' + unidad : fm(p.precio_venta);
    h += '<div class="prod-card" tabindex="0" role="button" aria-label="' + esc(p.nombre_producto) + ' ' + precioLabel + '" onclick="addToCart(' + p.id_producto + ',\'' + esc(p.nombre_producto) + '\',' + p.precio_venta + ',\'' + esc(p.codigo_de_barras) + '\',' + stk + ',\'' + (p.tipo_venta || 'UNIDAD') + '\',\'' + unidad + '\')" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();addToCart(' + p.id_producto + ',\'' + esc(p.nombre_producto) + '\',' + p.precio_venta + ',\'' + esc(p.codigo_de_barras) + '\',' + stk + ',\'' + (p.tipo_venta || 'UNIDAD') + '\',\'' + unidad + '\');}">' +
      '<div class="name">' + esc(p.nombre_producto) + '</div>' +
      '<div class="price">' + precioLabel + '</div>' +
      '<div class="sku">' + esc(p.codigo_de_barras || '') + '</div>' +
      '<div class="stock ' + stkCls + '">' + stkLbl + '</div>' +
      '</div>';
  }
  grid.innerHTML = h;
}

function handleBarcode() {
  var q = $('search-input').value.trim();
  if (!q) return;
  var savedCat = currentCat;
  currentCat = '';
  for (var i = 0; i < products.length; i++) {
    if (products[i].codigo_de_barras === q) {
      addToCart(products[i].id_producto, products[i].nombre_producto, products[i].precio_venta, products[i].codigo_de_barras, products[i].cantidad, products[i].tipo_venta, products[i].unidad_abrev);
      $('search-input').value = '';
      currentCat = savedCat;
      return;
    }
  }
  currentCat = savedCat;
  loadProducts();
}

/* ═══════════════════════════════════════════
   CART
   ═══════════════════════════════════════════ */
function addToCart(id, name, price, sku, stock, tipoVenta, unidad) {
  var stk = parseFloat(stock);
  if (isNaN(stk) || stk <= 0) { toast('Producto sin stock', 'err'); return; }
  if (!id) { toast('Producto inválido', 'err'); return; }
  tipoVenta = tipoVenta || 'UNIDAD';
  unidad = unidad || 'u';
  if (tipoVenta === 'PESO' || tipoVenta === 'VOLUMEN') { showMeasureModal({id:id,name:name,price:parseFloat(price)||0,sku:sku||'',stock:stk,tipoVenta:tipoVenta,unidad:unidad}); return; }
  for (var i = 0; i < cart.length; i++) {
    if (cart[i].id === id) {
      if (cart[i].cant >= stk) { toast('Stock máximo alcanzado', 'err'); return; }
      cart[i].cant++;
      renderCart();
      return;
    }
  }
  cart.push({ id:id, name:name, price:parseFloat(price)||0, sku:sku||'', cant:1, stock:stk, tipoVenta:'UNIDAD', unidad:unidad });
  renderCart();
  toast('<i class="fas fa-check"></i> ' + esc(name));
}


var pendingMeasureProduct = null;

function showMeasureModal(p) {
  pendingMeasureProduct = p;
  var label = p.tipoVenta === 'PESO' ? 'Peso' : 'Volumen';
  showModal('<h3><i class="fas fa-weight-scale"></i> ' + label + ' de ' + esc(p.name) + '</h3>' +
    '<div class="fld"><label>' + label + ' (' + esc(p.unidad) + ')</label><input id="measure-qty" type="number" min="0.001" max="' + p.stock + '" step="0.001" inputmode="decimal" placeholder="Ej: 0.750"></div>' +
    '<div style="padding:12px;background:#f8fafc;margin-bottom:12px">Precio: ' + fm(p.price) + '/' + esc(p.unidad) + ' &nbsp; Total: <strong id="measure-total">$0</strong><br><small>Disponible: ' + p.stock + ' ' + esc(p.unidad) + '</small></div>' +
    '<div class="mcb"><button class="btn-g" onclick="closeModal()">Cancelar</button><button class="btn-p" onclick="confirmMeasure()">Agregar</button></div>');
  setTimeout(function() { var input=$('measure-qty'); input.focus(); input.addEventListener('input',function(){$('measure-total').textContent=fm((parseFloat(input.value)||0)*p.price);}); input.addEventListener('keydown',function(e){if(e.key==='Enter')confirmMeasure();}); },100);
}

function confirmMeasure() {
  var p=pendingMeasureProduct, qty=Math.round((parseFloat($('measure-qty').value)||0)*1000)/1000;
  if(!p||qty<=0){toast('Ingrese un peso o volumen válido','err');return;}
  var found=null; for(var i=0;i<cart.length;i++)if(cart[i].id===p.id){found=cart[i];break;}
  var totalQty=(found?found.cant:0)+qty;
  if(totalQty>p.stock){toast('Stock máximo: '+p.stock+' '+p.unidad,'err');return;}
  if(found)found.cant=Math.round(totalQty*1000)/1000;else cart.push({id:p.id,name:p.name,price:p.price,sku:p.sku,cant:qty,stock:p.stock,tipoVenta:p.tipoVenta,unidad:p.unidad});
  pendingMeasureProduct=null;closeModal();renderCart();toast('<i class="fas fa-check"></i> '+esc(p.name));
}

function removeFromCart(idx) {
  cart.splice(idx, 1);
  renderCart();
}

function changeQty(idx, delta) {
  if (idx < 0 || idx >= cart.length) return;
  var item = cart[idx];
  var esPeso = item.tipoVenta === 'PESO' || item.tipoVenta === 'VOLUMEN';
  var step = esPeso ? 0.1 : 1;
  var newQ = item.cant + (delta * step);
  
  if (newQ <= 0) { removeFromCart(idx); return; }
  if (newQ > item.stock) { toast('Stock máximo: ' + item.stock + (esPeso ? ' ' + item.unidad : ''), 'err'); return; }
  
  cart[idx].cant = esPeso ? Math.round(newQ * 1000) / 1000 : newQ;
  renderCart();
}

function editQty(idx) {
  var item = cart[idx];
  var esPeso = item.tipoVenta === 'PESO' || item.tipoVenta === 'VOLUMEN';
  var unidad = item.unidad || 'u';
  var step = esPeso ? 0.001 : 1;
  
  var modal = showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:16px;"><i class="fas fa-edit" style="color:#4f46e5;"></i> Modificar Cantidad</h3>
    <div class="fld"><label>${esc(item.name)}</label><input id="edit-cantidad" type="number" min="${step}" step="${step}" value="${item.cant}" style="font-size:18px;font-weight:700;"></div>
    <div class="fld"><label>Unidad</label><input type="text" value="${unidad}" readonly style="background:#f1f5f9;"></div>
    <div class="mcb"><button class="btn-g" onclick="closeModal()">Cancelar</button><button class="btn-p" onclick="saveQty(${idx})"><i class="fas fa-check"></i> Aceptar</button></div>
  `);
  setTimeout(function() { $('edit-cantidad').focus(); $('edit-cantidad').select(); }, 100);
}

function saveQty(idx) {
  var newQty = parseFloat($('edit-cantidad').value) || 0;
  if (newQty <= 0) { removeFromCart(idx); closeModal(); return; }
  if (newQty > cart[idx].stock) { toast('Stock máximo: ' + cart[idx].stock, 'err'); return; }
  cart[idx].cant = newQty;
  renderCart();
  closeModal();
}

function editPrice(idx) {
  
  var old = cart[idx].price;
  var modal = showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:16px;"><i class="fas fa-tag" style="color:#4f46e5;"></i> Modificar Precio</h3>
    <div class="fld"><label>Nuevo precio para ${esc(cart[idx].name)}</label><input id="ep-price" type="number" min="0" value="${old}"></div>
    <div class="mcb"><button class="btn-g" onclick="closeModal()">Cancelar</button><button class="btn-p" onclick="savePrice(${idx})"><i class="fas fa-save"></i> Aceptar</button></div>
  `);
  setTimeout(function () { $('ep-price').focus(); $('ep-price').select(); }, 100);
}

function savePrice(idx) {
  var v = parseInt($('ep-price').value);
  if (v > 0) { cart[idx].price = v; renderCart(); }
  closeModal();
}

function renderCart() {
  var cont = $('cart-items');
  var countEl = $('cart-count');
  var total = 0, itemsCount = 0;
  var h = '';
  
  for (var i = 0; i < cart.length; i++) {
    var c = cart[i];
    var sub = c.price * c.cant;
    total += sub;
    itemsCount += c.cant;
    var esPeso = c.tipoVenta === 'PESO' || c.tipoVenta === 'VOLUMEN';
    var unidad = c.unidad || 'u';
    var cantDisplay = esPeso ? c.cant + ' ' + unidad : c.cant;
    var precioLabel = esPeso ? fm(c.price) + '/' + unidad : fm(c.price);
    
    var precioClick = 'onclick="editPrice(' + i + ')" title="Modificar precio (puede requerir supervisor)" style="cursor:pointer;"';
    
    h += '<div class="cart-item">' +
      '<div class="ci-info">' +
      '<div class="ci-name">' + esc(c.name) + '</div>' +
      '<div class="ci-sku">' + esc(c.sku) + '</div>' +
      '</div>' +
      '<div class="ci-qty">' +
      '<button onclick="changeQty(' + i + ',-1)" aria-label="Disminuir cantidad de ' + esc(c.name) + '">−</button>' +
      '<span onclick="editQty(' + i + ')" style="cursor:pointer;padding:0 8px;" title="Click para editar">' + cantDisplay + '</span>' +
      '<button onclick="changeQty(' + i + ',1)" aria-label="Aumentar cantidad de ' + esc(c.name) + '">+</button>' +
      '</div>' +
      '<div class="ci-price">' +
      '<div class="cp-val" ' + precioClick + '>' + precioLabel + '</div>' +
      '<div class="cp-sub">' + fm(sub) + '</div>' +
      '</div>' +
      '<button class="ci-del" onclick="removeFromCart(' + i + ')" aria-label="Eliminar ' + esc(c.name) + ' del carrito"><i class="fas fa-times"></i></button>' +
      '</div>';
  }
  cont.innerHTML = h || '<div class="loading" style="padding:20px;"><i class="fas fa-shopping-cart"></i>Carrito vacío</div>';
  if (countEl) countEl.textContent = Math.round(itemsCount * 100) / 100;

  var promoDcto = promoApplied ? promoApplied.descuento : 0;
  var neto = total - promoDcto;
  if (neto < 0) neto = 0;

  $('cart-subtotal').textContent = fm(total);
  $('cart-dcto').textContent = promoDcto > 0 ? '-' + fm(promoDcto) : '$0';
  $('cart-total').textContent = fm(neto);
  $('cart-total-val').textContent = neto;
  $('cart-items-count').textContent = Math.round(itemsCount * 100) / 100;
}

function clearCart() {
  cart = [];
  promoApplied = null;
  selectedClient = null;
  _pendingCotizacionId = 0;
  $('cart-client').innerHTML = '<i class="fas fa-user"></i> Consumidor Final';
  renderCart();
}

/* ═══════════════════════════════════════════
   PROMO CODE
   ═══════════════════════════════════════════ */
function applyPromo() {
  var code = $('promo-input').value.trim().toUpperCase();
  if (!code) { toast('Ingrese un código', 'err'); return; }
  var items = cart.map(function (c) { return { id_producto: c.id, cantidad: c.cant, precio_unitario: c.price }; });
  var total = items.reduce(function (s, it) { return s + it.precio_unitario * it.cantidad; }, 0);

  apiPost({ accion: 'promocion_validar', codigo: code, items: items, subtotal: total }, function (d) {
    var promo=d.promocion||{};
    promoApplied = { descuento: d.descuento, descripcion: promo.nombre||promo.descripcion||promo.codigo||code, id_promocion: promo.id_promocion };
    $('promo-tag').innerHTML = '<span class="promo-tag"><i class="fas fa-tag"></i> ' + esc(d.descripcion) + ' (-' + fm(d.descuento) + ')</span>';
    $('promo-input').value = '';
    renderCart();
    toast('Promoción aplicada: ' + d.descripcion);
  });
}

/* ═══════════════════════════════════════════
   CLIENTE
   ═══════════════════════════════════════════ */
function loadClientes() {
  apiGet({ accion: 'clientes' }, function (d) {
    window._clientes = d || [];
  });
}

function showClientSelector() {
  var lista = (window._clientes || []).map(function (c) {
    return '<div class="cart-item" style="cursor:pointer;" onclick="selectClient(' + c.id_cliente + ',\'' + esc(c.nombre) + '\',\'' + esc(c.rut) + '\',\'' + esc(c.correo) + '\',\'' + esc(c.telefono) + '\')">' +
      '<div><strong>' + esc(c.nombre) + '</strong><br><span style="font-size:11px;color:#64748b;">' + esc(c.rut) + ' · ' + esc(c.correo) + '</span></div></div>';
  }).join('') || '<p style="color:#94a3b8;">Sin clientes registrados</p>';

  var searchId = 'cl-s-' + Date.now();
  var m = showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;"><i class="fas fa-user" style="color:#4f46e5;"></i> Seleccionar Cliente</h3>
    <input id="${searchId}" placeholder="Buscar cliente..." style="width:100%;padding:10px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;margin-bottom:12px;outline:none;" onkeyup="buscarClientes(this.value)">
    <div id="cl-list" style="max-height:300px;overflow-y:auto;">${lista}</div>
    <div style="margin-top:8px;"><button class="btn-g" style="width:100%;padding:10px;" onclick="closeModal()">Cancelar</button></div>
    <div style="margin-top:4px;"><button class="btn-p" style="width:100%;padding:10px;" onclick="closeModal();showQuickClient()"><i class="fas fa-plus"></i> Cliente Nuevo</button></div>
  `);
  setTimeout(function () { $(searchId).focus(); }, 100);
}

var _clDebounce = null;
function buscarClientes(q) {
  clearTimeout(_clDebounce);
  _clDebounce = setTimeout(function() {
    var list = $('cl-list');
    if (!list) return;
    if (!q) { list.innerHTML = (window._clientes || []).map(function (c) {
      return '<div class="cart-item" style="cursor:pointer;" onclick="selectClient(' + c.id_cliente + ',\'' + esc(c.nombre) + '\',\'' + esc(c.rut) + '\',\'' + esc(c.correo) + '\',\'' + esc(c.telefono) + '\')"><div><strong>' + esc(c.nombre) + '</strong><br><span style="font-size:11px;color:#64748b;">' + esc(c.rut) + ' · ' + esc(c.correo) + '</span></div></div>';
    }).join('') || '<p style="color:#94a3b8;">Sin clientes</p>'; return; }
    apiGet({ accion: 'clientes', q: q }, function (d) {
      list.innerHTML = d.map(function (c) {
        return '<div class="cart-item" style="cursor:pointer;" onclick="selectClient(' + c.id_cliente + ',\'' + esc(c.nombre) + '\',\'' + esc(c.rut) + '\',\'' + esc(c.correo) + '\',\'' + esc(c.telefono) + '\')"><div><strong>' + esc(c.nombre) + '</strong><br><span style="font-size:11px;color:#64748b;">' + esc(c.rut) + ' · ' + esc(c.correo) + '</span></div></div>';
      }).join('') || '<p style="color:#94a3b8;">Sin resultados</p>';
    });
  }, 300);
}

function selectClient(id, nombre, rut, correo, tel) {
  selectedClient = { id_cliente: id, nombre: nombre, rut: rut, correo: correo, telefono: tel };
  $('cart-client').innerHTML = '<i class="fas fa-user-check"></i> ' + esc(nombre) + ' <span style="font-size:11px;color:#94a3b8;">' + esc(rut) + '</span>';
  closeModal();
  toast('Cliente: ' + nombre);
}

function showQuickClient() {
  var m = showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:16px;"><i class="fas fa-user-plus" style="color:#4f46e5;"></i> Nuevo Cliente</h3>
    <div class="gr2">
      <div class="fld"><label>Nombre *</label><input id="qc-nombre"></div>
      <div class="fld"><label>RUT</label><input id="qc-rut"></div>
      <div class="fld"><label>Correo</label><input id="qc-mail" type="email"></div>
      <div class="fld"><label>Teléfono</label><input id="qc-tel"></div>
      <div class="fld" style="grid-column:1/-1;"><label>Dirección</label><input id="qc-dir"></div>
      <div class="fld"><label>Ciudad</label><input id="qc-ciudad"></div>
    </div>
    <div class="mcb"><button class="btn-g" onclick="closeModal()">Cancelar</button><button class="btn-p" onclick="saveQuickClient()"><i class="fas fa-save"></i> Guardar y Seleccionar</button></div>
  `);
  setTimeout(function () { $('qc-nombre').focus(); }, 100);
}

function saveQuickClient() {
  var nombre = $('qc-nombre').value;
  if (!nombre) { toast('Nombre requerido', 'err'); return; }
  apiPost({
    accion: 'cliente_crear', nombre: nombre, rut: $('qc-rut').value,
    correo: $('qc-mail').value, telefono: $('qc-tel').value,
    direccion: $('qc-dir').value, ciudad: $('qc-ciudad').value
  }, function (d) {
    selectedClient = { id_cliente: d.id_cliente, nombre: nombre, rut: $('qc-rut').value, correo: $('qc-mail').value, telefono: $('qc-tel').value };
    $('cart-client').innerHTML = '<i class="fas fa-user-check"></i> ' + esc(nombre) + ' <span style="font-size:11px;color:#94a3b8;">' + esc($('qc-rut').value) + '</span>';
    closeModal();
    toast('Cliente creado y seleccionado');
    loadClientes();
  });
}

/* ═══════════════════════════════════════════
   PAYMENT
   ═══════════════════════════════════════════ */
function newSaleRequestKey() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
  var bytes = new Uint8Array(16);
  if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
  else for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
  return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
}

function paymentMethodLabel(method) {
  var labels = { EFECTIVO: 'Efectivo', TARJETA_CREDITO: 'Tarjeta crédito', TARJETA_DEBITO: 'Tarjeta débito', TRANSFERENCIA: 'Transferencia', OTRO: 'Otro' };
  return labels[method] || method;
}

function showPayment() {
  if (cart.length === 0) { toast('Carrito vacío', 'err'); return; }
  if (!cajaState || cajaState.estado !== 'ABIERTA') { toast('Debe abrir caja primero', 'err'); return; }
  var total = parseInt($('cart-total-val').textContent) || 0;
  if (total <= 0) { toast('Total inválido', 'err'); return; }

  var m = showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;"><i class="fas fa-credit-card" style="color:#4f46e5;"></i> Cobrar</h3>
    <select id="doc-tipo" style="margin-bottom:8px;padding:6px;border-radius:6px;width:100%;">
      <option value="BOLETA">Boleta</option>
      <option value="FACTURA">Factura</option>
    </select>
    <div style="text-align:center;margin-bottom:12px;">
      <div style="font-size:13px;color:#64748b;">Total a cobrar</div>
      <div style="font-size:32px;font-weight:800;color:#1e293b;">${fm(total)}</div>
    </div>
    <div class="pay-grid">
      <button class="pay-method" onclick="addPayment('EFECTIVO')"><i class="fas fa-money-bill-wave"></i> Efectivo</button>
      <button class="pay-method" onclick="addPayment('TARJETA_CREDITO')"><i class="fas fa-credit-card"></i> Crédito</button>
      <button class="pay-method" onclick="addPayment('TARJETA_DEBITO')"><i class="fas fa-credit-card"></i> Débito</button>
      <button class="pay-method" onclick="addPayment('TRANSFERENCIA')"><i class="fas fa-university"></i> Transferencia</button>
    </div>
    <div id="payment-list" style="margin:8px 0;"></div>
    <div id="payment-summary" style="display:flex;justify-content:space-between;font-size:14px;font-weight:600;padding:8px 0;border-top:1px solid #e2e8f0;">
      <span>Pagado: <span id="pay-total">$0</span></span>
      <span>Faltante: <span id="pay-restante" style="color:#dc2626;">${fm(total)}</span></span>
    </div>
    <div class="mcb">
      <button class="btn-g" onclick="closeModal()">Cancelar</button>
      <button class="btn-p" id="pay-confirm-btn" onclick="confirmPayment()" disabled><i class="fas fa-check-circle"></i> Cobrar $0</button>
    </div>
  `);
  window._payments = [];
  window._payTotal = 0;
  window._payTarget = total;
  _saleRequestKey = newSaleRequestKey();
  _paymentSubmitting = false;
  updatePaymentUI();
}

function addPayment(metodo) {
  var resto = window._payTarget - window._payTotal;
  if (resto <= 0) { toast('Ya está cubierto', 'ok'); return; }
  // Prompt for amount
  var m2 = showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;"><i class="fas fa-${metodo === 'EFECTIVO' ? 'money-bill-wave' : 'credit-card'}"></i> ${paymentMethodLabel(metodo)}</h3>
    <div class="fld"><label>Monto</label><input id="pm-monto" type="number" min="1" value="${resto}"></div>
    <div class="fld"><label>Referencia / Aut. (opcional)</label><input id="pm-ref"></div>
    <div class="mcb">
      <button class="btn-g" onclick="closeModal()">Cancelar</button>
      <button class="btn-p" onclick="confirmPaymentMethod('${metodo}')"><i class="fas fa-check"></i> Agregar</button>
    </div>
  `);
  setTimeout(function () { $('pm-monto').focus(); $('pm-monto').select(); }, 100);
}

function confirmPaymentMethod(metodo) {
  var monto = parseInt($('pm-monto').value) || 0;
  var restante = window._payTarget - window._payTotal;
  if (metodo !== 'EFECTIVO' && monto > restante) {
    toast('Solo el efectivo puede superar el saldo y generar vuelto', 'err'); return;
  }
  if (monto <= 0) { toast('Monto inválido', 'err'); return; }
  var ref = $('pm-ref').value || '';
  window._payments.push({ metodo: metodo, monto: monto, referencia: ref });
  window._payTotal += monto;
  closeModal();
  updatePaymentUI();
}

function updatePaymentUI() {
  var list = $('payment-list');
  var ph = '';
  for (var i = 0; i < (window._payments || []).length; i++) {
    var p = window._payments[i];
    ph += '<div style="display:flex;justify-content:space-between;padding:4px 8px;background:#f8fafc;border-radius:6px;margin:2px 0;"><span><strong>' + paymentMethodLabel(p.metodo) + '</strong></span><span>' + fm(p.monto) + '</span></div>';
  }
  if (list) list.innerHTML = ph;
  var totalEl = $('pay-total');
  if (totalEl) totalEl.textContent = fm(window._payTotal || 0);
  var resto = (window._payTarget || 0) - (window._payTotal || 0);
  var restEl = $('pay-restante');
  if (restEl) {
    restEl.textContent = resto > 0 ? fm(resto) : '$0';
    restEl.style.color = resto <= 0 ? '#059669' : '#dc2626';
  }
  var btn = $('pay-confirm-btn');
  if (btn) {
    btn.disabled = window._payTotal < window._payTarget;
    var cambio = window._payTotal - window._payTarget;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> ' + (cambio > 0 ? 'Cobrar ' + fm(window._payTarget) + ' (Cambio: ' + fm(cambio) + ')' : 'Cobrar ' + fm(window._payTotal));
  }
}

function confirmPayment() {
  if (_paymentSubmitting) return;
  var items = cart.map(function (c) { return { id_producto: c.id, cantidad: c.cant, precio_unitario: c.price }; });
  var pagos = window._payments;
  var total = window._payTarget;
  _paymentSubmitting = true;
  var confirmBtn = $('pay-confirm-btn');
  if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...'; }

  apiPost({
    accion: 'venta_crear', items: items, pagos: pagos,
    idempotency_key: _saleRequestKey,
    id_cotizacion: _pendingCotizacionId || 0,
    descuento: promoApplied ? promoApplied.descuento : 0,
    id_promocion: promoApplied ? promoApplied.id_promocion : 0,
    id_caja: cajaState.id_caja,
    cliente: selectedClient,
    tipo_documento: ($('doc-tipo') ? $('doc-tipo').value : 'BOLETA')
  }, function (d) {
    _paymentSubmitting = false;
    _saleRequestKey = null;
    closeModal();
    showReceipt(d, items, pagos);
    clearCart();
    loadDashboard();
    loadCajaState();
  }, function () {
    _paymentSubmitting = false;
    updatePaymentUI();
  });
}

/* ═══════════════════════════════════════════
   RECEIPT
   ═══════════════════════════════════════════ */
function showReceipt(data, items, pagos) {
  var now = new Date();
  var lines = '';
  for (var i = 0; i < items.length; i++) {
    var it = items[i];
    for (var j = 0; j < cart.length; j++) {
      if (cart[j].id === it.id_producto) {
        lines += '<div class="rec-line"><span>' + esc(cart[j].name) + ' x' + it.cantidad + '</span><span>' + fm(it.precio_unitario * it.cantidad) + '</span></div>';
        break;
      }
    }
  }
  
  // Calcular IVA según configuración
  var ivaPorcentaje = (configBoleta && configBoleta.iva_porcentaje) ? configBoleta.iva_porcentaje : 19;
  var neto = data.total;
  var iva = Math.round(neto * ivaPorcentaje / (100 + ivaPorcentaje));
  var exento = neto - iva;

  var payLines = '';
  for (var k = 0; k < pagos.length; k++) {
    payLines += '<div class="rec-line"><span>' + paymentMethodLabel(pagos[k].metodo) + '</span><span>' + fm(pagos[k].monto) + '</span></div>';
  }

  // Datos de la empresa desde configuración
  var nombreEmpresa = (configBoleta && configBoleta.nombre_empresa) ? configBoleta.nombre_empresa : 'Mi Empresa';
  var rutEmpresa = (configBoleta && configBoleta.rut_empresa) ? configBoleta.rut_empresa : '';
  var direccionEmpresa = (configBoleta && configBoleta.direccion) ? configBoleta.direccion : '';
  var telefonoEmpresa = (configBoleta && configBoleta.telefono) ? configBoleta.telefono : '';
  var emailEmpresa = (configBoleta && configBoleta.email) ? configBoleta.email : '';
  var logoEmpresaRaw = (configBoleta && configBoleta.logo) ? configBoleta.logo : '';
  var logoEmpresa = /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(logoEmpresaRaw) ? logoEmpresaRaw : '';
  var mensajeAgradecimiento = (configBoleta && configBoleta.mensaje_agradecimiento) ? configBoleta.mensaje_agradecimiento : '¡Gracias por su compra!';
  var mensajePie = (configBoleta && configBoleta.mensaje_pie) ? configBoleta.mensaje_pie : '';
  var mostrarDesgloseIVA = (configBoleta && configBoleta.mostrar_desglose_iva !== undefined) ? configBoleta.mostrar_desglose_iva : 1;
  var mostrarDescuento = (configBoleta && configBoleta.mostrar_descuento !== undefined) ? configBoleta.mostrar_descuento : 1;
  var mostrarRutCliente = (configBoleta && configBoleta.mostrar_rut_cliente !== undefined) ? configBoleta.mostrar_rut_cliente : 0;

  // Construir encabezado
  var headerHTML = '';
  if (logoEmpresa) {
    headerHTML += '<div style="text-align:center;margin-bottom:8px;"><img src="' + logoEmpresa + '" style="max-width:120px;max-height:80px;"></div>';
  }
  headerHTML += '<div class="rec-header">' + esc(nombreEmpresa) + '</div>';
  
  var infoEmpresa = '';
  if (rutEmpresa) infoEmpresa += 'RUT: ' + esc(rutEmpresa) + '<br>';
  if (direccionEmpresa) infoEmpresa += esc(direccionEmpresa) + '<br>';
  if (telefonoEmpresa) infoEmpresa += 'Tel: ' + esc(telefonoEmpresa) + '<br>';
  if (emailEmpresa) infoEmpresa += esc(emailEmpresa) + '<br>';
  
  if (infoEmpresa) {
    headerHTML += '<div style="text-align:center;font-size:10px;">' + infoEmpresa + '</div>';
  }
  
  headerHTML += '<div style="text-align:center;font-size:10px;margin-bottom:6px;">' + now.toLocaleString('es-CL') + '</div>';
  if (data.numero_documento) headerHTML += '<div style="text-align:center;font-size:11px;font-weight:700;margin-bottom:6px;">' + esc(data.numero_documento) + '</div>';
  
  // Información del cliente (si está configurado para mostrar)
  var clienteHTML = '';
  if (mostrarRutCliente && selectedClient && selectedClient.rut) {
    clienteHTML += '<div style="font-size:10px;margin-bottom:6px;padding:4px;background:#f8fafc;border-radius:4px;">';
    clienteHTML += '<strong>Cliente:</strong> ' + esc(selectedClient.nombre || 'Consumidor Final') + '<br>';
    clienteHTML += '<strong>RUT:</strong> ' + esc(selectedClient.rut);
    clienteHTML += '</div>';
  }

  // Desglose de impuestos
  var ivaHTML = '';
  if (mostrarDesgloseIVA) {
    ivaHTML += '<div class="rec-line"><span>Neto</span><span>' + fm(exento) + '</span></div>';
    ivaHTML += '<div class="rec-line"><span>IVA ' + ivaPorcentaje + '%</span><span>' + fm(iva) + '</span></div>';
  }

  // Descuento
  var descuentoHTML = '';
  if (mostrarDescuento && promoApplied) {
    descuentoHTML = '<div class="rec-line" style="color:#059669;"><span>Dto: ' + esc(promoApplied.descripcion) + '</span><span>-' + fm(promoApplied.descuento) + '</span></div>';
  }

  // Mensaje pie de página
  var pieHTML = '';
  if (mensajePie) {
    pieHTML = '<div style="text-align:center;font-size:9px;color:#64748b;margin-top:6px;font-style:italic;">' + esc(mensajePie) + '</div>';
  }

  showModal(`
    <div class="rec">
      ${headerHTML}
      <div class="rec-divider"></div>
      ${clienteHTML}
      ${lines}
      <div class="rec-divider"></div>
      ${ivaHTML}
      <div class="rec-line" style="font-weight:700;font-size:15px;"><span>TOTAL</span><span>${fm(neto)}</span></div>
      ${descuentoHTML}
      <div class="rec-divider"></div>
      <div style="font-weight:600;">Pagos:</div>
      ${payLines}
      ${data.cambio > 0 ? '<div class="rec-line"><span>Cambio</span><span>' + fm(data.cambio) + '</span></div>' : ''}
      <div class="rec-divider"></div>
      <div style="text-align:center;font-size:9px;color:#94a3b8;margin-top:6px;">Venta #${data.id_pedido}<br>${esc(mensajeAgradecimiento)}</div>
      ${pieHTML}
      <div class="mcb" style="margin-top:12px;">
        <button class="btn-p" onclick="printReceipt()"><i class="fas fa-print"></i> Imprimir</button>
        <button class="btn-g" onclick="closeModal()">Cerrar</button>
      </div>
    </div>
  `);
  window._lastReceiptHTML = document.querySelector('.rec').outerHTML;
}

function printReceipt() {
  try {
    var w = window.open('', '_blank', 'width=340,height=650');
    if (!w) { toast('Permita ventanas emergentes para imprimir', 'err'); return; }
    w.document.write('<!doctype html><html><head><meta charset="UTF-8"><title>Boleta</title><style>@page{size:80mm auto;margin:4mm}body{width:72mm;margin:0 auto;font-family:Consolas,monospace;font-size:12px;color:#000}.rec-header{text-align:center;font-size:18px;font-weight:800}.rec-line{display:flex;justify-content:space-between;gap:8px}.rec-divider{border-top:1px dashed #000;margin:6px 0}.mcb{display:none}img{max-width:120px;max-height:80px;object-fit:contain}</style></head><body>');
    w.document.write(window._lastReceiptHTML || '');
    w.document.write('</body></html>');
    w.document.close();

    var printed = false;
    function imprimirCuandoEsteLista() {
      if (printed) return;
      printed = true;
      w.focus();
      w.print();
      closeModal();
    }
    var imagenes = Array.prototype.slice.call(w.document.images || []);
    var pendientes = imagenes.filter(function(img) { return !img.complete; }).length;
    if (!pendientes) {
      setTimeout(imprimirCuandoEsteLista, 50);
      return;
    }
    imagenes.forEach(function(img) {
      if (img.complete) return;
      var terminar = function() {
        pendientes--;
        if (pendientes <= 0) setTimeout(imprimirCuandoEsteLista, 50);
      };
      img.onload = terminar;
      img.onerror = terminar;
    });
    setTimeout(imprimirCuandoEsteLista, 1800);
  } catch(e) {
    toast('Error al imprimir', 'err');
  }
}

/* ═══════════════════════════════════════════
   MODALS
   ═══════════════════════════════════════════ */
var _modalId = 0;

function showModal(html, wide) {
  _modalId++;
  var id = 'modal-' + _modalId;
  var m = document.createElement('div');
  m.className = 'mo show';
  m.id = id;
  m.innerHTML = '<div class="mc' + (wide ? ' wide' : '') + '">' + html + '</div>';
  m.addEventListener('click', function (e) { if (e.target === m) m.remove(); });
  document.body.appendChild(m);
  return m;
}

function closeModal() {
  var modals = document.querySelectorAll('.mo.show');
  if (modals.length) modals[modals.length - 1].remove();
}

function closeAllModals() {
  var modals = document.querySelectorAll('.mo.show');
  for (var i = 0; i < modals.length; i++) modals[i].remove();
}

/* ═══════════════════════════════════════════
   KEYBOARD SHORTCUTS
   ═══════════════════════════════════════════ */
function setupKeyboard() {
  document.addEventListener('keydown', function (e) {
    // Don't intercept when typing in inputs
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
      if (e.key === 'Enter' && e.target.id === 'search-input') { handleBarcode(); return; }
      if (e.key === 'Enter' && e.target.id === 'promo-input') { applyPromo(); return; }
      return;
    }
    if (document.querySelector('.mo.show') && e.key !== 'Escape') return;
    switch (e.key) {
      case 'Escape': var m = document.querySelector('.mo.show'); if (m) m.remove(); break;
      case 'F2': showPayment(); break;
      case 'F3': showClientSelector(); break;
      case 'F4': showHistorial(); break;
      case 'F8': showAbrirCaja(); break;
      case 'F9': if (cajaState && cajaState.estado === 'ABIERTA') showCerrarCaja(); break;
      case 'Delete': if (cart.length) { if (confirm('¿Limpiar carrito?')) clearCart(); } break;
    }
  });
  // Focus search on / key
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && e.target.tagName !== 'INPUT') { e.preventDefault(); $('search-input').focus(); }
  });
}

/* ═══════════════════════════════════════════
   HISTORIAL / QUICK ACTIONS
   ═══════════════════════════════════════════ */
function showHistorial() {
  apiGet({ accion: 'historial', page: 1 }, function (d) {
    var ventas = d.ventas || [];
    var lista = ventas.map(function (v) {
      var bc = v.anulado ? 'badge-INACTIVO' : 'badge-ACTIVO';
      return '<div class="cart-item" style="cursor:pointer;" onclick="showVentaDetalle(' + v.id_pedido + ')">' +
        '<div style="flex:1;"><strong>#' + v.id_pedido + '</strong> · ' + esc(v.cliente_nombre || 'CF') + '<br><span style="font-size:11px;color:#64748b;">' + v.fecha + ' · ' + fm(v.precio_total) + '</span></div>' +
        '<span class="badge ' + bc + '">' + (v.anulado ? 'Anulada' : 'OK') + '</span></div>';
    }).join('') || '<p style="color:#94a3b8;">Sin ventas</p>';

    showModal(`
      <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;"><i class="fas fa-history" style="color:#4f46e5;"></i> Historial de Ventas</h3>
      <div style="max-height:50vh;overflow-y:auto;">${lista}</div>
      <div class="mcb" style="margin-top:12px;"><button class="btn-g" onclick="closeModal()">Cerrar</button></div>
    `, true);
  });
}

function showVentaDetalle(id) {
  apiGet({ accion: 'venta_detalle', id: id }, function (v) {
    var itemsH = (v.items || []).map(function (it) {
      return '<div class="rec-line"><span>' + esc(it.nombre_producto || 'Producto') + ' x' + it.cantidad_pedida + '</span><span>' + fm(it.precio_total) + '</span></div>';
    }).join('');
    var pagosH = (v.pagos || []).map(function (p) {
      return '<div class="rec-line"><span>' + esc(p.nombre_metodo_pago) + '</span><span>' + fm(p.monto) + '</span></div>';
    }).join('');

    showModal(`
      <h3 style="font-size:18px;font-weight:700;margin-bottom:8px;"><i class="fas fa-receipt" style="color:#4f46e5;"></i> Venta #${v.id_pedido}</h3>
      <div style="font-size:13px;color:#64748b;margin-bottom:12px;">${v.fecha} · ${esc(v.cliente_nombre || 'Consumidor Final')}</div>
      <div class="rec">
        ${itemsH}
        <div class="rec-divider"></div>
        ${pagosH}
        <div class="rec-divider"></div>
        <div class="rec-line" style="font-weight:700;"><span>Total</span><span>${fm(v.precio_total)}</span></div>
        ${v.anulado ? '<div style="color:#dc2626;font-weight:600;margin-top:8px;">⚠ ANULADA</div>' : ''}
        ${v.devuelto ? '<div style="color:#d97706;font-weight:600;margin-top:4px;">⚠ Con devolución</div>' : ''}
      </div>
      <div class="mcb">
        <button class="btn-g" onclick="reimprimirVenta(${v.id_pedido})"><i class="fas fa-print"></i> Reimprimir</button>
        ${!v.anulado ? '<button class="btn-d" onclick="anularVenta(' + v.id_pedido + ')"><i class="fas fa-ban"></i> Anular</button>' : ''}
        ${!v.anulado ? '<button class="btn-p" onclick="showDevolucion(' + v.id_pedido + ')"><i class="fas fa-undo"></i> Devolución</button>' : ''}
        <button class="btn-g" onclick="closeModal()">Cerrar</button>
      </div>
    `);
  });
}

function reimprimirVenta(idPedido) {
  apiGet({ accion: 'documento', id: idPedido }, function (payload) {
    var d = payload.documento || {};
    var cfg = d.config || {};
    var logo = /^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(payload.logo || '') ? payload.logo : '';
    var lines = (d.items || []).map(function (it) {
      return '<div class="rec-line"><span>' + esc(it.nombre || 'Producto') + ' x' + it.cantidad + '</span><span>' + fm(it.subtotal) + '</span></div>';
    }).join('');
    var payments = (d.pagos || []).map(function (p) {
      return '<div class="rec-line"><span>' + paymentMethodLabel(p.metodo) + '</span><span>' + fm(p.monto) + '</span></div>';
    }).join('');
    var html = '<div class="rec">' +
      (logo ? '<div style="text-align:center;margin-bottom:8px;"><img src="' + logo + '" style="max-width:120px;max-height:80px;"></div>' : '') +
      '<div class="rec-header">' + esc(cfg.nombre_empresa || 'Mi Empresa') + '</div>' +
      '<div style="text-align:center;font-size:10px;">' + esc(d.numero_documento || ('Venta #' + idPedido)) + '<br>' + esc(d.fecha || '') + '</div>' +
      '<div class="rec-divider"></div>' + lines + '<div class="rec-divider"></div>' +
      '<div class="rec-line" style="font-weight:700;font-size:15px;"><span>TOTAL</span><span>' + fm(d.total) + '</span></div>' +
      '<div class="rec-divider"></div>' + payments +
      (d.vuelto > 0 ? '<div class="rec-line"><span>Vuelto</span><span>' + fm(d.vuelto) + '</span></div>' : '') +
      '<div style="text-align:center;font-size:9px;color:#64748b;margin-top:8px;">COPIA / REIMPRESIÓN</div>' +
      '<div class="mcb"><button class="btn-p" onclick="printReceipt()"><i class="fas fa-print"></i> Imprimir</button><button class="btn-g" onclick="closeModal()">Cerrar</button></div></div>';
    var modal = showModal(html);
    window._lastReceiptHTML = modal.querySelector('.rec').outerHTML;
  });
}

function anularVenta(id) {
  apiPost({ accion: 'venta_anular', id_pedido: id }, function () {
    toast('Venta anulada');
    closeModal();
    loadDashboard();
    loadCajaState();
  });
}

/* ═══════════════════════════════════════════
   DEVOLUCIONES
   ═══════════════════════════════════════════ */
function showDevolucion(idPedido) {
  apiGet({ accion: 'venta_detalle', id: idPedido }, function (v) {
    var items = (v.items || []).map(function (it, i) {
      var available = parseFloat(it.cantidad_disponible_devolucion || 0);
      if (available <= 0) return '';
      var step = String(it.cantidad_pedida).indexOf('.') >= 0 ? '0.001' : '1';
      return '<label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;"><input type="checkbox" class="dev-check" data-detail="' + it.id_detalle_pedido + '" data-id="' + it.id_producto + '"> ' + esc(it.nombre_producto) + ' <input type="number" class="dev-qty" min="' + step + '" max="' + available + '" step="' + step + '" value="' + available + '" style="width:80px;"> disponibles ' + available + ' (' + fm(it.precio_total) + ')</label>';
    }).join('');

    showModal(`
      <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;"><i class="fas fa-undo" style="color:#4f46e5;"></i> Devolución - Venta #${idPedido}</h3>
      <p style="font-size:12px;color:#64748b;margin-bottom:8px;">El sistema calculará si la devolución es parcial o total y el monto exacto.</p>
      <div class="fld" style="margin:8px 0;">${items}</div>
      <div class="fld"><label>Motivo</label><textarea id="dev-motivo" rows="2"></textarea></div>
      <div class="mcb">
        <button class="btn-g" onclick="closeModal()">Cancelar</button>
        <button class="btn-p" onclick="confirmDevolucion(${idPedido})"><i class="fas fa-undo"></i> Procesar Devolución</button>
      </div>
    `);
  });
}

function confirmDevolucion(idPedido) {
  var checks = document.querySelectorAll('.dev-check:checked');
  if (!checks.length) { toast('Seleccione productos a devolver', 'err'); return; }
  var items = [];
  for (var i = 0; i < checks.length; i++) {
    items.push({
      id_detalle_pedido: parseInt(checks[i].dataset.detail),
      id_producto: parseInt(checks[i].dataset.id),
      cantidad: parseFloat(checks[i].closest('label').querySelector('.dev-qty').value)
    });
  }
  var motivo = $('dev-motivo').value;
  if (!motivo || motivo.trim().length < 3) { toast('Indique el motivo de la devolución', 'err'); return; }

  apiPost({ accion: 'devolucion_crear', id_pedido: idPedido, items: items, motivo: motivo }, function (d) {
    toast('Devolución procesada: ' + fm(d.monto_devuelto));
    closeModal();
    loadDashboard();
    loadCajaState();
  });
}

/* ═══════════════════════════════════════════
   PROMOCIONES MANAGEMENT
   ═══════════════════════════════════════════ */
function loadPromociones() {
  apiGet({ accion: 'promociones' }, function (d) { window._promos = d.promociones || d || []; });
}

function showPromoManager() {
  var promos = window._promos || [];
  var lista = promos.map(function (p) {
    var bc = p.activo ? 'badge-ACTIVO' : 'badge-INACTIVO';
    return '<div style="display:flex;justify-content:space-between;padding:8px;border-bottom:1px solid #e2e8f0;"><div><strong>' + esc(p.nombre) + '</strong> <span class="badge ' + bc + '">' + (p.activo ? 'Activa' : 'Inactiva') + '</span><br><span style="font-size:11px;color:#64748b;">' + esc(p.codigo) + ' · ' + esc(p.tipo) + ' · Valor: ' + p.valor + '</span></div><button class="btn-d" style="padding:4px 10px;font-size:12px;" onclick="elimPromo(' + p.id_promocion + ')"><i class="fas fa-trash"></i></button></div>';
  }).join('') || '<p style="color:#94a3b8;">Sin promociones</p>';

  showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;"><i class="fas fa-tags" style="color:#4f46e5;"></i> Promociones</h3>
    <div style="margin-bottom:10px;"><button class="btn-p" style="padding:8px 16px;font-size:13px;" onclick="closeModal();showNewPromo()"><i class="fas fa-plus"></i> Nueva Promoción</button></div>
    <div style="max-height:40vh;overflow-y:auto;">${lista}</div>
    <div class="mcb"><button class="btn-g" onclick="closeModal()">Cerrar</button></div>
  `, true);
}

function showNewPromo() {
  showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:16px;"><i class="fas fa-plus-circle" style="color:#4f46e5;"></i> Nueva Promoción</h3>
    <div class="gr2">
      <div class="fld"><label>Nombre *</label><input id="np-nombre"></div>
      <div class="fld"><label>Código</label><input id="np-codigo" placeholder="Auto-gen si se deja vacío"></div>
      <div class="fld"><label>Tipo *</label><select id="np-tipo"><option value="DESCUENTO_PCT">% Descuento</option><option value="DESCUENTO_MONTO">$ Descuento</option><option value="2X1">2x1</option></select></div>
      <div class="fld"><label>Valor *</label><input id="np-valor" type="number" min="0"></div>
      <div class="fld"><label>Monto Mínimo</label><input id="np-mm" type="number" min="0"></div>
      <div class="fld"><label>Cant. Mínima</label><input id="np-mc" type="number" min="0"></div>
      <div class="fld"><label>Fecha Inicio</label><input id="np-fi" type="date"></div>
      <div class="fld"><label>Fecha Fin</label><input id="np-ff" type="date"></div>
      <div class="fld"><label>Hora Inicio</label><input id="np-hi" type="time"></div>
      <div class="fld"><label>Hora Fin</label><input id="np-hf" type="time"></div>
      <div class="fld"><label>Categoría</label><input id="np-cat" placeholder="Aplica a categoría"></div>
      <div class="fld" style="display:flex;align-items:center;gap:8px;padding-top:18px;"><input type="checkbox" id="np-comb"> <label style="margin:0;">Combinable con otras</label></div>
    </div>
    <div class="mcb"><button class="btn-g" onclick="closeModal()">Cancelar</button><button class="btn-p" onclick="savePromo()"><i class="fas fa-save"></i> Guardar</button></div>
  `);
}

function savePromo() {
  var nombre = $('np-nombre').value;
  var tipo = $('np-tipo').value;
  var valor = parseInt($('np-valor').value) || 0;
  if (!nombre || !tipo || !valor) { toast('Nombre, tipo y valor requeridos', 'err'); return; }
  apiPost({
    accion: 'promocion_crear',
    nombre: nombre, codigo: $('np-codigo').value, tipo: tipo, valor: valor,
    monto_minimo: parseInt($('np-mm').value) || 0, cantidad_minima: parseInt($('np-mc').value) || 0,
    fecha_inicio: $('np-fi').value, fecha_fin: $('np-ff').value,
    hora_inicio: $('np-hi').value, hora_fin: $('np-hf').value,
    aplica_categoria: $('np-cat').value, combinable: $('np-comb').checked
  }, function (d) {
    toast('Promoción creada: ' + d.codigo);
    closeModal();
    loadPromociones();
  });
}

function elimPromo(id) {
  apiPost({ accion: 'promocion_eliminar', id_promocion: id }, function () {
    toast('Promoción eliminada');
    closeModal();
    showPromoManager();
    loadPromociones();
  });
}

/* ═══════════════════════════════════════════
   COTIZACIONES
   ═══════════════════════════════════════════ */
function showCotizacion() {
  if (cart.length === 0) { toast('Carrito vacío', 'err'); return; }
  var total = parseInt($('cart-total-val').textContent) || 0;
  showModal(`
    <h3 style="font-size:18px;font-weight:700;margin-bottom:16px;"><i class="fas fa-file-invoice" style="color:#4f46e5;"></i> Nueva Cotización</h3>
    <div class="gr2">
      <div class="fld"><label>Cliente</label><input id="cot-cliente" value="${selectedClient ? esc(selectedClient.nombre) : ''}"></div>
      <div class="fld"><label>RUT</label><input id="cot-rut" value="${selectedClient ? esc(selectedClient.rut) : ''}"></div>
      <div class="fld"><label>Correo</label><input id="cot-mail" value="${selectedClient ? esc(selectedClient.correo) : ''}"></div>
      <div class="fld"><label>Teléfono</label><input id="cot-tel" value="${selectedClient ? esc(selectedClient.telefono) : ''}"></div>
    </div>
    <div class="fld"><label>Validez</label><input id="cot-validez" value="7 días"></div>
    <div class="fld"><label>Notas</label><textarea id="cot-notas" rows="2"></textarea></div>
    <div style="text-align:right;font-size:16px;font-weight:700;margin:8px 0;">Total: ${fm(total)}</div>
    <div class="mcb"><button class="btn-g" onclick="closeModal()">Cancelar</button><button class="btn-p" onclick="saveCotizacion()"><i class="fas fa-save"></i> Crear Cotización</button></div>
  `);
}

function saveCotizacion() {
  var items = cart.map(function (c) { return { id_producto: c.id, producto: c.name, sku: c.sku, cantidad: c.cant, precio_unitario: c.price }; });
  apiPost({
    accion: 'cotizacion_crear', items: items,
    cliente_nombre: $('cot-cliente').value, cliente_rut: $('cot-rut').value,
    cliente_correo: $('cot-mail').value, cliente_telefono: $('cot-tel').value,
    id_cliente: selectedClient ? selectedClient.id_cliente : 0,
    validez: $('cot-validez').value, notas: $('cot-notas').value,
    descuento: promoApplied ? promoApplied.descuento : 0
  }, function (d) {
    toast('Cotización ' + d.codigo + ' creada');
    closeModal();
  });
}

function showCotizacionesList() {
  apiGet({ accion: 'cotizaciones' }, function (d) {
    var quotes = d.cotizaciones || d || [];
    window._cotizaciones = quotes;
    var lista = quotes.map(function (c) {
      return '<div style="display:flex;justify-content:space-between;padding:8px;border-bottom:1px solid #e2e8f0;"><div><strong>' + esc(c.codigo) + '</strong> · ' + esc(c.cliente_nombre || 'Sin cliente') + '<br><span style="font-size:11px;color:#64748b;">' + c.fecha_creacion + ' · ' + fm(c.total) + ' · ' + (c.items || []).length + ' ítems</span></div><div>' + (c.convertida ? '<span class="badge badge-ACTIVO">Convertida</span>' : '<button class="btn-p" style="padding:4px 10px;font-size:12px;" onclick="convertirCot(' + c.id_cotizacion + ')" title="Cargar al carrito"><i class="fas fa-cart-plus"></i></button>') + '</div></div>';
    }).join('') || '<p style="color:#94a3b8;">Sin cotizaciones</p>';
    showModal(`
      <h3 style="font-size:18px;font-weight:700;margin-bottom:12px;"><i class="fas fa-file-invoice" style="color:#4f46e5;"></i> Cotizaciones</h3>
      <div style="max-height:50vh;overflow-y:auto;">${lista}</div>
      <div class="mcb"><button class="btn-g" onclick="closeModal()">Cerrar</button></div>
    `, true);
  });
}

function convertirCot(id) {
  var quote = null;
  for (var i=0;i<(window._cotizaciones||[]).length;i++) if (Number(window._cotizaciones[i].id_cotizacion)===Number(id)) quote=window._cotizaciones[i];
  if (!quote || Number(quote.convertida)) { toast('Cotización no disponible', 'err'); return; }
  var nextCart=[];
  for (var j=0;j<(quote.items||[]).length;j++) {
    var it=quote.items[j],qty=parseFloat(it.cantidad)||0,stock=parseFloat(it.stock_actual)||0;
    if (!it.id_producto || !Number(it.producto_activo)) { toast('La cotización contiene un producto inactivo', 'err'); return; }
    if (qty<=0 || stock<qty) { toast('Stock insuficiente para ' + esc(it.producto||'producto'), 'err'); return; }
    nextCart.push({id:parseInt(it.id_producto),name:it.producto||'Producto',price:parseInt(it.precio_unitario)||0,
      sku:it.sku||'',cant:qty,stock:stock,tipoVenta:it.tipo_venta||'UNIDAD',unidad:it.unidad_abrev||'u'});
  }
  if (!nextCart.length) { toast('Cotización sin productos', 'err'); return; }
  cart=nextCart;_pendingCotizacionId=parseInt(id);
  selectedClient=quote.id_cliente?{id_cliente:parseInt(quote.id_cliente),nombre:quote.cliente_nombre||'',rut:quote.cliente_rut||'',correo:quote.cliente_correo||'',telefono:quote.cliente_telefono||''}:null;
  $('cart-client').innerHTML=selectedClient?'<i class="fas fa-user-check"></i> '+esc(selectedClient.nombre||'Cliente'):'<i class="fas fa-user"></i> Consumidor Final';
  closeAllModals();renderCart();toast('Cotización cargada. Revise y presione Cobrar.');
}
