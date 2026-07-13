<?php
declare(strict_types=1);

function argValue(string $name): ?string {
    foreach (array_slice($_SERVER['argv'],1) as $arg) if (str_starts_with($arg,$name.'=')) return substr($arg,strlen($name)+1);
    return null;
}
function assertApi(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}
function invokeApi(string $root,string $env,string $endpoint,int $user,string $method,array $query=[],?array $body=null): array {
    $parts = [PHP_BINARY,$root.'/scripts/invoke-api-test.php','--env='.$env,'--endpoint='.$endpoint,'--user='.$user,'--method='.$method];
    if ($query) $parts[]='--query='.base64_encode(json_encode($query));
    if ($body !== null) $parts[]='--body='.base64_encode(json_encode($body));
    $command = implode(' ',array_map('escapeshellarg',$parts));
    exec($command,$lines,$code);
    $raw = implode("\n",$lines);
    $decoded = json_decode($raw,true);
    if (!is_array($decoded)) throw new RuntimeException("Respuesta API invalida ({$endpoint}, codigo {$code}): {$raw}");
    return $decoded;
}

$root = dirname(__DIR__);
$env = argValue('--env') ?? '.env.phase1-test';
$envPath = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/',$env) ? $env : $root.'/'.$env;
putenv('BLUECAT_ENV_FILE='.$envPath);
require_once $root.'/assets/api/_db.php';
if (getenv('APP_ENV') !== 'test' || DB_NAME === 'erp') throw new RuntimeException('Solo se permite ejecutar en APP_ENV=test.');

$db=getDB();
$accountIds=[];$userIds=[];$productIds=[];$clientIds=[];$warehouseIds=[];$sessionIds=[];$cashIds=[];$orderIds=[];
try {
    $db->query("INSERT INTO cuenta(nombre) VALUES ('API Tenant A'),('API Tenant B')");
    $accountA=(int)$db->insert_id;$accountB=$accountA+1;$accountIds=[$accountA,$accountB];
    $hash=password_hash('ApiTenant-2026',PASSWORD_DEFAULT);
    $stmt=$db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
    $name='api-a-'.bin2hex(random_bytes(3));$mail=$name.'@test.local';$stmt->bind_param('isss',$accountA,$name,$mail,$hash);$stmt->execute();$userA=(int)$db->insert_id;
    $name='api-b-'.bin2hex(random_bytes(3));$mail=$name.'@test.local';$stmt->bind_param('isss',$accountB,$name,$mail,$hash);$stmt->execute();$userB=(int)$db->insert_id;
    $name='api-employee-'.bin2hex(random_bytes(3));$mail=$name.'@test.local';$stmt->bind_param('isss',$accountA,$name,$mail,$hash);$stmt->execute();$employeeA=(int)$db->insert_id;
    $stmt->close();$userIds=[$userA,$userB,$employeeA];
    $db->query("UPDATE cuenta SET id_usuario_propietario={$userA} WHERE id_cuenta={$accountA}");
    $db->query("UPDATE cuenta SET id_usuario_propietario={$userB} WHERE id_cuenta={$accountB}");
    provisionTenantRoles($db,$accountA);provisionTenantRoles($db,$accountB);
    $adminA=(int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountA} AND nombre='Administrador'")->fetch_row()[0];
    $vendorA=(int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountA} AND nombre='Vendedor'")->fetch_row()[0];
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$userA},{$adminA}),({$employeeA},{$vendorA})");

    $stmt=$db->prepare('INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta) VALUES (?,?,?,1000)');
    $name='Producto API A';$stmt->bind_param('iis',$userA,$accountA,$name);$stmt->execute();$productA=(int)$db->insert_id;
    $name='Producto API B';$stmt->bind_param('iis',$userB,$accountB,$name);$stmt->execute();$productB=(int)$db->insert_id;$stmt->close();$productIds=[$productA,$productB];
    $stmt=$db->prepare("INSERT INTO bodega(id_user,id_cuenta,codigo,nombre,estado) VALUES (?,?,?,?,'ACTIVA')");
    $code='API-BOD-'.bin2hex(random_bytes(3));$name='Bodega API A';$stmt->bind_param('iiss',$userA,$accountA,$code,$name);$stmt->execute();$warehouseA=(int)$db->insert_id;$stmt->close();$warehouseIds=[$warehouseA];
    $db->query("INSERT INTO stock(id_producto,id_bodega,disponible) VALUES ({$productA},{$warehouseA},10)");
    $stmt=$db->prepare('INSERT INTO cliente(id_user,id_cuenta,codigo,nombre,razon_social) VALUES (?,?,?,?,\'Cliente API\')');
    $code='APIA'.bin2hex(random_bytes(2));$name='Cliente API A';$stmt->bind_param('iiss',$userA,$accountA,$code,$name);$stmt->execute();$clientA=(int)$db->insert_id;
    $code='APIB'.bin2hex(random_bytes(2));$name='Cliente API B';$stmt->bind_param('iiss',$userB,$accountB,$code,$name);$stmt->execute();$clientB=(int)$db->insert_id;$stmt->close();$clientIds=[$clientA,$clientB];

    $cashCode='API-CAJA-'.bin2hex(random_bytes(3));
    $open=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],['action'=>'caja_abrir','codigo'=>$cashCode,'nombre'=>'Caja API','monto_apertura'=>10000]);
    assertApi(!empty($open['success'])&&!empty($open['caja']['id_caja']),'el POS abre caja con contexto tenant');
    $cashA=(int)$open['caja']['id_caja'];$sessionA=(int)$open['caja']['id_sesion'];$cashIds=[$cashA];$sessionIds=[$sessionA];
    $cashAccount=(int)$db->query("SELECT id_cuenta FROM pos_caja WHERE id_caja={$cashA}")->fetch_row()[0];
    assertApi($cashAccount===$accountA,'la caja abierta queda asociada a la cuenta correcta');

    $sale=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],[
        'action'=>'venta_crear','items'=>[['id_producto'=>$productA,'cantidad'=>1,'precio_unitario'=>1000]],
        'pagos'=>[['metodo'=>'EFECTIVO','monto'=>1000]],'tipo_documento'=>'BOLETA'
    ]);
    assertApi(!empty($sale['success'])&&!empty($sale['id_pedido']),'el POS registra una venta propia');
    $orderA=(int)$sale['id_pedido'];$orderIds=[$orderA];
    $orderAccount=(int)$db->query("SELECT id_cuenta FROM pedido WHERE id_pedido={$orderA}")->fetch_row()[0];
    assertApi($orderAccount===$accountA,'la venta queda asociada a la cuenta correcta');
    $foreignSale=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],[
        'action'=>'venta_crear','items'=>[['id_producto'=>$productB,'cantidad'=>1,'precio_unitario'=>1000]],
        'pagos'=>[['metodo'=>'EFECTIVO','monto'=>1000]],'tipo_documento'=>'BOLETA'
    ]);
    assertApi(isset($foreignSale['error']),'el POS rechaza vender un producto de otra cuenta');
    $close=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],['action'=>'caja_cerrar','monto_real'=>11000]);
    assertApi(!empty($close['success']),'el POS cierra la caja del usuario');

    $providers=invokeApi($root,$envPath,'proveedores.php',$userA,'GET');
    assertApi(isset($providers['items'])&&is_array($providers['items']),'proveedores carga con alcance de cuenta');
    $invoices=invokeApi($root,$envPath,'facturas.php',$userA,'GET');
    assertApi(isset($invoices['data'])&&is_array($invoices['data']),'facturacion carga con alcance de cuenta');
    $crmDashboard=invokeApi($root,$envPath,'crm.php',$userA,'POST',[],['accion'=>'dashboard']);
    assertApi((int)($crmDashboard['total_clientes']??-1)===1,'el dashboard CRM cuenta solo clientes propios');

    $employeeClient=invokeApi($root,$envPath,'clientes.php',$employeeA,'GET',['id'=>$clientA]);
    assertApi((int)($employeeClient['id_cliente']??0)===$clientA,'un empleado con crm.ver comparte los clientes de su cuenta');
    $employeeEdit=invokeApi($root,$envPath,'clientes.php',$employeeA,'POST',[],['accion'=>'editar','id_cliente'=>$clientA,'nombre'=>'Editado por empleado']);
    $employeeEditedName=$db->query("SELECT nombre FROM cliente WHERE id_cliente={$clientA}")->fetch_row()[0];
    assertApi(!empty($employeeEdit['success'])&&$employeeEditedName==='Editado por empleado','crm.editar permite al empleado editar datos compartidos');
    $db->query("UPDATE cliente SET nombre='Cliente API A' WHERE id_cliente={$clientA}");
    $deniedInventory=invokeApi($root,$envPath,'inventario.php',$employeeA,'GET',['accion'=>'producto','id'=>$productA]);
    assertApi(isset($deniedInventory['error']),'sin inventario.ver el empleado no puede consultar inventario');
    $deniedProviders=invokeApi($root,$envPath,'proveedores.php',$employeeA,'GET');
    assertApi(isset($deniedProviders['error']),'sin proveedores.ver el empleado no puede consultar proveedores');

    $own=invokeApi($root,$envPath,'clientes.php',$userA,'GET',['id'=>$clientA]);
    assertApi((int)($own['id_cliente']??0)===$clientA,'el endpoint permite leer un cliente propio');
    $foreign=invokeApi($root,$envPath,'clientes.php',$userA,'GET',['id'=>$clientB]);
    assertApi(isset($foreign['error']),'el endpoint oculta clientes de otra cuenta');
    $list=invokeApi($root,$envPath,'clientes.php',$userA,'GET');
    $ids=array_map(fn($row)=>(int)$row['id_cliente'],$list['items']??[]);
    assertApi(in_array($clientA,$ids,true)&&!in_array($clientB,$ids,true),'el listado API no mezcla clientes');

    invokeApi($root,$envPath,'clientes.php',$userA,'POST',[],['accion'=>'editar','id_cliente'=>$clientB,'nombre'=>'INTRUSION']);
    $nameB=$db->query("SELECT nombre FROM cliente WHERE id_cliente={$clientB}")->fetch_row()[0];
    assertApi($nameB==='Cliente API B','una edicion API no modifica clientes ajenos');

    $foreignProduct=invokeApi($root,$envPath,'inventario.php',$userA,'GET',['accion'=>'producto','id'=>$productB]);
    assertApi(isset($foreignProduct['error']),'inventario oculta productos de otra cuenta');
    $editProduct=invokeApi($root,$envPath,'inventario.php',$userA,'POST',[],['accion'=>'producto_editar','id'=>$productB,'nombre_producto'=>'INTRUSION']);
    assertApi(isset($editProduct['error']),'inventario rechaza modificar productos ajenos');
    $nameB=$db->query("SELECT nombre_producto FROM producto WHERE id_producto={$productB}")->fetch_row()[0];
    assertApi($nameB==='Producto API B','el producto ajeno permanece intacto');
    echo "OK endpoints tenant verificados\n";
} finally {
    foreach ($cashIds as $id) $db->query("DELETE FROM pos_movimiento_caja WHERE id_caja=".(int)$id);
    foreach ($orderIds as $id) {
        $db->query("DELETE FROM metodo_de_pago WHERE id_pedido=".(int)$id);
        $db->query("DELETE FROM detalle_pedido WHERE id_pedido=".(int)$id);
        $db->query("DELETE FROM pedido WHERE id_pedido=".(int)$id);
    }
    foreach ($cashIds as $id) $db->query("DELETE FROM pos_caja WHERE id_caja=".(int)$id);
    foreach ($sessionIds as $id) $db->query("DELETE FROM sesion WHERE id_sesion=".(int)$id);
    foreach ($productIds as $id) $db->query("DELETE FROM kardex WHERE id_producto=".(int)$id);
    foreach ($warehouseIds as $id) {$db->query("DELETE FROM stock WHERE id_bodega=".(int)$id);$db->query("DELETE FROM bodega WHERE id_bodega=".(int)$id);}
    foreach ($productIds as $id) $db->query("DELETE FROM producto WHERE id_producto=".(int)$id);
    foreach ($clientIds as $id) $db->query("DELETE FROM cliente WHERE id_cliente=".(int)$id);
    foreach ($accountIds as $id) $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta=".(int)$id);
    foreach ($userIds as $id) {
        foreach (['pos_auditoria','inventario_auditoria','cliente_auditoria','core_auditoria','empleado_auditoria','proveedor_historial'] as $table) {
            try { $db->query("DELETE FROM {$table} WHERE id_user=".(int)$id); } catch (mysqli_sql_exception) {}
        }
        $db->query("DELETE FROM usuario WHERE id_user=".(int)$id);
    }
    foreach ($accountIds as $id) $db->query("DELETE FROM cuenta WHERE id_cuenta=".(int)$id);
}
