<?php
require_once __DIR__.'/_db.php';

$uid = requireUser();
$conn = getDB();
$accountId = tenantContext($uid)->accountId;
$input = getJsonInput();
$accion = $input['accion'] ?? $_GET['accion'] ?? '';

$rootScopeActions = [
    'producto_editar'=>'producto','producto_eliminar'=>'producto',
    'categoria_editar'=>'categoria','categoria_eliminar'=>'categoria',
    'marca_editar'=>'marca','marca_eliminar'=>'marca',
    'bodega_editar'=>'bodega','bodega_eliminar'=>'bodega',
];
if (isset($rootScopeActions[$accion])) {
    $scopeId = (int)($input['id'] ?? 0);
    if ($scopeId > 0) requireTenantEntity($conn, tenantContext($uid), $rootScopeActions[$accion], $scopeId);
}

// ========== HELPERS ==========
function invLog($conn, $uid, $accion, $entidad, $id_entidad=null, $detalle=null) {
    $det = $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO inventario_auditoria (id_user,accion,entidad,id_entidad,detalle,ip) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("ississ", $uid, $accion, $entidad, $id_entidad, $det, $ip);
    $stmt->execute();
    $stmt->close();
}

function generarCodigo($conn, $tabla, $campo, $prefijo) {
    // Validate identifiers against injection
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tabla)) return $prefijo . '0001';
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $campo)) return $prefijo . '0001';
    $safe_prefijo = $conn->real_escape_string($prefijo);
    $r = $conn->query("SELECT COALESCE(MAX(CAST(SUBSTRING($campo, LENGTH('$safe_prefijo')+1) AS UNSIGNED)),0)+1 AS n FROM $tabla");
    $f = $r->fetch_assoc();
    return $prefijo . str_pad($f['n'], 4, '0', STR_PAD_LEFT);
}

function requierePermiso($modulo, $accion) {
    if (!verificarPermiso($modulo, $accion)) {
        json(['error'=>'Permiso denegado: '.$modulo.'.'.$accion], 403);
    }
}

// ========== DISPATCH ==========
$inventoryReadActions = ['dashboard','productos','producto','producto_barcode','categorias','subcategorias','marcas','unidades','bodegas','ubicaciones','stock','movimientos','transferencias','ajustes','inventarios_fisicos','inventario_fisico_detalle','kardex','lotes','series','alertas','auditoria','reporte_existencias','reporte_stock_critico','reporte_valorizacion','reporte_rotacion','proveedores_select'];
if (in_array($accion, $inventoryReadActions, true)) requierePermiso('inventario','ver');

switch ($accion) {

case 'exportar_productos':
    requierePermiso('inventario','exportar');
    $cuenta = getCuentaId($conn, $uid);
    $stmt = $conn->prepare("SELECT p.nombre_producto, p.precio_venta, p.codigo_de_barras, p.cantidad, COALESCE(c.nombre,p.categoria,'') AS categoria, p.sku, p.precio_costo, p.tipo_venta, COALESCE(u.abreviatura,'u') AS unidad, p.activo FROM producto p LEFT JOIN categoria c ON p.id_categoria=c.id_categoria LEFT JOIN unidad_medida u ON p.id_unidad=u.id_unidad WHERE p.id_cuenta=? ORDER BY p.nombre_producto");
    $stmt->bind_param("i", $cuenta); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="productos_' . date('Y-m-d') . '.xls"');
    echo "\xEF\xBB\xBF<html><head><meta charset=\"UTF-8\"><style>table{border-collapse:collapse;font-family:Arial}th{background:#065f46;color:#fff}th,td{border:1px solid #cbd5e1;padding:6px 10px}.num{text-align:right;mso-number-format:\"0.000\"}</style></head><body><table><thead><tr>";
    foreach(['Nombre','Precio Venta','Código de Barras','Cantidad','Categoría','SKU','Precio Costo','Tipo Venta','Unidad','Activo'] as $h) echo '<th>'.$h.'</th>';
    echo '</tr></thead><tbody>';
    foreach($rows as $r) echo '<tr><td>'.htmlspecialchars($r['nombre_producto']).'</td><td class="num">'.(float)$r['precio_venta'].'</td><td style="mso-number-format:\'\\@\'">'.htmlspecialchars($r['codigo_de_barras']).'</td><td class="num">'.(float)$r['cantidad'].'</td><td>'.htmlspecialchars($r['categoria']).'</td><td>'.htmlspecialchars($r['sku']).'</td><td class="num">'.(float)$r['precio_costo'].'</td><td>'.htmlspecialchars($r['tipo_venta']).'</td><td>'.htmlspecialchars($r['unidad']).'</td><td>'.((int)$r['activo']?'Sí':'No').'</td></tr>';
    echo '</tbody></table></body></html>'; exit;

// ─── DASHBOARD ───
case 'dashboard':
    $data = [];
    $r = $conn->query("SELECT COUNT(*) AS t FROM producto WHERE id_cuenta=$accountId AND activo=1"); $data['total_productos'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM producto WHERE id_cuenta=$accountId AND (cantidad=0 OR cantidad IS NULL)"); $data['sin_stock'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM producto WHERE id_cuenta=$accountId AND cantidad>0 AND cantidad<=stock_minimo AND stock_minimo>0"); $data['stock_critico'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM bodega WHERE id_cuenta=$accountId AND estado='ACTIVA'"); $data['bodegas'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM categoria WHERE id_cuenta=$accountId"); $data['categorias'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM marca WHERE id_cuenta=$accountId"); $data['marcas'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COALESCE(SUM(cantidad*precio_venta),0) AS v FROM producto WHERE id_cuenta=$accountId AND activo=1"); $data['valor_inventario'] = (int)$r->fetch_assoc()['v'];
    $r = $conn->query("SELECT COALESCE(SUM(k.entrada),0) AS e FROM kardex k JOIN producto p ON p.id_producto=k.id_producto WHERE p.id_cuenta=$accountId AND DATE(k.fecha)=CURDATE()"); $data['entradas_hoy'] = (int)$r->fetch_assoc()['e'];
    $r = $conn->query("SELECT COALESCE(SUM(k.salida),0) AS s FROM kardex k JOIN producto p ON p.id_producto=k.id_producto WHERE p.id_cuenta=$accountId AND DATE(k.fecha)=CURDATE()"); $data['salidas_hoy'] = (int)$r->fetch_assoc()['s'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM lote l JOIN producto p ON p.id_producto=l.id_producto WHERE p.id_cuenta=$accountId AND l.fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND l.cantidad>0"); $data['proximos_vencer'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM lote l JOIN producto p ON p.id_producto=l.id_producto WHERE p.id_cuenta=$accountId AND l.fecha_vencimiento < CURDATE() AND l.cantidad>0"); $data['vencidos'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM alerta_stock a JOIN producto p ON p.id_producto=a.id_producto WHERE p.id_cuenta=$accountId AND a.leido=0 AND a.resuelto=0"); $data['alertas'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM transferencia t JOIN bodega b ON b.id_bodega=t.id_bodega_origen WHERE b.id_cuenta=$accountId AND (t.estado='PENDIENTE' OR t.estado='EN_TRANSITO')"); $data['transferencias_pendientes'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COUNT(*) AS t FROM inventario_fisico f JOIN usuario u ON u.id_user=f.id_user WHERE u.id_cuenta=$accountId AND (f.estado='PENDIENTE' OR f.estado='EN_PROGRESO')"); $data['inventarios_pendientes'] = (int)$r->fetch_assoc()['t'];
    $r = $conn->query("SELECT COALESCE(ROUND(AVG(dias),0),0) AS rotacion FROM (SELECT DATEDIFF(NOW(), MAX(k.fecha)) AS dias FROM kardex k JOIN producto p ON p.id_producto=k.id_producto WHERE p.id_cuenta=$accountId GROUP BY k.id_producto) sub"); $data['rotacion_promedio'] = (int)$r->fetch_assoc()['rotacion'];
    // Charts
    $chart_cat = []; $r = $conn->query("SELECT c.nombre, COUNT(p.id_producto) AS t FROM producto p JOIN categoria c ON p.id_categoria=c.id_categoria WHERE p.id_cuenta=$accountId GROUP BY c.id_categoria, c.nombre ORDER BY t DESC LIMIT 10");
    while ($f = $r->fetch_assoc()) $chart_cat[] = ['label'=>$f['nombre'], 'value'=>(int)$f['t']];
    $data['chart_categorias'] = $chart_cat;
    $chart_stock = []; $r = $conn->query("SELECT b.nombre, COALESCE(SUM(s.disponible),0) AS t FROM bodega b LEFT JOIN stock s ON b.id_bodega=s.id_bodega WHERE b.id_cuenta=$accountId GROUP BY b.id_bodega, b.nombre ORDER BY t DESC LIMIT 10");
    while ($f = $r->fetch_assoc()) $chart_stock[] = ['label'=>$f['nombre'], 'value'=>(int)$f['t']];
    $data['chart_stock_bodega'] = $chart_stock;
    $r = $conn->query("SELECT DATE(k.fecha) AS d, SUM(k.entrada) AS e, SUM(k.salida) AS s FROM kardex k JOIN producto p ON p.id_producto=k.id_producto WHERE p.id_cuenta=$accountId AND k.fecha>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) GROUP BY DATE(k.fecha) ORDER BY d");
    $chart_ent_sal = []; while ($f = $r->fetch_assoc()) $chart_ent_sal[] = ['fecha'=>$f['d'], 'entradas'=>(int)$f['e'], 'salidas'=>(int)$f['s']];
    $data['chart_entradas_salidas'] = $chart_ent_sal;
    json($data);

// ─── PRODUCTOS ───
case 'productos':
    $search = $input['search'] ?? $_GET['search'] ?? '';
    $id_bodega = (int)($input['id_bodega'] ?? $_GET['id_bodega'] ?? 0);
    $id_categoria = (int)($input['id_categoria'] ?? $_GET['id_categoria'] ?? 0);
    $id_marca = (int)($input['id_marca'] ?? $_GET['id_marca'] ?? 0);
    $estado = $input['estado'] ?? $_GET['estado'] ?? '';
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    
    $where = ["p.id_cuenta=?"];
    $wParams = [$accountId];
    $wTypes = 'i';
    if ($search) {
        $sLike = "%$search%";
        $where[] = "(p.nombre_producto LIKE ? OR p.codigo_de_barras LIKE ? OR p.sku LIKE ? OR p.descripcion LIKE ?)";
        array_push($wParams, $sLike, $sLike, $sLike, $sLike);
        $wTypes .= 'ssss';
    }
    if ($id_categoria) { $where[] = "p.id_categoria=?"; $wParams[] = $id_categoria; $wTypes .= "i"; }
    if ($id_marca) { $where[] = "p.id_marca=?"; $wParams[] = $id_marca; $wTypes .= "i"; }
    if ($estado === 'activo') $where[] = "p.activo=1";
    elseif ($estado === 'inactivo') $where[] = "p.activo=0";
    elseif ($estado === 'sin_stock') $where[] = "(p.cantidad=0 OR p.cantidad IS NULL)";
    elseif ($estado === 'stock_bajo') $where[] = "p.cantidad>0 AND p.cantidad<=p.stock_minimo AND p.stock_minimo>0";
    elseif ($estado === 'control_lote') $where[] = "p.control_lote=1";
    elseif ($estado === 'control_serie') $where[] = "p.control_serie=1";
    
    $w = implode(' AND ', $where);
    $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM producto p WHERE $w");
    if ($wParams) $stmt->bind_param($wTypes, ...$wParams);
    $stmt->execute();
    $r = $stmt->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt->close();
    
    $sql = "SELECT p.*, c.nombre AS categoria_nombre, m.nombre AS marca_nombre, u.abreviatura AS unidad_abrev
            FROM producto p
            LEFT JOIN categoria c ON p.id_categoria=c.id_categoria
            LEFT JOIN marca m ON p.id_marca=m.id_marca
            LEFT JOIN unidad_medida u ON p.id_unidad=u.id_unidad
            WHERE $w ORDER BY p.id_producto DESC LIMIT ? OFFSET ?";
    $selParams = array_merge($wParams, [$limit, $offset]);
    $selTypes = $wTypes . "ii";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($selTypes, ...$selParams);
    $stmt->execute();
    $r = $stmt->get_result();
    $items = [];
    while ($f = $r->fetch_assoc()) {
        $f['cantidad'] = (int)($f['cantidad'] ?? 0);
        $f['precio_venta'] = (int)($f['precio_venta'] ?? 0);
        $f['precio_costo'] = (int)($f['precio_costo'] ?? 0);
        $items[] = $f;
    }
    $stmt->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page, 'limit'=>$limit]);

case 'producto':
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt = $conn->prepare("SELECT p.*, c.nombre AS categoria_nombre, m.nombre AS marca_nombre, u.abreviatura AS unidad_abrev
                       FROM producto p
                       LEFT JOIN categoria c ON p.id_categoria=c.id_categoria
                       LEFT JOIN marca m ON p.id_marca=m.id_marca
                       LEFT JOIN unidad_medida u ON p.id_unidad=u.id_unidad
                       WHERE p.id_producto=? AND p.id_cuenta=$accountId");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) json(['error'=>'Producto no encontrado'],404);
    $prod = $r->fetch_assoc();
    $stmt->close();
    // Get stock por bodega
    $stock = [];
    $stmt = $conn->prepare("SELECT s.*, b.nombre AS bodega_nombre FROM stock s JOIN bodega b ON s.id_bodega=b.id_bodega WHERE s.id_producto=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $s = $stmt->get_result();
    while ($f = $s->fetch_assoc()) $stock[] = $f;
    $stmt->close();
    $prod['stock_bodegas'] = $stock;
    // Get lotes
    $lotes = [];
    $stmt = $conn->prepare("SELECT l.*, pr.razon_social AS proveedor FROM lote l LEFT JOIN proveedor pr ON l.id_proveedor=pr.id_proveedor WHERE l.id_producto=? ORDER BY l.fecha_vencimiento ASC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $l = $stmt->get_result();
    while ($f = $l->fetch_assoc()) $lotes[] = $f;
    $stmt->close();
    $prod['lotes'] = $lotes;
    json($prod);

case 'producto_barcode':
    $barcode = $input['barcode'] ?? $_GET['barcode'] ?? '';
    if (!$barcode) json(['error'=>'Código requerido'],400);
    $stmt = $conn->prepare("SELECT p.*, c.nombre AS categoria_nombre, m.nombre AS marca_nombre,
        COALESCE(s.disponible,0) AS stock_disponible, b.nombre AS bodega_nombre, b.id_bodega,
        (SELECT id_bodega FROM bodega WHERE id_cuenta=$accountId AND estado='ACTIVA' LIMIT 1) AS id_bodega_default
        FROM producto p
        LEFT JOIN categoria c ON p.id_categoria=c.id_categoria
        LEFT JOIN marca m ON p.id_marca=m.id_marca
        LEFT JOIN stock s ON p.id_producto=s.id_producto
        LEFT JOIN bodega b ON s.id_bodega=b.id_bodega
        WHERE p.codigo_de_barras=? AND p.id_cuenta=$accountId AND p.activo=1 LIMIT 1");
    $stmt->bind_param("s", $barcode);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) json(['error'=>'Producto no encontrado'],404);
    $p = $r->fetch_assoc();
    $p['precio_venta'] = (int)($p['precio_venta'] ?? 0);
    $p['precio_costo'] = (int)($p['precio_costo'] ?? 0);
    $p['stock_disponible'] = (int)($p['stock_disponible'] ?? 0);
    json($p);

case 'producto_crear':
    requierePermiso('inventario','crear');
    $nombre = $input['nombre_producto'] ?? '';
    if (!$nombre) json(['error'=>'Nombre requerido'],400);
    $codigo = $input['codigo_de_barras'] ?? '';
    $precio = (float)($input['precio_venta'] ?? 0);
    $cantidad = (float)($input['cantidad'] ?? 0);
    $categoria_id = (int)($input['id_categoria'] ?? 0);
    $marca_id = (int)($input['id_marca'] ?? 0);
    $proveedor_id = (int)($input['id_proveedor'] ?? 0);
    $tipo = $input['tipo'] ?? 'PRODUCTO';
    $precio_costo = (float)($input['precio_costo'] ?? 0);
    $descripcion = $input['descripcion'] ?? '';
    $sku = $input['sku'] ?? '';
    $id_unidad = (int)($input['id_unidad'] ?? 1);
    $tipo_venta = $input['tipo_venta'] ?? 'UNIDAD';
    $precio_por_unidad = $input['precio_por_unidad'] ?? 'UNIDAD';
    $stock_min = (float)($input['stock_minimo'] ?? 0);
    $stock_max = (float)($input['stock_maximo'] ?? 0);
    $punto_reposicion = (float)($input['punto_reposicion'] ?? 0);
    $stock_seguridad = (float)($input['stock_seguridad'] ?? 0);
    $control_lote = (int)($input['control_lote'] ?? 0);
    $control_serie = (int)($input['control_serie'] ?? 0);
    $peso = (float)($input['peso'] ?? 0);
    $volumen = (float)($input['volumen'] ?? 0);
    
    $stmt = $conn->prepare("INSERT INTO producto (id_user,nombre_producto,codigo_de_barras,precio_venta,cantidad,id_categoria,id_marca,id_proveedor,tipo,precio_costo,descripcion,sku,id_unidad,tipo_venta,precio_por_unidad,stock_minimo,stock_maximo,punto_reposicion,stock_seguridad,control_lote,control_serie,peso,volumen,costo_promedio,ultimo_costo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("issddiiisdssissddddiidddd", $uid, $nombre, $codigo, $precio, $cantidad, $categoria_id, $marca_id, $proveedor_id, $tipo, $precio_costo, $descripcion, $sku, $id_unidad, $tipo_venta, $precio_por_unidad, $stock_min, $stock_max, $punto_reposicion, $stock_seguridad, $control_lote, $control_serie, $peso, $volumen, $precio_costo, $precio_costo);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    
    // Create stock entry in default bodega
    $r = $conn->query("SELECT id_bodega FROM bodega WHERE id_cuenta=$accountId AND codigo='BOD-001' LIMIT 1");
    if ($r && $r->num_rows) {
        $id_bodega = (int)$r->fetch_assoc()['id_bodega'];
        $stmt2 = $conn->prepare("INSERT INTO stock (id_producto,id_bodega,disponible) VALUES (?,?,?)");
        $stmt2->bind_param("iid", $id, $id_bodega, $cantidad);
        $stmt2->execute();
        $stmt2->close();
        actualizarKardex($conn, $uid, $id, $id_bodega, 'INGRESO', $id, 'PRODUCTO', $cantidad, 0, $precio_costo, 'Creación de producto');
    }
    invLog($conn, $uid, 'CREAR', 'producto', $id, ['nombre'=>$nombre, 'tipo_venta'=>$tipo_venta]);
    json(['success'=>true, 'id'=>$id], 201);

case 'producto_editar':
    requierePermiso('inventario','editar');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $fields = [];
    $params = [];
    $types = '';
    $allowed = ['nombre_producto','codigo_de_barras','sku','descripcion','precio_venta','precio_costo','cantidad','id_categoria','id_marca','id_proveedor','tipo','id_unidad','tipo_venta','precio_por_unidad','stock_minimo','stock_maximo','punto_reposicion','stock_seguridad','lead_time','control_lote','control_serie','activo','peso','volumen','alto','ancho','largo','imagen'];
    foreach ($input as $k => $v) {
        if (in_array($k, $allowed)) {
            $fields[] = "$k=?";
            $params[] = $v;
            $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
        }
    }
    if (!count($fields)) json(['error'=>'Sin campos'],400);
    $params[] = $id;
    $types .= 'i';
    $sql = "UPDATE producto SET " . implode(',', $fields) . " WHERE id_producto=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    // Recalcular costo promedio si cambió precio_costo
    if (isset($input['precio_costo']) || isset($input['cantidad'])) {
        $stmt2 = $conn->prepare("SELECT precio_costo, cantidad FROM producto WHERE id_producto=?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $r = $stmt2->get_result();
        if ($r->num_rows) {
            $f = $r->fetch_assoc();
            $stmt2->close();
            $pc = (int)$f['precio_costo'];
            $stmt2 = $conn->prepare("UPDATE producto SET costo_promedio=?, ultimo_costo=? WHERE id_producto=?");
            $stmt2->bind_param("iii", $pc, $pc, $id);
            $stmt2->execute();
        }
        $stmt2->close();
    }
    invLog($conn, $uid, 'EDITAR', 'producto', $id, $input);
    json(['success'=>true]);

case 'producto_eliminar':
    requierePermiso('inventario','eliminar');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("SELECT id_producto FROM producto WHERE id_producto=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt2->bind_param("ii", $id, $uid);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);
    $stmt2 = $conn->prepare("UPDATE producto SET activo=0 WHERE id_producto=?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'ELIMINAR', 'producto', $id);
    json(['success'=>true]);

// ─── CATEGORÍAS ───
case 'categorias':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS t FROM categoria WHERE id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt2->bind_param("i", $uid);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt2->close();
    $items = [];
    $stmt2 = $conn->prepare("SELECT * FROM categoria WHERE id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?) ORDER BY nombre LIMIT ? OFFSET ?");
    $stmt2->bind_param("iii", $uid, $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'categoria_crear':
    requierePermiso('inventario','crear');
    $nombre = $input['nombre'] ?? '';
    if (!$nombre) json(['error'=>'Nombre requerido'],400);
    $desc = $input['descripcion'] ?? '';
    $stmt = $conn->prepare("INSERT INTO categoria (id_user,nombre,descripcion) VALUES (?,?,?)");
    $stmt->bind_param("iss", $uid, $nombre, $desc);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    invLog($conn, $uid, 'CREAR', 'categoria', $id, ['nombre'=>$nombre]);
    json(['success'=>true, 'id'=>$id], 201);

case 'categoria_editar':
    requierePermiso('inventario','editar');
    $id = (int)($input['id'] ?? 0);
    $nombre = $input['nombre'] ?? '';
    if (!$id || !$nombre) json(['error'=>'Datos requeridos'],400);
    $desc = $input['descripcion'] ?? '';
    $stmt = $conn->prepare("UPDATE categoria SET nombre=?, descripcion=? WHERE id_categoria=?");
    $stmt->bind_param("ssi", $nombre, $desc, $id);
    $stmt->execute();
    $stmt->close();
    invLog($conn, $uid, 'EDITAR', 'categoria', $id, ['nombre'=>$nombre]);
    json(['success'=>true]);

case 'categoria_eliminar':
    requierePermiso('inventario','eliminar');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("SELECT id_categoria FROM categoria WHERE id_categoria=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt2->bind_param("ii", $id, $uid);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);
    $stmt2 = $conn->prepare("DELETE FROM categoria WHERE id_categoria=?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'ELIMINAR', 'categoria', $id);
    json(['success'=>true]);

// ─── SUBCATEGORÍAS ───
case 'subcategorias':
    $id_cat = (int)($input['id_categoria'] ?? $_GET['id_categoria'] ?? 0);
    $items = [];
    $sql = "SELECT s.*, c.nombre AS categoria_nombre FROM subcategoria s JOIN categoria c ON s.id_categoria=c.id_categoria";
    $subParams = [];
    $subTypes = "";
    if ($id_cat) { $sql .= " WHERE s.id_categoria=?"; $subParams[] = $id_cat; $subTypes = "i"; }
    $sql .= " ORDER BY s.nombre";
    $stmt2 = $conn->prepare($sql);
    if ($subParams) $stmt2->bind_param($subTypes, ...$subParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json($items);

// ─── MARCAS ───
case 'marcas':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS t FROM marca WHERE id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt2->bind_param("i", $uid);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt2->close();
    $items = [];
    $stmt2 = $conn->prepare("SELECT * FROM marca WHERE id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?) ORDER BY nombre LIMIT ? OFFSET ?");
    $stmt2->bind_param("iii", $uid, $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'marca_crear':
    requierePermiso('inventario','crear');
    $nombre = $input['nombre'] ?? '';
    if (!$nombre) json(['error'=>'Nombre requerido'],400);
    $desc = $input['descripcion'] ?? '';
    $stmt = $conn->prepare("INSERT INTO marca (id_user,nombre,descripcion) VALUES (?,?,?)");
    $stmt->bind_param("iss", $uid, $nombre, $desc);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    invLog($conn, $uid, 'CREAR', 'marca', $id, ['nombre'=>$nombre]);
    json(['success'=>true, 'id'=>$id], 201);

case 'marca_editar':
    requierePermiso('inventario','editar');
    $id = (int)($input['id'] ?? 0);
    $nombre = $input['nombre'] ?? '';
    if (!$id || !$nombre) json(['error'=>'Datos requeridos'],400);
    $desc_m = $input['descripcion'] ?? '';
    $stmt = $conn->prepare("UPDATE marca SET nombre=?, descripcion=? WHERE id_marca=?");
    $stmt->bind_param("ssi", $nombre, $desc_m, $id);
    $stmt->execute();
    $stmt->close();
    invLog($conn, $uid, 'EDITAR', 'marca', $id, ['nombre'=>$nombre]);
    json(['success'=>true]);

case 'marca_eliminar':
    requierePermiso('inventario','eliminar');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("SELECT id_marca FROM marca WHERE id_marca=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt2->bind_param("ii", $id, $uid);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);
    $stmt2 = $conn->prepare("DELETE FROM marca WHERE id_marca=?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'ELIMINAR', 'marca', $id);
    json(['success'=>true]);

// ─── UNIDADES MEDIDA ───
case 'unidades':
    $items = []; $r = $conn->query("SELECT * FROM unidad_medida ORDER BY nombre");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

// ─── BODEGAS ───
case 'bodegas':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $r = $conn->query("SELECT COUNT(*) AS t FROM bodega b WHERE b.id_cuenta=$accountId");
    $total = (int)$r->fetch_assoc()['t'];
    $items = [];
    $stmt2 = $conn->prepare("SELECT b.*, (SELECT COALESCE(SUM(disponible),0) FROM stock WHERE id_bodega=b.id_bodega) AS total_items FROM bodega b WHERE b.id_cuenta=$accountId ORDER BY b.nombre LIMIT ? OFFSET ?");
    $stmt2->bind_param("ii", $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'bodega_crear':
    requierePermiso('inventario','crear');
    $codigo = $input['codigo'] ?? generarCodigo($conn,'bodega','codigo','BOD-');
    $nombre = $input['nombre'] ?? '';
    if (!$nombre) json(['error'=>'Nombre requerido'],400);
    $stmt = $conn->prepare("INSERT INTO bodega (id_user,codigo,nombre,responsable,direccion,telefono,capacidad,observaciones) VALUES (?,?,?,?,?,?,?,?)");
    $resp = $input['responsable'] ?? ''; $dir = $input['direccion'] ?? ''; $tel = $input['telefono'] ?? ''; $cap = (int)($input['capacidad'] ?? 0); $obs = $input['observaciones'] ?? '';
    $stmt->bind_param("isssssis", $uid, $codigo, $nombre, $resp, $dir, $tel, $cap, $obs);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    invLog($conn, $uid, 'CREAR', 'bodega', $id, ['nombre'=>$nombre,'codigo'=>$codigo]);
    json(['success'=>true, 'id'=>$id, 'codigo'=>$codigo], 201);

case 'bodega_editar':
    requierePermiso('inventario','editar');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $allowed = ['nombre','responsable','direccion','telefono','estado','capacidad','observaciones'];
    $fields = []; $params = []; $types = '';
    foreach ($input as $k => $v) {
        if (in_array($k, $allowed)) { $fields[] = "$k=?"; $params[] = $v; $types .= is_int($v) ? 'i' : 's'; }
    }
    if (!count($fields)) json(['error'=>'Sin campos'],400);
    $params[] = $id; $types .= 'i';
    $stmt = $conn->prepare("UPDATE bodega SET " . implode(',', $fields) . " WHERE id_bodega=?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    invLog($conn, $uid, 'EDITAR', 'bodega', $id);
    json(['success'=>true]);

case 'bodega_eliminar':
    requierePermiso('inventario','eliminar');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("SELECT id_bodega FROM bodega WHERE id_bodega=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt2->bind_param("ii", $id, $uid);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);
    $stmt2 = $conn->prepare("UPDATE bodega SET estado='INACTIVA' WHERE id_bodega=?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'ELIMINAR', 'bodega', $id);
    json(['success'=>true]);

// ─── UBICACIONES ───
case 'ubicaciones':
    $id_bodega = (int)($input['id_bodega'] ?? $_GET['id_bodega'] ?? 0);
    $items = [];
    $sql = "SELECT u.*, b.nombre AS bodega_nombre FROM ubicacion u JOIN bodega b ON u.id_bodega=b.id_bodega";
    $ubiParams = [];
    $ubiTypes = "";
    if ($id_bodega) { $sql .= " WHERE u.id_bodega=?"; $ubiParams[] = $id_bodega; $ubiTypes = "i"; }
    $sql .= " ORDER BY b.nombre, u.pasillo, u.rack";
    $stmt2 = $conn->prepare($sql);
    if ($ubiParams) $stmt2->bind_param($ubiTypes, ...$ubiParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json($items);

case 'ubicacion_crear':
    requierePermiso('inventario','crear');
    $id_bodega = (int)($input['id_bodega'] ?? 0);
    if (!$id_bodega) json(['error'=>'Bodega requerida'],400);
    $codigo = $input['codigo'] ?? '';
    $pasillo = $input['pasillo'] ?? '';
    $rack = $input['rack'] ?? '';
    $nivel = $input['nivel'] ?? '';
    $columna = $input['columna'] ?? '';
    $posicion = $input['posicion'] ?? '';
    $zona = $input['zona'] ?? '';
    $sector = $input['sector'] ?? '';
    $stmt = $conn->prepare("INSERT INTO ubicacion (id_bodega,codigo,pasillo,rack,nivel,columna_,posicion,zona,sector) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("issssssss", $id_bodega, $codigo, $pasillo, $rack, $nivel, $columna, $posicion, $zona, $sector);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    json(['success'=>true, 'id'=>$id], 201);

// ─── STOCK ───
case 'stock':
    $id_bodega = (int)($input['id_bodega'] ?? $_GET['id_bodega'] ?? 0);
    $search = $input['search'] ?? $_GET['search'] ?? '';
    $items = [];
    $sql = "SELECT s.*, p.nombre_producto, p.codigo_de_barras, p.sku, p.precio_venta, p.precio_costo, b.nombre AS bodega_nombre, u.codigo AS ubicacion_codigo
            FROM stock s
            JOIN producto p ON s.id_producto=p.id_producto
            JOIN bodega b ON s.id_bodega=b.id_bodega
            LEFT JOIN ubicacion u ON s.id_ubicacion=u.id_ubicacion
            WHERE p.id_cuenta=$accountId AND p.activo=1";
    $stParams = [];
    $stTypes = "";
    if ($id_bodega) { $sql .= " AND s.id_bodega=?"; $stParams[] = $id_bodega; $stTypes .= "i"; }
    if ($search) { $sLike = "%$search%"; $sql .= " AND (p.nombre_producto LIKE ? OR p.codigo_de_barras LIKE ? OR p.sku LIKE ?)"; array_push($stParams, $sLike, $sLike, $sLike); $stTypes .= 'sss'; }
    $sql .= " ORDER BY b.nombre, p.nombre_producto";
    $stmt2 = $conn->prepare($sql);
    if ($stParams) $stmt2->bind_param($stTypes, ...$stParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json($items);

case 'stock_actualizar':
    requierePermiso('inventario','editar');
    $id_stock = (int)($input['id_stock'] ?? 0);
    $campo = $input['campo'] ?? '';
    $valor = (int)($input['valor'] ?? 0);
    $allowed = ['disponible','reservado','comprometido','en_transito','danado','bloqueado','devuelto','produccion'];
    if (!$id_stock || !in_array($campo, $allowed)) json(['error'=>'Datos inválidos'],400);
    $stmt2 = $conn->prepare("SELECT s.id_stock, s.id_producto FROM stock s JOIN bodega b ON s.id_bodega=b.id_bodega WHERE s.id_stock=? AND b.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt2->bind_param("ii", $id_stock, $uid);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    if (!$r->num_rows) json(['error'=>'No autorizado'], 403);
    $row = $r->fetch_assoc();
    $stmt2 = $conn->prepare("UPDATE stock SET $campo=? WHERE id_stock=?");
    $stmt2->bind_param("ii", $valor, $id_stock);
    $stmt2->execute();
    $stmt2->close();
    sincronizarCantidadProducto($conn, (int)$row['id_producto']);
    invLog($conn, $uid, 'EDITAR', 'stock', $id_stock, ['campo'=>$campo, 'valor'=>$valor]);
    json(['success'=>true]);

// ─── MOVIMIENTOS ───
case 'movimientos':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $tipo = $input['tipo'] ?? $_GET['tipo'] ?? '';
    $id_producto = (int)($input['id_producto'] ?? $_GET['id_producto'] ?? 0);
    
    $where = ["m.id_user IN (SELECT id_user FROM usuario WHERE id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?))"];
    $mParams = [$uid];
    $mTypes = "i";
    if ($tipo) { $where[] = "m.tipo=?"; $mParams[] = $tipo; $mTypes .= "s"; }
    if ($id_producto) { $where[] = "m.id_producto=?"; $mParams[] = $id_producto; $mTypes .= "i"; }
    $w = implode(' AND ', $where);
    
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS t FROM movimiento_inventario m WHERE $w");
    if ($mParams) $stmt2->bind_param($mTypes, ...$mParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt2->close();
    
    $items = [];
    $selSql = "SELECT m.*, p.nombre_producto, bo.nombre AS bodega_origen, bd.nombre AS bodega_destino, u.nombre AS user_nombre
        FROM movimiento_inventario m
        JOIN producto p ON m.id_producto=p.id_producto
        LEFT JOIN bodega bo ON m.id_bodega_origen=bo.id_bodega
        LEFT JOIN bodega bd ON m.id_bodega_destino=bd.id_bodega
        LEFT JOIN usuario u ON m.id_user=u.id_user
        WHERE $w ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
    $selParams = array_merge($mParams, [$limit, $offset]);
    $selTypes = $mTypes . "ii";
    $stmt2 = $conn->prepare($selSql);
    $stmt2->bind_param($selTypes, ...$selParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'movimiento_crear':
    requierePermiso('inventario','movimientos');
    $tipo = $input['tipo'] ?? '';
    $id_producto = (int)($input['id_producto'] ?? 0);
    $cantidad = (int)($input['cantidad'] ?? 0);
    $id_bodega = (int)($input['id_bodega'] ?? 0);
    $costo = (int)($input['costo'] ?? 0);
    $obs = $input['observaciones'] ?? '';
    if (!$tipo || !$id_producto || !$cantidad || !$id_bodega) json(['error'=>'Datos incompletos'],400);
    
    $conn->begin_transaction();
    try {
        $num = generarCodigo($conn, 'movimiento_inventario', 'numero', 'MOV-');
        $stmt = $conn->prepare("INSERT INTO movimiento_inventario (numero,tipo,id_producto,id_bodega_origen,id_bodega_destino,cantidad,costo,id_user,observaciones) VALUES (?,?,?,?,?,?,?,?,?)");
        $bodega_origen = $id_bodega; $bodega_destino = 0;
        $delta_stock = 0; $entrada = 0; $salida = 0;
        
        switch ($tipo) {
            case 'INGRESO': case 'PRODUCCION': case 'DEVOLUCION':
                $bodega_destino = $id_bodega; $delta_stock = $cantidad; $entrada = $cantidad; break;
            case 'SALIDA': case 'CONSUMO': case 'MERMA': case 'PERDIDA':
                $bodega_destino = 0; $delta_stock = -$cantidad; $salida = $cantidad; break;
            case 'REGULARIZACION':
                $bodega_destino = $id_bodega; $delta_stock = $cantidad; break;
            case 'TRANSFERENCIA':
                $bodega_origen = $id_bodega; $bodega_destino = (int)($input['id_bodega_destino'] ?? 0); $delta_stock = 0; break;
        }
        $stmt->bind_param("ssiiiisss", $num, $tipo, $id_producto, $bodega_origen, $bodega_destino, $cantidad, $costo, $uid, $obs);
        $stmt->execute();
        $id_mov = (int)$conn->insert_id;
        $stmt->close();
        
        if ($delta_stock !== 0) {
            actualizarStock($conn, $id_producto, $id_bodega, 'disponible', $delta_stock);
            actualizarKardex($conn, $uid, $id_producto, $id_bodega, $tipo, $id_mov, 'MOVIMIENTO', $entrada, $salida, $costo, $obs);
        }
        invLog($conn, $uid, 'CREAR_MOVIMIENTO', 'movimiento_inventario', $id_mov, ['tipo'=>$tipo, 'cantidad'=>$cantidad]);
        $conn->commit();
        json(['success'=>true, 'id'=>$id_mov, 'numero'=>$num], 201);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }

// ─── TRANSFERENCIAS ───
case 'transferencias':
    $estado = $input['estado'] ?? $_GET['estado'] ?? '';
    $items = [];
    $sql = "SELECT t.*, bo.nombre AS bodega_origen_nombre, bd.nombre AS bodega_destino_nombre, u.nombre AS user_nombre
            FROM transferencia t
            JOIN bodega bo ON t.id_bodega_origen=bo.id_bodega
            JOIN bodega bd ON t.id_bodega_destino=bd.id_bodega
            LEFT JOIN usuario u ON t.id_user=u.id_user
            WHERE bo.id_cuenta=? AND bd.id_cuenta=?";
    $trfParams = [$accountId, $accountId];
    $trfTypes = "ii";
    if ($estado) { $sql .= " AND t.estado=?"; $trfParams[] = $estado; $trfTypes .= "s"; }
    $sql .= " ORDER BY t.fecha_creacion DESC";
    $stmt2 = $conn->prepare($sql);
    if ($trfParams) $stmt2->bind_param($trfTypes, ...$trfParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    while ($f = $r->fetch_assoc()) {
        // Get detalles
        $det = [];
        $stmt2 = $conn->prepare("SELECT td.*, p.nombre_producto FROM transferencia_detalle td JOIN producto p ON td.id_producto=p.id_producto WHERE td.id_transferencia=?");
        $idtrf = (int)$f['id_transferencia'];
        $stmt2->bind_param("i", $idtrf);
        $stmt2->execute();
        $d = $stmt2->get_result();
        while ($dd = $d->fetch_assoc()) $det[] = $dd;
        $stmt2->close();
        $f['detalles'] = $det;
        $items[] = $f;
    }
    json($items);

case 'transferencia_crear':
    requierePermiso('inventario','transferencias');
    $id_origen = (int)($input['id_bodega_origen'] ?? 0);
    $id_destino = (int)($input['id_bodega_destino'] ?? 0);
    $productos = $input['productos'] ?? [];
    if (!$id_origen || !$id_destino || !count($productos)) json(['error'=>'Datos incompletos'],400);
    if ($id_origen === $id_destino) json(['error'=>'Origen y destino deben ser distintos'],400);
    requireTenantEntity($conn, tenantContext($uid), 'bodega', $id_origen);
    requireTenantEntity($conn, tenantContext($uid), 'bodega', $id_destino);
    foreach ($productos as $productoTransferencia) {
        requireTenantEntity($conn, tenantContext($uid), 'producto', (int)($productoTransferencia['id_producto'] ?? 0));
    }
    
    $num = generarCodigo($conn, 'transferencia', 'numero', 'TRF-');
    $obs = $input['observaciones'] ?? '';
    $stmt = $conn->prepare("INSERT INTO transferencia (numero,id_bodega_origen,id_bodega_destino,estado,id_user,observaciones) VALUES (?,?,?,'PENDIENTE',?,?)");
    $stmt->bind_param("siiis", $num, $id_origen, $id_destino, $uid, $obs);
    $stmt->execute();
    $id_trf = (int)$conn->insert_id;
    $stmt->close();
    
    $total_items = 0;
    foreach ($productos as $p) {
        $idp = (int)($p['id_producto'] ?? 0);
        $cant = (int)($p['cantidad'] ?? 0);
        if ($idp && $cant > 0) {
            $stmt2 = $conn->prepare("INSERT INTO transferencia_detalle (id_transferencia,id_producto,cantidad) VALUES (?,?,?)");
            $stmt2->bind_param("iii", $id_trf, $idp, $cant);
            $stmt2->execute();
            $stmt2->close();
            // Reservar stock
            actualizarStock($conn, $idp, $id_origen, 'comprometido', $cant);
            $total_items += $cant;
        }
    }
    invLog($conn, $uid, 'CREAR_TRANSFERENCIA', 'transferencia', $id_trf, ['origen'=>$id_origen, 'destino'=>$id_destino, 'items'=>$total_items]);
    json(['success'=>true, 'id'=>$id_trf, 'numero'=>$num], 201);

case 'transferencia_recibir':
    requierePermiso('inventario','transferencias');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("SELECT t.* FROM transferencia t JOIN bodega b ON b.id_bodega=t.id_bodega_origen WHERE t.id_transferencia=? AND b.id_cuenta=?");
    $stmt2->bind_param("ii", $id, $accountId);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    if (!$r->num_rows) json(['error'=>'Transferencia no encontrada'],404);
    $trf = $r->fetch_assoc();
    if ($trf['estado'] !== 'EN_TRANSITO') json(['error'=>'Solo se pueden recibir transferencias EN_TRANSITO'],400);
    
    $stmt2 = $conn->prepare("SELECT * FROM transferencia_detalle WHERE id_transferencia=?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $detalles = $stmt2->get_result();
    $stmt2->close();
    while ($d = $detalles->fetch_assoc()) {
        $idp = (int)$d['id_producto'];
        $cant = (int)$d['cantidad'];
        // Quitar comprometido del origen
        actualizarStock($conn, $idp, $trf['id_bodega_origen'], 'comprometido', -$cant);
        actualizarStock($conn, $idp, $trf['id_bodega_origen'], 'en_transito', -$cant);
        // Agregar disponible en destino
        actualizarStock($conn, $idp, $trf['id_bodega_destino'], 'disponible', $cant);
        // Kardex
        actualizarKardex($conn, $uid, $idp, $trf['id_bodega_destino'], 'TRANSFERENCIA', $id, 'TRANSFERENCIA', $cant, 0, 0, 'Recibida transferencia '.$trf['numero']);
    }
    $stmt2 = $conn->prepare("UPDATE transferencia SET estado='RECIBIDA', fecha_recepcion=NOW(), id_user_recibe=? WHERE id_transferencia=?");
    $stmt2->bind_param("ii", $uid, $id);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'RECIBIR_TRANSFERENCIA', 'transferencia', $id);
    json(['success'=>true]);

case 'transferencia_enviar':
    requierePermiso('inventario','transferencias');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("SELECT t.* FROM transferencia t JOIN bodega b ON b.id_bodega=t.id_bodega_origen WHERE t.id_transferencia=? AND b.id_cuenta=?");
    $stmt2->bind_param("ii", $id, $accountId);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    if (!$r->num_rows) json(['error'=>'Transferencia no encontrada'],404);
    $trf = $r->fetch_assoc();
    if ($trf['estado'] !== 'PENDIENTE') json(['error'=>'La transferencia debe estar PENDIENTE'],400);
    
    $stmt2 = $conn->prepare("SELECT * FROM transferencia_detalle WHERE id_transferencia=?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $detalles = $stmt2->get_result();
    $stmt2->close();
    while ($d = $detalles->fetch_assoc()) {
        $idp = (int)$d['id_producto'];
        $cant = (int)$d['cantidad'];
        // Quitar disponible del origen y poner en tránsito
        actualizarStock($conn, $idp, $trf['id_bodega_origen'], 'disponible', -$cant);
        actualizarStock($conn, $idp, $trf['id_bodega_origen'], 'comprometido', -$cant);
        actualizarStock($conn, $idp, $trf['id_bodega_origen'], 'en_transito', $cant);
        actualizarKardex($conn, $uid, $idp, $trf['id_bodega_origen'], 'TRANSFERENCIA', $id, 'TRANSFERENCIA', 0, $cant, 0, 'Enviada transferencia '.$trf['numero']);
    }
    $stmt2 = $conn->prepare("UPDATE transferencia SET estado='EN_TRANSITO' WHERE id_transferencia=?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'ENVIAR_TRANSFERENCIA', 'transferencia', $id);
    json(['success'=>true]);

case 'transferencia_cancelar':
    requierePermiso('inventario','transferencias');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("SELECT t.* FROM transferencia t JOIN bodega b ON b.id_bodega=t.id_bodega_origen WHERE t.id_transferencia=? AND b.id_cuenta=?");
    $stmt2->bind_param("ii", $id, $accountId);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    if (!$r->num_rows) json(['error'=>'Transferencia no encontrada'],404);
    $trf = $r->fetch_assoc();
    if ($trf['estado'] === 'RECIBIDA' || $trf['estado'] === 'CANCELADA') json(['error'=>'No se puede cancelar'],400);
    
    if ($trf['estado'] === 'PENDIENTE') {
        $stmt2 = $conn->prepare("SELECT * FROM transferencia_detalle WHERE id_transferencia=?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $detalles = $stmt2->get_result();
        $stmt2->close();
        while ($d = $detalles->fetch_assoc()) {
            actualizarStock($conn, (int)$d['id_producto'], $trf['id_bodega_origen'], 'comprometido', -(int)$d['cantidad']);
        }
    }
    if ($trf['estado'] === 'EN_TRANSITO') {
        $stmt2 = $conn->prepare("SELECT * FROM transferencia_detalle WHERE id_transferencia=?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $detalles = $stmt2->get_result();
        $stmt2->close();
        while ($d = $detalles->fetch_assoc()) {
            $idp = (int)$d['id_producto'];
            actualizarStock($conn, $idp, $trf['id_bodega_origen'], 'en_transito', -(int)$d['cantidad']);
            actualizarStock($conn, $idp, $trf['id_bodega_origen'], 'disponible', (int)$d['cantidad']);
        }
    }
    $stmt2 = $conn->prepare("UPDATE transferencia SET estado='CANCELADA' WHERE id_transferencia=?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'CANCELAR_TRANSFERENCIA', 'transferencia', $id);
    json(['success'=>true]);

// ─── AJUSTES ───
case 'ajustes':
    $items = []; $r = $conn->query("SELECT a.*, p.nombre_producto, b.nombre AS bodega_nombre, u.nombre AS user_nombre FROM ajuste_inventario a JOIN producto p ON a.id_producto=p.id_producto JOIN bodega b ON a.id_bodega=b.id_bodega LEFT JOIN usuario u ON a.id_user=u.id_user WHERE p.id_cuenta=$accountId AND b.id_cuenta=$accountId ORDER BY a.created_at DESC LIMIT 200");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

case 'ajuste_crear':
    requierePermiso('inventario','ajustes');
    $tipo = $input['tipo'] ?? '';
    $id_producto = (int)($input['id_producto'] ?? 0);
    $id_bodega = (int)($input['id_bodega'] ?? 0);
    $cantidad_nueva = (int)($input['cantidad_nueva'] ?? 0);
    $motivo = $input['motivo'] ?? '';
    if (!$tipo || !$id_producto || !$id_bodega || $motivo === '') json(['error'=>'Datos incompletos'],400);
    
    // Obtener cantidad anterior
    $stmt2 = $conn->prepare("SELECT disponible FROM stock WHERE id_producto=? AND id_bodega=? LIMIT 1");
    $stmt2->bind_param("ii", $id_producto, $id_bodega);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    $cant_anterior = ($r->num_rows) ? (int)$r->fetch_assoc()['disponible'] : 0;
    $diferencia = $cantidad_nueva - $cant_anterior;
    
    $num = generarCodigo($conn, 'ajuste_inventario', 'numero', 'AJ-');
    $stmt = $conn->prepare("INSERT INTO ajuste_inventario (numero,tipo,id_producto,id_bodega,cantidad_anterior,cantidad_nueva,diferencia,motivo,id_user,autorizado_por,documento_respaldo,observaciones) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $aut = $input['autorizado_por'] ?? ''; $doc = $input['documento_respaldo'] ?? ''; $obs = $input['observaciones'] ?? '';
    $stmt->bind_param("ssiiiissssss", $num, $tipo, $id_producto, $id_bodega, $cant_anterior, $cantidad_nueva, $diferencia, $motivo, $uid, $aut, $doc, $obs);
    $stmt->execute();
    $id_aj = (int)$conn->insert_id;
    $stmt->close();
    
    // Actualizar stock
    actualizarStock($conn, $id_producto, $id_bodega, 'disponible', $diferencia);
    $entrada = $diferencia > 0 ? $diferencia : 0;
    $salida = $diferencia < 0 ? -$diferencia : 0;
    actualizarKardex($conn, $uid, $id_producto, $id_bodega, 'AJUSTE', $id_aj, 'AJUSTE', $entrada, $salida, 0, $motivo);
    
    invLog($conn, $uid, 'CREAR_AJUSTE', 'ajuste_inventario', $id_aj, ['tipo'=>$tipo, 'diferencia'=>$diferencia]);
    json(['success'=>true, 'id'=>$id_aj, 'numero'=>$num], 201);

// ─── INVENTARIOS FÍSICOS ───
case 'inventarios_fisicos':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $r = $conn->query("SELECT COUNT(*) AS t FROM inventario_fisico f JOIN usuario u ON f.id_user=u.id_user WHERE u.id_cuenta=$accountId");
    $total = (int)$r->fetch_assoc()['t'];
    $items = [];
    $stmt2 = $conn->prepare("SELECT f.*, b.nombre AS bodega_nombre, u.nombre AS user_nombre FROM inventario_fisico f LEFT JOIN bodega b ON f.id_bodega=b.id_bodega JOIN usuario u ON f.id_user=u.id_user WHERE u.id_cuenta=? ORDER BY f.created_at DESC LIMIT ? OFFSET ?");
    $stmt2->bind_param("iii", $accountId, $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'inventario_fisico_crear':
    requierePermiso('inventario','conteo_fisico');
    $tipo = $input['tipo'] ?? 'GENERAL';
    $id_bodega = (int)($input['id_bodega'] ?? 0);
    $obs = $input['observaciones'] ?? '';
    if ($id_bodega) requireTenantEntity($conn, tenantContext($uid), 'bodega', $id_bodega);
    $codigo = generarCodigo($conn, 'inventario_fisico', 'codigo', 'INV-');
    $stmt = $conn->prepare("INSERT INTO inventario_fisico (codigo,tipo,id_bodega,id_user,observaciones,estado,fecha_inicio) VALUES (?,?,?,?,?,'EN_PROGRESO',NOW())");
    $stmt->bind_param("ssiis", $codigo, $tipo, $id_bodega, $uid, $obs);
    $stmt->execute();
    $id_inv = (int)$conn->insert_id;
    $stmt->close();
    
    // Crear conteos automáticos para todos los productos de la bodega
    if ($id_bodega) {
        $stmt2 = $conn->prepare("SELECT s.id_producto, s.id_ubicacion, s.disponible FROM stock s WHERE s.id_bodega=? GROUP BY s.id_producto, s.id_ubicacion, s.disponible");
        $stmt2->bind_param("i", $id_bodega);
    } else {
        $stmt2 = $conn->prepare("SELECT s.id_producto, s.id_ubicacion, s.disponible FROM stock s JOIN producto p ON p.id_producto=s.id_producto WHERE p.id_cuenta=$accountId AND s.disponible>0 GROUP BY s.id_producto, s.id_ubicacion, s.disponible");
    }
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    $count = 0;
    while ($f = $r->fetch_assoc()) {
        $idp = (int)$f['id_producto'];
        $idu = $f['id_ubicacion'] ? (int)$f['id_ubicacion'] : null;
        $disp = (int)$f['disponible'];
        if ($idu === null) {
            $stmt2 = $conn->prepare("INSERT INTO conteo_inventario (id_inventario,id_producto,id_ubicacion,conteo1) VALUES (?,?,NULL,?)");
            $stmt2->bind_param("iii", $id_inv, $idp, $disp);
        } else {
            $stmt2 = $conn->prepare("INSERT INTO conteo_inventario (id_inventario,id_producto,id_ubicacion,conteo1) VALUES (?,?,?,?)");
            $stmt2->bind_param("iiii", $id_inv, $idp, $idu, $disp);
        }
        $stmt2->execute();
        $stmt2->close();
        $count++;
    }
    invLog($conn, $uid, 'CREAR_INVENTARIO_FISICO', 'inventario_fisico', $id_inv, ['productos'=>$count]);
    json(['success'=>true, 'id'=>$id_inv, 'codigo'=>$codigo], 201);

case 'inventario_fisico_conteo':
    requierePermiso('inventario','conteo_fisico');
    $id_conteo = (int)($input['id_conteo'] ?? 0);
    $ronda = $input['ronda'] ?? 'conteo1';
    $valor = (int)($input['valor'] ?? 0);
    $allowed = ['conteo1','conteo2','conteo3'];
    if (!in_array($ronda, $allowed)) json(['error'=>'Ronda inválida'],400);
    $stmt2 = $conn->prepare("UPDATE conteo_inventario c JOIN inventario_fisico f ON f.id_inventario=c.id_inventario JOIN usuario u ON u.id_user=f.id_user SET c.$ronda=? WHERE c.id_conteo=? AND u.id_cuenta=?");
    $stmt2->bind_param("iii", $valor, $id_conteo, $accountId);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'EDITAR', 'conteo_inventario', $id_conteo, ['ronda'=>$ronda, 'valor'=>$valor]);
    json(['success'=>true]);

case 'inventario_fisico_cerrar':
    requierePermiso('inventario','conteo_fisico');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("SELECT f.id_bodega, f.id_user FROM inventario_fisico f JOIN usuario u ON u.id_user=f.id_user WHERE f.id_inventario=? AND u.id_cuenta=?");
    $stmt2->bind_param("ii", $id, $accountId);
    $stmt2->execute();
    $inv = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    if (!$inv) json(['error'=>'No autorizado'], 403);
    $id_bodega_default = (int)($inv['id_bodega'] ?? 0);
    
    $conn->begin_transaction();
    try {
        $stmt2 = $conn->prepare("SELECT c.*, COALESCE(s.disponible,0) AS stock_actual, s.id_bodega AS stock_bodega FROM conteo_inventario c LEFT JOIN stock s ON c.id_producto=s.id_producto AND (c.id_ubicacion=s.id_ubicacion OR c.id_ubicacion IS NULL) WHERE c.id_inventario=? AND c.conciliado=0");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $r = $stmt2->get_result();
        $stmt2->close();
        while ($c = $r->fetch_assoc()) {
            $conteo_final = (int)($c['conteo3'] ?? $c['conteo2'] ?? $c['conteo1'] ?? 0);
            $diferencia = $conteo_final - (int)$c['stock_actual'];
            $idConteo = (int)$c['id_conteo'];
            $stmt2 = $conn->prepare("UPDATE conteo_inventario SET diferencia=?, conciliado=1 WHERE id_conteo=?");
            $stmt2->bind_param("ii", $diferencia, $idConteo);
            $stmt2->execute();
            $stmt2->close();
            if ($diferencia !== 0) {
                $idp = (int)$c['id_producto'];
                $idb = (int)($c['stock_bodega'] ?? $id_bodega_default);
                if ($idb > 0) actualizarStock($conn, $idp, $idb, 'disponible', $diferencia);
                $num = generarCodigo($conn, 'ajuste_inventario', 'numero', 'AJ-');
                $stmt = $conn->prepare("INSERT INTO ajuste_inventario (numero,tipo,id_producto,id_bodega,cantidad_anterior,cantidad_nueva,diferencia,motivo,id_user,observaciones) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $tipo_aj = 'FISICO'; $motivo = 'Ajuste por inventario físico'; $obs = 'Conteo físico conciliado';
                $c_stock_actual = (int)$c['stock_actual'];
                $stmt->bind_param("ssiiiiisss", $num, $tipo_aj, $idp, $idb, $c_stock_actual, $conteo_final, $diferencia, $motivo, $uid, $obs);
                $stmt->execute();
                $stmt->close();
            }
        }
        $stmt2 = $conn->prepare("UPDATE inventario_fisico SET estado='CERRADO', fecha_fin=NOW() WHERE id_inventario=?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        $stmt2->close();
        invLog($conn, $uid, 'CERRAR_INVENTARIO_FISICO', 'inventario_fisico', $id);
        $conn->commit();
        json(['success'=>true]);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }

case 'inventario_fisico_detalle':
    $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $items = [];
    $stmt2 = $conn->prepare("SELECT c.*, p.nombre_producto, p.codigo_de_barras, p.sku, u.codigo AS ubicacion_codigo FROM conteo_inventario c JOIN producto p ON c.id_producto=p.id_producto LEFT JOIN ubicacion u ON c.id_ubicacion=u.id_ubicacion WHERE c.id_inventario=? AND p.id_cuenta=?");
    $stmt2->bind_param("ii", $id, $accountId);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

// ─── KARDEX ───
case 'kardex':
    $id_producto = (int)($input['id_producto'] ?? $_GET['id_producto'] ?? 0);
    $id_bodega = (int)($input['id_bodega'] ?? $_GET['id_bodega'] ?? 0);
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(200, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    
    $where = [];
    $kParams = [];
    $kTypes = "";
    if ($id_producto) { $where[] = "k.id_producto=?"; $kParams[] = $id_producto; $kTypes .= "i"; }
    if ($id_bodega) { $where[] = "k.id_bodega=?"; $kParams[] = $id_bodega; $kTypes .= "i"; }
    $w = $where ? 'WHERE '.implode(' AND ', $where) : '';
    
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS t FROM kardex k $w");
    if ($kParams) $stmt2->bind_param($kTypes, ...$kParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt2->close();
    
    $items = [];
    $selSql = "SELECT k.*, p.nombre_producto, b.nombre AS bodega_nombre FROM kardex k JOIN producto p ON k.id_producto=p.id_producto LEFT JOIN bodega b ON k.id_bodega=b.id_bodega $w ORDER BY k.fecha DESC LIMIT ? OFFSET ?";
    $selParams = array_merge($kParams, [$limit, $offset]);
    $selTypes = $kTypes . "ii";
    $stmt2 = $conn->prepare($selSql);
    $stmt2->bind_param($selTypes, ...$selParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

// ─── LOTES ───
case 'lotes':
    $id_producto = (int)($input['id_producto'] ?? $_GET['id_producto'] ?? 0);
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $base = "FROM lote l JOIN producto p ON l.id_producto=p.id_producto LEFT JOIN proveedor pr ON l.id_proveedor=pr.id_proveedor";
    $where = "";
    $lParams = [];
    $lTypes = "";
    if ($id_producto) { $where = "WHERE l.id_producto=?"; $lParams[] = $id_producto; $lTypes = "i"; }
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS t $base $where");
    if ($lParams) $stmt2->bind_param($lTypes, ...$lParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt2->close();
    $items = [];
    $selSql = "SELECT l.*, p.nombre_producto, pr.nombre_empresa AS proveedor_nombre $base $where ORDER BY l.fecha_vencimiento ASC LIMIT ? OFFSET ?";
    $selParams = array_merge($lParams, [$limit, $offset]);
    $selTypes = $lTypes . "ii";
    $stmt2 = $conn->prepare($selSql);
    $stmt2->bind_param($selTypes, ...$selParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'lote_crear':
    requierePermiso('inventario','crear');
    $id_producto = (int)($input['id_producto'] ?? 0);
    $numero_lote = $input['numero_lote'] ?? '';
    if (!$id_producto || !$numero_lote) json(['error'=>'Datos incompletos'],400);
    $stmt = $conn->prepare("INSERT INTO lote (id_producto,numero_lote,id_proveedor,fecha_fabricacion,fecha_ingreso,fecha_vencimiento,cantidad,cantidad_original,id_ubicacion) VALUES (?,?,?,?,?,?,?,?,?)");
    $id_prov = (int)($input['id_proveedor'] ?? 0);
    $ff = $input['fecha_fabricacion'] ?? null;
    $fi = $input['fecha_ingreso'] ?? date('Y-m-d');
    $fv = $input['fecha_vencimiento'] ?? null;
    $cant = (int)($input['cantidad'] ?? 0);
    $id_ubi = (int)($input['id_ubicacion'] ?? 0);
    $stmt->bind_param("ississiii", $id_producto, $numero_lote, $id_prov, $ff, $fi, $fv, $cant, $cant, $id_ubi);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    json(['success'=>true, 'id'=>$id], 201);

// ─── SERIES ───
case 'series':
    $id_producto = (int)($input['id_producto'] ?? $_GET['id_producto'] ?? 0);
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $base = "FROM serie s JOIN producto p ON s.id_producto=p.id_producto";
    $where = "";
    $serParams = [];
    $serTypes = "";
    if ($id_producto) { $where = "WHERE s.id_producto=?"; $serParams[] = $id_producto; $serTypes = "i"; }
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS t $base $where");
    if ($serParams) $stmt2->bind_param($serTypes, ...$serParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt2->close();
    $items = [];
    $selSql = "SELECT s.*, p.nombre_producto $base $where ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
    $selParams = array_merge($serParams, [$limit, $offset]);
    $selTypes = $serTypes . "ii";
    $stmt2 = $conn->prepare($selSql);
    $stmt2->bind_param($selTypes, ...$selParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'serie_crear':
    requierePermiso('inventario','crear');
    $id_producto = (int)($input['id_producto'] ?? 0);
    $numero_serie = $input['numero_serie'] ?? '';
    if (!$id_producto || !$numero_serie) json(['error'=>'Datos incompletos'],400);
    $stmt = $conn->prepare("INSERT INTO serie (id_producto,numero_serie,id_lote,id_ubicacion,estado) VALUES (?,?,?,?,'DISPONIBLE')");
    $id_lote = (int)($input['id_lote'] ?? 0);
    $id_ubi = (int)($input['id_ubicacion'] ?? 0);
    $stmt->bind_param("isii", $id_producto, $numero_serie, $id_lote, $id_ubi);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    json(['success'=>true, 'id'=>$id], 201);

// ─── ALERTAS ───
case 'alertas':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $r = $conn->query("SELECT COUNT(*) AS t FROM alerta_stock a JOIN producto p ON a.id_producto=p.id_producto WHERE p.id_cuenta=$accountId AND a.resuelto=0");
    $total = (int)$r->fetch_assoc()['t'];
    $items = [];
    $stmt2 = $conn->prepare("SELECT a.*, p.nombre_producto FROM alerta_stock a JOIN producto p ON a.id_producto=p.id_producto WHERE p.id_cuenta=? AND a.resuelto=0 ORDER BY a.created_at DESC LIMIT ? OFFSET ?");
    $stmt2->bind_param("iii", $accountId, $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'alerta_resolver':
    requierePermiso('inventario','editar');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt2 = $conn->prepare("UPDATE alerta_stock a JOIN producto p ON p.id_producto=a.id_producto SET a.resuelto=1, a.leido=1 WHERE a.id_alerta=? AND p.id_cuenta=?");
    $stmt2->bind_param("ii", $id, $accountId);
    $stmt2->execute();
    $stmt2->close();
    invLog($conn, $uid, 'RESOLVER', 'alerta_stock', $id);
    json(['success'=>true]);

// ─── AUDITORÍA ───
case 'auditoria':
    $items = [];
    $limit = min(200, (int)($input['limit'] ?? $_GET['limit'] ?? 100));
    $stmt2 = $conn->prepare("SELECT a.*, u.nombre AS user_nombre FROM inventario_auditoria a JOIN usuario u ON a.id_user=u.id_user WHERE u.id_cuenta=? ORDER BY a.created_at DESC LIMIT ?");
    $stmt2->bind_param("ii", $accountId, $limit);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

// ─── REPORTES ───
case 'reporte_existencias':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(500, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 100)));
    $offset = ($page-1)*$limit;
    $base = "FROM producto p LEFT JOIN stock s ON p.id_producto=s.id_producto LEFT JOIN categoria c ON p.id_categoria=c.id_categoria LEFT JOIN marca m ON p.id_marca=m.id_marca WHERE p.id_cuenta=$accountId AND p.activo=1";
    $r = $conn->query("SELECT COUNT(*) AS t $base");
    $total = (int)$r->fetch_assoc()['t'];
    $items = [];
    $stmt2 = $conn->prepare("SELECT p.id_producto, p.nombre_producto, p.codigo_de_barras, p.sku, p.precio_costo, p.precio_venta, p.costo_promedio,
        COALESCE(s.disponible,0) AS stock_total,
        COALESCE(s.reservado,0) AS reservado,
        COALESCE(s.comprometido,0) AS comprometido,
        (COALESCE(s.disponible,0)-COALESCE(s.reservado,0)-COALESCE(s.comprometido,0)) AS disponible,
        c.nombre AS categoria, m.nombre AS marca,
        (COALESCE(s.disponible,0) * p.costo_promedio) AS valor_costo,
        (COALESCE(s.disponible,0) * p.precio_venta) AS valor_venta
        $base ORDER BY p.nombre_producto LIMIT ? OFFSET ?");
    $stmt2->bind_param("ii", $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'reporte_stock_critico':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(500, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 100)));
    $offset = ($page-1)*$limit;
    $base = "FROM producto p LEFT JOIN stock s ON p.id_producto=s.id_producto LEFT JOIN categoria c ON p.id_categoria=c.id_categoria LEFT JOIN marca m ON p.id_marca=m.id_marca WHERE p.id_cuenta=$accountId AND p.activo=1 AND (COALESCE(s.disponible,0) <= p.stock_minimo OR p.cantidad=0 OR p.cantidad IS NULL)";
    $r = $conn->query("SELECT COUNT(*) AS t $base");
    $total = (int)$r->fetch_assoc()['t'];
    $items = [];
    $stmt2 = $conn->prepare("SELECT p.*, c.nombre AS categoria_nombre, m.nombre AS marca_nombre,
        COALESCE(s.disponible,0) AS stock_actual,
        (p.stock_minimo - COALESCE(s.disponible,0)) AS necesita_reponer
        $base ORDER BY COALESCE(s.disponible,0) ASC LIMIT ? OFFSET ?");
    $stmt2->bind_param("ii", $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    while ($f = $r->fetch_assoc()) {
        $f['precio_venta'] = (int)($f['precio_venta'] ?? 0);
        $f['stock_actual'] = (int)($f['stock_actual'] ?? 0);
        $f['stock_minimo'] = (int)($f['stock_minimo'] ?? 0);
        $items[] = $f;
    }
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'reporte_valorizacion':
    $items = [];
    $r = $conn->query("SELECT v.*, u.nombre AS user_nombre FROM valorizacion_inventario v JOIN usuario u ON v.id_user=u.id_user WHERE u.id_cuenta=$accountId ORDER BY v.fecha DESC LIMIT 50");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

case 'reporte_rotacion':
    $items = [];
    $r = $conn->query("SELECT p.id_producto, p.nombre_producto, p.sku, c.nombre AS categoria,
        COALESCE(s.disponible,0) AS stock_actual,
        COALESCE((SELECT SUM(salida) FROM kardex WHERE id_producto=p.id_producto AND fecha>=DATE_SUB(NOW(),INTERVAL 30 DAY)),0) AS salidas_30d,
        COALESCE((SELECT MAX(fecha) FROM kardex WHERE id_producto=p.id_producto AND salida>0),'NUNCA') AS ultima_salida,
        DATEDIFF(NOW(), COALESCE((SELECT MAX(fecha) FROM kardex WHERE id_producto=p.id_producto AND salida>0), NOW())) AS dias_sin_movimiento
        FROM producto p
        LEFT JOIN stock s ON p.id_producto=s.id_producto
        LEFT JOIN categoria c ON p.id_categoria=c.id_categoria
        WHERE p.id_cuenta=$accountId AND p.activo=1
        ORDER BY dias_sin_movimiento DESC");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

// ─── PROVEEDORES (select aux) ───
case 'proveedores_select':
    $items = []; $r = $conn->query("SELECT id_proveedor, nombre_empresa FROM proveedor WHERE id_cuenta=$accountId AND activo=1 ORDER BY nombre_empresa");
    while ($f = $r->fetch_assoc()) $items[] = $f;
    json($items);

default:
    json(['error'=>'Acción no válida: '.$accion], 400);
}
