var totalPrice = 0;
var totalPayment = 0;
var change = 0;
var paymentRecords = [];
var cartItemsArray = [];

loadProducts();

/* ── Modal system ── */
function createModal(id, html) {
  var el = document.getElementById(id);
  if (!el) {
    el = document.createElement('div');
    el.id = id;
    el.className = 'pos-modal';
    el.innerHTML = html;
    document.body.appendChild(el);
    el.addEventListener('click', function(e) { if (e.target === el) closeModal(id); });
  }
  return el;
}

function closeModal(id) {
  var el = document.getElementById(id);
  if (el) el.style.display = 'none';
}

function openModal(id) {
  var el = document.getElementById(id);
  if (!el) return;
  el.style.display = 'flex';
  requestAnimationFrame(function() {
    var box = el.querySelector('.pos-modal-box');
    if (box) box.classList.add('show');
    var inp = el.querySelector('input');
    if (inp) { inp.focus(); inp.select(); }
  });
}

function modalAlert(msg) {
  var el = createModal('modal-alert',
    '<div class="pos-modal-box"><div class="pos-modal-icon"><i class="fas fa-exclamation-circle"></i></div><p id="modal-alert-text"></p><div class="modal-actions"><button class="modal-btn modal-btn-primary" onclick="closeModal(\'modal-alert\')">Aceptar</button></div></div>');
  document.getElementById('modal-alert-text').textContent = msg;
  openModal('modal-alert');
}

function modalConfirm(msg, cb) {
  var confirmCb = cb;
  var el = createModal('modal-confirm',
    '<div class="pos-modal-box"><div class="pos-modal-icon warn"><i class="fas fa-question-circle"></i></div><p id="modal-confirm-text"></p><div class="modal-actions"><button class="modal-btn modal-btn-ghost" onclick="closeModal(\'modal-confirm\')">Cancelar</button><button class="modal-btn modal-btn-danger" id="modal-confirm-yes">Confirmar</button></div></div>');
  document.getElementById('modal-confirm-text').textContent = msg;
  document.getElementById('modal-confirm-yes').onclick = function() { closeModal('modal-confirm'); if (confirmCb) confirmCb(); };
  openModal('modal-confirm');
}

function modalPrompt(msg, defaultVal, opts, cb) {
  if (typeof opts === 'function') { cb = opts; opts = {}; }
  opts = opts || {};
  var icon = opts.icon || 'dollar-sign';
  var iconClass = opts.iconClass || 'input-icon';
  var inputType = opts.type || 'number';
  var cbFn = cb;

  var el = createModal('modal-prompt',
    '<div class="pos-modal-box"><div class="pos-modal-icon ' + iconClass + '"><i class="fas fa-' + icon + '"></i></div><p id="modal-prompt-text"></p><input type="' + inputType + '" id="modal-prompt-input" class="modal-input" min="0" step="1"><div class="modal-actions"><button class="modal-btn modal-btn-ghost" onclick="closeModal(\'modal-prompt\')">Cancelar</button><button class="modal-btn modal-btn-primary" id="modal-prompt-ok">Aceptar</button></div></div>');

  document.getElementById('modal-prompt-text').textContent = msg;
  var input = document.getElementById('modal-prompt-input');
  input.value = defaultVal !== undefined ? defaultVal : '';
  input.type = inputType;

  document.getElementById('modal-prompt-ok').onclick = function() {
    var val = input.value;
    closeModal('modal-prompt');
    if (cbFn) cbFn(val);
  };
  input.onkeydown = function(e) {
    if (e.key === 'Enter') { document.getElementById('modal-prompt-ok').click(); }
    if (e.key === 'Escape') { closeModal('modal-prompt'); }
  };
  openModal('modal-prompt');
}

/* ── Toast ── */
function showToast(msg, type) {
  var t = document.createElement('div');
  t.className = 'pos-toast pos-toast-' + (type || 'success');
  t.innerHTML = msg;
  document.body.appendChild(t);
  requestAnimationFrame(function() { t.classList.add('show'); });
  setTimeout(function() { t.classList.remove('show'); setTimeout(function() { t.remove(); }, 300); }, 2200);
}

/* ── Cart UI ── */
function updateCartUI() {
  var cart = document.getElementById('cart-items');
  var items = cart.querySelectorAll('li:not(.empty-cart)');
  var emptyMsg = document.getElementById('empty-cart-msg');
  var totals = document.getElementById('cart-totals');
  var count = document.getElementById('cart-count');

  if (items.length === 0) {
    emptyMsg.style.display = 'block';
    totals.style.display = 'none';
    count.textContent = '0 productos';
    document.getElementById('subtotal-amount').textContent = '$0';
    document.getElementById('total-amount').textContent = '$0';
    document.getElementById('payment-amount').textContent = '$0';
    document.getElementById('change-amount').textContent = '$0';
    return;
  }

  emptyMsg.style.display = 'none';
  totals.style.display = 'block';
  count.textContent = items.length + ' producto' + (items.length !== 1 ? 's' : '');

  var total = 0;
  for (var i = 0; i < items.length; i++) {
    var itemText = items[i].innerText;
    var price = parseFloat(itemText.split('$').pop());
    total += price;
  }

  totalPrice = Math.round(total);
  document.getElementById('subtotal-amount').textContent = '$' + totalPrice;
  document.getElementById('total-amount').textContent = '$' + totalPrice;

  var paymentTotal = paymentRecords.reduce(function(t, r) { return t + r.value; }, 0);
  totalPayment = Math.round(paymentTotal);
  change = Math.round(totalPayment - totalPrice);

  document.getElementById('payment-amount').textContent = '$' + totalPayment;
  document.getElementById('change-amount').textContent = (change >= 0 ? '' : '-') + '$' + Math.abs(change);
}

/* ── Pay button ── */
document.querySelector('.pagar-btn').addEventListener('click', function () {
  var totalPriceVal = totalPrice;
  var iva = Math.round(totalPriceVal * 0.19);
  var changeVal = Math.round(totalPayment - totalPriceVal);

  var cartItems = storeCartItems();
  var payments = storePaymentsRecord();

  if (cartItems.length === 0) {
    modalAlert('El carrito está vacío');
    return;
  }
  if (payments.length === 0) {
    modalAlert('Debe seleccionar al menos un método de pago');
    return;
  }

  var paymentRecordsJson = JSON.stringify(payments);
  var cartItemsArrayJson = JSON.stringify(cartItems);
  var saleData = { totalPrice: totalPriceVal, totalPayment: totalPayment, change: changeVal };
  var saleDataJson = JSON.stringify(saleData);

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/PHP/pedidos.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function () {
    if (xhr.readyState == 4 && xhr.status == 200) {
      var receiptContent = '<style>body{font-family:"Segoe UI",Arial,sans-serif;font-size:10px;padding:20px;color:#1e293b}h1{font-size:14px;font-weight:700;margin-bottom:4px;text-align:center}.ci{font-size:8px;text-align:center;color:#64748b;margin-bottom:12px}.rs{margin-bottom:8px}.rs h2{font-size:11px;font-weight:600;margin-bottom:4px;border-bottom:1px dashed #cbd5e1;padding-bottom:4px}p{margin-bottom:2px}ul{list-style:none;padding-left:0;margin-top:0}li{margin-bottom:2px;display:flex;justify-content:space-between}.tl{display:flex;justify-content:space-between;font-weight:600}.gt{font-size:13px;font-weight:700;border-top:1px solid #1e293b;padding-top:4px;margin-top:4px}.thx{text-align:center;font-style:italic;color:#64748b;margin-top:12px}</style>';
      receiptContent += '<div class="rs"><h1>MiniMarket "San Fernando"</h1><div class="ci"><p>RUT: 76086428-5</p><p>distribuidoralamartina@gmail.com</p></div></div>';
      receiptContent += '<div class="rs"><h2>Productos</h2><ul>';
      cartItems.forEach(function (item) {
        receiptContent += '<li><span>' + item.name + ' x' + item.quantity + '</span><span>$' + Math.round(item.price * item.quantity) + '</span></li>';
      });
      receiptContent += '</ul></div><div class="rs"><h2>Resumen de Pago</h2>';
      receiptContent += '<div class="tl"><span>Valor neto:</span><span>$' + Math.round(totalPriceVal - iva) + '</span></div>';
      receiptContent += '<div class="tl"><span>IVA (19%):</span><span>$' + iva + '</span></div>';
      receiptContent += '<div class="gt tl"><span>Valor total:</span><span>$' + totalPriceVal + '</span></div>';
      receiptContent += '<div class="tl"><span>Pago:</span><span>$' + totalPayment + '</span></div>';
      receiptContent += '<div class="tl"><span>Cambio:</span><span>$' + changeVal + '</span></div>';
      receiptContent += '<div class="thx">Gracias por su compra!</div>';

      var doc = document.implementation.createHTMLDocument('');
      doc.body.innerHTML = receiptContent;
      var w = window.open('', '_blank');
      w.document.write(doc.documentElement.outerHTML);
      w.document.close();
      w.print();
      w.document.addEventListener('click', function () { w.close(); });
      w.addEventListener('keydown', function (e) { if (e.keyCode === 27) w.close(); });
      w.addEventListener('unload', function () { location.reload(); });
    }
  };
  xhr.send(JSON.stringify({ saleData: saleDataJson, paymentRecords: paymentRecordsJson, cartItemsArray: cartItemsArrayJson }));
});

function storeCartItems() {
  var items = [];
  var cartItems = document.getElementById('cart-items').querySelectorAll('li:not(.empty-cart)');
  for (var i = 0; i < cartItems.length; i++) {
    var el = cartItems[i];
    var text = el.innerText;
    var dollarIndex = text.lastIndexOf('$');
    var itemPrice = parseFloat(text.substring(dollarIndex + 1).trim());
    var nameQty = text.substring(0, dollarIndex).trim();
    var qtyIdx = nameQty.lastIndexOf('x');
    items.push({
      id_producto: el.getAttribute('data-id'),
      name: nameQty.substring(0, qtyIdx).trim(),
      price: itemPrice,
      quantity: parseInt(nameQty.substring(qtyIdx + 1).trim())
    });
  }
  return items;
}

function setPaymentAmountAndType(paymentType) {
  var total = totalPrice;
  var totalPayments = paymentRecords.reduce(function (t, r) { return t + r.value; }, 0);
  var difference = Math.max(total - totalPayments, 0);
  modalPrompt('Ingrese el monto pagado en ' + paymentType + ':', difference, function(val) {
    var amount = parseFloat(val);
    if (!isNaN(amount) && amount > 0) {
      amount = Math.round(Math.max(amount, 0));
      paymentRecords.push({ name: paymentType, value: amount });
      totalPayment = paymentRecords.reduce(function (t, r) { return t + r.value; }, 0);
      updateCartUI();
      showToast('<i class="fas fa-check-circle"></i> ' + paymentType + ': $' + amount.toLocaleString('es-CL') + ' registrado');
    }
  });
}

function storePaymentsRecord() { return paymentRecords; }

function loadProducts() {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/PHP/obtener_productos.php', true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4 && xhr.status === 200) {
      var productos = JSON.parse(xhr.responseText);
      var grid = document.getElementById('product-grid');
      grid.innerHTML = '';
      productos.forEach(function (p) {
        var idP = p.id_producto;
        var div = document.createElement('div');
        div.classList.add('product');
        var overlay = document.createElement('div');
        overlay.classList.add('overlay');
        overlay.addEventListener('click', function () { addToCart(p.nombre_producto, Math.round(parseFloat(p.precio_venta)), idP); });
        var name = document.createElement('h3');
        name.textContent = p.nombre_producto;
        var price = document.createElement('div');
        price.className = 'product-price';
        price.textContent = '$' + Math.round(parseFloat(p.precio_venta));
        var bc = document.createElement('div');
        bc.className = 'product-barcode';
        bc.textContent = p.codigo_de_barras || '';
        div.appendChild(overlay); div.appendChild(name); div.appendChild(price); div.appendChild(bc);
        grid.appendChild(div);
      });
    }
  };
  xhr.send();
}

var searchInput = document.getElementById('search-input');

function searchProducts() {
  var text = searchInput.value.trim();
  if (text !== '') {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '../assets/PHP/obtener_productos.php?search=' + encodeURIComponent(text), true);
    xhr.onload = function () { if (xhr.status >= 200 && xhr.status < 300) mostrarResultadosBusqueda(JSON.parse(xhr.responseText)); };
    xhr.send();
  }
}

function mostrarResultadosBusqueda(resultados) {
  var grid = document.getElementById('product-grid');
  grid.innerHTML = '';
  if (resultados.length > 0) {
    resultados.forEach(function (p) {
      var idP = p.id_producto;
      var div = document.createElement('div');
      div.classList.add('product');
      var overlay = document.createElement('div');
      overlay.classList.add('overlay');
      overlay.addEventListener('click', function () { addToCart(p.nombre_producto, Math.round(parseFloat(p.precio_venta)), idP); });
      var name = document.createElement('h3');
      name.textContent = p.nombre_producto;
      var price = document.createElement('div');
      price.className = 'product-price';
      price.textContent = '$' + Math.round(parseFloat(p.precio_venta));
      var bc = document.createElement('div');
      bc.className = 'product-barcode';
      bc.textContent = p.codigo_de_barras || '';
      div.appendChild(overlay); div.appendChild(name); div.appendChild(price); div.appendChild(bc);
      grid.appendChild(div);
    });
  } else {
    grid.innerHTML = '<p style="text-align:center;color:#94a3b8;padding:40px;">No se encontraron resultados.</p>';
  }
}

searchInput.addEventListener('keydown', function(e) { if (e.keyCode === 13) searchProducts(); });

document.addEventListener('keydown', function (e) { if (e.key === 'Delete') RemoveLastItem(); });

function RemoveLastItem() {
  var cart = document.getElementById('cart-items');
  var items = cart.querySelectorAll('li:not(.empty-cart)');
  var last = items[items.length - 1];
  if (last) { cart.removeChild(last); updateCartUI(); }
}

function removeFromCart(productName) {
  var items = document.querySelectorAll('#cart-items li:not(.empty-cart)');
  for (var i = 0; i < items.length; i++) {
    var text = items[i].innerText;
    var dollarIdx = text.lastIndexOf('$');
    var namePart = text.substring(0, dollarIdx).trim();
    var qtyIdx = namePart.lastIndexOf('x');
    var name = namePart.substring(0, qtyIdx).trim();
    if (name === productName) { items[i].parentNode.removeChild(items[i]); updateCartUI(); return; }
  }
}

function addToCart(productName, productPrice, idProducto) {
  var cart = document.getElementById('cart-items');
  var items = cart.querySelectorAll('li:not(.empty-cart)');
  var existing = null;
  for (var i = 0; i < items.length; i++) {
    var text = items[i].innerText;
    var dollarIdx = text.lastIndexOf('$');
    var namePart = text.substring(0, dollarIdx).trim();
    var qtyIdx = namePart.lastIndexOf('x');
    var name = namePart.substring(0, qtyIdx).trim();
    if (name === productName) { existing = items[i]; break; }
  }

  if (existing) {
    var qty = parseInt(existing.getAttribute('data-quantity')) + 1;
    existing.setAttribute('data-quantity', qty);
    existing.innerHTML = '<span class="item-info"><span class="item-name">' + productName + '</span><span class="item-meta">x' + qty + '</span></span><span class="item-price">$' + Math.round(productPrice * qty) + '</span><button class="cart-remove-button"><i class="fas fa-times"></i></button>';
  } else {
    var li = document.createElement('li');
    li.setAttribute('data-name', productName);
    li.setAttribute('data-quantity', '1');
    li.setAttribute('data-id', idProducto);
    li.innerHTML = '<span class="item-info"><span class="item-name">' + productName + '</span><span class="item-meta">x1</span></span><span class="item-price">$' + Math.round(productPrice) + '</span><button class="cart-remove-button"><i class="fas fa-times"></i></button>';
    cart.appendChild(li);
  }

  updateCartUI();
  reattachRemoveButtons();
}

function getTotalPrice() { return totalPrice; }

// Payment buttons
document.getElementById('efectivo-btn').addEventListener('click', function () { setPaymentAmountAndType('Efectivo'); });
document.getElementById('tarjeta-btn').addEventListener('click', function () { setPaymentAmountAndType('Tarjeta'); });
document.getElementById('transferencia-btn').addEventListener('click', function () { setPaymentAmountAndType('Transferencia'); });

// Cancel
document.getElementById('cancelar-venta').addEventListener('click', function () {
  modalConfirm('¿Cancelar la venta actual?', function() { window.location.reload(); });
});

// Click on cart item to modify price
document.getElementById('cart-items').addEventListener('click', function(e) {
  var li = e.target.closest('li:not(.empty-cart)');
  if (!li) return;
  if (e.target.closest('.cart-remove-button')) return;
  var text = li.innerText;
  var dollarIdx = text.lastIndexOf('$');
  var namePart = text.substring(0, dollarIdx).trim();
  var qtyIdx = namePart.lastIndexOf('x');
  var name = namePart.substring(0, qtyIdx).trim();
  var currentPrice = parseInt(text.substring(dollarIdx + 1).trim()) || 0;
  var qty = parseInt(li.getAttribute('data-quantity')) || 1;
  var unitPrice = Math.round(currentPrice / qty);

  modalPrompt('Nuevo precio unitario para <strong>' + name + '</strong>:', unitPrice, { icon: 'edit', iconClass: 'input-icon' }, function(val) {
    var newPrice = parseInt(val);
    if (!isNaN(newPrice) && newPrice >= 0) {
      li.setAttribute('data-quantity', qty);
      li.innerHTML = '<span class="item-info"><span class="item-name">' + name + '</span><span class="item-meta">x' + qty + '</span></span><span class="item-price">$' + Math.round(newPrice * qty) + '</span><button class="cart-remove-button"><i class="fas fa-times"></i></button>';
      reattachRemoveButtons();
      updateCartUI();
      showToast('<i class="fas fa-check-circle"></i> Precio actualizado: <strong>$' + Math.round(newPrice) + '</strong>');
    }
  });
});

function reattachRemoveButtons() {
  var cart = document.getElementById('cart-items');
  var allItems = cart.querySelectorAll('li:not(.empty-cart)');
  for (var j = 0; j < allItems.length; j++) {
    var btn = allItems[j].querySelector('.cart-remove-button');
    if (btn) {
      btn.onclick = function(e) {
        e.stopPropagation();
        var li = this.closest('li');
        var text = li.innerText;
        var dollarIdx = text.lastIndexOf('$');
        var namePart = text.substring(0, dollarIdx).trim();
        var qtyIdx = namePart.lastIndexOf('x');
        var name2 = namePart.substring(0, qtyIdx).trim();
        removeFromCart(name2);
      };
    }
  }
}

// * key: modify last item price
document.addEventListener('keydown', function(e) {
  if (e.key === '*') {
    if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA')) return;
    var cart = document.getElementById('cart-items');
    var items = cart.querySelectorAll('li:not(.empty-cart)');
    var last = items[items.length - 1];
    if (!last) return;
    var text = last.innerText;
    var dollarIdx = text.lastIndexOf('$');
    var namePart = text.substring(0, dollarIdx).trim();
    var qtyIdx = namePart.lastIndexOf('x');
    var name = namePart.substring(0, qtyIdx).trim();
    var currentPrice = parseInt(text.substring(dollarIdx + 1).trim()) || 0;
    var qty = parseInt(last.getAttribute('data-quantity')) || 1;
    var unitPrice = Math.round(currentPrice / qty);

    modalPrompt('Nuevo precio unitario para <strong>' + name + '</strong>:', unitPrice, { icon: 'edit', iconClass: 'input-icon' }, function(val) {
      var newPrice = parseInt(val);
      if (!isNaN(newPrice) && newPrice >= 0) {
        last.setAttribute('data-quantity', qty);
        last.innerHTML = '<span class="item-info"><span class="item-name">' + name + '</span><span class="item-meta">x' + qty + '</span></span><span class="item-price">$' + Math.round(newPrice * qty) + '</span><button class="cart-remove-button"><i class="fas fa-times"></i></button>';
        reattachRemoveButtons();
        updateCartUI();
        showToast('<i class="fas fa-check-circle"></i> Precio actualizado: <strong>$' + Math.round(newPrice) + '</strong>');
      }
    });
  }
});

// Barcode
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && document.activeElement.tagName === 'INPUT') {
    var val = document.activeElement.value;
    document.activeElement.value = '';
    handleBarcodeScan(val);
  }
});

function handleBarcodeScan(barcode) {
  if (barcode.trim() === '') { loadProducts(); return; }
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/PHP/obtener_productos.php', true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState === 4 && xhr.status === 200) {
      var productos = JSON.parse(xhr.responseText);
      for (var i = 0; i < productos.length; i++) {
        if (productos[i].codigo_de_barras === barcode) {
          addToCart(productos[i].nombre_producto, Math.round(parseFloat(productos[i].precio_venta)), productos[i].id_producto);
          return;
        }
      }
    }
  };
  xhr.send();
}

// Keyboard shortcuts
function handleShortcut(e) {
  switch (e.key) {
    case 'Insert': document.getElementById('efectivo-btn').click(); break;
    case 'Home': document.getElementById('tarjeta-btn').click(); break;
    case 'End': document.getElementById('transferencia-btn').click(); break;
    case 'PageUp': document.getElementById('pagar-btn').click(); break;
  }
}
document.addEventListener('keydown', handleShortcut);

// Focus
document.addEventListener('DOMContentLoaded', function() {
  var si = document.getElementById('search-input');
  var popup = document.getElementById('popup');
  document.addEventListener('click', function(e) { if (!popup.contains(e.target)) si.focus(); });
  si.focus();
  updateCartUI();
});
