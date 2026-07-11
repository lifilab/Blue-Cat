<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();
$conn = getDB();
$method = $_SERVER['REQUEST_METHOD'];

function requierePermiso($modulo, $accion) {
    if (!verificarPermiso($modulo, $accion)) {
        json(['error'=>'Permiso denegado: '.$modulo.'.'.$accion], 403);
    }
}

function auditar($conn, $uid, $accion, $entidad, $id_entidad=null, $detalle=null, $nivel='INFO') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $vn = $detalle !== null ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $conn->prepare("INSERT INTO core_auditoria (id_user, accion, entidad, id_entidad, valor_nuevo, ip, user_agent, nivel) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("ississss", $uid, $accion, $entidad, $id_entidad, $vn, $ip, $ua, $nivel);
    $stmt->execute();
    $stmt->close();
}

function buildWhere($conn, $uid) {
    $f = ['desde','hasta','periodo','id_user','id_sesion','estado','metodo','busqueda'];
    $p = []; foreach ($f as $k) $p[$k] = $_GET[$k] ?? '';
    $p['id_user'] = (int)$p['id_user'];
    $p['id_sesion'] = (int)$p['id_sesion'];
    $w = "1=1";
    
    // Manejar periodo o fechas personalizadas
    if ($p['desde'] && $p['hasta']) {
        $w .= " AND DATE(p.fecha) BETWEEN '".$conn->real_escape_string($p['desde'])."' AND '".$conn->real_escape_string($p['hasta'])."'";
    } elseif ($p['periodo']) {
        // Convertir periodo a rango de fechas
        $hoy = date('Y-m-d');
        switch ($p['periodo']) {
            case 'hoy':
                $w .= " AND DATE(p.fecha) = '$hoy'";
                break;
            case 'ayer':
                $ayer = date('Y-m-d', strtotime('-1 day'));
                $w .= " AND DATE(p.fecha) = '$ayer'";
                break;
            case 'esta_semana':
                $inicio_semana = date('Y-m-d', strtotime('monday this week'));
                $w .= " AND DATE(p.fecha) BETWEEN '$inicio_semana' AND '$hoy'";
                break;
            case 'este_mes':
                $inicio_mes = date('Y-m-01');
                $w .= " AND DATE(p.fecha) BETWEEN '$inicio_mes' AND '$hoy'";
                break;
            case 'este_año':
            case 'este_ano':
                $inicio_anio = date('Y-01-01');
                $w .= " AND DATE(p.fecha) BETWEEN '$inicio_anio' AND '$hoy'";
                break;
            default:
                // Si es un formato YYYY-MM, filtrar por mes
                if (preg_match('/^\d{4}-\d{2}$/', $p['periodo'])) {
                    $w .= " AND DATE_FORMAT(p.fecha,'%Y-%m')='".$conn->real_escape_string($p['periodo'])."'";
                }
                break;
        }
    }
    
    if ($p['id_user']) $w .= " AND s.id_user={$p['id_user']}";
    if ($p['id_sesion']) $w .= " AND p.id_sesion={$p['id_sesion']}";
    // Manejar filtro de estado (aceptar múltiples formatos)
    $estado = strtolower($p['estado']);
    if ($estado === 'anulado' || $estado === 'anulada') {
        $w .= " AND p.anulado=1";
    } elseif ($estado === 'activo' || $estado === 'vigente' || $estado === 'completada') {
        $w .= " AND p.anulado=0";
    }
    if ($p['metodo']) $w .= " AND p.id_pedido IN (SELECT id_pedido FROM metodo_de_pago WHERE nombre_metodo_pago LIKE '%".$conn->real_escape_string($p['metodo'])."%')";
    if ($p['busqueda']) {
        $b = $conn->real_escape_string($p['busqueda']);
        $w .= " AND (p.cliente_nombre LIKE '%$b%' OR p.cliente_rut LIKE '%$b%' OR CAST(p.id_pedido AS CHAR) LIKE '%$b%' OR p.tipo_documento LIKE '%$b%')";
    }
    if (!verificarPermiso('ventas','ver_todos')) $w .= " AND s.id_user=$uid";
    return $w;
}

function calcResumen($conn, $w) {
    $r = $conn->query("SELECT COUNT(*) as cant, COALESCE(SUM(p.precio_total),0) as total FROM pedido p LEFT JOIN sesion s ON p.id_sesion=s.id_sesion WHERE $w AND p.anulado=0")->fetch_assoc();
    $res = ['total_ventas'=>(int)$r['cant'],'total_monto'=>(int)$r['total'],'promedio_ticket'=>$r['cant']>0 ? round($r['total']/$r['cant']) : 0];
    $met = $conn->query("SELECT mp.nombre_metodo_pago, COALESCE(SUM(mp.monto),0) as total FROM metodo_de_pago mp INNER JOIN pedido p ON mp.id_pedido=p.id_pedido LEFT JOIN sesion s ON p.id_sesion=s.id_sesion WHERE $w AND p.anulado=0 GROUP BY mp.nombre_metodo_pago")->fetch_all(MYSQLI_ASSOC);
    $res['por_metodo'] = [];
    foreach ($met as $m) $res['por_metodo'][$m['nombre_metodo_pago']] = (int)$m['total'];
    $a = $conn->query("SELECT COUNT(*) as cant, COALESCE(SUM(p.precio_total),0) as total FROM pedido p LEFT JOIN sesion s ON p.id_sesion=s.id_sesion WHERE $w AND p.anulado=1")->fetch_assoc();
    $res['anulado_cant'] = (int)$a['cant'];
    $res['anulado_total'] = (int)$a['total'];
    return $res;
}

if ($method === 'GET') {
    $accion = $_GET['accion'] ?? 'listar';
    if ($accion === 'listar') listar($conn, $uid);
    elseif ($accion === 'detalle') detalle($conn, $uid);
    elseif ($accion === 'resumen') resumen($conn, $uid);
    elseif ($accion === 'sesiones') sesiones($conn, $uid);
    elseif ($accion === 'exportar') exportar($conn, $uid);
    else json(['error'=>'Acción no válida'], 400);
} elseif ($method === 'POST') {
    $input = getJsonInput();
    if (is_object($input)) {
        $input = json_decode(json_encode($input), true);
    }
    if (!is_array($input)) {
        $input = [];
    }
    $accion = $input['accion'] ?? '';
    if ($accion === 'cuadre') { requierePermiso('ventas','cuadre'); cuadre($conn, $uid, $input); }
    elseif ($accion === 'editar') { requierePermiso('ventas','editar'); editar($conn, $uid, $input); }
    elseif ($accion === 'eliminar') { requierePermiso('ventas','eliminar'); eliminar($conn, $uid, $input); }
    else json(['error'=>'Acción no válida'], 400);
} else {
    json(['error'=>'Método no soportado'], 405);
}

function listar($conn, $uid) {
    requierePermiso('ventas','ver');
    $w = buildWhere($conn, $uid);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    $order = $_GET['order'] ?? 'p.fecha DESC';
    $orderCol = strtoupper(explode(' ', trim($order))[0]);
    $allowed = ['P.FECHA','P.ID_PEDIDO','P.PRECIO_TOTAL','P.CLIENTE_NOMBRE','P.TIPO_DOCUMENTO','S.EMPLEADO'];
    if (!in_array($orderCol, $allowed)) {
        $order = 'p.fecha DESC';
    } else {
        $orderDir = (stripos($order, 'DESC') !== false) ? 'DESC' : 'ASC';
        $order = $orderCol . ' ' . $orderDir;
    }
    $base = "FROM pedido p LEFT JOIN sesion s ON p.id_sesion=s.id_sesion WHERE $w";
    $total = (int)$conn->query("SELECT COUNT(*) as t $base")->fetch_assoc()['t'];
    $sql = "SELECT p.*, s.empleado, s.fecha_ingreso as sesion_fecha $base ORDER BY $order LIMIT $limit OFFSET $offset";
    $ventas = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    if (!empty($ventas)) {
        $id_values = array_column($ventas, 'id_pedido');
        $placeholders = implode(',', array_fill(0, count($id_values), '?'));
        $types = str_repeat('i', count($id_values));
        $stmt = $conn->prepare("SELECT dp.*, pr.nombre_producto, pr.codigo_de_barras FROM detalle_pedido dp LEFT JOIN producto pr ON dp.id_producto=pr.id_producto WHERE dp.id_pedido IN ($placeholders)");
        $stmt->bind_param($types, ...$id_values);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $stmt = $conn->prepare("SELECT * FROM metodo_de_pago WHERE id_pedido IN ($placeholders)");
        $stmt->bind_param($types, ...$id_values);
        $stmt->execute();
        $pagos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $im = []; foreach ($items as $it) $im[$it['id_pedido']][] = $it;
        $pm = []; foreach ($pagos as $pg) $pm[$pg['id_pedido']][] = $pg;
        foreach ($ventas as &$v) { $v['items'] = $im[$v['id_pedido']] ?? []; $v['pagos'] = $pm[$v['id_pedido']] ?? []; }
    }
    json(['ventas'=>$ventas,'total'=>$total,'pagina'=>$page,'limite'=>$limit,'paginas'=>max(1,ceil($total/$limit)),'resumen'=>calcResumen($conn,$w)]);
}

function detalle($conn, $uid) {
    requierePermiso('ventas','ver');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT p.*, s.fecha_ingreso as sesion_fecha, s.empleado, s.id_user as sesion_user FROM pedido p LEFT JOIN sesion s ON p.id_sesion=s.id_sesion WHERE p.id_pedido=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$p) json(['error'=>'No encontrada'], 404);
    if ((int)$p['sesion_user'] !== $uid && !verificarPermiso('ventas','ver_todos')) json(['error'=>'No autorizado'], 403);
    $stmt = $conn->prepare("SELECT dp.*, pr.nombre_producto, pr.codigo_de_barras FROM detalle_pedido dp LEFT JOIN producto pr ON dp.id_producto=pr.id_producto WHERE dp.id_pedido=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $p['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM metodo_de_pago WHERE id_pedido=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $p['pagos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json($p);
}

function resumen($conn, $uid) {
    requierePermiso('ventas','ver');
    json(calcResumen($conn, buildWhere($conn, $uid)));
}

function sesiones($conn, $uid) {
    $stmt = $conn->prepare("SELECT id_sesion, fecha_ingreso, fecha_cierre, empleado, monto_apertura FROM sesion WHERE id_user=? ORDER BY fecha_ingreso DESC LIMIT 50");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) $r['monto_apertura'] = (int)$r['monto_apertura'];
    json($rows);
}

function exportar($conn, $uid) {
    $w = buildWhere($conn, $uid);
    $sql = "SELECT p.id_pedido, p.fecha, p.tipo_documento, p.cliente_nombre, p.cliente_rut, p.precio_total, p.pago_total, p.diferencia, p.anulado, s.empleado FROM pedido p LEFT JOIN sesion s ON p.id_sesion=s.id_sesion WHERE $w ORDER BY p.fecha DESC";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ventas.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID','Fecha','Documento','Cliente','RUT','Total','Pagado','Diferencia','Anulado','Empleado']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id_pedido'],$r['fecha'],$r['tipo_documento'],$r['cliente_nombre'],$r['cliente_rut'],$r['precio_total'],$r['pago_total'],$r['diferencia'],$r['anulado']?'Sí':'No',$r['empleado']]);
    }
    fclose($out);
    exit;
}

function cuadre($conn, $uid, $input) {
    $id_sesion = (int)($input['id_sesion'] ?? 0);
    if (!$id_sesion) {
        $stmt = $conn->prepare("SELECT id_sesion FROM sesion WHERE id_user=? AND fecha_cierre IS NULL ORDER BY fecha_ingreso DESC LIMIT 1");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $r = $stmt->get_result();
        $stmt->close();
        $s = $r->fetch_assoc();
        if (!$s) json(['error'=>'No hay sesión activa. Abra caja primero.'], 400);
        $id_sesion = (int)$s['id_sesion'];
    }
    $stmt = $conn->prepare("SELECT * FROM sesion WHERE id_sesion=?");
    $stmt->bind_param("i", $id_sesion);
    $stmt->execute();
    $ses = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ses) json(['error'=>'Sesión no encontrada'], 404);
    $stmt = $conn->prepare("SELECT COALESCE(SUM(precio_total),0) as total FROM pedido WHERE id_sesion=? AND anulado=0");
    $stmt->bind_param("i", $id_sesion);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();
    $monto_ventas = (int)$r->fetch_assoc()['total'];
    $stmt = $conn->prepare("SELECT SUM(CASE WHEN m.nombre_metodo_pago IN ('Efectivo','EFECTIVO') THEN m.monto ELSE 0 END) AS efectivo, SUM(CASE WHEN m.nombre_metodo_pago IN ('Tarjeta','TARJETA','Crédito','Débito','DEBITO','CREDITO') THEN m.monto ELSE 0 END) AS tarjeta, SUM(CASE WHEN m.nombre_metodo_pago IN ('Transferencia','TRANSFERENCIA') THEN m.monto ELSE 0 END) AS transferencia FROM metodo_de_pago m INNER JOIN pedido p ON m.id_pedido=p.id_pedido WHERE p.id_sesion=? AND p.anulado=0");
    $stmt->bind_param("i", $id_sesion);
    $stmt->execute();
    $metodos = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    json(['empleado'=>$ses['empleado'] ?? '','nota'=>$ses['nota'] ?? '','fecha_apertura'=>$ses['fecha_ingreso'],'monto_apertura'=>(int)($ses['monto_apertura'] ?? 0),'efectivo'=>(int)($metodos['efectivo'] ?? 0),'tarjeta'=>(int)($metodos['tarjeta'] ?? 0),'transferencia'=>(int)($metodos['transferencia'] ?? 0),'monto_total'=>(int)($ses['monto_apertura'] ?? 0) + $monto_ventas,'monto_ventas'=>$monto_ventas,'id_sesion'=>$id_sesion]);
}

function editar($conn, $uid, $input) {
    $id_pedido = (int)($input['id_pedido'] ?? 0);
    $items_keep = $input['items_keep'] ?? [];
    $items_remove = $input['items_remove'] ?? [];
    $pagos = $input['pagos'] ?? [];
    if (!$id_pedido) json(['error'=>'ID de pedido requerido'], 400);
    if (empty($items_keep)) json(['error'=>'Debe quedar al menos un producto'], 400);
    $stmt = $conn->prepare("SELECT p.*, s.id_user as sesion_user FROM pedido p JOIN sesion s ON p.id_sesion=s.id_sesion WHERE p.id_pedido=?");
    $stmt->bind_param("i", $id_pedido);
    $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pedido) json(['error'=>'Venta no encontrada'], 404);
    if ((int)$pedido['sesion_user'] !== $uid) json(['error'=>'No autorizado'], 403);
    if ($pedido['anulado']) json(['error'=>'Venta anulada no se puede editar'], 400);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id_bodega FROM pedido WHERE id_pedido=?");
        $stmt->bind_param("i", $id_pedido);
        $stmt->execute();
        $p = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $id_bodega = $p && $p['id_bodega'] ? (int)$p['id_bodega'] : 0;
        if (!$id_bodega) throw new Exception('Bodega no encontrada para esta venta');

        foreach ($items_remove as $item) {
            $id_prod = (int)$item['id_producto']; $cant = (int)($item['cantidad'] ?? 0);
            if ($id_prod && $cant) {
                reponerStock($conn, $id_prod, $id_bodega, $cant);
                actualizarKardex($conn, $uid, $id_prod, $id_bodega, 'AJUSTE', $id_pedido, 'PEDIDO', $cant, 0, 0, "Edición venta #$id_pedido - item quitado");
            }
            $stmt = $conn->prepare("DELETE FROM detalle_pedido WHERE id_pedido=? AND id_producto=?");
            $stmt->bind_param("ii", $id_pedido, $id_prod);
            $stmt->execute();
            $stmt->close();
        }
        foreach ($items_keep as $item) {
            $id_prod = (int)$item['id_producto']; $new_cant = (int)($item['cantidad'] ?? 1); $new_precio = (int)($item['precio_total'] ?? 0);
            $stmt = $conn->prepare("SELECT cantidad_pedida FROM detalle_pedido WHERE id_pedido=? AND id_producto=?");
            $stmt->bind_param("ii", $id_pedido, $id_prod);
            $stmt->execute();
            $old = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($old) {
                $old_cant = (int)$old['cantidad_pedida']; $diff = $old_cant - $new_cant;
                if ($diff > 0) {
                    reponerStock($conn, $id_prod, $id_bodega, $diff);
                    actualizarKardex($conn, $uid, $id_prod, $id_bodega, 'AJUSTE', $id_pedido, 'PEDIDO', $diff, 0, 0, "Edición venta #$id_pedido - reducción");
                } elseif ($diff < 0) {
                    $abs_diff = abs($diff);
                    $ok = descontarStock($conn, $id_prod, $id_bodega, $abs_diff);
                    if (!$ok) throw new Exception("Stock insuficiente para producto #$id_prod");
                    actualizarKardex($conn, $uid, $id_prod, $id_bodega, 'AJUSTE', $id_pedido, 'PEDIDO', 0, $abs_diff, 0, "Edición venta #$id_pedido - incremento");
                }
                $stmt = $conn->prepare("UPDATE detalle_pedido SET cantidad_pedida=?, precio_total=? WHERE id_pedido=? AND id_producto=?");
                $stmt->bind_param("iiii", $new_cant, $new_precio, $id_pedido, $id_prod);
                $stmt->execute();
                $stmt->close();
            }
        }
        $nuevo_total = 0; foreach ($items_keep as $item) $nuevo_total += (int)($item['precio_total'] ?? 0);
        $total_pago = 0; foreach ($pagos as $p) $total_pago += (int)($p['monto'] ?? 0);
        $diferencia = $total_pago - $nuevo_total;
        $stmt = $conn->prepare("UPDATE pedido SET precio_total=?, pago_total=?, diferencia=? WHERE id_pedido=?");
        $stmt->bind_param("iiii", $nuevo_total, $total_pago, $diferencia, $id_pedido);
        $stmt->execute(); $stmt->close();
        $id_caja = (int)($pedido['id_caja'] ?? 0);
        if ($id_caja) {
            $old_total = (int)$pedido['precio_total'];
            $concepto1 = "Ajuste edición venta #$id_pedido";
            $stmt = $conn->prepare("INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, id_pedido) VALUES (?, ?, 'EGRESO', ?, ?, 'Efectivo', ?)");
            $stmt->bind_param("iisii", $id_caja, $uid, $concepto1, $old_total, $id_pedido);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("UPDATE pos_caja SET monto_actual=monto_actual-? WHERE id_caja=?");
            $stmt->bind_param("ii", $old_total, $id_caja);
            $stmt->execute();
            $stmt->close();
            $concepto2 = "Ajuste edición venta #$id_pedido";
            $stmt = $conn->prepare("INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo, id_pedido) VALUES (?, ?, 'INGRESO', ?, ?, 'Efectivo', ?)");
            $stmt->bind_param("iisii", $id_caja, $uid, $concepto2, $nuevo_total, $id_pedido);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("UPDATE pos_caja SET monto_actual=monto_actual+? WHERE id_caja=?");
            $stmt->bind_param("ii", $nuevo_total, $id_caja);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM metodo_de_pago WHERE id_pedido=?");
        $stmt->bind_param("i", $id_pedido);
        $stmt->execute();
        $stmt->close();
        foreach ($pagos as $p) {
            $met = $conn->real_escape_string($p['metodo'] ?? 'Efectivo'); $mon = (int)($p['monto'] ?? 0);
            $stmt = $conn->prepare("INSERT INTO metodo_de_pago (id_pedido, nombre_metodo_pago, monto) VALUES (?,?,?)");
            $stmt->bind_param("isi", $id_pedido, $met, $mon);
            $stmt->execute(); $stmt->close();
        }
        $motivo = $input['motivo'] ?? '';
        auditar($conn, $uid, 'EDITAR', 'pedido', $id_pedido, ['motivo'=>$motivo, 'precio_anterior'=>(int)$pedido['precio_total'],'precio_nuevo'=>$nuevo_total]);
        $conn->commit();
        json(['success'=>true,'id_pedido'=>$id_pedido,'nuevo_total'=>$nuevo_total]);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function eliminar($conn, $uid, $input) {
    $id_pedido = (int)($input['id_pedido'] ?? 0);
    if (!$id_pedido) json(['error'=>'ID de pedido requerido'], 400);
    $stmt = $conn->prepare("SELECT p.*, s.id_user as sesion_user FROM pedido p JOIN sesion s ON p.id_sesion=s.id_sesion WHERE p.id_pedido=?");
    $stmt->bind_param("i", $id_pedido);
    $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$pedido) json(['error'=>'Venta no encontrada'], 404);
    if ((int)$pedido['sesion_user'] !== $uid) json(['error'=>'No autorizado'], 403);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id_bodega FROM pedido WHERE id_pedido=?");
        $stmt->bind_param("i", $id_pedido);
        $stmt->execute();
        $p_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $id_bodega = $p_row && $p_row['id_bodega'] ? (int)$p_row['id_bodega'] : getDefaultBodega($conn);
        $stmt = $conn->prepare("SELECT id_producto, cantidad_pedida FROM detalle_pedido WHERE id_pedido=?");
        $stmt->bind_param("i", $id_pedido);
        $stmt->execute();
        $detalles = $stmt->get_result();
        $stmt->close();
        while ($d = $detalles->fetch_assoc()) {
            $idp = (int)$d['id_producto']; $cant = (int)$d['cantidad_pedida'];
            reponerStock($conn, $idp, $id_bodega, $cant);
            actualizarKardex($conn, $uid, $idp, $id_bodega, 'ANULACION', $id_pedido, 'PEDIDO', $cant, 0, 0, "Eliminación venta #$id_pedido");
        }
        $monto = (int)($pedido['precio_total'] ?? 0);
        if ($pedido['id_caja']) {
            $id_caja = (int)$pedido['id_caja'];
            $concepto = "Eliminación venta #$id_pedido";
            $stmt = $conn->prepare("INSERT INTO pos_movimiento_caja (id_caja, id_user, tipo, concepto, monto, metodo) VALUES (?, ?, 'EGRESO', ?, ?, 'Efectivo')");
            $stmt->bind_param("iisi", $id_caja, $uid, $concepto, $monto);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("UPDATE pos_caja SET monto_actual=monto_actual-? WHERE id_caja=?");
            $stmt->bind_param("ii", $monto, $id_caja);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM metodo_de_pago WHERE id_pedido=?");
        $stmt->bind_param("i", $id_pedido);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("DELETE FROM detalle_pedido WHERE id_pedido=?");
        $stmt->bind_param("i", $id_pedido);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("DELETE FROM pos_descuento WHERE id_pedido=?");
        $stmt->bind_param("i", $id_pedido);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("DELETE FROM pedido WHERE id_pedido=?");
        $stmt->bind_param("i", $id_pedido);
        $stmt->execute();
        $stmt->close();
        $motivo = $input['motivo'] ?? '';
        auditar($conn, $uid, 'ELIMINAR', 'pedido', $id_pedido, ['motivo'=>$motivo, 'monto'=>$monto]);
        $conn->commit();
        json(['success'=>true]);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }
}
