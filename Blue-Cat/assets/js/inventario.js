// ========== GLOBALS ==========
var API = '../assets/api/inventario.php';

function api(accion, data, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      if (xhr.status >= 200 && xhr.status < 300) {
        try { cb(JSON.parse(xhr.responseText)); } catch(e) { cb(xhr.responseText); }
      } else {
        try { var e = JSON.parse(xhr.responseText);
          if (window.SupervisorApproval && window.SupervisorApproval.handle(e, function(token) { data.supervisor_token=token; api(accion,data,cb); })) return;
          toast(e.message || (typeof e.error==='string' ? e.error : 'Error'), 'error');
        } catch(ex) { toast('Error de conexión', 'error'); }
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

function num(n) {
  var value = Number.parseFloat(n);
  return Number.isFinite(value) ? value : 0;
}

function fmt(n) { return (num(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

function closeModal() { document.getElementById('modal-overlay').style.display = 'none'; }

function openModal(html) {
  document.getElementById('modal-body').innerHTML = html;
  document.getElementById('modal-overlay').style.display = 'block';
}

// ========== SECTION SWITCHER ==========
function switchSection(section) {
  document.querySelectorAll('.section-title').forEach(function(el) { el.classList.remove('active'); });
  document.querySelectorAll('.inv-sidebar li').forEach(function(el) { el.classList.remove('active'); });
  document.getElementById('section-' + section).classList.add('active');
  document.querySelector('.inv-sidebar li[data-section="' + section + '"]').classList.add('active');
  switch(section) {
    case 'dashboard': loadDashboard(); break;
    case 'productos': loadCategoriasSelect(); loadMarcasSelect(); loadProductos(); break;
    case 'categorias': loadCategorias(); break;
    case 'marcas': loadMarcas(); break;
    case 'bodegas': loadBodegas(); break;
    case 'stock': loadSelectBodegas('filter-stock-bodega'); loadStock(); break;
    case 'movimientos': loadMovimientos(); break;
    case 'transferencias': loadTransferencias(); break;
    case 'ajustes': loadAjustes(); break;
    case 'fisico': loadFisicos(); break;
    case 'kardex': loadProductosSelect('filter-kardex-producto'); loadKardex(); break;
    case 'lotes': loadLotes(); break;
    case 'series': loadSeries(); break;
    case 'alertas': loadAlertas(); break;
    case 'reportes': switchReporte('existencias'); break;
    case 'auditoria': loadAuditoria(); break;
  }
}

// ========== DASHBOARD ==========
function loadDashboard() {
  api('dashboard', {}, function(d) {
    var el = document.getElementById('stats-bar');
    el.innerHTML =
      '<div class="stat"><span class="stat-icon blue"><i class="fas fa-cube"></i></span><div><div class="stat-num">' + d.total_productos + '</div><div class="stat-label">Productos</div></div></div>' +
      '<div class="stat"><span class="stat-icon amber"><i class="fas fa-exclamation-triangle"></i></span><div><div class="stat-num">' + d.stock_critico + '</div><div class="stat-label">Stock crítico</div></div></div>' +
      '<div class="stat"><span class="stat-icon red"><i class="fas fa-times-circle"></i></span><div><div class="stat-num">' + d.sin_stock + '</div><div class="stat-label">Sin stock</div></div></div>' +
      '<div class="stat"><span class="stat-icon green"><i class="fas fa-dollar-sign"></i></span><div><div class="stat-num">$' + fmt(d.valor_inventario) + '</div><div class="stat-label">Valor inventario</div></div></div>' +
      '<div class="stat"><span class="stat-icon purple"><i class="fas fa-warehouse"></i></span><div><div class="stat-num">' + d.bodegas + '</div><div class="stat-label">Bodegas</div></div></div>' +
      '<div class="stat"><span class="stat-icon blue"><i class="fas fa-tags"></i></span><div><div class="stat-num">' + d.alertas + '</div><div class="stat-label">Alertas</div></div></div>';

    // Charts
    var catBars = document.getElementById('chart-cat-bars');
    catBars.innerHTML = d.chart_categorias.map(function(c) { return '<div class="chart-bar"><span class="chart-bar-label">' + esc(c.label) + '</span><div class="chart-bar-fill" style="width:' + Math.max(4, c.value * 3) + 'px"></div><span style="color:#64748b;font-size:12px;">' + c.value + '</span></div>'; }).join('') || '<p style="color:#94a3b8;font-size:13px;">Sin datos</p>';

    var stockBars = document.getElementById('chart-stock-bars');
    stockBars.innerHTML = d.chart_stock_bodega.map(function(c) { return '<div class="chart-bar"><span class="chart-bar-label">' + esc(c.label) + '</span><div class="chart-bar-fill" style="width:' + Math.max(4, c.value / 10) + 'px;background:#059669;"></div><span style="color:#64748b;font-size:12px;">' + fmt(c.value) + '</span></div>'; }).join('') || '<p style="color:#94a3b8;font-size:13px;">Sin datos</p>';

    var es = document.getElementById('chart-ent-sal');
    es.innerHTML = '<table style="width:100%;font-size:12px;"><thead><tr><th>Fecha</th><th>Entradas</th><th>Salidas</th></tr></thead><tbody>' + (d.chart_entradas_salidas || []).map(function(r) { return '<tr><td>' + r.fecha + '</td><td>' + r.entradas + '</td><td>' + r.salidas + '</td></tr>'; }).join('') + '</tbody></table>';
  });
}

// ========== PRODUCTOS ==========
function loadProductos() {
  var search = document.getElementById('search-productos').value;
  var cat = document.getElementById('filter-categoria').value;
  var marca = document.getElementById('filter-marca').value;
  var estado = document.getElementById('filter-estado').value;
  api('productos', { search: search, id_categoria: num(cat), id_marca: num(marca), estado: estado, limit: 200 }, function(r) {
    var tbody = document.getElementById('tbody-productos');
    document.getElementById('product-count').textContent = r.total + ' productos';
    if (!r.items || !r.items.length) {
      tbody.innerHTML = '<tr><td colspan="11"><div class="empty-state"><i class="fas fa-box-open"></i><p>No hay productos</p></div></td></tr>';
      return;
    }
    tbody.innerHTML = r.items.map(function(p) {
      var badge = p.activo == 0 ? '<span class="badge badge-danger">Inactivo</span>' :
        (num(p.cantidad) === 0 ? '<span class="badge badge-danger">Sin stock</span>' :
        (num(p.cantidad) <= num(p.stock_minimo) && p.stock_minimo > 0 ? '<span class="badge badge-warning">Stock bajo</span>' : '<span class="badge badge-success">' + num(p.cantidad) + ' uds</span>'));
      return '<tr><td class="cell-id">#' + p.id_producto + '</td><td><a href="#" onclick="showProductEditForm(' + p.id_producto + ');return false;" style="color:#4f46e5;font-weight:500;">' + esc(p.nombre_producto) + '</a></td><td>' + esc(p.codigo_de_barras) + '</td><td>' + esc(p.sku) + '</td><td>' + esc(p.categoria_nombre) + '</td><td>' + esc(p.marca_nombre) + '</td><td>' + badge + '</td><td>$' + fmt(p.precio_costo) + '</td><td class="cell-price">$' + fmt(p.precio_venta) + '</td><td>' + (p.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>') + '</td><td><button class="btn btn-outline btn-xs" onclick="showProductDetail(' + p.id_producto + ')" title="Ver detalle"><i class="fas fa-eye"></i></button> <button class="btn btn-outline btn-xs" onclick="showProductEditForm(' + p.id_producto + ')" title="Editar"><i class="fas fa-edit"></i></button></td></tr>';
    }).join('');
  });
}

document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('search-productos').addEventListener('input', function() { clearTimeout(window._ps); window._ps = setTimeout(loadProductos, 300); });
});

function showProductDetail(id) {
  api('producto', { id: id }, function(p) {
    var stockHtml = (p.stock_bodegas || []).map(function(s) {
      return '<tr><td>' + esc(s.bodega_nombre) + '</td><td>' + num(s.disponible) + '</td><td>' + num(s.reservado) + '</td><td>' + num(s.comprometido) + '</td><td>' + num(s.en_transito) + '</td></tr>';
    }).join('') || '<tr><td colspan="5" style="color:#94a3b8;">Sin stock registrado</td></tr>';

    var loteHtml = (p.lotes || []).map(function(l) {
      var venc = l.fecha_vencimiento ? new Date(l.fecha_vencimiento) : null;
      var vencClass = venc && venc < new Date() ? 'badge-danger' : (venc && (venc - new Date()) < 30*86400000 ? 'badge-warning' : 'badge-info');
      return '<tr><td>' + esc(l.numero_lote) + '</td><td>' + esc(l.proveedor) + '</td><td>' + (l.fecha_vencimiento || '-') + '</td><td>' + num(l.cantidad) + '</td><td><span class="badge ' + vencClass + '">' + l.estado + '</span></td></tr>';
    }).join('');

    openModal(
      '<div class="modal-body">' +
      '<div class="kpi-row">' +
        '<div class="kpi-item"><div class="kpi-value">$' + fmt(p.precio_venta) + '</div><div class="kpi-label">Precio venta</div></div>' +
        '<div class="kpi-item"><div class="kpi-value">$' + fmt(p.precio_costo) + '</div><div class="kpi-label">Costo</div></div>' +
        '<div class="kpi-item"><div class="kpi-value">' + num(p.cantidad) + '</div><div class="kpi-label">Stock total</div></div>' +
      '</div>' +
      '<div class="tab-bar">' +
        '<button class="tab-btn active" onclick="switchProdTab(\'general\',this)">General</button>' +
        '<button class="tab-btn" onclick="switchProdTab(\'stock\',this)">Stock x Bodega</button>' +
        '<button class="tab-btn" onclick="switchProdTab(\'lotes\',this)">Lotes</button>' +
      '</div>' +
      '<div id="prod-tab-general">' +
        '<div class="form-grid">' +
          '<div><label>Nombre</label><div style="font-size:14px;padding:8px 0;">' + esc(p.nombre_producto) + '</div></div>' +
          '<div><label>Código Barras</label><div style="font-size:14px;padding:8px 0;">' + esc(p.codigo_de_barras) + '</div></div>' +
          '<div><label>SKU</label><div style="font-size:14px;padding:8px 0;">' + esc(p.sku) + '</div></div>' +
          '<div><label>Categoría</label><div style="font-size:14px;padding:8px 0;">' + esc(p.categoria_nombre) + '</div></div>' +
          '<div><label>Marca</label><div style="font-size:14px;padding:8px 0;">' + esc(p.marca_nombre) + '</div></div>' +
          '<div><label>Tipo</label><div style="font-size:14px;padding:8px 0;">' + p.tipo + '</div></div>' +
          '<div class="full"><label>Descripción</label><div style="font-size:14px;padding:8px 0;">' + esc(p.descripcion) + '</div></div>' +
        '</div>' +
      '</div>' +
      '<div id="prod-tab-stock" style="display:none;"><table><thead><tr><th>Bodega</th><th>Disponible</th><th>Reservado</th><th>Comprometido</th><th>Tránsito</th></tr></thead><tbody>' + stockHtml + '</tbody></table></div>' +
      '<div id="prod-tab-lotes" style="display:none;"><table><thead><tr><th>Lote</th><th>Proveedor</th><th>Vencimiento</th><th>Cantidad</th><th>Estado</th></tr></thead><tbody>' + loteHtml + '</tbody></table></div>' +
      '<div style="margin-top:12px;display:flex;gap:8px;">' +
        '<button class="btn btn-primary btn-sm" onclick="showProductEditForm(' + p.id_producto + ')"><i class="fas fa-edit"></i> Editar</button>' +
        '<button class="btn btn-danger btn-sm" onclick="if(confirm(\'Desactivar producto?\')){api(\'producto_eliminar\',{id:' + p.id_producto + '},function(){toast(\'Producto desactivado\');loadProductos();closeModal();})}"><i class="fas fa-ban"></i> Desactivar</button>' +
      '</div></div>'
    );
  });
}

function switchProdTab(tab, btn) {
  document.querySelectorAll('#modal-overlay .tab-btn').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
  document.querySelectorAll('[id^="prod-tab-"]').forEach(function(el) { el.style.display = 'none'; });
  document.getElementById('prod-tab-' + tab).style.display = 'block';
}

function showProductForm() {
  loadCategoriasSelectForForm();
  loadMarcasSelectForForm();
  loadUnidadesSelectForForm();
  openModal(
    '<div class="modal-body"><h3><i class="fas fa-plus-circle"></i> Nuevo Producto</h3>' +
    '<form onsubmit="saveProduct(event)"><div class="form-grid">' +
      '<div class="full"><label>Nombre *</label><input type="text" id="f-nombre" required></div>' +
      '<div><label>Código Barras</label><input type="text" id="f-codigo"></div>' +
      '<div><label>SKU</label><input type="text" id="f-sku"></div>' +
      '<div><label>Categoría</label><select id="f-categoria"></select></div>' +
      '<div><label>Marca</label><select id="f-marca"></select></div>' +
      '<div><label>Unidad Medida</label><select id="f-unidad"></select></div>' +
      '<div><label>Tipo de Venta *</label><select id="f-tipo_venta"><option value="UNIDAD">Por Unidad</option><option value="PESO">Por Peso (kg, g, lb)</option><option value="VOLUMEN">Por Volumen (L, mL)</option></select></div>' +
      '<div><label>Tipo</label><select id="f-tipo"><option value="PRODUCTO">Producto</option><option value="SERVICIO">Servicio</option><option value="MATERIA_PRIMA">Materia Prima</option><option value="TERMINADO">Terminado</option><option value="CONSUMIBLE">Consumible</option></select></div>' +
      '<div><label>Precio Costo</label><input type="number" step="0.01" id="f-precio_costo"></div>' +
      '<div><label>Precio Venta * <small>(por unidad/kg/L)</small></label><input type="number" step="0.01" id="f-precio_venta" required></div>' +
      '<div><label>Cantidad Inicial <small>(uds/kg/L)</small></label><input type="number" step="0.001" id="f-cantidad" value="0"></div>' +
      '<div><label>Stock Mínimo</label><input type="number" step="0.001" id="f-stock_minimo" value="0"></div>' +
      '<div><label>Stock Máximo</label><input type="number" step="0.001" id="f-stock_maximo" value="0"></div>' +
      '<div><label>Punto Reposición</label><input type="number" step="0.001" id="f-punto_reposicion" value="0"></div>' +
      '<div><label>Stock Seguridad</label><input type="number" step="0.001" id="f-stock_seguridad" value="0"></div>' +
      '<div><label>Control Lote</label><select id="f-control_lote"><option value="0">No</option><option value="1">Sí</option></select></div>' +
      '<div><label>Control Serie</label><select id="f-control_serie"><option value="0">No</option><option value="1">Sí</option></select></div>' +
      '<div><label>Peso (kg)</label><input type="number" step="0.01" id="f-peso"></div>' +
      '<div><label>Volumen (m³)</label><input type="number" step="0.01" id="f-volumen"></div>' +
      '<div class="full"><label>Descripción</label><textarea id="f-descripcion" rows="3"></textarea></div>' +
    '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Crear Producto</button></form></div>'
  );
}

function saveProduct(e) {
  e.preventDefault();
  var data = {
    nombre_producto: document.getElementById('f-nombre').value,
    codigo_de_barras: document.getElementById('f-codigo').value,
    sku: document.getElementById('f-sku').value,
    id_categoria: num(document.getElementById('f-categoria').value),
    id_marca: num(document.getElementById('f-marca').value),
    id_unidad: num(document.getElementById('f-unidad').value),
    tipo_venta: document.getElementById('f-tipo_venta').value,
    tipo: document.getElementById('f-tipo').value,
    precio_costo: Math.round(parseFloat(document.getElementById('f-precio_costo').value) || 0),
    precio_venta: Math.round(parseFloat(document.getElementById('f-precio_venta').value) || 0),
    cantidad: parseFloat(document.getElementById('f-cantidad').value) || 0,
    stock_minimo: parseFloat(document.getElementById('f-stock_minimo').value) || 0,
    stock_maximo: parseFloat(document.getElementById('f-stock_maximo').value) || 0,
    punto_reposicion: parseFloat(document.getElementById('f-punto_reposicion').value) || 0,
    stock_seguridad: parseFloat(document.getElementById('f-stock_seguridad').value) || 0,
    control_lote: num(document.getElementById('f-control_lote').value),
    control_serie: num(document.getElementById('f-control_serie').value),
    peso: parseFloat(document.getElementById('f-peso').value) || 0,
    volumen: parseFloat(document.getElementById('f-volumen').value) || 0,
    descripcion: document.getElementById('f-descripcion').value
  };
  api('producto_crear', data, function(r) {
    toast('Producto creado');
    closeModal();
    loadProductos();
  });
}

function showProductEditForm(id) {
  api('producto', { id: id }, function(p) {
    loadCategoriasSelectForForm('f-categoria', p.id_categoria);
    loadMarcasSelectForForm('f-marca', p.id_marca);
    loadUnidadesSelectForForm('f-unidad', p.id_unidad);
    openModal(
      '<div class="modal-body"><h3><i class="fas fa-edit"></i> Editar Producto</h3>' +
      '<form onsubmit="saveProductEdit(event,' + id + ')"><div class="form-grid">' +
        '<div class="full"><label>Nombre</label><input type="text" id="f-nombre" value="' + esc(p.nombre_producto) + '"></div>' +
        '<div><label>Código Barras</label><input type="text" id="f-codigo" value="' + esc(p.codigo_de_barras||'') + '"></div>' +
        '<div><label>SKU</label><input type="text" id="f-sku" value="' + esc(p.sku||'') + '"></div>' +
        '<div><label>Categoría</label><select id="f-categoria"></select></div>' +
        '<div><label>Marca</label><select id="f-marca"></select></div>' +
        '<div><label>Unidad</label><select id="f-unidad"></select></div>' +
        '<div><label>Tipo de Venta</label><select id="f-tipo_venta"><option value="UNIDAD"' + ((p.tipo_venta||'UNIDAD')==='UNIDAD'?' selected':'') + '>Por Unidad</option><option value="PESO"' + (p.tipo_venta==='PESO'?' selected':'') + '>Por Peso</option><option value="VOLUMEN"' + (p.tipo_venta==='VOLUMEN'?' selected':'') + '>Por Volumen</option></select></div>' +
        '<div><label>Tipo</label><select id="f-tipo"><option value="PRODUCTO"' + (p.tipo==='PRODUCTO'?' selected':'') + '>Producto</option><option value="SERVICIO"' + (p.tipo==='SERVICIO'?' selected':'') + '>Servicio</option><option value="MATERIA_PRIMA"' + (p.tipo==='MATERIA_PRIMA'?' selected':'') + '>Materia Prima</option><option value="TERMINADO"' + (p.tipo==='TERMINADO'?' selected':'') + '>Terminado</option></select></div>' +
        '<div><label>Costo</label><input type="number" step="0.01" id="f-precio_costo" value="' + num(p.precio_costo) + '"></div>' +
        '<div><label>Precio Venta <small>(por unidad/kg/L)</small></label><input type="number" step="0.01" id="f-precio_venta" value="' + num(p.precio_venta) + '"></div>' +
        '<div><label>Stock Mínimo</label><input type="number" step="0.001" id="f-stock_minimo" value="' + num(p.stock_minimo) + '"></div>' +
        '<div><label>Stock Máximo</label><input type="number" step="0.001" id="f-stock_maximo" value="' + num(p.stock_maximo) + '"></div>' +
        '<div><label>Control Lote</label><select id="f-control_lote"><option value="0"' + (p.control_lote==0?' selected':'') + '>No</option><option value="1"' + (p.control_lote==1?' selected':'') + '>Sí</option></select></div>' +
        '<div><label>Control Serie</label><select id="f-control_serie"><option value="0"' + (p.control_serie==0?' selected':'') + '>No</option><option value="1"' + (p.control_serie==1?' selected':'') + '>Sí</option></select></div>' +
        '<div class="full"><label>Descripción</label><textarea id="f-descripcion" rows="3">' + esc(p.descripcion||'') + '</textarea></div>' +
      '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Guardar</button></form></div>'
    );
  });
}

function saveProductEdit(e, id) {
  e.preventDefault();
  var data = { id: id,
    nombre_producto: document.getElementById('f-nombre').value,
    codigo_de_barras: document.getElementById('f-codigo').value,
    sku: document.getElementById('f-sku').value,
    id_categoria: num(document.getElementById('f-categoria').value),
    id_marca: num(document.getElementById('f-marca').value),
    id_unidad: num(document.getElementById('f-unidad').value),
    tipo_venta: document.getElementById('f-tipo_venta').value,
    tipo: document.getElementById('f-tipo').value,
    precio_costo: Math.round(parseFloat(document.getElementById('f-precio_costo').value) || 0),
    precio_venta: Math.round(parseFloat(document.getElementById('f-precio_venta').value) || 0),
    stock_minimo: parseFloat(document.getElementById('f-stock_minimo').value) || 0,
    stock_maximo: parseFloat(document.getElementById('f-stock_maximo').value) || 0,
    descripcion: document.getElementById('f-descripcion').value
  };
  api('producto_editar', data, function(r) {
    toast('Producto actualizado');
    closeModal();
    loadProductos();
  });
}

// ========== CATEGORÍAS ==========
function loadCategorias() {
  api('categorias', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-categorias');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-tags"></i><p>Sin categorías</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(c) {
      return '<tr><td>#' + c.id_categoria + '</td><td>' + esc(c.nombre) + '</td><td>' + esc(c.descripcion||'') + '</td><td>-</td><td>' + (c.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>') + '</td><td><button class="btn btn-outline btn-xs" onclick="showCategoriaEditForm(' + c.id_categoria + ',\'' + esc(c.nombre) + '\',\'' + esc(c.descripcion||'') + '\')"><i class="fas fa-edit"></i></button></td></tr>';
    }).join('');
  });
}

function showCategoriaForm() {
  openModal('<div class="modal-body"><h3><i class="fas fa-plus"></i> Nueva Categoría</h3><form onsubmit="saveCategoria(event)"><label>Nombre</label><input type="text" id="cat-nombre" class="input" required><label>Descripción</label><textarea id="cat-desc" class="input" rows="3"></textarea><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Crear</button></form></div>');
}

function saveCategoria(e) {
  e.preventDefault();
  api('categoria_crear', { nombre: document.getElementById('cat-nombre').value, descripcion: document.getElementById('cat-desc').value }, function(r) {
    toast('Categoría creada'); closeModal(); loadCategorias();
  });
}

function showCategoriaEditForm(id, nombre, desc) {
  openModal('<div class="modal-body"><h3><i class="fas fa-edit"></i> Editar Categoría</h3><form onsubmit="saveCategoriaEdit(event,' + id + ')"><label>Nombre</label><input type="text" id="cat-nombre" class="input" value="' + nombre + '" required><label>Descripción</label><textarea id="cat-desc" class="input" rows="3">' + desc + '</textarea><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Guardar</button></form></div>');
}

function saveCategoriaEdit(e, id) {
  e.preventDefault();
  api('categoria_editar', { id: id, nombre: document.getElementById('cat-nombre').value, descripcion: document.getElementById('cat-desc').value }, function(r) {
    toast('Categoría actualizada'); closeModal(); loadCategorias();
  });
}

// ========== MARCAS ==========
function loadMarcas() {
  api('marcas', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-marcas');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-tag"></i><p>Sin marcas</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(m) {
      return '<tr><td>#' + m.id_marca + '</td><td>' + esc(m.nombre) + '</td><td>' + esc(m.descripcion||'') + '</td><td>-</td><td>' + (m.activo == 1 ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>') + '</td><td><button class="btn btn-outline btn-xs" onclick="showMarcaEditForm(' + m.id_marca + ',\'' + esc(m.nombre) + '\',\'' + esc(m.descripcion||'') + '\')"><i class="fas fa-edit"></i></button></td></tr>';
    }).join('');
  });
}

function showMarcaForm() {
  openModal('<div class="modal-body"><h3><i class="fas fa-plus"></i> Nueva Marca</h3><form onsubmit="saveMarca(event)"><label>Nombre</label><input type="text" id="mar-nombre" class="input" required><label>Descripción</label><textarea id="mar-desc" class="input" rows="3"></textarea><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Crear</button></form></div>');
}

function saveMarca(e) {
  e.preventDefault();
  api('marca_crear', { nombre: document.getElementById('mar-nombre').value, descripcion: document.getElementById('mar-desc').value }, function(r) {
    toast('Marca creada'); closeModal(); loadMarcas();
  });
}

function showMarcaEditForm(id, nombre, desc) {
  openModal('<div class="modal-body"><h3><i class="fas fa-edit"></i> Editar Marca</h3><form onsubmit="saveMarcaEdit(event,' + id + ')"><label>Nombre</label><input type="text" id="mar-nombre" class="input" value="' + nombre + '" required><label>Descripción</label><textarea id="mar-desc" class="input" rows="3">' + desc + '</textarea><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Guardar</button></form></div>');
}

function saveMarcaEdit(e, id) {
  e.preventDefault();
  api('marca_editar', { id: id, nombre: document.getElementById('mar-nombre').value, descripcion: document.getElementById('mar-desc').value }, function(r) {
    toast('Marca actualizada'); closeModal(); loadMarcas();
  });
}

// ========== BODEGAS ==========
function loadBodegas() {
  api('bodegas', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-bodegas');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-warehouse"></i><p>Sin bodegas</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(b) {
      var est = b.estado === 'ACTIVA' ? '<span class="badge badge-success">' + b.estado + '</span>' : '<span class="badge badge-gray">' + b.estado + '</span>';
      return '<tr><td>' + esc(b.codigo) + '</td><td>' + esc(b.nombre) + '</td><td>' + esc(b.responsable||'') + '</td><td>' + esc(b.direccion||'') + '</td><td>' + est + '</td><td>' + num(b.total_items) + '</td><td><button class="btn btn-outline btn-xs" onclick="showBodegaEditForm(' + b.id_bodega + ')"><i class="fas fa-edit"></i></button></td></tr>';
    }).join('');
  });
}

function showBodegaForm() {
  openModal('<div class="modal-body"><h3><i class="fas fa-plus"></i> Nueva Bodega</h3><form onsubmit="saveBodega(event)"><div class="form-grid">' +
    '<div><label>Nombre *</label><input type="text" id="b-nombre" required></div><div><label>Código</label><input type="text" id="b-codigo" placeholder="Auto"></div>' +
    '<div><label>Responsable</label><input type="text" id="b-responsable"></div><div><label>Teléfono</label><input type="text" id="b-telefono"></div>' +
    '<div><label>Dirección</label><input type="text" id="b-direccion" class="full"></div>' +
    '<div><label>Capacidad</label><input type="number" id="b-capacidad"></div>' +
    '<div class="full"><label>Observaciones</label><textarea id="b-observaciones" rows="2"></textarea></div>' +
  '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Crear</button></form></div>');
}

function saveBodega(e) {
  e.preventDefault();
  api('bodega_crear', {
    nombre: document.getElementById('b-nombre').value,
    codigo: document.getElementById('b-codigo').value,
    responsable: document.getElementById('b-responsable').value,
    telefono: document.getElementById('b-telefono').value,
    direccion: document.getElementById('b-direccion').value,
    capacidad: num(document.getElementById('b-capacidad').value),
    observaciones: document.getElementById('b-observaciones').value
  }, function(r) { toast('Bodega creada: ' + r.codigo); closeModal(); loadBodegas(); });
}

function showBodegaEditForm(id) {
  api('bodegas', {}, function(items) {
    items = items.items || items;
    var b = items.find(function(x) { return x.id_bodega == id; });
    if (!b) return;
    openModal('<div class="modal-body"><h3><i class="fas fa-edit"></i> Editar Bodega</h3><form onsubmit="saveBodegaEdit(event,' + id + ')"><div class="form-grid">' +
      '<div><label>Nombre</label><input type="text" id="be-nombre" value="' + esc(b.nombre) + '" required></div>' +
      '<div><label>Código</label><input type="text" id="be-codigo" value="' + esc(b.codigo) + '" readonly></div>' +
      '<div><label>Responsable</label><input type="text" id="be-responsable" value="' + esc(b.responsable||'') + '"></div>' +
      '<div><label>Teléfono</label><input type="text" id="be-telefono" value="' + esc(b.telefono||'') + '"></div>' +
      '<div><label>Dirección</label><input type="text" id="be-direccion" value="' + esc(b.direccion||'') + '"></div>' +
      '<div><label>Estado</label><select id="be-estado"><option value="ACTIVA"' + (b.estado==='ACTIVA'?' selected':'') + '>Activa</option><option value="INACTIVA"' + (b.estado==='INACTIVA'?' selected':'') + '>Inactiva</option><option value="MANTENCION"' + (b.estado==='MANTENCION'?' selected':'') + '>Mantención</option></select></div>' +
      '<div class="full"><label>Observaciones</label><textarea id="be-obs" rows="2">' + esc(b.observaciones||'') + '</textarea></div>' +
    '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Guardar</button></form></div>');
  });
}

function saveBodegaEdit(e, id) {
  e.preventDefault();
  api('bodega_editar', {
    id: id, nombre: document.getElementById('be-nombre').value,
    responsable: document.getElementById('be-responsable').value,
    direccion: document.getElementById('be-direccion').value,
    estado: document.getElementById('be-estado').value,
    observaciones: document.getElementById('be-obs').value
  }, function(r) { toast('Bodega actualizada'); closeModal(); loadBodegas(); });
}

// ========== STOCK ==========
function loadStock() {
  var id_bodega = document.getElementById('filter-stock-bodega').value;
  var search = document.getElementById('search-stock').value;
  api('stock', { id_bodega: num(id_bodega), search: search }, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-stock');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><i class="fas fa-cubes"></i><p>Sin stock</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(s) {
      return '<tr><td>' + esc(s.nombre_producto) + '</td><td>' + esc(s.bodega_nombre) + '</td><td class="cell-price">' + num(s.disponible) + '</td><td>' + num(s.reservado) + '</td><td>' + num(s.comprometido) + '</td><td>' + num(s.en_transito) + '</td><td>' + num(s.danado) + '</td><td>' + num(s.bloqueado) + '</td></tr>';
    }).join('');
  });
}

document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('search-stock').addEventListener('input', function() { clearTimeout(window._ss); window._ss = setTimeout(loadStock, 300); });
});

// ========== MOVIMIENTOS (HISTORIAL) ==========
function loadMovimientos() {
  var tipo = document.getElementById('filter-mov-tipo').value;
  api('movimientos', { tipo: tipo, limit: 100 }, function(r) {
    var tbody = document.getElementById('tbody-movimientos');
    if (!r.items || !r.items.length) { tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-exchange-alt"></i><p>Sin movimientos</p></div></td></tr>'; return; }
    tbody.innerHTML = r.items.map(function(m) {
      var badge = m.tipo === 'INGRESO' ? 'badge-success' : (m.tipo === 'SALIDA' ? 'badge-danger' : (m.tipo === 'TRANSFERENCIA' ? 'badge-info' : 'badge-warning'));
      return '<tr><td>' + esc(m.numero) + '</td><td>' + m.created_at + '</td><td><span class="badge ' + badge + '">' + m.tipo + '</span></td><td>' + esc(m.nombre_producto) + '</td><td class="cell-price" style="color:' + (m.tipo==='INGRESO'?'#059669':'#dc2626') + ';font-weight:600;">' + (m.tipo==='INGRESO'?'+':'') + m.cantidad + '</td><td>' + esc(m.bodega_origen||m.bodega_destino||'-') + '</td><td>' + esc(m.user_nombre||'') + '</td></tr>';
    }).join('');
  });
}

// ========== ACTUALIZAR STOCK (POPUP RÁPIDO) ==========
var _stockProduct = null, _stockBodega = null;

function showQuickStock() {
  openModal(
    '<h3 style="font-size:18px;font-weight:700;margin-bottom:0;"><i class="fas fa-bolt" style="color:#059669;"></i> Actualizar Stock</h3>' +
    '<p style="font-size:12px;color:#64748b;margin:4px 0 12px;">Código de barras → Enter busca → Enter cantidad → Enter costo → ¡listo!</p>' +
    '<input type="text" id="qs-barcode" class="input" placeholder="Pistoleá el código..." style="font-size:18px;padding:12px;width:100%;box-sizing:border-box;margin-bottom:8px;">' +
    '<div style="display:flex;gap:8px;margin-bottom:8px;">' +
      '<div style="flex:1;"><label style="font-size:11px;font-weight:600;color:#475569;">Cantidad</label><input type="number" id="qs-cant" class="input" value="1" min="0.001" step="0.001" style="padding:10px;width:100%;box-sizing:border-box;"></div>' +
      '<div style="flex:1;"><label style="font-size:11px;font-weight:600;color:#475569;">Costo $</label><input type="number" id="qs-costo" class="input" value="0" style="padding:10px;width:100%;box-sizing:border-box;"></div>' +
    '</div>' +
    '<div id="qs-info" style="min-height:44px;padding:8px 0;font-size:13px;color:#64748b;border-bottom:1px solid #e2e8f0;margin-bottom:8px;">' +
      '<span style="color:#94a3b8;">Esperando escaneo...</span>' +
    '</div>' +
    '<div id="qs-feedback" style="min-height:20px;"></div>'
  );
  setTimeout(function() {
    var b = document.getElementById('qs-barcode');
    var c = document.getElementById('qs-cant');
    var co = document.getElementById('qs-costo');
    if (!b) return;
    b.focus();
    
    // Barcode: Enter searches and advances to cantidad
    b.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); buscarStockBarcode(); }
    });
    
    // Cantidad: Enter advances to costo
    if (c) c.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); if (co) co.focus(); co.select(); }
    });
    
    // Costo: Enter saves the movement
    if (co) co.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); quickStockSave(); }
    });
  }, 150);
}

function buscarStockBarcode() {
  var barcode = document.getElementById('qs-barcode').value.trim();
  if (!barcode) return;
  api('producto_barcode', { barcode: barcode }, function(p) {
    _stockProduct = p;
    _stockBodega = p.id_bodega || p.id_bodega_default || 0;
    var sc = p.stock_disponible <= 0 ? '#dc2626' : (p.stock_disponible <= 5 ? '#d97706' : '#059669');
    document.getElementById('qs-info').innerHTML =
      '<div style="font-weight:700;font-size:15px;color:#1e293b;">' + esc(p.nombre_producto) + '</div>' +
      '<div>Stock: <span style="color:' + sc + ';font-weight:600;">' + p.stock_disponible + '</span> | ' +
      'Bodega: <strong>' + esc(p.bodega_nombre||'Principal') + '</strong> | $' + fmt(p.precio_venta) + '</div>';
    if (p.precio_costo) document.getElementById('qs-costo').value = p.precio_costo;
    // Advance to cantidad
    var cant = document.getElementById('qs-cant');
    if (cant) { cant.focus(); cant.select(); }
  });
}

function quickStockSave() {
  if (!_stockProduct) { toast('Escaneá un producto primero', 'error'); return; }
  if (!_stockBodega) { toast('Producto sin bodega', 'error'); return; }
  var cant = num(document.getElementById('qs-cant').value) || 1;
  var costo = num(document.getElementById('qs-costo').value) || 0;
  api('movimiento_crear', {
    tipo: 'INGRESO', id_producto: _stockProduct.id_producto,
    cantidad: cant, id_bodega: _stockBodega, costo: costo,
    observaciones: 'Actualización rápida: ' + esc(_stockProduct.codigo_de_barras)
  }, function(r) {
    var fb = document.getElementById('qs-feedback');
    fb.innerHTML = '<span style="color:#059669;font-weight:600;">✓ +' + cant + ' — Stock: ' + (_stockProduct.stock_disponible + cant) + '</span>';
    _stockProduct.stock_disponible += cant;
    // Reset for next scan
    document.getElementById('qs-barcode').value = '';
    document.getElementById('qs-cant').value = '1';
    document.getElementById('qs-costo').value = '0';
    document.getElementById('qs-info').innerHTML = '<span style="color:#94a3b8;">Esperando escaneo...</span>';
    _stockProduct = null;
    loadProductos(); loadStock(); loadMovimientos(); loadDashboard();
    setTimeout(function() { document.getElementById('qs-feedback').innerHTML = ''; }, 2500);
    // Focus back to barcode for next scan
    var b = document.getElementById('qs-barcode');
    if (b) b.focus();
  });
}

// ========== TRANSFERENCIAS ==========
function loadTransferencias() {
  api('transferencias', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-transferencias');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-truck-moving"></i><p>Sin transferencias</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(t) {
      var badge = t.estado === 'RECIBIDA' ? 'badge-success' : (t.estado === 'EN_TRANSITO' ? 'badge-info' : (t.estado === 'CANCELADA' ? 'badge-danger' : 'badge-warning'));
      var actions = '';
      if (t.estado === 'PENDIENTE') actions += '<button class="btn btn-success btn-xs" onclick="enviarTransferencia(' + t.id_transferencia + ')"><i class="fas fa-paper-plane"></i></button> ';
      if (t.estado === 'EN_TRANSITO') actions += '<button class="btn btn-success btn-xs" onclick="recibirTransferencia(' + t.id_transferencia + ')"><i class="fas fa-check"></i></button> ';
      if (t.estado !== 'RECIBIDA' && t.estado !== 'CANCELADA') actions += '<button class="btn btn-danger btn-xs" onclick="cancelarTransferencia(' + t.id_transferencia + ')"><i class="fas fa-ban"></i></button>';
      var items = (t.detalles || []).map(function(d) { return d.cantidad + 'x ' + d.nombre_producto; }).join(', ');
      return '<tr><td>' + esc(t.numero) + '</td><td>' + esc(t.bodega_origen_nombre) + '</td><td>' + esc(t.bodega_destino_nombre) + '</td><td><span class="badge ' + badge + '">' + t.estado + '</span></td><td style="font-size:12px;">' + esc(items.substring(0,50)) + '</td><td>' + t.fecha_creacion + '</td><td>' + actions + '</td></tr>';
    }).join('');
  });
}

function showTransferenciaForm() {
  openModal('<div class="modal-body"><h3><i class="fas fa-plus"></i> Nueva Transferencia</h3><form onsubmit="saveTransferencia(event)"><div class="form-grid">' +
    '<div><label>Origen *</label><select id="trf-origen"></select></div><div><label>Destino *</label><select id="trf-destino"></select></div>' +
    '<div class="full"><label>Productos (seleccione y agregue)</label></div>' +
    '<div class="full"><div id="trf-items"><div class="trf-row" style="display:flex;gap:8px;margin-bottom:6px;"><select class="trf-prod" style="flex:2;padding:7px;border:1px solid #e2e8f0;border-radius:6px;"></select><input type="number" class="trf-cant" min="0.001" step="0.001" placeholder="Cant" style="flex:1;padding:7px;border:1px solid #e2e8f0;border-radius:6px;"></div></div>' +
    '<button type="button" class="btn btn-outline btn-sm full" onclick="addTrfRow()"><i class="fas fa-plus"></i> Agregar producto</button></div>' +
    '<div class="full"><label>Observaciones</label><textarea id="trf-obs" rows="2"></textarea></div>' +
  '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Crear Transferencia</button></form></div>');
  loadBodegasSelect('trf-origen');
  loadBodegasSelect('trf-destino');
  loadProductosSelectMulti();
}

var trfRowCount = 0;
function addTrfRow() {
  var items = document.getElementById('trf-items');
  var div = document.createElement('div');
  div.className = 'trf-row';
  div.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;';
  div.innerHTML = '<select class="trf-prod" style="flex:2;padding:7px;border:1px solid #e2e8f0;border-radius:6px;"></select><input type="number" class="trf-cant" min="0.001" step="0.001" placeholder="Cant" style="flex:1;padding:7px;border:1px solid #e2e8f0;border-radius:6px;"><button type="button" class="btn btn-danger btn-xs" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>';
  items.appendChild(div);
  loadProductosSelectMulti();
}

function saveTransferencia(e) {
  e.preventDefault();
  var rows = document.querySelectorAll('.trf-row');
  var productos = [];
  rows.forEach(function(r) {
    var idp = num(r.querySelector('.trf-prod').value);
    var cant = num(r.querySelector('.trf-cant').value);
    if (idp && cant > 0) productos.push({ id_producto: idp, cantidad: cant });
  });
  if (!productos.length) { toast('Agregue al menos un producto', 'error'); return; }
  api('transferencia_crear', {
    id_bodega_origen: num(document.getElementById('trf-origen').value),
    id_bodega_destino: num(document.getElementById('trf-destino').value),
    productos: productos,
    observaciones: document.getElementById('trf-obs').value
  }, function(r) { toast('Transferencia creada: ' + r.numero); closeModal(); loadTransferencias(); });
}

function enviarTransferencia(id) {
  if (!confirm('¿Enviar esta transferencia?')) return;
  api('transferencia_enviar', { id: id }, function(r) { toast('Transferencia enviada'); loadTransferencias(); });
}

function recibirTransferencia(id) {
  if (!confirm('¿Recibir esta transferencia?')) return;
  api('transferencia_recibir', { id: id }, function(r) { toast('Transferencia recibida'); loadTransferencias(); });
}

function cancelarTransferencia(id) {
  if (!confirm('¿Cancelar esta transferencia?')) return;
  api('transferencia_cancelar', { id: id }, function(r) { toast('Transferencia cancelada'); loadTransferencias(); });
}

// ========== AJUSTES ==========
function loadAjustes() {
  api('ajustes', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-ajustes');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><i class="fas fa-sliders-h"></i><p>Sin ajustes</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(a) {
      var difClass = a.diferencia > 0 ? 'cell-price' : (a.diferencia < 0 ? 'badge-danger' : '');
      return '<tr><td>' + esc(a.numero) + '</td><td><span class="badge badge-info">' + a.tipo + '</span></td><td>' + esc(a.nombre_producto) + '</td><td>' + a.cantidad_anterior + '</td><td>' + a.cantidad_nueva + '</td><td class="' + difClass + '">' + (a.diferencia > 0 ? '+' : '') + a.diferencia + '</td><td>' + esc((a.motivo||'').substring(0,30)) + '</td><td>' + a.created_at + '</td></tr>';
    }).join('');
  });
}

function showAjusteForm() {
  loadBodegasSelect('aj-bodega');
  loadProductosSelect('aj-producto');
  openModal('<div class="modal-body"><h3><i class="fas fa-plus"></i> Nuevo Ajuste</h3><form onsubmit="saveAjuste(event)"><div class="form-grid">' +
    '<div><label>Tipo *</label><select id="aj-tipo"><option value="AUMENTO">Aumento</option><option value="DISMINUCION">Disminución</option><option value="REGULARIZACION">Regularización</option><option value="CORRECCION">Corrección</option></select></div>' +
    '<div><label>Producto *</label><select id="aj-producto"></select></div>' +
    '<div><label>Bodega *</label><select id="aj-bodega"></select></div>' +
    '<div><label>Nuevo Stock *</label><input type="number" id="aj-cantidad" min="0" step="0.001" required></div>' +
    '<div><label>Autorizado por</label><input type="text" id="aj-autorizado"></div>' +
    '<div><label>Documento</label><input type="text" id="aj-documento"></div>' +
    '<div class="full"><label>Motivo *</label><textarea id="aj-motivo" required></textarea></div>' +
    '<div class="full"><label>Observaciones</label><textarea id="aj-obs" rows="2"></textarea></div>' +
  '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Realizar Ajuste</button></form></div>');
}

function saveAjuste(e) {
  e.preventDefault();
  api('ajuste_crear', {
    tipo: document.getElementById('aj-tipo').value,
    id_producto: num(document.getElementById('aj-producto').value),
    id_bodega: num(document.getElementById('aj-bodega').value),
    cantidad_nueva: num(document.getElementById('aj-cantidad').value),
    motivo: document.getElementById('aj-motivo').value,
    autorizado_por: document.getElementById('aj-autorizado').value,
    documento_respaldo: document.getElementById('aj-documento').value,
    observaciones: document.getElementById('aj-obs').value
  }, function(r) { toast('Ajuste registrado: ' + r.numero); closeModal(); loadAjustes(); });
}

// ========== INVENTARIO FÍSICO ==========
function loadFisicos() {
  api('inventarios_fisicos', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-fisicos');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-clipboard-list"></i><p>Sin inventarios físicos</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(f) {
      var badge = f.estado === 'CERRADO' ? 'badge-success' : (f.estado === 'EN_PROGRESO' ? 'badge-info' : 'badge-warning');
      return '<tr><td>' + esc(f.codigo) + '</td><td><span class="badge badge-purple">' + f.tipo + '</span></td><td>' + esc(f.bodega_nombre||'Todas') + '</td><td><span class="badge ' + badge + '">' + f.estado + '</span></td><td>' + (f.fecha_inicio||'') + '</td><td>' + (f.fecha_fin||'') + '</td><td><button class="btn btn-outline btn-xs" onclick="verFisicoDetalle(' + f.id_inventario + ')"><i class="fas fa-eye"></i></button></td></tr>';
    }).join('');
  });
}

function showFisicoForm() {
  loadBodegasSelect('fis-bodega');
  openModal('<div class="modal-body"><h3><i class="fas fa-plus"></i> Nuevo Inventario Físico</h3><form onsubmit="saveFisico(event)"><div class="form-grid">' +
    '<div><label>Tipo</label><select id="fis-tipo"><option value="GENERAL">General</option><option value="BODEGA">Por Bodega</option></select></div>' +
    '<div><label>Bodega (opcional)</label><select id="fis-bodega"><option value="">Todas</option></select></div>' +
    '<div class="full"><label>Observaciones</label><textarea id="fis-obs" rows="2"></textarea></div>' +
  '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Iniciar</button></form></div>');
}

function saveFisico(e) {
  e.preventDefault();
  api('inventario_fisico_crear', {
    tipo: document.getElementById('fis-tipo').value,
    id_bodega: num(document.getElementById('fis-bodega').value),
    observaciones: document.getElementById('fis-obs').value
  }, function(r) { toast('Inventario creado: ' + r.codigo); closeModal(); loadFisicos(); });
}

function verFisicoDetalle(id) {
  api('inventario_fisico_detalle', { id: id }, function(items) {
    openModal('<div class="modal-body"><h3>Detalle Inventario Físico</h3><table><thead><tr><th>Producto</th><th>Código</th><th>Conteo 1</th><th>Conteo 2</th><th>Conteo 3</th><th>Diferencia</th></tr></thead><tbody>' +
      (items.map(function(c) { return '<tr><td>' + esc(c.nombre_producto) + '</td><td>' + esc(c.codigo_de_barras) + '</td><td><span class="cell-editable" contenteditable onblur="actualizarConteo(' + c.id_conteo + ',\'conteo1\',this)">' + (c.conteo1 != null ? c.conteo1 : '-') + '</span></td><td contenteditable onblur="actualizarConteo(' + c.id_conteo + ',\'conteo2\',this)">' + (c.conteo2 != null ? c.conteo2 : '-') + '</td><td contenteditable onblur="actualizarConteo(' + c.id_conteo + ',\'conteo3\',this)">' + (c.conteo3 != null ? c.conteo3 : '-') + '</td><td>' + (c.diferencia != null ? c.diferencia : '-') + '</td></tr>'; }).join('') || '<tr><td colspan="6" style="color:#94a3b8;">Sin datos</td></tr>') +
    '</tbody></table><button class="btn btn-success" style="width:100%;margin-top:12px;" onclick="cerrarFisico(' + id + ')"><i class="fas fa-check"></i> Cerrar y Conciliar</button></div>');
  });
}

function actualizarConteo(id, ronda, el) {
  var val = num(el.textContent);
  api('inventario_fisico_conteo', { id_conteo: id, ronda: ronda, valor: val }, function() {});
}

function cerrarFisico(id) {
  if (!confirm('¿Cerrar y conciliar este inventario? Se generarán ajustes automáticos.')) return;
  api('inventario_fisico_cerrar', { id: id }, function(r) { toast('Inventario cerrado y conciliado'); closeModal(); loadFisicos(); });
}

// ========== KARDEX ==========
function loadKardex() {
  var id_producto = num(document.getElementById('filter-kardex-producto').value);
  api('kardex', { id_producto: id_producto, limit: 100 }, function(r) {
    var tbody = document.getElementById('tbody-kardex');
    if (!r.items || !r.items.length) { tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><i class="fas fa-book"></i><p>Sin movimientos kardex</p></div></td></tr>'; return; }
    tbody.innerHTML = r.items.map(function(k) {
      return '<tr><td>' + k.fecha + '</td><td>' + esc(k.nombre_producto) + '</td><td>' + esc(k.bodega_nombre||'-') + '</td><td>' + k.tipo_movimiento + '</td><td class="cell-price">' + (k.entrada > 0 ? k.entrada : '-') + '</td><td class="cell-price">' + (k.salida > 0 ? k.salida : '-') + '</td><td class="cell-price">' + k.saldo + '</td><td>$' + fmt(k.costo_unitario) + '</td></tr>';
    }).join('');
  });
}

// ========== LOTES ==========
function loadLotes() {
  api('lotes', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-lotes');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-layer-group"></i><p>Sin lotes</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(l) {
      var estClass = l.estado === 'DISPONIBLE' ? 'badge-success' : (l.estado === 'VENCIDO' ? 'badge-danger' : (l.estado === 'AGOTADO' ? 'badge-gray' : 'badge-warning'));
      return '<tr><td>' + esc(l.numero_lote) + '</td><td>' + esc(l.nombre_producto) + '</td><td>' + esc(l.proveedor_nombre||'-') + '</td><td>' + (l.fecha_vencimiento || '-') + '</td><td>' + num(l.cantidad) + '</td><td><span class="badge ' + estClass + '">' + l.estado + '</span></td></tr>';
    }).join('');
  });
}

function showLoteForm() {
  loadProductosSelect('lote-producto');
  openModal('<div class="modal-body"><h3><i class="fas fa-plus"></i> Nuevo Lote</h3><form onsubmit="saveLote(event)"><div class="form-grid">' +
    '<div><label>Producto *</label><select id="lote-producto"></select></div><div><label>N° Lote *</label><input type="text" id="lote-numero" required></div>' +
    '<div><label>Cantidad</label><input type="number" id="lote-cantidad" min="0.001" step="0.001"></div><div><label>Proveedor</label><select id="lote-proveedor"><option value="">Seleccionar...</option></select></div>' +
    '<div><label>Fabricación</label><input type="date" id="lote-fabricacion"></div><div><label>Vencimiento</label><input type="date" id="lote-vencimiento"></div>' +
  '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Crear</button></form></div>');
  // Load proveedores
  api('proveedores_select', {}, function(provs) {
    var sel = document.getElementById('lote-proveedor');
    provs.forEach(function(p) { sel.innerHTML += '<option value="' + p.id_proveedor + '">' + esc(p.nombre_empresa) + '</option>'; });
  });
}

function saveLote(e) {
  e.preventDefault();
  api('lote_crear', {
    id_producto: num(document.getElementById('lote-producto').value),
    numero_lote: document.getElementById('lote-numero').value,
    cantidad: num(document.getElementById('lote-cantidad').value),
    id_proveedor: num(document.getElementById('lote-proveedor').value),
    fecha_fabricacion: document.getElementById('lote-fabricacion').value,
    fecha_vencimiento: document.getElementById('lote-vencimiento').value
  }, function(r) { toast('Lote creado'); closeModal(); loadLotes(); });
}

// ========== SERIES ==========
function loadSeries() {
  api('series', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-series');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-barcode"></i><p>Sin series</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(s) {
      return '<tr><td>' + esc(s.numero_serie) + '</td><td>' + esc(s.nombre_producto) + '</td><td>' + (s.id_lote || '-') + '</td><td><span class="badge badge-info">' + s.estado + '</span></td><td>' + (s.id_cliente || '-') + '</td><td>' + (s.garantia_dias ? s.garantia_dias + ' días' : '-') + '</td></tr>';
    }).join('');
  });
}

function showSerieForm() {
  loadProductosSelect('serie-producto');
  openModal('<div class="modal-body"><h3><i class="fas fa-plus"></i> Nueva Serie</h3><form onsubmit="saveSerie(event)"><div class="form-grid">' +
    '<div><label>Producto *</label><select id="serie-producto"></select></div><div><label>N° Serie *</label><input type="text" id="serie-numero" required></div>' +
  '</div><button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;"><i class="fas fa-save"></i> Crear</button></form></div>');
}

function saveSerie(e) {
  e.preventDefault();
  api('serie_crear', {
    id_producto: num(document.getElementById('serie-producto').value),
    numero_serie: document.getElementById('serie-numero').value
  }, function(r) { toast('Serie creada'); closeModal(); loadSeries(); });
}

// ========== ALERTAS ==========
function loadAlertas() {
  api('alertas', {}, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-alertas');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="5"><div class="empty-state"><i class="fas fa-bell-slash"></i><p>Sin alertas pendientes</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(a) {
      return '<tr><td><span class="badge badge-danger">' + a.tipo + '</span></td><td>' + esc(a.nombre_producto) + '</td><td>' + esc(a.mensaje) + '</td><td>' + a.created_at + '</td><td><button class="btn btn-success btn-xs" onclick="resolverAlerta(' + a.id_alerta + ')"><i class="fas fa-check"></i></button></td></tr>';
    }).join('');
  });
}

function resolverAlerta(id) {
  api('alerta_resolver', { id: id }, function(r) { toast('Alerta resuelta'); loadAlertas(); });
}

// ========== REPORTES ==========
function switchReporte(tipo, btn) {
  if (btn) {
    document.querySelectorAll('#section-reportes .tab-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
  }
  var title = document.getElementById('reporte-title');
  var thead = document.getElementById('reporte-thead');
  var tbody = document.getElementById('tbody-reporte');

  if (tipo === 'existencias') {
    title.innerHTML = '<i class="fas fa-file-alt"></i> Reporte de Existencias';
    thead.innerHTML = '<tr><th>Producto</th><th>Código</th><th>SKU</th><th>Categoría</th><th>Stock</th><th>Disponible</th><th>Costo Prom.</th><th>Valor Costo</th><th>Precio Venta</th><th>Valor Venta</th></tr>';
    api('reporte_existencias', {}, function(items) {
      items = items.items || items;
      tbody.innerHTML = items.map(function(p) {
        return '<tr><td>' + esc(p.nombre_producto) + '</td><td>' + esc(p.codigo_de_barras) + '</td><td>' + esc(p.sku) + '</td><td>' + esc(p.categoria||'') + '</td><td>' + num(p.stock_total) + '</td><td>' + num(p.disponible) + '</td><td>$' + fmt(p.costo_promedio) + '</td><td>$' + fmt(p.valor_costo) + '</td><td class="cell-price">$' + fmt(p.precio_venta) + '</td><td class="cell-price">$' + fmt(p.valor_venta) + '</td></tr>';
      }).join('');
    });
  } else if (tipo === 'stock_critico') {
    title.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Reporte de Stock Crítico';
    thead.innerHTML = '<tr><th>Producto</th><th>Código</th><th>Stock Actual</th><th>Stock Mínimo</th><th>Necesita Reponer</th><th>Precio Venta</th></tr>';
    api('reporte_stock_critico', {}, function(items) {
      items = items.items || items;
      tbody.innerHTML = items.map(function(p) {
        return '<tr><td>' + esc(p.nombre_producto) + '</td><td>' + esc(p.codigo_de_barras) + '</td><td class="' + (p.stock_actual <= 0 ? 'badge-danger' : 'badge-warning') + '">' + p.stock_actual + '</td><td>' + p.stock_minimo + '</td><td><strong>' + (p.necesita_reponer > 0 ? p.necesita_reponer : 0) + '</strong></td><td class="cell-price">$' + fmt(p.precio_venta) + '</td></tr>';
      }).join('') || '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-check-circle" style="color:#059669;"></i><p>No hay productos con stock crítico</p></div></td></tr>';
    });
  } else if (tipo === 'rotacion') {
    title.innerHTML = '<i class="fas fa-chart-line"></i> Reporte de Rotación';
    thead.innerHTML = '<tr><th>Producto</th><th>Categoría</th><th>Stock</th><th>Salidas 30d</th><th>Rotación</th><th>Última Salida</th><th>Días Inactivo</th></tr>';
    api('reporte_rotacion', {}, function(items) {
      tbody.innerHTML = items.map(function(p) {
        var inactClass = p.dias_sin_movimiento > 90 ? 'badge-danger' : (p.dias_sin_movimiento > 30 ? 'badge-warning' : 'badge-success');
        return '<tr><td>' + esc(p.nombre_producto) + '</td><td>' + esc(p.categoria||'') + '</td><td>' + num(p.stock_actual) + '</td><td>' + num(p.salidas_30d) + '</td><td>' + (num(p.salidas_30d) > 0 ? Math.round(num(p.stock_actual)/num(p.salidas_30d)) + ' meses' : 'Sin rotación') + '</td><td>' + (p.ultima_salida === 'NUNCA' ? 'Nunca' : p.ultima_salida) + '</td><td><span class="badge ' + inactClass + '">' + p.dias_sin_movimiento + ' días</span></td></tr>';
      }).join('');
    });
  }
}

// ========== AUDITORÍA ==========
function loadAuditoria() {
  api('auditoria', { limit: 200 }, function(items) {
    items = items.items || items;
    var tbody = document.getElementById('tbody-auditoria');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-history"></i><p>Sin registros de auditoría</p></div></td></tr>'; return; }
    tbody.innerHTML = items.map(function(a) {
      var det = a.detalle ? JSON.stringify(a.detalle).substring(0, 60) : '';
      return '<tr><td>' + a.created_at + '</td><td>' + esc(a.user_nombre||'') + '</td><td><span class="badge badge-info">' + esc(a.accion) + '</span></td><td>' + esc(a.entidad) + '</td><td>' + (a.id_entidad || '-') + '</td><td style="font-size:12px;color:#64748b;">' + esc(det) + '</td></tr>';
    }).join('');
  });
}

// ========== SELECT HELPERS ==========
function loadCategoriasSelect() {
  api('categorias', {}, function(items) {
    items = items.items || items;
    var sel = document.getElementById('filter-categoria');
    sel.innerHTML = '<option value="">Todas las categorías</option>';
    items.forEach(function(c) { sel.innerHTML += '<option value="' + c.id_categoria + '">' + esc(c.nombre) + '</option>'; });
  });
}

function loadCategoriasSelectForForm(id, selected) {
  id = id || 'f-categoria';
  api('categorias', {}, function(items) {
    items = items.items || items;
    var sel = document.getElementById(id);
    sel.innerHTML = '<option value="">Sin categoría</option>';
    items.forEach(function(c) { sel.innerHTML += '<option value="' + c.id_categoria + '"' + (c.id_categoria == selected ? ' selected' : '') + '>' + esc(c.nombre) + '</option>'; });
  });
}

function loadMarcasSelect() {
  api('marcas', {}, function(items) {
    items = items.items || items;
    var sel = document.getElementById('filter-marca');
    sel.innerHTML = '<option value="">Todas las marcas</option>';
    items.forEach(function(m) { sel.innerHTML += '<option value="' + m.id_marca + '">' + esc(m.nombre) + '</option>'; });
  });
}

function loadMarcasSelectForForm(id, selected) {
  id = id || 'f-marca';
  api('marcas', {}, function(items) {
    items = items.items || items;
    var sel = document.getElementById(id);
    sel.innerHTML = '<option value="">Sin marca</option>';
    items.forEach(function(m) { sel.innerHTML += '<option value="' + m.id_marca + '"' + (m.id_marca == selected ? ' selected' : '') + '>' + esc(m.nombre) + '</option>'; });
  });
}

function loadUnidadesSelectForForm(id, selected) {
  id = id || 'f-unidad';
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      var sel = document.getElementById(id);
      if (!sel) return;
      sel.innerHTML = '<option value="">Seleccionar...</option>';
      try { JSON.parse(xhr.responseText).forEach(function(u) { sel.innerHTML += '<option value="' + u.id_unidad + '"' + (u.id_unidad == selected ? ' selected' : '') + '>' + esc(u.nombre) + ' (' + u.abreviatura + ')</option>'; }); } catch(e) {}
    }
  };
  xhr.send(JSON.stringify({ accion: 'unidades' }));
}

function loadBodegasSelect(id, selected) {
  id = id || 'filter-stock-bodega';
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      var sel = document.getElementById(id);
      if (!sel) return;
      sel.innerHTML = '<option value="">Seleccionar...</option>';
      try { var r = JSON.parse(xhr.responseText); (r.items || r || []).forEach(function(b) { sel.innerHTML += '<option value="' + b.id_bodega + '"' + (b.id_bodega == selected ? ' selected' : '') + '>' + esc(b.nombre) + '</option>'; }); } catch(e) {}
    }
  };
  xhr.send(JSON.stringify({ accion: 'bodegas' }));
}

function loadProductosSelect(id, selected) {
  id = id || 'filter-kardex-producto';
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      var sel = document.getElementById(id);
      if (!sel) return;
      sel.innerHTML = '<option value="">Seleccionar...</option>';
      try {
        var r = JSON.parse(xhr.responseText);
        (r.items || r).forEach(function(p) { sel.innerHTML += '<option value="' + (p.id_producto || p.id) + '"' + ((p.id_producto||p.id) == selected ? ' selected' : '') + '>' + esc(p.nombre_producto) + '</option>'; });
      } catch(e) {}
    }
  };
  xhr.send(JSON.stringify({ accion: 'productos', limit: 500 }));
}

function loadProductosSelectMulti() {
  // Populate ALL .trf-prod select elements in transferencia form
  var xhr = new XMLHttpRequest();
  xhr.open('POST', API, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4 && xhr.status >= 200 && xhr.status < 300) {
      try {
        var r = JSON.parse(xhr.responseText);
        var opts = '<option value="">Seleccionar producto...</option>';
        (r.items || r || []).forEach(function(p) {
          opts += '<option value="' + (p.id_producto || p.id) + '">' + esc(p.nombre_producto) + '</option>';
        });
        document.querySelectorAll('.trf-prod').forEach(function(sel) {
          var val = sel.value;
          sel.innerHTML = opts;
          if (val) sel.value = val;
        });
      } catch(e) {}
    }
  };
  xhr.send(JSON.stringify({ accion: 'productos', limit: 500 }));
}

function loadSelectBodegas(id) {
  loadBodegasSelect(id);
}

// ========== INIT ==========
document.addEventListener('DOMContentLoaded', function() {
  loadDashboard();
});

function exportProductosXls() {
  window.location.href = API + '?accion=exportar_productos';
}

function importProductosFile(input) {
  if (!input.files || !input.files[0]) return;
  var fd = new FormData();
  fd.append('file', input.files[0]);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/importar_productos.php', true);
  xhr.onload = function() {
    input.value = '';
    try {
      var r = JSON.parse(xhr.responseText);
      toast(r.msg || (r.success ? 'Importación completada' : 'Error al importar'), r.success ? 'success' : 'error');
      if (r.success) loadProductos();
    } catch(e) { toast('Respuesta inválida al importar', 'error'); }
  };
  xhr.onerror = function() { input.value=''; toast('Error de conexión al importar', 'error'); };
  xhr.send(fd);
}

