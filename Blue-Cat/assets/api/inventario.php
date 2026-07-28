<?php
require_once __DIR__.'/_db.php';
require_once __DIR__.'/_supervisor.php';
require_once __DIR__.'/_xlsx.php';

$uid = requireUser();
requireModuleEntitlement('inventario');
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
    try {
        $det = $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null;$ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO inventario_auditoria (id_user,accion,entidad,id_entidad,detalle,ip) VALUES (?,?,?,?,?,?)");
        if(!$stmt)return;$stmt->bind_param("ississ", $uid, $accion, $entidad, $id_entidad, $det, $ip);$stmt->execute();$stmt->close();
    } catch(Throwable $error) { error_log('inventario_auditoria: '.$error->getMessage()); }
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
    requirePermission($modulo, $accion);
}

function invPuedeVerCostos(): bool {
    static $permitido = null;
    if ($permitido === null) {
        $permitido = verificarPermiso('inventario', 'ver_costos');
    }
    return $permitido;
}

/**
 * El permiso inventario.ver no implica acceso a costos. Este filtro se aplica
 * también a objetos anidados y al JSON de auditoría para evitar fugas laterales.
 */
function invOcultarCostos(array $valor): array {
    if (invPuedeVerCostos()) return $valor;
    $camposCosto = [
        'precio_costo', 'costo_promedio', 'ultimo_costo', 'costo',
        'costo_unitario', 'costo_total', 'valor_costo', 'costo_neto',
        'costo_bruto', 'monto_impuesto', 'precio_costo_anterior',
        'precio_costo_nuevo',
    ];
    foreach ($valor as $clave => $contenido) {
        if (in_array((string)$clave, $camposCosto, true)) {
            unset($valor[$clave]);
            continue;
        }
        if ($clave === 'detalle' && is_string($contenido) && $contenido !== '') {
            $detalle = json_decode($contenido, true);
            if (is_array($detalle)) {
                $valor[$clave] = json_encode(
                    invOcultarCostos($detalle),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
            continue;
        }
        if (is_array($contenido)) $valor[$clave] = invOcultarCostos($contenido);
    }
    return $valor;
}

function invIdentificador(?string $valor, int $maximo, string $campo): ?string {
    $normalizado = trim((string)$valor);
    if ($normalizado === '') return null;
    if (mb_strlen($normalizado) > $maximo) {
        throw new InvalidArgumentException("{$campo} supera {$maximo} caracteres");
    }
    return $normalizado;
}

function invErrorIdentificadorDuplicado(mysqli_sql_exception $error): ?DomainException {
    if ((int)$error->getCode() !== 1062) return null;
    $mensaje = strtolower($error->getMessage());
    if (str_contains($mensaje, 'producto_identificador')) {
        return new DomainException('El código de barras o SKU ya pertenece a otro producto de esta cuenta');
    }
    if (str_contains($mensaje, 'uq_producto_cuenta_barcode')) {
        return new DomainException('El código de barras ya pertenece a otro producto de esta cuenta');
    }
    if (str_contains($mensaje, 'uq_producto_cuenta_sku')) {
        return new DomainException('El SKU ya pertenece a otro producto de esta cuenta');
    }
    return null;
}

function invValidarIdentificadores(
    mysqli $conn,
    int $accountId,
    ?string $codigo,
    ?string $sku,
    int $excluirProducto = 0
): void {
    if ($codigo !== null && $sku !== null && strcasecmp($codigo, $sku) === 0) {
        throw new DomainException('El código de barras y el SKU deben ser identificadores distintos');
    }
    $identificadores = array_values(array_filter([$codigo, $sku], static fn($valor): bool => $valor !== null));
    if (!$identificadores) return;
    $primero = $identificadores[0];
    $segundo = $identificadores[1] ?? $primero;
    $stmt = $conn->prepare(
        'SELECT id_producto,nombre_producto,codigo_de_barras,sku
         FROM producto
         WHERE id_cuenta=? AND id_producto<>?
           AND (codigo_de_barras IN (?,?) OR sku IN (?,?))
         ORDER BY id_producto LIMIT 1 FOR UPDATE'
    );
    $stmt->bind_param('iissss', $accountId, $excluirProducto, $primero, $segundo, $primero, $segundo);
    $stmt->execute();
    $duplicado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($duplicado) {
        throw new DomainException(
            'El código de barras o SKU ya pertenece al producto ' . (string)$duplicado['nombre_producto']
        );
    }
}

function invValidarTipoUnidad(mysqli $conn, string $tipoVenta, ?int $idUnidad): string {
    $tipoVenta = strtoupper(trim($tipoVenta));
    if (!in_array($tipoVenta, ['UNIDAD', 'PESO', 'VOLUMEN'], true)) {
        throw new InvalidArgumentException('Tipo de venta inválido');
    }
    if ($idUnidad === null) return $tipoVenta;
    $stmt = $conn->prepare('SELECT tipo FROM unidad_medida WHERE id_unidad=? LIMIT 1');
    $stmt->bind_param('i', $idUnidad);
    $stmt->execute();
    $unidad = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$unidad) throw new InvalidArgumentException('Unidad de medida inexistente');
    if (strtoupper((string)$unidad['tipo']) !== $tipoVenta) {
        throw new InvalidArgumentException('La unidad de medida no corresponde al tipo de venta');
    }
    return $tipoVenta;
}

function invCantidad($value, bool $permitirCero = false): float {
    if (!is_numeric($value)) throw new InvalidArgumentException('Cantidad inválida');
    $original = (float)$value;
    $cantidad = round($original, 3);
    if (!is_finite($original) || abs($original - $cantidad) > 0.000001) {
        throw new InvalidArgumentException('La cantidad admite como máximo 3 decimales');
    }
    if (!is_finite($cantidad) || $cantidad < 0 || (!$permitirCero && $cantidad <= 0)) {
        throw new InvalidArgumentException($permitirCero ? 'La cantidad no puede ser negativa' : 'La cantidad debe ser mayor a cero');
    }
    return $cantidad;
}

function invCantidadProducto(array $producto, $value, bool $permitirCero = false): float {
    $cantidad = invCantidad($value, $permitirCero);
    if (strtoupper((string)($producto['tipo_venta'] ?? 'UNIDAD')) === 'UNIDAD'
        && abs($cantidad - round($cantidad)) > 0.000001) {
        throw new InvalidArgumentException('Los productos por unidad requieren cantidades enteras');
    }
    return $cantidad;
}

function invMonto($value, string $campo, bool $permitirCero = true): float {
    if (!is_numeric($value)) throw new InvalidArgumentException($campo . ' inválido');
    $original = (float)$value;
    $monto = round($original, 2);
    if (!is_finite($original) || abs($original - $monto) > 0.000001) {
        throw new InvalidArgumentException($campo . ' admite como máximo 2 decimales');
    }
    if ($monto < 0 || (!$permitirCero && $monto <= 0) || $monto > 99999999.99) {
        throw new InvalidArgumentException($permitirCero
            ? $campo . ' debe estar entre 0 y 99.999.999,99'
            : $campo . ' debe ser mayor a cero y menor a 100.000.000');
    }
    return $monto;
}

function invPorcentajeImpuesto($value): float {
    if (!is_numeric($value)) throw new InvalidArgumentException('Porcentaje de impuesto inválido');
    $original = (float)$value;
    $porcentaje = round($original, 2);
    if (!is_finite($original) || abs($original - $porcentaje) > 0.000001 || $porcentaje < 0 || $porcentaje > 100) {
        throw new InvalidArgumentException('El porcentaje de impuesto debe estar entre 0 y 100, con máximo 2 decimales');
    }
    return $porcentaje;
}

function invBloquearProducto(mysqli $conn, int $idProducto, int $accountId): array {
    $stmt = $conn->prepare('SELECT id_producto,nombre_producto,tipo_venta,precio_costo,precio_venta,costo_promedio,ultimo_costo FROM producto WHERE id_producto=? AND id_cuenta=? AND activo=1 FOR UPDATE');
    $stmt->bind_param('ii', $idProducto, $accountId);
    $stmt->execute();
    $producto = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$producto) throw new InvalidArgumentException('Producto no encontrado en esta cuenta');
    return $producto;
}

function invBloquearProductos(mysqli $conn, array $ids, int $accountId): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    $productos = [];
    foreach ($ids as $idProducto) {
        if ($idProducto <= 0) throw new InvalidArgumentException('Producto inválido en el detalle');
        $productos[$idProducto] = invBloquearProducto($conn, $idProducto, $accountId);
    }
    return $productos;
}

function invBloquearBodega(mysqli $conn, int $idBodega, int $accountId): void {
    $stmt = $conn->prepare("SELECT id_bodega FROM bodega WHERE id_bodega=? AND id_cuenta=? AND estado='ACTIVA' FOR UPDATE");
    $stmt->bind_param('ii', $idBodega, $accountId);
    $stmt->execute();
    $existe = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$existe) throw new InvalidArgumentException('Bodega no encontrada o inactiva');
}

function invBloquearBodegas(mysqli $conn, array $ids, int $accountId): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    foreach ($ids as $idBodega) invBloquearBodega($conn, $idBodega, $accountId);
}

function invLeerTransferencia(mysqli $conn, int $idTransferencia, int $accountId, bool $bloquear = false): ?array {
    $sql = "SELECT t.* FROM transferencia t
        JOIN bodega bo ON bo.id_bodega=t.id_bodega_origen
        JOIN bodega bd ON bd.id_bodega=t.id_bodega_destino
        WHERE t.id_transferencia=? AND t.id_cuenta=? AND bo.id_cuenta=? AND bd.id_cuenta=?";
    if ($bloquear) $sql .= ' FOR UPDATE';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiii', $idTransferencia, $accountId, $accountId, $accountId);
    $stmt->execute();
    $transferencia = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $transferencia;
}

function invBloquearStock(mysqli $conn, int $idProducto, int $idBodega): ?array {
    $stmt = $conn->prepare('SELECT id_stock,disponible,reservado,comprometido,en_transito,bloqueado FROM stock WHERE id_producto=? AND id_bodega=? ORDER BY id_ubicacion IS NULL DESC,id_stock ASC FOR UPDATE');
    $stmt->bind_param('ii', $idProducto, $idBodega);
    $stmt->execute();
    $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$filas) return null;

    $resumen = $filas[0];
    foreach (['disponible','reservado','comprometido','en_transito','bloqueado'] as $campo) {
        $resumen[$campo] = 0.0;
        foreach ($filas as $fila) {
            $resumen[$campo] = round($resumen[$campo] + (float)($fila[$campo] ?? 0), 3);
        }
    }
    $resumen['filas'] = count($filas);
    return $resumen;
}

function invResponderError(mysqli $conn, Throwable $error, string $contexto): void {
    try { $conn->rollback(); } catch (Throwable) {}
    if ($error instanceof OutOfBoundsException) json(['error'=>$error->getMessage()], 404);
    if ($error instanceof InvalidArgumentException) json(['error'=>$error->getMessage()], 400);
    if ($error instanceof DomainException) json(['error'=>$error->getMessage()], 409);
    error_log($contexto . ': ' . $error->getMessage());
    json(['error'=>'Error interno del servidor'], 500);
}

function invDescargarXlsx(array $headers, iterable $rows, string $filename, string $sheetName): void {
    try {
        $workbook = bluecatXlsxBuildWorkbook($headers, $rows, $sheetName);
    } catch (Throwable $error) {
        error_log('inventario_xlsx: ' . $error->getMessage());
        json(['error'=>'No se pudo generar el archivo XLSX'], 500);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($workbook));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $workbook;
    exit;
}

// ========== DISPATCH ==========
$inventoryReadActions = ['dashboard','productos','producto','producto_barcode','categorias','subcategorias','marcas','unidades','bodegas','ubicaciones','stock','movimientos','transferencias','ajustes','inventarios_fisicos','inventario_fisico_detalle','kardex','lotes','series','alertas','auditoria','reporte_existencias','reporte_stock_critico','reporte_valorizacion','reporte_rotacion','proveedores_select'];
if (in_array($accion, $inventoryReadActions, true)) requierePermiso('inventario','ver');

switch ($accion) {

case 'exportar_productos':
    requierePermiso('inventario','exportar');
    requierePermiso('inventario','ver_costos');
    $warehouseId = (int)($_GET['id_bodega'] ?? 0);
    if ($warehouseId <= 0) json(['error'=>'Debe seleccionar una bodega activa para exportar'], 400);
    $stmt = $conn->prepare("SELECT nombre FROM bodega WHERE id_bodega=? AND id_cuenta=? AND estado='ACTIVA' LIMIT 1");
    $stmt->bind_param('ii', $warehouseId, $accountId);$stmt->execute();$warehouse = $stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$warehouse) json(['error'=>'La bodega no existe, está inactiva o no pertenece a su cuenta'], 400);

    $stmt = $conn->prepare("SELECT p.nombre_producto,p.precio_venta,p.codigo_de_barras,
        COALESCE((SELECT SUM(s.disponible) FROM stock s WHERE s.id_producto=p.id_producto AND s.id_bodega=?),0) AS cantidad,
        COALESCE(c.nombre,p.categoria,'') AS categoria,p.sku,p.precio_costo,p.tipo_venta,COALESCE(u.abreviatura,'') AS unidad,p.activo
        FROM producto p
        LEFT JOIN categoria c ON c.id_categoria=p.id_categoria AND c.id_cuenta=p.id_cuenta
        LEFT JOIN unidad_medida u ON u.id_unidad=p.id_unidad
        WHERE p.id_cuenta=? ORDER BY p.nombre_producto,p.id_producto LIMIT 5001");
    $stmt->bind_param('ii', $warehouseId, $accountId);$stmt->execute();$catalog = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    if (count($catalog)>5000) json(['error'=>'La exportación XLSX admite hasta 5.000 productos; filtre o divida el catálogo'],422);
    $rows = array_map(static fn(array $row): array => [
        (string)($row['nombre_producto'] ?? ''),
        (float)($row['precio_venta'] ?? 0),
        (string)($row['codigo_de_barras'] ?? ''),
        (float)($row['cantidad'] ?? 0),
        (string)($row['categoria'] ?? ''),
        (string)($row['sku'] ?? ''),
        (float)($row['precio_costo'] ?? 0),
        (string)($row['tipo_venta'] ?? 'UNIDAD'),
        (string)($row['unidad'] ?? ''),
        (int)($row['activo'] ?? 0),
    ], $catalog);
    invLog($conn,$uid,'EXPORTAR','producto',null,['formato'=>'XLSX','id_bodega'=>$warehouseId,'bodega'=>(string)$warehouse['nombre'],'filas'=>count($rows)]);
    invDescargarXlsx(
        ['Nombre','Precio','Codigo','Cantidad','Categoria','SKU','Costo','Tipo venta','Unidad','Activo'],
        $rows,
        'productos_' . date('Y-m-d') . '.xlsx',
        'Productos'
    );

case 'plantilla_productos':
    requierePermiso('inventario','importar');
    requierePermiso('inventario','ver_costos');
    $exampleRows = [
        ['Arroz ejemplo 1 kg',1990,'EJ-UNIDAD-001',0,'Abarrotes','SKU-EJ-001',1200,'UNIDAD','',0],
        ['Manzana a granel',2490,'EJ-PESO-001',0,'Frutas','SKU-EJ-002',1500,'PESO','',0],
        ['Aceite ejemplo 1 L',3990,'EJ-VOLUMEN-001',0,'Abarrotes','SKU-EJ-003',2500,'VOLUMEN','',0],
        ['Bebida ejemplo',1490,'EJ-UNIDAD-002',0,'Bebidas','SKU-EJ-004',800,'UNIDAD','',0],
        ['Producto inactivo ejemplo',990,'EJ-UNIDAD-003',0,'Ejemplos','SKU-EJ-005',500,'UNIDAD','',0],
    ];
    invLog($conn,$uid,'DESCARGAR_PLANTILLA','producto',null,['formato'=>'XLSX','filas'=>count($exampleRows),'cantidad_ejemplo'=>0]);
    invDescargarXlsx(
        ['Nombre','Precio','Codigo','Cantidad','Categoria','SKU','Costo','Tipo venta','Unidad','Activo'],
        $exampleRows,
        'plantilla_productos.xlsx',
        'Productos'
    );

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
    $r = $conn->query("SELECT COALESCE(SUM(k.entrada),0) AS e FROM kardex k JOIN producto p ON p.id_producto=k.id_producto WHERE p.id_cuenta=$accountId AND DATE(k.fecha)=CURDATE()"); $data['entradas_hoy'] = (float)$r->fetch_assoc()['e'];
    $r = $conn->query("SELECT COALESCE(SUM(k.salida),0) AS s FROM kardex k JOIN producto p ON p.id_producto=k.id_producto WHERE p.id_cuenta=$accountId AND DATE(k.fecha)=CURDATE()"); $data['salidas_hoy'] = (float)$r->fetch_assoc()['s'];
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
    while ($f = $r->fetch_assoc()) $chart_stock[] = ['label'=>$f['nombre'], 'value'=>(float)$f['t']];
    $data['chart_stock_bodega'] = $chart_stock;
    $r = $conn->query("SELECT DATE(k.fecha) AS d, SUM(k.entrada) AS e, SUM(k.salida) AS s FROM kardex k JOIN producto p ON p.id_producto=k.id_producto WHERE p.id_cuenta=$accountId AND k.fecha>=DATE_SUB(CURDATE(),INTERVAL 14 DAY) GROUP BY DATE(k.fecha) ORDER BY d");
    $chart_ent_sal = []; while ($f = $r->fetch_assoc()) $chart_ent_sal[] = ['fecha'=>$f['d'], 'entradas'=>(float)$f['e'], 'salidas'=>(float)$f['s']];
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
    $limit = min(500, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
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
        $f['cantidad'] = (float)($f['cantidad'] ?? 0);
        $f['precio_venta'] = (float)($f['precio_venta'] ?? 0);
        if (invPuedeVerCostos()) $f['precio_costo'] = (float)($f['precio_costo'] ?? 0);
        $items[] = invOcultarCostos($f);
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
    $stmt = $conn->prepare("SELECT l.*, COALESCE(pr.nombre_comercial,pr.razon_social) AS proveedor FROM lote l LEFT JOIN proveedor pr ON l.id_proveedor=pr.id_proveedor WHERE l.id_producto=? ORDER BY l.fecha_vencimiento ASC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $l = $stmt->get_result();
    while ($f = $l->fetch_assoc()) $lotes[] = $f;
    $stmt->close();
    $prod['lotes'] = $lotes;
    $stmt = $conn->prepare('SELECT iva_porcentaje FROM config_boleta WHERE id_cuenta=? AND activo=1 ORDER BY id_config DESC LIMIT 1');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $configFiscal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $prod['iva_porcentaje'] = max(0.0, min(100.0, (float)($configFiscal['iva_porcentaje'] ?? 19.0)));
    json(invOcultarCostos($prod));

case 'producto_barcode':
    $barcode = $input['barcode'] ?? $_GET['barcode'] ?? '';
    if (!$barcode) json(['error'=>'Código requerido'],400);
    $id_bodega = (int)($input['id_bodega'] ?? $_GET['id_bodega'] ?? 0);
    if (!$id_bodega) json(['error'=>'Debe seleccionar una bodega activa'],400);
    $stmt = $conn->prepare("SELECT p.*, c.nombre AS categoria_nombre, m.nombre AS marca_nombre,
        COALESCE((SELECT SUM(s.disponible) FROM stock s WHERE s.id_producto=p.id_producto AND s.id_bodega=b.id_bodega),0) AS stock_disponible,
        b.nombre AS bodega_nombre, b.id_bodega
        FROM producto p
        JOIN bodega b ON b.id_bodega=? AND b.id_cuenta=p.id_cuenta AND b.estado='ACTIVA'
        LEFT JOIN categoria c ON p.id_categoria=c.id_categoria
        LEFT JOIN marca m ON p.id_marca=m.id_marca
        WHERE p.codigo_de_barras=? AND p.id_cuenta=? AND p.activo=1 LIMIT 1");
    $stmt->bind_param("isi", $id_bodega, $barcode, $accountId);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) json(['error'=>'Producto no encontrado'],404);
    $p = $r->fetch_assoc();
    $p['precio_venta'] = (float)($p['precio_venta'] ?? 0);
    if (invPuedeVerCostos()) $p['precio_costo'] = (float)($p['precio_costo'] ?? 0);
    $p['stock_disponible'] = (float)($p['stock_disponible'] ?? 0);
    json(invOcultarCostos($p));

case 'producto_crear':
    requierePermiso('inventario','crear');
    $nombre = $input['nombre_producto'] ?? '';
    if (!$nombre) json(['error'=>'Nombre requerido'],400);
    $incluyeCosto = is_array($input)
        ? array_key_exists('precio_costo', $input)
        : ($input instanceof ArrayAccess && $input->offsetExists('precio_costo'));
    if ($incluyeCosto) requierePermiso('inventario','ver_costos');
    try {
        $codigo = invIdentificador($input['codigo_de_barras'] ?? null, 30, 'Código de barras');
        $sku = invIdentificador($input['sku'] ?? null, 50, 'SKU');
        $precio = invMonto($input['precio_venta'] ?? 0, 'Precio de venta');
        $precio_costo = invMonto($input['precio_costo'] ?? 0, 'Precio de costo');
        $cantidad = invCantidad($input['cantidad'] ?? 0, true);
    }
    catch (Throwable $error) { json(['error'=>$error->getMessage()], 400); }
    $id_bodega = (int)($input['id_bodega'] ?? 0);
    if ($id_bodega < 0) json(['error'=>'Bodega inválida'],400);
    if ($cantidad > 0 && $id_bodega <= 0) json(['error'=>'Debe seleccionar la bodega activa del stock inicial'],400);
    $categoria_id = (int)($input['id_categoria'] ?? 0); if ($categoria_id <= 0) $categoria_id = null;
    $marca_id = (int)($input['id_marca'] ?? 0); if ($marca_id <= 0) $marca_id = null;
    $proveedor_id = (int)($input['id_proveedor'] ?? 0); if ($proveedor_id <= 0) $proveedor_id = null;
    $tipo = $input['tipo'] ?? 'PRODUCTO';
    $descripcion = $input['descripcion'] ?? '';
    $id_unidad = (int)($input['id_unidad'] ?? 0); if ($id_unidad <= 0) $id_unidad = null;
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

    $tenant = tenantContext($uid);
    if ($categoria_id !== null) requireTenantEntity($conn, $tenant, 'categoria', $categoria_id);
    if ($marca_id !== null) requireTenantEntity($conn, $tenant, 'marca', $marca_id);
    if ($proveedor_id !== null) requireTenantEntity($conn, $tenant, 'proveedor', $proveedor_id);
    
    try {
        $tipo_venta = invValidarTipoUnidad($conn, (string)$tipo_venta, $id_unidad);
    } catch (Throwable $error) {
        json(['error'=>$error->getMessage()], 400);
    }
    if ($tipo_venta === 'UNIDAD' && abs($cantidad - round($cantidad)) > 0.000001) {
        json(['error'=>'Un producto por unidad requiere una cantidad entera'], 400);
    }

    try {
        $conn->begin_transaction();
        if ($id_bodega > 0) invBloquearBodega($conn, $id_bodega, $accountId);
        invValidarIdentificadores($conn, $accountId, $codigo, $sku);
        $stmt = $conn->prepare("INSERT INTO producto (id_user,nombre_producto,codigo_de_barras,precio_venta,cantidad,id_categoria,id_marca,id_proveedor,tipo,precio_costo,descripcion,sku,id_unidad,tipo_venta,precio_por_unidad,stock_minimo,stock_maximo,punto_reposicion,stock_seguridad,control_lote,control_serie,peso,volumen,costo_promedio,ultimo_costo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("issddiiisdssissddddiidddd", $uid, $nombre, $codigo, $precio, $cantidad, $categoria_id, $marca_id, $proveedor_id, $tipo, $precio_costo, $descripcion, $sku, $id_unidad, $tipo_venta, $precio_por_unidad, $stock_min, $stock_max, $punto_reposicion, $stock_seguridad, $control_lote, $control_serie, $peso, $volumen, $precio_costo, $precio_costo);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();

        if ($id_bodega > 0) {
            $stmt = $conn->prepare('INSERT INTO stock (id_producto,id_bodega,disponible) VALUES (?,?,?)');
            $stmt->bind_param('iid', $id, $id_bodega, $cantidad);
            $stmt->execute();
            $stmt->close();
            if ($cantidad > 0) actualizarKardex($conn, $uid, $id, $id_bodega, 'INGRESO', $id, 'PRODUCTO', $cantidad, 0, $precio_costo, 'Creación de producto');
        }
        sincronizarCantidadProducto($conn, $id);
        invLog($conn, $uid, 'CREAR', 'producto', $id, [
            'nombre'=>$nombre,
            'tipo_venta'=>$tipo_venta,
            'cantidad_inicial'=>$cantidad,
            'id_bodega_inicial'=>$id_bodega > 0 ? $id_bodega : null,
        ]);
        $conn->commit();
        json(['success'=>true, 'id'=>$id], 201);
    } catch (Throwable $error) {
        if ($error instanceof mysqli_sql_exception) {
            $duplicado = invErrorIdentificadorDuplicado($error);
            if ($duplicado) $error = $duplicado;
        }
        invResponderError($conn, $error, 'producto_crear');
    }

case 'producto_editar':
    requierePermiso('inventario','editar');
    $inputArray = (array)$input;
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    if (isset($input['cantidad'])) json(['error'=>'El stock se modifica únicamente mediante movimientos, ajustes o conteo físico'],409);
    if (isset($input['precio_costo']) || isset($input['precio_venta'])) {
        json(['error'=>'Costo y precio se modifican únicamente mediante la acción Costo y precio, con documento de respaldo'],409);
    }
    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare(
            'SELECT codigo_de_barras,sku,tipo_venta,id_unidad,cantidad
             FROM producto WHERE id_producto=? AND id_cuenta=? FOR UPDATE'
        );
        $stmt->bind_param('ii', $id, $accountId);
        $stmt->execute();
        $actual = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$actual) throw new InvalidArgumentException('Producto no encontrado en esta cuenta');

        $codigoNuevo = array_key_exists('codigo_de_barras', $inputArray)
            ? invIdentificador($input['codigo_de_barras'], 30, 'Código de barras')
            : invIdentificador($actual['codigo_de_barras'], 30, 'Código de barras');
        $skuNuevo = array_key_exists('sku', $inputArray)
            ? invIdentificador($input['sku'], 50, 'SKU')
            : invIdentificador($actual['sku'], 50, 'SKU');
        $tipoVentaNuevo = array_key_exists('tipo_venta', $inputArray)
            ? (string)$input['tipo_venta']
            : (string)$actual['tipo_venta'];
        $idUnidadNuevo = array_key_exists('id_unidad', $inputArray)
            ? ((int)$input['id_unidad'] > 0 ? (int)$input['id_unidad'] : null)
            : ($actual['id_unidad'] !== null ? (int)$actual['id_unidad'] : null);
        if (array_key_exists('tipo_venta', $inputArray) || array_key_exists('id_unidad', $inputArray)) {
            $tipoVentaNuevo = invValidarTipoUnidad($conn, $tipoVentaNuevo, $idUnidadNuevo);
        } else {
            $tipoVentaNuevo = strtoupper($tipoVentaNuevo);
        }
        if ($tipoVentaNuevo === 'UNIDAD' && strtoupper((string)$actual['tipo_venta']) !== 'UNIDAD') {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) total FROM stock
                 WHERE id_producto=? AND (
                   ABS(disponible-ROUND(disponible))>0.000001
                   OR ABS(reservado-ROUND(reservado))>0.000001
                   OR ABS(comprometido-ROUND(comprometido))>0.000001
                   OR ABS(bloqueado-ROUND(bloqueado))>0.000001
                 ) FOR UPDATE'
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $fraccional = (int)$stmt->get_result()->fetch_assoc()['total'] > 0
                || abs((float)$actual['cantidad'] - round((float)$actual['cantidad'])) > 0.000001;
            $stmt->close();
            if ($fraccional) {
                throw new DomainException('No puede convertir a unidad mientras exista stock fraccionado');
            }
        }
        invValidarIdentificadores($conn, $accountId, $codigoNuevo, $skuNuevo, $id);

        $fields = [];
        $params = [];
        $types = '';
        $allowed = ['nombre_producto','codigo_de_barras','sku','descripcion','id_categoria','id_marca','id_proveedor','tipo','id_unidad','tipo_venta','precio_por_unidad','stock_minimo','stock_maximo','punto_reposicion','stock_seguridad','lead_time','control_lote','control_serie','activo','peso','volumen','alto','ancho','largo','imagen'];
        $nullableIds = ['id_categoria','id_marca','id_proveedor','id_unidad'];
        $tenantReferences = ['id_categoria'=>'categoria','id_marca'=>'marca','id_proveedor'=>'proveedor'];
        foreach ($input as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if ($k === 'codigo_de_barras') $v = $codigoNuevo;
            if ($k === 'sku') $v = $skuNuevo;
            if ($k === 'tipo_venta') $v = $tipoVentaNuevo;
            if ($k === 'id_unidad') $v = $idUnidadNuevo;
            if (in_array($k, $nullableIds, true) && $k !== 'id_unidad') {
                $v = (int)$v;
                $v = $v > 0 ? $v : null;
            }
            if ($v !== null && isset($tenantReferences[$k])) {
                requireTenantEntity($conn, tenantContext($uid), $tenantReferences[$k], (int)$v);
            }
            $fields[] = "$k=?";
            $params[] = $v;
            $types .= in_array($k, $nullableIds, true) || is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
        }
        if (!count($fields)) throw new InvalidArgumentException('Sin campos');
        $params[] = $id;
        $params[] = $accountId;
        $types .= 'ii';
        $sql = "UPDATE producto SET " . implode(',', $fields) . " WHERE id_producto=? AND id_cuenta=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        invLog($conn, $uid, 'EDITAR', 'producto', $id, $input);
        $conn->commit();
        json(['success'=>true]);
    } catch (Throwable $error) {
        if ($conn->errno === 0 || $conn->thread_id) {
            try { $conn->rollback(); } catch (Throwable) {}
        }
        if ($error instanceof mysqli_sql_exception) {
            $duplicado = invErrorIdentificadorDuplicado($error);
            if ($duplicado) $error = $duplicado;
        }
        $status = $error instanceof DomainException ? 409 : 400;
        json(['error'=>$error->getMessage()], $status);
    }

case 'producto_actualizar_precios':
    requierePermiso('inventario','precios');
    requierePermiso('inventario','ver_costos');
    $id = (int)($input['id_producto'] ?? 0);
    $baseCosto = strtoupper(trim((string)($input['base_costo'] ?? '')));
    $referencia = trim((string)($input['documento_referencia'] ?? ''));
    $observaciones = trim((string)($input['observaciones'] ?? ''));
    if ($id <= 0) json(['error'=>'Producto requerido'],400);
    if (!in_array($baseCosto, ['NETO','BRUTO'], true)) json(['error'=>'La base del costo debe ser NETO o BRUTO'],400);
    if ($referencia === '' || strlen($referencia) > 120) json(['error'=>'Ingrese una referencia o ticket de hasta 120 caracteres'],400);
    if (strlen($observaciones) > 1000) json(['error'=>'Las observaciones no pueden superar 1000 caracteres'],400);
    try {
        $montoCosto = invMonto($input['monto_costo'] ?? null, 'Monto de costo');
        $precioVenta = invMonto($input['precio_venta'] ?? null, 'Precio de venta');
        $impuestoPorcentaje = invPorcentajeImpuesto($input['impuesto_porcentaje'] ?? null);
    } catch (Throwable $error) {
        json(['error'=>$error->getMessage()],400);
    }

    try {
        $conn->begin_transaction();
        $producto = invBloquearProducto($conn, $id, $accountId);
        $factor = 1 + ($impuestoPorcentaje / 100);
        if ($baseCosto === 'NETO') {
            $costoNeto = $montoCosto;
            $costoBruto = round($costoNeto * $factor, 2);
        } else {
            $costoBruto = $montoCosto;
            $costoNeto = round($costoBruto / $factor, 2);
        }
        $montoImpuesto = round($costoBruto - $costoNeto, 2);
        if ($costoBruto > 99999999.99) throw new InvalidArgumentException('El costo bruto calculado supera el máximo permitido');

        $stmt = $conn->prepare('UPDATE producto SET precio_costo=?,costo_promedio=?,ultimo_costo=?,precio_venta=? WHERE id_producto=? AND id_cuenta=?');
        $stmt->bind_param('ddddii', $costoNeto, $costoNeto, $costoNeto, $precioVenta, $id, $accountId);
        $stmt->execute();
        if ($stmt->affected_rows > 1) throw new DomainException('No se pudo actualizar el producto de forma segura');
        $stmt->close();

        invLog($conn, $uid, 'ACTUALIZAR_COSTO_PRECIO', 'producto', $id, [
            'documento_referencia'=>$referencia,
            'base_costo'=>$baseCosto,
            'impuesto_porcentaje'=>$impuestoPorcentaje,
            'costo_neto'=>$costoNeto,
            'monto_impuesto'=>$montoImpuesto,
            'costo_bruto'=>$costoBruto,
            'precio_venta_anterior'=>(float)$producto['precio_venta'],
            'precio_venta_nuevo'=>$precioVenta,
            'precio_costo_anterior'=>(float)$producto['precio_costo'],
            'observaciones'=>$observaciones,
            'stock_alterado'=>false,
        ]);
        $conn->commit();
        json([
            'success'=>true,
            'id_producto'=>$id,
            'costo_neto'=>$costoNeto,
            'monto_impuesto'=>$montoImpuesto,
            'costo_bruto'=>$costoBruto,
            'precio_venta'=>$precioVenta,
            'stock_alterado'=>false,
        ]);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'producto_actualizar_precios');
    }

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
    $limit = min(500, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
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
    $stmt = $conn->prepare("UPDATE categoria SET nombre=?, descripcion=? WHERE id_categoria=? AND id_cuenta=?");
    $stmt->bind_param("ssii", $nombre, $desc, $id, $accountId);
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
    $sql = "SELECT s.*, c.nombre AS categoria_nombre FROM subcategoria s JOIN categoria c ON s.id_categoria=c.id_categoria WHERE c.id_cuenta=?";
    $subParams = [$accountId];
    $subTypes = "i";
    if ($id_cat) { $sql .= " AND s.id_categoria=?"; $subParams[] = $id_cat; $subTypes .= "i"; }
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
    $limit = min(500, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
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
    $stmt = $conn->prepare("UPDATE marca SET nombre=?, descripcion=? WHERE id_marca=? AND id_cuenta=?");
    $stmt->bind_param("ssii", $nombre, $desc_m, $id, $accountId);
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
    $params[] = $id; $params[] = $accountId; $types .= 'ii';
    $stmt = $conn->prepare("UPDATE bodega SET " . implode(',', $fields) . " WHERE id_bodega=? AND id_cuenta=?");
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
    $sql = "SELECT u.*, b.nombre AS bodega_nombre FROM ubicacion u JOIN bodega b ON u.id_bodega=b.id_bodega WHERE b.id_cuenta=?";
    $ubiParams = [$accountId];
    $ubiTypes = "i";
    if ($id_bodega) { $sql .= " AND u.id_bodega=?"; $ubiParams[] = $id_bodega; $ubiTypes .= "i"; }
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
    requireTenantEntity($conn, tenantContext($uid), 'bodega', $id_bodega);
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
    while ($f = $r->fetch_assoc()) $items[] = invOcultarCostos($f);
    $stmt2->close();
    json($items);

case 'stock_actualizar':
    requierePermiso('inventario','editar');
    json(['error'=>'La edición directa de stock está deshabilitada. Use un movimiento, ajuste o conteo físico para conservar Kardex y auditoría.'],409);

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
    while ($f = $r->fetch_assoc()) $items[] = invOcultarCostos($f);
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'movimiento_crear':
    requierePermiso('inventario','movimientos');
    $tipo = strtoupper(trim((string)($input['tipo'] ?? '')));
    $id_producto = (int)($input['id_producto'] ?? 0);
    $id_bodega = (int)($input['id_bodega'] ?? 0);
    $costoSolicitado = null;
    if (isset($input['costo'])) {
        requierePermiso('inventario','precios');
        requierePermiso('inventario','ver_costos');
        try { $costoSolicitado = invMonto($input['costo'], 'Costo del movimiento'); }
        catch (Throwable $error) { json(['error'=>$error->getMessage()], 400); }
    }
    $obs = $input['observaciones'] ?? '';
    if (!$tipo || !$id_producto || !$id_bodega) json(['error'=>'Datos incompletos'],400);
    try { $cantidad = invCantidad($input['cantidad'] ?? null); }
    catch (Throwable $e) { json(['error'=>$e->getMessage()], 400); }
    $tiposEntrada = ['INGRESO','PRODUCCION','DEVOLUCION'];
    $tiposSalida = ['SALIDA','CONSUMO','MERMA','PERDIDA'];
    if (!in_array($tipo, array_merge($tiposEntrada, $tiposSalida, ['REGULARIZACION']), true)) {
        json(['error'=>'Tipo de movimiento no válido; use el flujo de transferencias o ajustes cuando corresponda'], 400);
    }
    
    $conn->begin_transaction();
    try {
        invBloquearBodega($conn, $id_bodega, $accountId);
        $producto = invBloquearProducto($conn, $id_producto, $accountId);
        $cantidad = invCantidadProducto($producto, $cantidad);
        $costo = $costoSolicitado ?? round((float)$producto['precio_costo'], 2);
        $num = generarCodigo($conn, 'movimiento_inventario', 'numero', 'MOV-');
        $stmt = $conn->prepare("INSERT INTO movimiento_inventario (numero,tipo,id_producto,id_bodega_origen,id_bodega_destino,cantidad,costo,id_user,observaciones) VALUES (?,?,?,?,?,?,?,?,?)");
        $bodega_origen = $id_bodega; $bodega_destino = 0;
        $delta_stock = 0.0; $entrada = 0.0; $salida = 0.0;
        
        if (in_array($tipo, $tiposEntrada, true) || $tipo === 'REGULARIZACION') {
            $bodega_destino = $id_bodega; $delta_stock = $cantidad; $entrada = $cantidad;
        } else {
            $delta_stock = -$cantidad; $salida = $cantidad;
        }
        $stmt->bind_param('ssiiiddis', $num, $tipo, $id_producto, $bodega_origen, $bodega_destino, $cantidad, $costo, $uid, $obs);
        $stmt->execute();
        $id_mov = (int)$conn->insert_id;
        $stmt->close();
        
        if (abs($delta_stock) > 0.000001) {
            try { actualizarStock($conn, $id_producto, $id_bodega, 'disponible', $delta_stock, $delta_stock < 0); }
            catch (RuntimeException $e) { throw new DomainException($e->getMessage(), 0, $e); }
            actualizarKardex($conn, $uid, $id_producto, $id_bodega, $tipo, $id_mov, 'MOVIMIENTO', $entrada, $salida, $costo, $obs);
        }
        invLog($conn, $uid, 'CREAR_MOVIMIENTO', 'movimiento_inventario', $id_mov, ['tipo'=>$tipo, 'cantidad'=>$cantidad]);
        $conn->commit();
        json(['success'=>true, 'id'=>$id_mov, 'numero'=>$num], 201);
    } catch (Throwable $e) {
        invResponderError($conn, $e, 'movimiento_crear');
    }

// ─── TRANSFERENCIAS ───
case 'transferencias':
    $estado = $input['estado'] ?? $_GET['estado'] ?? '';
    $items = [];
    $sql = "SELECT t.*, t.created_at AS fecha_creacion, bo.nombre AS bodega_origen_nombre, bd.nombre AS bodega_destino_nombre, u.nombre AS user_nombre
            FROM transferencia t
            JOIN bodega bo ON t.id_bodega_origen=bo.id_bodega
            JOIN bodega bd ON t.id_bodega_destino=bd.id_bodega
            LEFT JOIN usuario u ON t.id_user=u.id_user
            WHERE bo.id_cuenta=? AND bd.id_cuenta=?";
    $trfParams = [$accountId, $accountId];
    $trfTypes = "ii";
    if ($estado) { $sql .= " AND t.estado=?"; $trfParams[] = $estado; $trfTypes .= "s"; }
    $sql .= " ORDER BY t.created_at DESC";
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
        while ($dd = $d->fetch_assoc()) { $dd['cantidad'] = (float)$dd['cantidad']; $det[] = $dd; }
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
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    $obs = trim((string)($input['observaciones'] ?? ''));
    if (!$id_origen || !$id_destino || !is_iterable($productos) || !count($productos)) json(['error'=>'Datos incompletos'],400);
    if ($id_origen === $id_destino) json(['error'=>'Origen y destino deben ser distintos'],400);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,63}$/', $idempotencyKey)) {
        json(['error'=>'La transferencia requiere una clave de idempotencia válida'],400);
    }

    try {
        $detalleAgrupado = [];
        foreach ($productos as $productoTransferencia) {
            $idProducto = (int)($productoTransferencia['id_producto'] ?? 0);
            if ($idProducto <= 0) throw new InvalidArgumentException('Producto inválido en el detalle');
            $cantidad = invCantidad($productoTransferencia['cantidad'] ?? null);
            $detalleAgrupado[$idProducto] = round(($detalleAgrupado[$idProducto] ?? 0) + $cantidad, 3);
        }
        ksort($detalleAgrupado, SORT_NUMERIC);
        $detalleHash = [];
        foreach ($detalleAgrupado as $idProducto => $cantidad) {
            $detalleHash[] = [(int)$idProducto, number_format((float)$cantidad, 3, '.', '')];
        }
        $requestHash = hash('sha256', (string)json_encode([
            'origen'=>$id_origen,
            'destino'=>$id_destino,
            'productos'=>$detalleHash,
            'observaciones'=>$obs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $conn->begin_transaction();
        $bodegas = [$id_origen, $id_destino];
        sort($bodegas, SORT_NUMERIC);
        foreach ($bodegas as $idBodega) invBloquearBodega($conn, $idBodega, $accountId);

        $stmt = $conn->prepare('SELECT id_transferencia,numero,solicitud_hash FROM transferencia WHERE id_cuenta=? AND clave_idempotencia=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('is', $accountId, $idempotencyKey);
        $stmt->execute();
        $transferenciaExistente = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($transferenciaExistente) {
            if (!hash_equals((string)$transferenciaExistente['solicitud_hash'], $requestHash)) {
                throw new DomainException('La clave de idempotencia ya fue usada con otra transferencia');
            }
            $conn->commit();
            json([
                'success'=>true,
                'id'=>(int)$transferenciaExistente['id_transferencia'],
                'numero'=>$transferenciaExistente['numero'],
                'idempotent_replay'=>true,
            ]);
        }

        $productosBloqueados = invBloquearProductos($conn, array_keys($detalleAgrupado), $accountId);
        foreach ($detalleAgrupado as $idProducto => $cantidad) {
            $detalleAgrupado[$idProducto] = invCantidadProducto($productosBloqueados[(int)$idProducto], $cantidad);
        }

        foreach ($detalleAgrupado as $idProducto => $cantidad) {
            $stock = invBloquearStock($conn, (int)$idProducto, $id_origen);
            if (!$stock) throw new DomainException('No existe stock de origen para uno de los productos');
            $libre = round(
                (float)$stock['disponible']
                - (float)$stock['reservado']
                - (float)$stock['comprometido']
                - (float)$stock['bloqueado'],
                3
            );
            if ($libre + 0.000001 < $cantidad) {
                throw new DomainException('Stock disponible insuficiente para reservar la transferencia');
            }
        }

        $num = generarCodigo($conn, 'transferencia', 'numero', 'TRF-');
        $stmt = $conn->prepare("INSERT INTO transferencia (id_cuenta,numero,clave_idempotencia,solicitud_hash,id_bodega_origen,id_bodega_destino,estado,id_user,observaciones) VALUES (?,?,?,?,?,?,'PENDIENTE',?,?)");
        $stmt->bind_param('isssiiis', $accountId, $num, $idempotencyKey, $requestHash, $id_origen, $id_destino, $uid, $obs);
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $insertError) {
            $stmt->close();
            if ((int)$insertError->getCode() !== 1062) throw $insertError;
            $stmt = $conn->prepare('SELECT id_transferencia,numero,solicitud_hash FROM transferencia WHERE id_cuenta=? AND clave_idempotencia=? LIMIT 1');
            $stmt->bind_param('is', $accountId, $idempotencyKey);
            $stmt->execute();
            $transferenciaExistente = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$transferenciaExistente || !hash_equals((string)$transferenciaExistente['solicitud_hash'], $requestHash)) {
                throw new DomainException('La clave de idempotencia ya fue usada con otra transferencia');
            }
            $conn->commit();
            json([
                'success'=>true,
                'id'=>(int)$transferenciaExistente['id_transferencia'],
                'numero'=>$transferenciaExistente['numero'],
                'idempotent_replay'=>true,
            ]);
        }
        $id_trf = (int)$conn->insert_id;
        $stmt->close();

        $total_items = 0.0;
        foreach ($detalleAgrupado as $idProducto => $cantidad) {
            $idProducto = (int)$idProducto;
            $stmt = $conn->prepare('INSERT INTO transferencia_detalle (id_transferencia,id_producto,cantidad) VALUES (?,?,?)');
            $stmt->bind_param('iid', $id_trf, $idProducto, $cantidad);
            $stmt->execute();
            $stmt->close();

            actualizarStock($conn, $idProducto, $id_origen, 'comprometido', $cantidad);
            $total_items = round($total_items + $cantidad, 3);
        }

        invLog($conn, $uid, 'CREAR_TRANSFERENCIA', 'transferencia', $id_trf, ['origen'=>$id_origen, 'destino'=>$id_destino, 'items'=>$total_items]);
        $conn->commit();
        json(['success'=>true, 'id'=>$id_trf, 'numero'=>$num, 'idempotent_replay'=>false], 201);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'transferencia_crear');
    }

case 'transferencia_recibir':
    requierePermiso('inventario','transferencias');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    try {
        $conn->begin_transaction();
        $scopeTransferencia = invLeerTransferencia($conn, $id, $accountId);
        if (!$scopeTransferencia) throw new OutOfBoundsException('Transferencia no encontrada');
        invBloquearBodegas($conn, [(int)$scopeTransferencia['id_bodega_origen'], (int)$scopeTransferencia['id_bodega_destino']], $accountId);
        $trf = invLeerTransferencia($conn, $id, $accountId, true);
        if (!$trf) throw new OutOfBoundsException('Transferencia no encontrada');
        if ((int)$trf['id_bodega_origen'] !== (int)$scopeTransferencia['id_bodega_origen']
            || (int)$trf['id_bodega_destino'] !== (int)$scopeTransferencia['id_bodega_destino']) {
            throw new DomainException('El alcance de bodegas de la transferencia cambió; reintente la operación');
        }
        if ($trf['estado'] !== 'EN_TRANSITO') throw new DomainException('Solo se pueden recibir transferencias EN_TRANSITO');

        $stmt = $conn->prepare('SELECT id_producto,cantidad FROM transferencia_detalle WHERE id_transferencia=? ORDER BY id_producto FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$detalles) throw new DomainException('La transferencia no tiene productos');
        invBloquearProductos($conn, array_column($detalles, 'id_producto'), $accountId);

        foreach ($detalles as $detalle) {
            $idProducto = (int)$detalle['id_producto'];
            $cantidad = invCantidad($detalle['cantidad']);
            $stockOrigen = invBloquearStock($conn, $idProducto, (int)$trf['id_bodega_origen']);
            if (!$stockOrigen || (float)$stockOrigen['en_transito'] + 0.000001 < $cantidad) {
                throw new DomainException('El stock en tránsito no coincide con la transferencia');
            }

            actualizarStock($conn, $idProducto, (int)$trf['id_bodega_origen'], 'en_transito', -$cantidad);
            actualizarStock($conn, $idProducto, (int)$trf['id_bodega_destino'], 'disponible', $cantidad);
            actualizarKardex($conn, $uid, $idProducto, (int)$trf['id_bodega_destino'], 'TRANSFERENCIA', $id, 'TRANSFERENCIA', $cantidad, 0, 0, 'Recibida transferencia '.$trf['numero']);
        }

        $stmt = $conn->prepare("UPDATE transferencia SET estado='RECIBIDA', fecha_recepcion=NOW(), id_user_recibe=? WHERE id_transferencia=? AND estado='EN_TRANSITO'");
        $stmt->bind_param('ii', $uid, $id);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new DomainException('La transferencia cambió de estado durante la recepción');
        $stmt->close();
        invLog($conn, $uid, 'RECIBIR_TRANSFERENCIA', 'transferencia', $id);
        $conn->commit();
        json(['success'=>true]);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'transferencia_recibir');
    }

case 'transferencia_enviar':
    requierePermiso('inventario','transferencias');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    try {
        $conn->begin_transaction();
        $scopeTransferencia = invLeerTransferencia($conn, $id, $accountId);
        if (!$scopeTransferencia) throw new OutOfBoundsException('Transferencia no encontrada');
        invBloquearBodegas($conn, [(int)$scopeTransferencia['id_bodega_origen'], (int)$scopeTransferencia['id_bodega_destino']], $accountId);
        $trf = invLeerTransferencia($conn, $id, $accountId, true);
        if (!$trf) throw new OutOfBoundsException('Transferencia no encontrada');
        if ((int)$trf['id_bodega_origen'] !== (int)$scopeTransferencia['id_bodega_origen']
            || (int)$trf['id_bodega_destino'] !== (int)$scopeTransferencia['id_bodega_destino']) {
            throw new DomainException('El alcance de bodegas de la transferencia cambió; reintente la operación');
        }
        if ($trf['estado'] !== 'PENDIENTE') throw new DomainException('La transferencia debe estar PENDIENTE');

        supervisorRequire('inventario.transferencia_enviar',['entidad_tipo'=>'transferencia','entidad_id'=>(string)$id],$input['supervisor_token']??null);
        $stmt = $conn->prepare('SELECT id_producto,cantidad FROM transferencia_detalle WHERE id_transferencia=? ORDER BY id_producto FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$detalles) throw new DomainException('La transferencia no tiene productos');
        invBloquearProductos($conn, array_column($detalles, 'id_producto'), $accountId);

        $stmtAsignacion = $conn->prepare('INSERT INTO transferencia_asignacion (id_transferencia,id_producto,id_stock,cantidad) VALUES (?,?,?,?)');
        foreach ($detalles as $detalle) {
            $idProducto = (int)$detalle['id_producto'];
            $cantidad = invCantidad($detalle['cantidad']);
            $stockOrigen = invBloquearStock($conn, $idProducto, (int)$trf['id_bodega_origen']);
            if (!$stockOrigen
                || (float)$stockOrigen['comprometido'] + 0.000001 < $cantidad
                || (float)$stockOrigen['disponible'] + 0.000001 < $cantidad) {
                throw new DomainException('El stock comprometido no permite enviar la transferencia');
            }
        // Libera exactamente el compromiso de esta salida y descuenta esas
        // mismas ubicaciones. Así no consume reservas ajenas ubicadas en otro bin.
            $asignaciones = [];
            actualizarStock($conn, $idProducto, (int)$trf['id_bodega_origen'], 'comprometido', -$cantidad, false, $asignaciones);
            foreach ($asignaciones as $asignacion) {
                $idStockAsignado = (int)$asignacion['id_stock'];
                $cantidadAsignada = (float)$asignacion['cantidad'];
                $stmtStock = $conn->prepare(
                    'UPDATE stock SET disponible=disponible-?
                     WHERE id_stock=? AND disponible+0.000001>=?
                       AND (disponible-?)+0.000001>=reservado+comprometido+bloqueado'
                );
                $stmtStock->bind_param('didd', $cantidadAsignada, $idStockAsignado, $cantidadAsignada, $cantidadAsignada);
                $stmtStock->execute();
                $descontado = $stmtStock->affected_rows === 1;
                $stmtStock->close();
                if (!$descontado) throw new DomainException('El stock cambió durante el envío de la transferencia');
                $stmtAsignacion->bind_param('iiid', $id, $idProducto, $idStockAsignado, $cantidadAsignada);
                $stmtAsignacion->execute();
            }
            sincronizarCantidadProducto($conn, $idProducto);
            actualizarStock($conn, $idProducto, (int)$trf['id_bodega_origen'], 'en_transito', $cantidad);
            actualizarKardex($conn, $uid, $idProducto, (int)$trf['id_bodega_origen'], 'TRANSFERENCIA', $id, 'TRANSFERENCIA', 0, $cantidad, 0, 'Enviada transferencia '.$trf['numero']);
        }
        $stmtAsignacion->close();

        $stmt = $conn->prepare("UPDATE transferencia SET estado='EN_TRANSITO' WHERE id_transferencia=? AND estado='PENDIENTE'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $transicionAplicada = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$transicionAplicada) throw new DomainException('La transferencia cambió de estado durante el envío');
        invLog($conn, $uid, 'ENVIAR_TRANSFERENCIA', 'transferencia', $id);
        $conn->commit();
        json(['success'=>true]);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'transferencia_enviar');
    }

case 'transferencia_cancelar':
    requierePermiso('inventario','transferencias');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    try {
        $conn->begin_transaction();
        $scopeTransferencia = invLeerTransferencia($conn, $id, $accountId);
        if (!$scopeTransferencia) throw new OutOfBoundsException('Transferencia no encontrada');
        invBloquearBodegas($conn, [(int)$scopeTransferencia['id_bodega_origen'], (int)$scopeTransferencia['id_bodega_destino']], $accountId);
        $trf = invLeerTransferencia($conn, $id, $accountId, true);
        if (!$trf) throw new OutOfBoundsException('Transferencia no encontrada');
        if ((int)$trf['id_bodega_origen'] !== (int)$scopeTransferencia['id_bodega_origen']
            || (int)$trf['id_bodega_destino'] !== (int)$scopeTransferencia['id_bodega_destino']) {
            throw new DomainException('El alcance de bodegas de la transferencia cambió; reintente la operación');
        }
        if (!in_array($trf['estado'], ['PENDIENTE','EN_TRANSITO'], true)) throw new DomainException('No se puede cancelar la transferencia en su estado actual');

        $stmt = $conn->prepare('SELECT id_producto,cantidad FROM transferencia_detalle WHERE id_transferencia=? ORDER BY id_producto FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$detalles) throw new DomainException('La transferencia no tiene productos');
        invBloquearProductos($conn, array_column($detalles, 'id_producto'), $accountId);

        foreach ($detalles as $detalle) {
            $idProducto = (int)$detalle['id_producto'];
            $cantidad = invCantidad($detalle['cantidad']);
            $stockOrigen = invBloquearStock($conn, $idProducto, (int)$trf['id_bodega_origen']);
            if (!$stockOrigen) throw new DomainException('No existe stock de origen para revertir la transferencia');
            if ($trf['estado'] === 'PENDIENTE') {
                if ((float)$stockOrigen['comprometido'] + 0.000001 < $cantidad) {
                    throw new DomainException('El stock comprometido no coincide con la transferencia');
                }
                actualizarStock($conn, $idProducto, (int)$trf['id_bodega_origen'], 'comprometido', -$cantidad);
                continue;
            }

            if ((float)$stockOrigen['en_transito'] + 0.000001 < $cantidad) {
                throw new DomainException('El stock en transito no coincide con la transferencia');
            }
            actualizarStock($conn, $idProducto, (int)$trf['id_bodega_origen'], 'en_transito', -$cantidad);
            $stmt = $conn->prepare('SELECT a.id_stock,a.cantidad,s.id_producto AS stock_producto,s.id_bodega AS stock_bodega FROM transferencia_asignacion a LEFT JOIN stock s ON s.id_stock=a.id_stock WHERE a.id_transferencia=? AND a.id_producto=? ORDER BY a.id_asignacion FOR UPDATE');
            $stmt->bind_param('ii', $id, $idProducto);
            $stmt->execute();
            $asignaciones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $restaurado = 0.0;
            foreach ($asignaciones as $asignacion) {
                $cantidadAsignada = invCantidad($asignacion['cantidad']);
                if ((int)($asignacion['id_stock'] ?? 0) <= 0
                    || (int)($asignacion['stock_producto'] ?? 0) !== $idProducto
                    || (int)($asignacion['stock_bodega'] ?? 0) !== (int)$trf['id_bodega_origen']) {
                    continue;
                }
                $idStockAsignado = (int)$asignacion['id_stock'];
                $stmt = $conn->prepare('UPDATE stock SET disponible=disponible+? WHERE id_stock=?');
                $stmt->bind_param('di', $cantidadAsignada, $idStockAsignado);
                $stmt->execute();
                $updated = $stmt->affected_rows;
                $stmt->close();
                if ($updated !== 1) throw new DomainException('No se pudo restaurar una ubicación de la transferencia');
                $restaurado = round($restaurado + $cantidadAsignada, 3);
            }
            if ($restaurado > $cantidad + 0.000001) throw new DomainException('Las asignaciones de la transferencia exceden su cantidad');
            $restante = round($cantidad - $restaurado, 3);
            if ($restante > 0.000001) {
                // Compatibilidad con transferencias EN_TRANSITO creadas antes
                // de que existiera el registro de asignaciones por ubicación.
                actualizarStock($conn, $idProducto, (int)$trf['id_bodega_origen'], 'disponible', $restante);
            } else {
                sincronizarCantidadProducto($conn, $idProducto);
            }
            actualizarKardex($conn, $uid, $idProducto, (int)$trf['id_bodega_origen'], 'TRANSFERENCIA_CANCELADA', $id, 'TRANSFERENCIA', $cantidad, 0, 0, 'Transferencia cancelada '.$trf['numero']);
        }

        $estadoAnterior = $trf['estado'];
        $stmt = $conn->prepare("UPDATE transferencia SET estado='CANCELADA' WHERE id_transferencia=? AND estado=?");
        $stmt->bind_param('is', $id, $estadoAnterior);
        $stmt->execute();
        $transicionAplicada = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$transicionAplicada) throw new DomainException('La transferencia cambio de estado durante la cancelacion');
        invLog($conn, $uid, 'CANCELAR_TRANSFERENCIA', 'transferencia', $id, ['estado_anterior'=>$estadoAnterior]);
        $conn->commit();
        json(['success'=>true]);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'transferencia_cancelar');
    }

// ─── AJUSTES ───
case 'ajustes':
    $items = []; $r = $conn->query("SELECT a.*, p.nombre_producto, b.nombre AS bodega_nombre, u.nombre AS user_nombre FROM ajuste_inventario a JOIN producto p ON a.id_producto=p.id_producto JOIN bodega b ON a.id_bodega=b.id_bodega LEFT JOIN usuario u ON a.id_user=u.id_user WHERE p.id_cuenta=$accountId AND b.id_cuenta=$accountId ORDER BY a.created_at DESC LIMIT 200");
    while ($f = $r->fetch_assoc()) $items[] = invOcultarCostos($f);
    json($items);

case 'ajuste_crear':
    requierePermiso('inventario','ajustes');
    $tipo = strtoupper(trim((string)($input['tipo'] ?? '')));
    $id_producto = (int)($input['id_producto'] ?? 0);
    $id_bodega = (int)($input['id_bodega'] ?? 0);
    $motivo = trim((string)($input['motivo'] ?? ''));
    if (!$tipo || !$id_producto || !$id_bodega || $motivo === '') json(['error'=>'Datos incompletos'],400);
    if (!preg_match('/^[A-Z_]{2,30}$/', $tipo)) json(['error'=>'Tipo de ajuste invalido'],400);
    try { $cantidad_nueva = invCantidad($input['cantidad_nueva'] ?? null, true); }
    catch (Throwable $error) { json(['error'=>$error->getMessage()], 400); }

    try {
        $conn->begin_transaction();
        invBloquearBodega($conn, $id_bodega, $accountId);
        $producto = invBloquearProducto($conn, $id_producto, $accountId);
        $cantidad_nueva = invCantidadProducto($producto, $cantidad_nueva, true);
        $stock = invBloquearStock($conn, $id_producto, $id_bodega);
        $cant_anterior = $stock ? round((float)$stock['disponible'], 3) : 0.0;
        $diferencia = round($cantidad_nueva - $cant_anterior, 3);

        $contextoSupervisor = ['entidad_tipo'=>'producto','entidad_id'=>(string)$id_producto,'id_bodega'=>$id_bodega,'cantidad_nueva'=>$cantidad_nueva];
        $autorizadoPor = supervisorRequire('inventario.ajuste', $contextoSupervisor, $input['supervisor_token'] ?? null);
        if ($autorizadoPor === null) $autorizadoPor = $uid;

        $num = generarCodigo($conn, 'ajuste_inventario', 'numero', 'AJ-');
        $doc = trim((string)($input['documento_respaldo'] ?? ''));
        $obs = trim((string)($input['observaciones'] ?? ''));
        $stmt = $conn->prepare("INSERT INTO ajuste_inventario (numero,tipo,id_producto,id_bodega,cantidad_anterior,cantidad_nueva,diferencia,motivo,id_user,autorizado_por,documento_respaldo,observaciones) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssiidddsiiss', $num, $tipo, $id_producto, $id_bodega, $cant_anterior, $cantidad_nueva, $diferencia, $motivo, $uid, $autorizadoPor, $doc, $obs);
        $stmt->execute();
        $id_aj = (int)$conn->insert_id;
        $stmt->close();

        if (abs($diferencia) > 0.000001) {
            try { actualizarStock($conn, $id_producto, $id_bodega, 'disponible', $diferencia, $diferencia < 0); }
            catch (RuntimeException $error) { throw new DomainException($error->getMessage(), 0, $error); }
            $entrada = $diferencia > 0 ? $diferencia : 0.0;
            $salida = $diferencia < 0 ? -$diferencia : 0.0;
            actualizarKardex($conn, $uid, $id_producto, $id_bodega, 'AJUSTE', $id_aj, 'AJUSTE', $entrada, $salida, 0, $motivo);
        }

        invLog($conn, $uid, 'CREAR_AJUSTE', 'ajuste_inventario', $id_aj, ['tipo'=>$tipo, 'cantidad_anterior'=>$cant_anterior, 'cantidad_nueva'=>$cantidad_nueva, 'diferencia'=>$diferencia, 'autorizado_por'=>$autorizadoPor]);
        $conn->commit();
        json(['success'=>true, 'id'=>$id_aj, 'numero'=>$num], 201);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'ajuste_crear');
    }

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
    $tipo = strtoupper(trim((string)($input['tipo'] ?? 'GENERAL')));
    $id_bodega = (int)($input['id_bodega'] ?? 0);
    $obs = trim((string)($input['observaciones'] ?? ''));
    if (!in_array($tipo, ['GENERAL','BODEGA'], true)) json(['error'=>'Tipo de inventario invalido'],400);
    if ($tipo === 'BODEGA' && !$id_bodega) json(['error'=>'Debe seleccionar una bodega'],400);
    if ($tipo === 'GENERAL') $id_bodega = 0;

    try {
        $conn->begin_transaction();
        if ($id_bodega) {
            invBloquearBodega($conn, $id_bodega, $accountId);
        } else {
            $stmt = $conn->prepare("SELECT id_bodega FROM bodega WHERE id_cuenta=? AND estado='ACTIVA' ORDER BY id_bodega FOR UPDATE");
            $stmt->bind_param('i', $accountId);
            $stmt->execute();
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT f.id_inventario FROM inventario_fisico f JOIN usuario u ON u.id_user=f.id_user WHERE u.id_cuenta=? AND f.estado='EN_PROGRESO' AND (f.tipo='GENERAL' OR ?='GENERAL' OR f.id_bodega=?) LIMIT 1 FOR UPDATE");
        $stmt->bind_param('isi', $accountId, $tipo, $id_bodega);
        $stmt->execute();
        $inventarioSuperpuesto = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($inventarioSuperpuesto) {
            throw new DomainException('Ya existe un inventario físico en progreso que incluye este alcance');
        }

        $codigo = generarCodigo($conn, 'inventario_fisico', 'codigo', 'INV-');
        $idBodegaCabecera = $id_bodega ?: null;
        $stmt = $conn->prepare("INSERT INTO inventario_fisico (codigo,tipo,id_bodega,id_user,observaciones,estado,fecha_inicio) VALUES (?,?,?,?,?,'EN_PROGRESO',NOW())");
        $stmt->bind_param('ssiis', $codigo, $tipo, $idBodegaCabecera, $uid, $obs);
        $stmt->execute();
        $id_inv = (int)$conn->insert_id;
        $stmt->close();
    
    // Crear conteos automáticos para todos los productos de la bodega
        $sqlSnapshot = "SELECT s.id_producto,s.id_bodega,s.id_ubicacion,s.disponible FROM stock s JOIN producto p ON p.id_producto=s.id_producto JOIN bodega b ON b.id_bodega=s.id_bodega WHERE p.id_cuenta=? AND b.id_cuenta=?";
        if ($id_bodega) $sqlSnapshot .= ' AND s.id_bodega=?';
        $sqlSnapshot .= ' ORDER BY s.id_producto,s.id_bodega,s.id_ubicacion FOR UPDATE';
        $stmt = $conn->prepare($sqlSnapshot);
        if ($id_bodega) $stmt->bind_param('iii', $accountId, $accountId, $id_bodega);
        else $stmt->bind_param('ii', $accountId, $accountId);
        $stmt->execute();
        $filasStock = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$filasStock) throw new DomainException('No existe stock para iniciar el inventario físico solicitado');

        $stmtConteo = $conn->prepare('INSERT INTO conteo_inventario (id_inventario,id_producto,id_bodega,id_ubicacion,stock_sistema,conteo1) VALUES (?,?,?,?,?,NULL)');
        $count = 0;
        foreach ($filasStock as $filaStock) {
            $idProducto = (int)$filaStock['id_producto'];
            $idBodegaLinea = (int)$filaStock['id_bodega'];
            $idUbicacion = $filaStock['id_ubicacion'] === null ? null : (int)$filaStock['id_ubicacion'];
            $stockSistema = round((float)$filaStock['disponible'], 3);
            $stmtConteo->bind_param('iiiid', $id_inv, $idProducto, $idBodegaLinea, $idUbicacion, $stockSistema);
            $stmtConteo->execute();
            $count++;
        }
        $stmtConteo->close();
        invLog($conn, $uid, 'CREAR_INVENTARIO_FISICO', 'inventario_fisico', $id_inv, ['productos'=>$count, 'id_bodega'=>$idBodegaCabecera]);
        $conn->commit();
        json(['success'=>true, 'id'=>$id_inv, 'codigo'=>$codigo, 'lineas'=>$count], 201);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'inventario_fisico_crear');
    }

case 'inventario_fisico_conteo':
    requierePermiso('inventario','conteo_fisico');
    $id_conteo = (int)($input['id_conteo'] ?? 0);
    $ronda = $input['ronda'] ?? 'conteo1';
    $allowed = ['conteo1','conteo2','conteo3'];
    if (!in_array($ronda, $allowed)) json(['error'=>'Ronda inválida'],400);
    if (!$id_conteo) json(['error'=>'Conteo requerido'],400);
    try { $valor = invCantidad($input['valor'] ?? null, true); }
    catch (Throwable $error) { json(['error'=>$error->getMessage()], 400); }

    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare('SELECT c.id_conteo,f.estado,p.tipo_venta FROM conteo_inventario c JOIN inventario_fisico f ON f.id_inventario=c.id_inventario JOIN usuario u ON u.id_user=f.id_user JOIN producto p ON p.id_producto=c.id_producto AND p.id_cuenta=u.id_cuenta WHERE c.id_conteo=? AND u.id_cuenta=? FOR UPDATE');
        $stmt->bind_param('ii', $id_conteo, $accountId);
        $stmt->execute();
        $conteo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$conteo) throw new OutOfBoundsException('Conteo no encontrado');
        if ($conteo['estado'] !== 'EN_PROGRESO') throw new DomainException('El inventario fisico ya no admite conteos');
        $valor = invCantidadProducto($conteo, $valor, true);

        $stmt = $conn->prepare("UPDATE conteo_inventario SET $ronda=?, cantidad_contada=? WHERE id_conteo=?");
        $stmt->bind_param('ddi', $valor, $valor, $id_conteo);
        $stmt->execute();
        $stmt->close();
        invLog($conn, $uid, 'EDITAR', 'conteo_inventario', $id_conteo, ['ronda'=>$ronda, 'valor'=>$valor]);
        $conn->commit();
        json(['success'=>true]);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'inventario_fisico_conteo');
    }

case 'inventario_fisico_cerrar':
    requierePermiso('inventario','conteo_fisico');
    $id = (int)($input['id'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $autorizadoPor = supervisorRequire(
        'inventario.conteo_cerrar',
        ['entidad_tipo'=>'inventario_fisico','entidad_id'=>(string)$id],
        $input['supervisor_token'] ?? null
    );
    if ($autorizadoPor === null) $autorizadoPor = $uid;

    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare('SELECT f.id_inventario,f.id_bodega,f.estado FROM inventario_fisico f JOIN usuario u ON u.id_user=f.id_user WHERE f.id_inventario=? AND u.id_cuenta=?');
        $stmt->bind_param('ii', $id, $accountId);
        $stmt->execute();
        $inventarioScope = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$inventarioScope) throw new OutOfBoundsException('Inventario físico no encontrado');

        $bodegasScope = [];
        if ((int)($inventarioScope['id_bodega'] ?? 0) > 0) $bodegasScope[] = (int)$inventarioScope['id_bodega'];
        $stmt = $conn->prepare('SELECT DISTINCT c.id_bodega FROM conteo_inventario c JOIN inventario_fisico f ON f.id_inventario=c.id_inventario JOIN usuario u ON u.id_user=f.id_user WHERE c.id_inventario=? AND u.id_cuenta=? AND c.id_bodega IS NOT NULL ORDER BY c.id_bodega');
        $stmt->bind_param('ii', $id, $accountId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $filaBodega) $bodegasScope[] = (int)$filaBodega['id_bodega'];
        $stmt->close();
        if (!$bodegasScope) throw new DomainException('El inventario físico no tiene una bodega válida asociada');
        $bodegasScope = array_values(array_unique($bodegasScope));
        sort($bodegasScope, SORT_NUMERIC);
        invBloquearBodegas($conn, $bodegasScope, $accountId);

        $stmt = $conn->prepare('SELECT f.id_inventario,f.id_bodega,f.estado FROM inventario_fisico f JOIN usuario u ON u.id_user=f.id_user WHERE f.id_inventario=? AND u.id_cuenta=? FOR UPDATE');
        $stmt->bind_param('ii', $id, $accountId);
        $stmt->execute();
        $inventario = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$inventario) throw new OutOfBoundsException('Inventario físico no encontrado');
        if ((int)($inventario['id_bodega'] ?? 0) !== (int)($inventarioScope['id_bodega'] ?? 0)) {
            throw new DomainException('El alcance del inventario físico cambió; reintente la operación');
        }
        if ($inventario['estado'] !== 'EN_PROGRESO') throw new DomainException('El inventario físico ya fue cerrado o no admite conciliación');

        $stmt = $conn->prepare('SELECT c.* FROM conteo_inventario c WHERE c.id_inventario=? AND c.conciliado=0 ORDER BY c.id_producto,c.id_bodega,c.id_ubicacion,c.id_conteo FOR UPDATE');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $conteos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$conteos) throw new DomainException('El inventario físico no tiene líneas pendientes para conciliar');

        foreach ($conteos as $conteoScope) {
            $idBodegaScope = (int)($conteoScope['id_bodega'] ?? $inventario['id_bodega'] ?? 0);
            if (!in_array($idBodegaScope, $bodegasScope, true)) {
                throw new DomainException('El alcance de bodegas del inventario físico cambió; reintente la operación');
            }
        }
        $productosBloqueados = invBloquearProductos($conn, array_column($conteos, 'id_producto'), $accountId);
        $productosSincronizar = [];
        foreach ($conteos as $conteo) {
            $valorFinal = $conteo['conteo3'] ?? $conteo['conteo2'] ?? $conteo['conteo1'] ?? null;
            if ($valorFinal === null) throw new DomainException('Debe registrar el conteo de todas las líneas antes de cerrar');
            $idProducto = (int)$conteo['id_producto'];
            $conteoFinal = invCantidadProducto($productosBloqueados[$idProducto], $valorFinal, true);
            $idBodega = (int)($conteo['id_bodega'] ?? $inventario['id_bodega'] ?? 0);
            $idUbicacion = $conteo['id_ubicacion'] === null ? null : (int)$conteo['id_ubicacion'];
            if ($idBodega <= 0) throw new DomainException('Una línea del inventario no tiene bodega asociada');

            $stmt = $conn->prepare('SELECT s.id_stock,s.disponible,s.reservado,s.comprometido,s.bloqueado,p.precio_costo FROM stock s JOIN bodega b ON b.id_bodega=s.id_bodega JOIN producto p ON p.id_producto=s.id_producto WHERE s.id_producto=? AND s.id_bodega=? AND s.id_ubicacion <=> ? AND b.id_cuenta=? AND p.id_cuenta=? FOR UPDATE');
            $stmt->bind_param('iiiii', $idProducto, $idBodega, $idUbicacion, $accountId, $accountId);
            $stmt->execute();
            $stock = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$stock) throw new DomainException('El stock cambió desde que comenzó el inventario; vuelva a iniciar el conteo');

            $stockSistema = round((float)$conteo['stock_sistema'], 3);
            $stockActual = round((float)$stock['disponible'], 3);
            $diferencia = round($conteoFinal - $stockSistema, 3);
            $stockConciliado = round($stockActual + $diferencia, 3);
            $stockProtegido = round(
                max(0.0, (float)($stock['reservado'] ?? 0))
                + max(0.0, (float)($stock['comprometido'] ?? 0))
                + max(0.0, (float)($stock['bloqueado'] ?? 0)),
                3
            );
            if ($stockConciliado < 0) {
                throw new DomainException('Los movimientos posteriores al conteo no permiten aplicar la diferencia sin dejar stock negativo');
            }
            if ($stockConciliado + 0.000001 < $stockProtegido) {
                throw new DomainException('El conteo no puede consumir stock reservado, comprometido o bloqueado');
            }
            $idStock = (int)$stock['id_stock'];
            $stmt = $conn->prepare('UPDATE stock SET disponible=? WHERE id_stock=?');
            $stmt->bind_param('di', $stockConciliado, $idStock);
            $stmt->execute();
            $stmt->close();

            $idConteo = (int)$conteo['id_conteo'];
            $stmt = $conn->prepare('UPDATE conteo_inventario SET cantidad_contada=?,diferencia=?,conciliado=1 WHERE id_conteo=? AND conciliado=0');
            $stmt->bind_param('ddi', $conteoFinal, $diferencia, $idConteo);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new DomainException('Una línea cambió durante la conciliación');
            }
            $stmt->close();

            if (abs($diferencia) > 0.000001) {
                $num = generarCodigo($conn, 'ajuste_inventario', 'numero', 'AJ-');
                $tipoAjuste = 'FISICO';
                $motivo = 'Ajuste por inventario físico';
                $observaciones = 'Conteo físico conciliado';
                $stmt = $conn->prepare('INSERT INTO ajuste_inventario (numero,tipo,id_producto,id_bodega,cantidad_anterior,cantidad_nueva,diferencia,motivo,id_user,autorizado_por,observaciones) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->bind_param('ssiidddsiis', $num, $tipoAjuste, $idProducto, $idBodega, $stockActual, $stockConciliado, $diferencia, $motivo, $uid, $autorizadoPor, $observaciones);
                $stmt->execute();
                $idAjuste = (int)$conn->insert_id;
                $stmt->close();
                $entrada = $diferencia > 0 ? $diferencia : 0.0;
                $salida = $diferencia < 0 ? -$diferencia : 0.0;
                actualizarKardex($conn, $uid, $idProducto, $idBodega, 'AJUSTE_FISICO', $idAjuste, 'AJUSTE', $entrada, $salida, (float)$stock['precio_costo'], $motivo);
            }
            $productosSincronizar[$idProducto] = true;
        }

        foreach (array_keys($productosSincronizar) as $idProducto) sincronizarCantidadProducto($conn, (int)$idProducto);
        $stmt = $conn->prepare("UPDATE inventario_fisico SET estado='CERRADO', fecha_fin=NOW() WHERE id_inventario=? AND estado='EN_PROGRESO'");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $cerrado = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$cerrado) throw new DomainException('El inventario físico cambió de estado durante el cierre');

        invLog($conn, $uid, 'CERRAR_INVENTARIO_FISICO', 'inventario_fisico', $id, ['lineas'=>count($conteos),'autorizado_por'=>$autorizadoPor]);
        $conn->commit();
        json(['success'=>true,'lineas'=>count($conteos)]);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'inventario_fisico_cerrar');
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
    
    $where = ['p.id_cuenta=?'];
    $kParams = [$accountId];
    $kTypes = "i";
    if ($id_producto) { $where[] = "k.id_producto=?"; $kParams[] = $id_producto; $kTypes .= "i"; }
    if ($id_bodega) { $where[] = "k.id_bodega=?"; $kParams[] = $id_bodega; $kTypes .= "i"; }
    $w = $where ? 'WHERE '.implode(' AND ', $where) : '';
    
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS t FROM kardex k JOIN producto p ON p.id_producto=k.id_producto $w");
    if ($kParams) $stmt2->bind_param($kTypes, ...$kParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt2->close();
    
    $items = [];
    $selSql = "SELECT k.*, p.nombre_producto, b.nombre AS bodega_nombre
        FROM kardex k
        JOIN producto p ON k.id_producto=p.id_producto
        LEFT JOIN bodega b ON k.id_bodega=b.id_bodega AND b.id_cuenta=p.id_cuenta
        $w ORDER BY k.fecha DESC LIMIT ? OFFSET ?";
    $selParams = array_merge($kParams, [$limit, $offset]);
    $selTypes = $kTypes . "ii";
    $stmt2 = $conn->prepare($selSql);
    $stmt2->bind_param($selTypes, ...$selParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = invOcultarCostos($f);
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

// ─── LOTES ───
case 'lotes':
    $id_producto = (int)($input['id_producto'] ?? $_GET['id_producto'] ?? 0);
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $base = "FROM lote l JOIN producto p ON l.id_producto=p.id_producto LEFT JOIN proveedor pr ON l.id_proveedor=pr.id_proveedor";
    $where = "WHERE p.id_cuenta=?";
    $lParams = [$accountId];
    $lTypes = "i";
    if ($id_producto) { $where .= " AND l.id_producto=?"; $lParams[] = $id_producto; $lTypes .= "i"; }
    $stmt2 = $conn->prepare("SELECT COUNT(*) AS t $base $where");
    if ($lParams) $stmt2->bind_param($lTypes, ...$lParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $total = (int)$r->fetch_assoc()['t'];
    $stmt2->close();
    $items = [];
    $selSql = "SELECT l.*, p.nombre_producto, COALESCE(pr.nombre_comercial,pr.razon_social) AS proveedor_nombre $base $where ORDER BY l.fecha_vencimiento ASC LIMIT ? OFFSET ?";
    $selParams = array_merge($lParams, [$limit, $offset]);
    $selTypes = $lTypes . "ii";
    $stmt2 = $conn->prepare($selSql);
    $stmt2->bind_param($selTypes, ...$selParams);
    $stmt2->execute();
    $r = $stmt2->get_result();
    while ($f = $r->fetch_assoc()) $items[] = invOcultarCostos($f);
    $stmt2->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'lote_crear':
    requierePermiso('inventario','crear');
    $id_producto = (int)($input['id_producto'] ?? 0);
    $numero_lote = trim((string)($input['numero_lote'] ?? ''));
    if (!$id_producto || !$numero_lote) json(['error'=>'Datos incompletos'],400);
    try {
        $conn->begin_transaction();
        $producto = invBloquearProducto($conn, $id_producto, $accountId);
        $cant = invCantidadProducto($producto, $input['cantidad'] ?? null);
        $id_prov = (int)($input['id_proveedor'] ?? 0); if ($id_prov <= 0) $id_prov = null;
        if ($id_prov !== null) {
            $stmt = $conn->prepare('SELECT id_proveedor FROM proveedor WHERE id_proveedor=? AND id_cuenta=? FOR UPDATE');
            $stmt->bind_param('ii', $id_prov, $accountId);$stmt->execute();$existe=(bool)$stmt->get_result()->fetch_row();$stmt->close();
            if (!$existe) throw new InvalidArgumentException('Proveedor no encontrado en esta cuenta');
        }
        $id_ubi = (int)($input['id_ubicacion'] ?? 0); if ($id_ubi <= 0) $id_ubi = null;
        if ($id_ubi !== null) {
            $stmt = $conn->prepare('SELECT u.id_ubicacion FROM ubicacion u JOIN bodega b ON b.id_bodega=u.id_bodega WHERE u.id_ubicacion=? AND b.id_cuenta=? FOR UPDATE');
            $stmt->bind_param('ii', $id_ubi, $accountId);$stmt->execute();$existe=(bool)$stmt->get_result()->fetch_row();$stmt->close();
            if (!$existe) throw new InvalidArgumentException('Ubicación no encontrada en esta cuenta');
        }
        $ff = trim((string)($input['fecha_fabricacion'] ?? '')) ?: null;
        $fi = trim((string)($input['fecha_ingreso'] ?? '')) ?: date('Y-m-d');
        $fv = trim((string)($input['fecha_vencimiento'] ?? '')) ?: null;
        $stmt = $conn->prepare("INSERT INTO lote (id_producto,numero_lote,id_proveedor,fecha_fabricacion,fecha_ingreso,fecha_vencimiento,cantidad,cantidad_original,id_ubicacion) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('isisssddi', $id_producto, $numero_lote, $id_prov, $ff, $fi, $fv, $cant, $cant, $id_ubi);
        $stmt->execute();$id = (int)$conn->insert_id;$stmt->close();
        invLog($conn, $uid, 'CREAR_LOTE', 'lote', $id, ['id_producto'=>$id_producto,'cantidad'=>$cant]);
        $conn->commit();
        json(['success'=>true, 'id'=>$id], 201);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'lote_crear');
    }

// ─── SERIES ───
case 'series':
    $id_producto = (int)($input['id_producto'] ?? $_GET['id_producto'] ?? 0);
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;
    $base = "FROM serie s JOIN producto p ON s.id_producto=p.id_producto";
    $where = "WHERE p.id_cuenta=?";
    $serParams = [$accountId];
    $serTypes = "i";
    if ($id_producto) { $where .= " AND s.id_producto=?"; $serParams[] = $id_producto; $serTypes .= "i"; }
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
    $numero_serie = trim((string)($input['numero_serie'] ?? ''));
    if (!$id_producto || !$numero_serie) json(['error'=>'Datos incompletos'],400);
    try {
        $conn->begin_transaction();
        invBloquearProducto($conn, $id_producto, $accountId);
        $id_lote = (int)($input['id_lote'] ?? 0); if ($id_lote <= 0) $id_lote = null;
        if ($id_lote !== null) {
            $stmt = $conn->prepare('SELECT l.id_lote FROM lote l JOIN producto p ON p.id_producto=l.id_producto WHERE l.id_lote=? AND l.id_producto=? AND p.id_cuenta=? FOR UPDATE');
            $stmt->bind_param('iii', $id_lote, $id_producto, $accountId);$stmt->execute();$existe=(bool)$stmt->get_result()->fetch_row();$stmt->close();
            if (!$existe) throw new InvalidArgumentException('Lote no encontrado para este producto');
        }
        $id_ubi = (int)($input['id_ubicacion'] ?? 0); if ($id_ubi <= 0) $id_ubi = null;
        if ($id_ubi !== null) {
            $stmt = $conn->prepare('SELECT u.id_ubicacion FROM ubicacion u JOIN bodega b ON b.id_bodega=u.id_bodega WHERE u.id_ubicacion=? AND b.id_cuenta=? FOR UPDATE');
            $stmt->bind_param('ii', $id_ubi, $accountId);$stmt->execute();$existe=(bool)$stmt->get_result()->fetch_row();$stmt->close();
            if (!$existe) throw new InvalidArgumentException('Ubicación no encontrada en esta cuenta');
        }
        $stmt = $conn->prepare("INSERT INTO serie (id_producto,numero_serie,id_lote,id_ubicacion,estado) VALUES (?,?,?,?,'DISPONIBLE')");
        $stmt->bind_param('isii', $id_producto, $numero_serie, $id_lote, $id_ubi);
        $stmt->execute();$id = (int)$conn->insert_id;$stmt->close();
        invLog($conn, $uid, 'CREAR_SERIE', 'serie', $id, ['id_producto'=>$id_producto]);
        $conn->commit();
        json(['success'=>true, 'id'=>$id], 201);
    } catch (Throwable $error) {
        invResponderError($conn, $error, 'serie_crear');
    }

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
    while ($f = $r->fetch_assoc()) $items[] = invOcultarCostos($f);
    json($items);

// ─── REPORTES ───
case 'reporte_existencias':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(500, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 100)));
    $offset = ($page-1)*$limit;
    $base = "FROM producto p LEFT JOIN (SELECT s.id_producto,SUM(s.disponible) disponible,SUM(s.reservado) reservado,SUM(s.comprometido) comprometido,SUM(s.bloqueado) bloqueado FROM stock s JOIN producto sp ON sp.id_producto=s.id_producto WHERE sp.id_cuenta=$accountId GROUP BY s.id_producto) s ON p.id_producto=s.id_producto LEFT JOIN categoria c ON p.id_categoria=c.id_categoria LEFT JOIN marca m ON p.id_marca=m.id_marca WHERE p.id_cuenta=$accountId AND p.activo=1";
    $r = $conn->query("SELECT COUNT(*) AS t $base");
    $total = (int)$r->fetch_assoc()['t'];
    $items = [];
    $stmt2 = $conn->prepare("SELECT p.id_producto, p.nombre_producto, p.codigo_de_barras, p.sku, p.precio_costo, p.precio_venta, p.costo_promedio,
        COALESCE(s.disponible,0) AS stock_total,
        COALESCE(s.reservado,0) AS reservado,
        COALESCE(s.comprometido,0) AS comprometido,
        COALESCE(s.bloqueado,0) AS bloqueado,
        GREATEST(COALESCE(s.disponible,0)-COALESCE(s.reservado,0)-COALESCE(s.comprometido,0)-COALESCE(s.bloqueado,0),0) AS disponible,
        c.nombre AS categoria, m.nombre AS marca,
        (COALESCE(s.disponible,0) * p.costo_promedio) AS valor_costo,
        (COALESCE(s.disponible,0) * p.precio_venta) AS valor_venta
        $base ORDER BY p.nombre_producto LIMIT ? OFFSET ?");
    $stmt2->bind_param("ii", $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    while ($f = $r->fetch_assoc()) $items[] = invOcultarCostos($f);
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'reporte_stock_critico':
    $page = max(1, (int)($input['page'] ?? $_GET['page'] ?? 1));
    $limit = min(500, max(10, (int)($input['limit'] ?? $_GET['limit'] ?? 100)));
    $offset = ($page-1)*$limit;
    $base = "FROM producto p LEFT JOIN (SELECT s.id_producto,SUM(s.disponible) disponible,SUM(s.reservado) reservado,SUM(s.comprometido) comprometido,SUM(s.bloqueado) bloqueado FROM stock s JOIN producto sp ON sp.id_producto=s.id_producto WHERE sp.id_cuenta=$accountId GROUP BY s.id_producto) s ON p.id_producto=s.id_producto LEFT JOIN categoria c ON p.id_categoria=c.id_categoria LEFT JOIN marca m ON p.id_marca=m.id_marca WHERE p.id_cuenta=$accountId AND p.activo=1 AND GREATEST(COALESCE(s.disponible,0)-COALESCE(s.reservado,0)-COALESCE(s.comprometido,0)-COALESCE(s.bloqueado,0),0) <= p.stock_minimo";
    $r = $conn->query("SELECT COUNT(*) AS t $base");
    $total = (int)$r->fetch_assoc()['t'];
    $items = [];
    $stmt2 = $conn->prepare("SELECT p.*, c.nombre AS categoria_nombre, m.nombre AS marca_nombre,
        GREATEST(COALESCE(s.disponible,0)-COALESCE(s.reservado,0)-COALESCE(s.comprometido,0)-COALESCE(s.bloqueado,0),0) AS stock_actual,
        (p.stock_minimo-GREATEST(COALESCE(s.disponible,0)-COALESCE(s.reservado,0)-COALESCE(s.comprometido,0)-COALESCE(s.bloqueado,0),0)) AS necesita_reponer
        $base ORDER BY stock_actual ASC LIMIT ? OFFSET ?");
    $stmt2->bind_param("ii", $limit, $offset);
    $stmt2->execute();
    $r = $stmt2->get_result();
    $stmt2->close();
    while ($f = $r->fetch_assoc()) {
        $f['precio_venta'] = (float)($f['precio_venta'] ?? 0);
        $f['stock_actual'] = (float)($f['stock_actual'] ?? 0);
        $f['stock_minimo'] = (float)($f['stock_minimo'] ?? 0);
        $items[] = invOcultarCostos($f);
    }
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);

case 'reporte_valorizacion':
    requierePermiso('inventario','ver_costos');
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
        LEFT JOIN (SELECT s.id_producto,SUM(s.disponible) disponible FROM stock s JOIN producto sp ON sp.id_producto=s.id_producto WHERE sp.id_cuenta=$accountId GROUP BY s.id_producto) s ON p.id_producto=s.id_producto
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
