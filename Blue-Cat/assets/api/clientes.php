<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();
$conn = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id) getCliente($conn, $uid, $id);
        else listClientes($conn, $uid);
        break;
    case 'POST':
        $input = getJsonInput();
        $input['accion'] = $input['accion'] ?? 'crear';
        if ($input['accion'] === 'crear') crearCliente($conn, $uid, $input);
        elseif ($input['accion'] === 'editar') editarCliente($conn, $uid, $input);
        else json(['error'=>'Acción no válida'], 400);
        break;
    default: json(['error'=>'Método no soportado'], 405);
}

function listClientes($conn, $uid) {
    $q = $_GET['q'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;

    $where = " WHERE id_user = $uid";
    $params = []; $types = '';

    if ($q) {
        $where .= " AND (razon_social LIKE ? OR rut LIKE ? OR nombre LIKE ? OR correo LIKE ? OR telefono LIKE ?)";
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like, $like];
        $types = 'sssss';
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM cliente" . $where);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    $sql = "SELECT * FROM cliente" . $where . " ORDER BY razon_social ASC LIMIT $limit OFFSET $offset";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);
}

function getCliente($conn, $uid, $id) {
    $stmt = $conn->prepare("SELECT * FROM cliente WHERE id_cliente = ? AND id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $c = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$c) json(['error'=>'Cliente no encontrado'], 404);
    // Get last 10 invoices
    $stmt = $conn->prepare("SELECT id_factura, numero, total, estado, fecha_emision FROM factura WHERE id_cliente = ? AND id_user = ? ORDER BY id_factura DESC LIMIT 10");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $c['facturas'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json($c);
}

function crearCliente($conn, $uid, $input) {
    $rut = $input['rut'] ?? '';
    $razon = $input['razon_social'] ?? '';
    $nombre = $input['nombre'] ?? '';
    $direccion = $input['direccion'] ?? '';
    $ciudad = $input['ciudad'] ?? '';
    $comuna = $input['comuna'] ?? '';
    $correo = $input['correo'] ?? '';
    $telefono = $input['telefono'] ?? '';
    $giro = $input['giro'] ?? '';

    if (empty($razon)) json(['error'=>'Razón social es obligatoria'], 400);

    $stmt = $conn->prepare("INSERT INTO cliente (id_user, rut, razon_social, nombre, direccion, ciudad, comuna, correo, telefono, giro) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssssssss", $uid, $rut, $razon, $nombre, $direccion, $ciudad, $comuna, $correo, $telefono, $giro);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    json(['success'=>true, 'id_cliente'=>$id], 201);
}

function editarCliente($conn, $uid, $input) {
    $id = (int)($input['id_cliente'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'], 400);

    $fields = ['rut','razon_social','nombre','direccion','ciudad','comuna','correo','telefono','giro'];
    $sets = []; $params = []; $types = '';
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $sets[] = "$f = ?";
            $params[] = $input[$f];
            $types .= 's';
        }
    }
    if (empty($sets)) json(['error'=>'Sin campos para actualizar'], 400);
    $params[] = $id; $types .= 'i';
    $sql = "UPDATE cliente SET " . implode(', ', $sets) . " WHERE id_cliente = ? AND id_user = ?";
    $params[] = $uid; $types .= 'i';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    json(['success'=>true]);
}
?>