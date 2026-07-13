<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();

function requierePermiso($modulo, $accion) {
    if (!verificarPermiso($modulo, $accion)) {
        json(['error'=>'Permiso denegado: '.$modulo.'.'.$accion], 403);
    }
}

$conn = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        requierePermiso('proveedores','ver');
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id) getProveedor($conn, $uid, $id);
        else listProveedores($conn, $uid);
        break;
    case 'POST':
        $input = getJsonInput();
        $accion = $input['accion'] ?? 'crear';
        if ($accion === 'crear') { requierePermiso('proveedores','crear'); crearProveedor($conn, $uid, $input); }
        elseif ($accion === 'editar') { requierePermiso('proveedores','editar'); editarProveedor($conn, $uid, $input); }
        elseif ($accion === 'cambiar_estado') { requierePermiso('proveedores','editar'); cambiarEstado($conn, $uid, $input); }
        elseif ($accion === 'contacto_crear') { requierePermiso('proveedores','crear'); crearContacto($conn, $uid, $input); }
        elseif ($accion === 'contacto_editar') { requierePermiso('proveedores','editar'); editarContacto($conn, $uid, $input); }
        elseif ($accion === 'contacto_eliminar') { requierePermiso('proveedores','eliminar'); eliminarContacto($conn, $uid, $input); }
        elseif ($accion === 'banco_crear') { requierePermiso('proveedores','crear'); crearBanco($conn, $uid, $input); }
        elseif ($accion === 'banco_eliminar') { requierePermiso('proveedores','eliminar'); eliminarBanco($conn, $uid, $input); }
        elseif ($accion === 'producto_asociar') { requierePermiso('proveedores','editar'); asociarProducto($conn, $uid, $input); }
        elseif ($accion === 'producto_eliminar') { requierePermiso('proveedores','eliminar'); eliminarProductoAsoc($conn, $uid, $input); }
        else json(['error'=>'Acción no válida'], 400);
        break;
    default: json(['error'=>'Método no soportado'], 405);
}

function listProveedores($conn, $uid) {
    $accountId = tenantContext($uid)->accountId;
    $q = $_GET['q'] ?? '';
    $estado = $_GET['estado'] ?? '';
    $categoria = $_GET['categoria'] ?? '';
    $ciudad = $_GET['ciudad'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;

    $where = " WHERE p.id_cuenta = $accountId";
    $params = []; $types = '';

    if ($q) {
        $where .= " AND (p.razon_social LIKE ? OR p.rut LIKE ? OR p.nombre_comercial LIKE ? OR p.correo LIKE ? OR p.telefono LIKE ?)";
        $like = '%' . $q . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]); $types .= 'sssss';
    }
    if ($estado)   { $where .= " AND p.estado = ?"; $params[] = $estado; $types .= 's'; }
    if ($categoria) { $where .= " AND p.categoria = ?"; $params[] = $categoria; $types .= 's'; }
    if ($ciudad)   { $where .= " AND p.ciudad LIKE ?"; $params[] = '%'.$ciudad.'%'; $types .= 's'; }

    $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM proveedor p" . $where);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    $sql = "SELECT p.*, (SELECT COUNT(*) FROM proveedor_contacto WHERE id_proveedor = p.id_proveedor) as contactos FROM proveedor p" . $where . " ORDER BY p.razon_social ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);
}

function requireProveedorChild($conn, $uid, $table, $primaryKey, $id) {
    $allowed = [
        'proveedor_contacto' => 'id_contacto',
        'proveedor_banco' => 'id_banco',
        'proveedor_producto' => 'id_prov_producto',
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== $primaryKey) json(['error'=>'Entidad no valida'], 400);
    $accountId = tenantContext($uid)->accountId;
    $stmt = $conn->prepare("SELECT x.id_proveedor FROM $table x JOIN proveedor p ON p.id_proveedor=x.id_proveedor WHERE x.$primaryKey=? AND p.id_cuenta=?");
    $stmt->bind_param("ii", $id, $accountId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) json(['error'=>'No autorizado'], 403);
    return (int)$row['id_proveedor'];
}

function getProveedor($conn, $uid, $id) {
    $stmt = $conn->prepare("SELECT * FROM proveedor WHERE id_proveedor = ? AND id_cuenta = (SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$p) json(['error'=>'Proveedor no encontrado'], 404);

    $stmt = $conn->prepare("SELECT * FROM proveedor_contacto WHERE id_proveedor = ? ORDER BY principal DESC, nombre ASC");
    $stmt->bind_param("i", $id); $stmt->execute();
    $p['contactos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM proveedor_banco WHERE id_proveedor = ? ORDER BY principal DESC");
    $stmt->bind_param("i", $id); $stmt->execute();
    $p['bancos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $stmt = $conn->prepare("SELECT pp.*, pr.nombre_producto, pr.codigo_de_barras FROM proveedor_producto pp LEFT JOIN producto pr ON pp.id_producto = pr.id_producto WHERE pp.id_proveedor = ? ORDER BY pp.producto ASC");
    $stmt->bind_param("i", $id); $stmt->execute();
    $p['productos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $stmt = $conn->prepare("SELECT h.*, u.nombre as usuario FROM proveedor_historial h LEFT JOIN usuario u ON h.id_user = u.id_user WHERE h.id_proveedor = ? ORDER BY h.fecha DESC LIMIT 30");
    $stmt->bind_param("i", $id); $stmt->execute();
    $p['historial'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    json($p);
}

function crearProveedor($conn, $uid, $input) {
    $rut = $input['rut'] ?? '';
    $razon = $input['razon_social'] ?? '';
    $comercial = $input['nombre_comercial'] ?? '';
    if (empty($razon)) json(['error'=>'Razón social es obligatoria'], 400);

    // Auto code
    $stmt = $conn->prepare("SELECT COALESCE(MAX(id_proveedor),0)+1 as n FROM proveedor WHERE id_cuenta = (SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $codigo = 'PROV-' . str_pad((int)$r['n'], 4, '0', STR_PAD_LEFT);
    $stmt->close();

    $fields = ['rut','razon_social','nombre_comercial','giro','categoria','tipo','estado','pais','region','ciudad','comuna','direccion','codigo_postal','telefono','correo','sitio_web','contacto_principal','responsable_interno','condicion_pago','moneda','incoterm','tipo_contribuyente','actividad_economica','resolucion_sii','notas'];
    $vals = [$codigo, $uid];
    $phs = ['?','?'];
    $sql_fields = 'codigo, id_user';
    $types = 'si';

    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $sql_fields .= ", $f";
            $v = $input[$f];
            $vals[] = $v; $phs[] = '?'; $types .= 's';
        }
    }
    // int fields
    foreach (['limite_credito','descuento','tiempo_entrega','pedido_minimo'] as $f) {
        if (isset($input[$f])) {
            $sql_fields .= ", $f";
            $vals[] = (int)$input[$f]; $phs[] = '?'; $types .= 'i';
        }
    }
    foreach (['exento_iva','retefuente'] as $f) {
        if (isset($input[$f])) {
            $sql_fields .= ", $f";
            $vals[] = $input[$f] ? 1 : 0; $phs[] = '?'; $types .= 'i';
        }
    }

    $sql = "INSERT INTO proveedor ($sql_fields) VALUES (" . implode(',', $phs) . ")";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    addProvHistorial($conn, $id, $uid, 'CREAR', null, "Proveedor $razon creado");
    json(['success'=>true, 'id_proveedor'=>$id, 'codigo'=>$codigo], 201);
}

function editarProveedor($conn, $uid, $input) {
    $id = (int)($input['id_proveedor'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'], 400);

    $fields = ['rut','razon_social','nombre_comercial','giro','categoria','tipo','estado','pais','region','ciudad','comuna','direccion','codigo_postal','telefono','correo','sitio_web','contacto_principal','responsable_interno','condicion_pago','moneda','incoterm','tipo_contribuyente','actividad_economica','resolucion_sii','notas'];
    $intFields = ['limite_credito','descuento','tiempo_entrega','pedido_minimo'];
    $boolFields = ['exento_iva','retefuente'];

    $sets = []; $params = []; $types = '';
    foreach ($fields as $f) {
        if (isset($input[$f])) { $sets[] = "$f = ?"; $params[] = $input[$f]; $types .= 's'; }
    }
    foreach ($intFields as $f) {
        if (isset($input[$f])) { $sets[] = "$f = ?"; $params[] = (int)$input[$f]; $types .= 'i'; }
    }
    foreach ($boolFields as $f) {
        if (isset($input[$f])) { $sets[] = "$f = ?"; $params[] = $input[$f] ? 1 : 0; $types .= 'i'; }
    }
    if (empty($sets)) json(['error'=>'Sin cambios'], 400);
    $params[] = $id; $types .= 'i';
    $params[] = $uid; $types .= 'i';
    $sql = "UPDATE proveedor SET " . implode(', ', $sets) . " WHERE id_proveedor = ? AND id_cuenta = (SELECT id_cuenta FROM usuario WHERE id_user=?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    addProvHistorial($conn, $id, $uid, 'EDITAR', null, 'Datos actualizados');
    json(['success'=>true]);
}

function cambiarEstado($conn, $uid, $input) {
    $id = (int)($input['id_proveedor'] ?? 0);
    $estado = $input['estado'] ?? '';
    if (!$id || !$estado) json(['error'=>'Datos inválidos'], 400);
    $stmt = $conn->prepare("SELECT estado FROM proveedor WHERE id_proveedor = ? AND id_cuenta = (SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) json(['error'=>'No autorizado'], 403);
    $old = $row['estado'];
    $accountId = tenantContext($uid)->accountId;
    $stmt = $conn->prepare("UPDATE proveedor SET estado = ? WHERE id_proveedor = ? AND id_cuenta = ?");
    $stmt->bind_param("sii", $estado, $id, $accountId);
    $stmt->execute();
    $stmt->close();
    addProvHistorial($conn, $id, $uid, 'ESTADO', $old, $estado);
    json(['success'=>true]);
}

/* ── Contactos ── */
function crearContacto($conn, $uid, $input) {
    $id_prov = (int)($input['id_proveedor'] ?? 0);
    $nombre = $input['nombre'] ?? '';
    if (!$id_prov || empty($nombre)) json(['error'=>'Datos inválidos'], 400);
    requireTenantEntity($conn, tenantContext($uid), 'proveedor', $id_prov);
    $cargo = $input['cargo'] ?? '';
    $depto = $input['departamento'] ?? '';
    $correo = $input['correo'] ?? '';
    $telefono = $input['telefono'] ?? '';
    $celular = $input['celular'] ?? '';
    $whatsapp = $input['whatsapp'] ?? '';
    $principal = $input['principal'] ? 1 : 0;
    $notas = $input['notas'] ?? '';

    if ($principal) {
        $st = $conn->prepare("UPDATE proveedor_contacto SET principal=0 WHERE id_proveedor = ?");
        $st->bind_param("i", $id_prov);
        $st->execute();
        $st->close();
    }
    $stmt = $conn->prepare("INSERT INTO proveedor_contacto (id_proveedor,nombre,cargo,departamento,correo,telefono,celular,whatsapp,principal,notas) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssssssis", $id_prov, $nombre, $cargo, $depto, $correo, $telefono, $celular, $whatsapp, $principal, $notas);
    $stmt->execute();
    $id_c = (int)$conn->insert_id; $stmt->close();
    addProvHistorial($conn, $id_prov, $uid, 'CONTACTO_CREAR', null, "Contacto: $nombre");
    json(['success'=>true, 'id_contacto'=>$id_c], 201);
}

function editarContacto($conn, $uid, $input) {
    $id = (int)($input['id_contacto'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'], 400);
    requireProveedorChild($conn, $uid, 'proveedor_contacto', 'id_contacto', $id);
    $fields = ['nombre','cargo','departamento','correo','telefono','celular','whatsapp','notas'];
    $sets = []; $params = []; $types = '';
    foreach ($fields as $f) {
        if (isset($input[$f])) { $sets[] = "$f = ?"; $params[] = $input[$f]; $types .= 's'; }
    }
    if (isset($input['principal'])) {
        $st = $conn->prepare("SELECT id_proveedor FROM proveedor_contacto WHERE id_contacto = ?");
        $st->bind_param("i", $id);
        $st->execute();
        $c = $st->get_result()->fetch_assoc();
        $st->close();
        if ($c) {
            $st2 = $conn->prepare("UPDATE proveedor_contacto SET principal=0 WHERE id_proveedor = ?");
            $c_id_proveedor = (int)$c['id_proveedor'];
            $st2->bind_param("i", $c_id_proveedor);
            $st2->execute();
            $st2->close();
        }
        $sets[] = "principal = 1";
    }
    if (empty($sets)) json(['error'=>'Sin cambios'], 400);
    $params[] = $id; $types .= 'i';
    $stmt = $conn->prepare("UPDATE proveedor_contacto SET " . implode(', ', $sets) . " WHERE id_contacto = ?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute(); $stmt->close();
    json(['success'=>true]);
}

function eliminarContacto($conn, $uid, $input) {
    $id = (int)($input['id_contacto'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'], 400);
    requireProveedorChild($conn, $uid, 'proveedor_contacto', 'id_contacto', $id);
    $stmt2 = $conn->prepare("DELETE FROM proveedor_contacto WHERE id_contacto = ?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    json(['success'=>true]);
}

/* ── Bancos ── */
function crearBanco($conn, $uid, $input) {
    $id_prov = (int)($input['id_proveedor'] ?? 0);
    $banco = $input['banco'] ?? '';
    if (!$id_prov || empty($banco)) json(['error'=>'Datos inválidos'], 400);
    requireTenantEntity($conn, tenantContext($uid), 'proveedor', $id_prov);
    $tipo = $input['tipo_cuenta'] ?? '';
    $num = $input['numero_cuenta'] ?? '';
    $titular = $input['titular'] ?? '';
    $rut_tit = $input['rut_titular'] ?? '';
    $swift = $input['swift'] ?? '';
    $iban = $input['iban'] ?? '';
    $correo_p = $input['correo_pagos'] ?? '';
    $principal = $input['principal'] ? 1 : 0;
    if ($principal) {
        $st = $conn->prepare("UPDATE proveedor_banco SET principal=0 WHERE id_proveedor = ?");
        $st->bind_param("i", $id_prov);
        $st->execute();
        $st->close();
    }
    $stmt = $conn->prepare("INSERT INTO proveedor_banco (id_proveedor,banco,tipo_cuenta,numero_cuenta,titular,rut_titular,swift,iban,correo_pagos,principal) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("issssssssi", $id_prov, $banco, $tipo, $num, $titular, $rut_tit, $swift, $iban, $correo_p, $principal);
    $stmt->execute(); $id_b = (int)$conn->insert_id; $stmt->close();
    json(['success'=>true, 'id_banco'=>$id_b], 201);
}

function eliminarBanco($conn, $uid, $input) {
    $id = (int)($input['id_banco'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'], 400);
    requireProveedorChild($conn, $uid, 'proveedor_banco', 'id_banco', $id);
    $stmt2 = $conn->prepare("DELETE FROM proveedor_banco WHERE id_banco = ?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    json(['success'=>true]);
}

/* ── Productos del proveedor ── */
function asociarProducto($conn, $uid, $input) {
    $id_prov = (int)($input['id_proveedor'] ?? 0);
    $id_prod = (int)($input['id_producto'] ?? 0);
    $sku_prov = $input['sku_proveedor'] ?? '';
    $precio = (int)($input['precio_compra'] ?? 0);
    if (!$id_prov || !$id_prod) json(['error'=>'Datos inválidos'], 400);

    requireTenantEntity($conn, tenantContext($uid), 'proveedor', $id_prov);
    $stmt = $conn->prepare("SELECT nombre_producto, codigo_de_barras, precio_venta, categoria FROM producto WHERE id_producto = ? AND id_cuenta = (SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id_prod, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r || !$r->num_rows) json(['error'=>'Producto no encontrado'], 404);
    $pr = $r->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO proveedor_producto (id_proveedor, id_producto, sku_proveedor, sku_interno, producto, marca, categoria, precio_compra) VALUES (?,?,?,?,?,?,?,?)");
    $marca = $input['marca'] ?? '';
    $pr_codigo = $pr['codigo_de_barras'] ?? '';
    $pr_nombre = $pr['nombre_producto'] ?? '';
    $pr_categoria = $pr['categoria'] ?? '';
    $stmt->bind_param("iisssssi", $id_prov, $id_prod, $sku_prov, $pr_codigo, $pr_nombre, $marca, $pr_categoria, $precio);
    $stmt->execute(); $stmt->close();
    addProvHistorial($conn, $id_prov, $uid, 'PRODUCTO_ASOCIAR', null, "Producto: {$pr['nombre_producto']}");
    json(['success'=>true], 201);
}

function eliminarProductoAsoc($conn, $uid, $input) {
    $id = (int)($input['id_prov_producto'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'], 400);
    requireProveedorChild($conn, $uid, 'proveedor_producto', 'id_prov_producto', $id);
    $stmt2 = $conn->prepare("DELETE FROM proveedor_producto WHERE id_prov_producto = ?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $stmt2->close();
    json(['success'=>true]);
}

/* ── Historial ── */
function addProvHistorial($conn, $id_proveedor, $id_user, $accion, $valor_anterior=null, $valor_nuevo=null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO proveedor_historial (id_proveedor, id_user, accion, valor_anterior, valor_nuevo, ip) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("iissss", $id_proveedor, $id_user, $accion, $valor_anterior, $valor_nuevo, $ip);
    $stmt->execute(); $stmt->close();
}
?>