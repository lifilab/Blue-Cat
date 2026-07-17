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
    $bodyFile = null;
    if ($body !== null) {
        $bodyJson = json_encode($body);
        if (strlen($bodyJson) > 8000) {
            $bodyFile = tempnam(sys_get_temp_dir(),'bluecat-api-body-');
            if ($bodyFile === false || file_put_contents($bodyFile,$bodyJson) === false) throw new RuntimeException('No se pudo crear body temporal');
            $parts[]='--body-file='.$bodyFile;
        } else {
            $parts[]='--body='.base64_encode($bodyJson);
        }
    }
    $command = implode(' ',array_map('escapeshellarg',$parts));
    try {
        exec($command,$lines,$code);
    } finally {
        if ($bodyFile && is_file($bodyFile)) unlink($bodyFile);
    }
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
$accountIds=[];$userIds=[];$productIds=[];$clientIds=[];$warehouseIds=[];$sessionIds=[];$cashIds=[];$orderIds=[];$quoteIds=[];
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
    $cashierB=(int)$db->query("SELECT id_rol FROM rol WHERE id_cuenta={$accountB} AND nombre='Cajero'")->fetch_row()[0];
    $db->query("INSERT INTO usuario_rol(id_user,id_rol) VALUES ({$userA},{$adminA}),({$employeeA},{$vendorA}),({$userB},{$cashierB})");

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

    $quote=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],[
        'action'=>'cotizacion_crear','codigo'=>'COT-API-'.bin2hex(random_bytes(3)),'id_cliente'=>$clientA,
        'cliente_nombre'=>'Cliente API A','items'=>[['id_producto'=>$productA,'producto'=>'Producto API A','cantidad'=>2,'precio_unitario'=>1000]]
    ]);
    assertApi(!empty($quote['success'])&&!empty($quote['id_cotizacion']),'crea una cotización sin generar una venta');
    $quoteA=(int)$quote['id_cotizacion'];$quoteIds=[$quoteA];

    $saleKey='tenant-test-sale-a-'.bin2hex(random_bytes(8));
    $salePayload=[
        'action'=>'venta_crear','items'=>[['id_producto'=>$productA,'cantidad'=>2,'precio_unitario'=>1000]],
        'pagos'=>[['metodo'=>'DEBITO','monto'=>1200,'referencia'=>'API-DB'],['metodo'=>'EFECTIVO','monto'=>1000]],
        'tipo_documento'=>'BOLETA','id_caja'=>$cashA,'id_cotizacion'=>$quoteA,'idempotency_key'=>$saleKey
    ];
    $sale=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],$salePayload);
    assertApi(!empty($sale['success'])&&!empty($sale['id_pedido']),'el POS registra una venta propia');
    $orderA=(int)$sale['id_pedido'];$orderIds=[$orderA];
    $quoteOrder=(int)$db->query("SELECT id_pedido FROM pos_cotizacion WHERE id_cotizacion={$quoteA}")->fetch_row()[0];
    assertApi($quoteOrder===$orderA,'la cotización se marca convertida dentro de la misma transacción de venta');
    assertApi((int)($sale['monto_recibido']??0)===2200&&(int)($sale['cambio']??0)===200,'el servidor separa monto recibido y vuelto');
    $saleReplay=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],$salePayload);
    assertApi(!empty($saleReplay['idempotent_replay'])&&(int)$saleReplay['id_pedido']===$orderA,'reintentar la misma venta devuelve el pedido original');
    assertApi(($sale['numero_documento']??'')==='B-00000001'&&($saleReplay['numero_documento']??'')==='B-00000001','el reintento conserva el mismo folio transaccional');
    $storedDocument=invokeApi($root,$envPath,'pos.php',$userA,'GET',['accion'=>'documento','id'=>$orderA]);
    assertApi((int)($storedDocument['documento']['total']??0)===2000&&count($storedDocument['documento']['items']??[])===1,'la boleta queda persistida para reimpresión');
    $forbiddenEdit=invokeApi($root,$envPath,'ventas.php',$userA,'POST',[],['accion'=>'editar','id_pedido'=>$orderA]);
    assertApi(isset($forbiddenEdit['error']),'una venta confirmada no puede editarse ni borrar su trazabilidad');
    $stockAfterReplay=(float)$db->query("SELECT disponible FROM stock WHERE id_producto={$productA} AND id_bodega={$warehouseA}")->fetch_row()[0];
    assertApi(abs($stockAfterReplay-8.0)<0.0001,'el reintento no descuenta stock dos veces');
    $conflictingPayload=$salePayload;$conflictingPayload['pagos']=[['metodo'=>'EFECTIVO','monto'=>1000]];
    $conflict=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],$conflictingPayload);
    assertApi(isset($conflict['error']),'una clave idempotente no puede reutilizarse con otra venta');
    $invalidPayment=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],[
        'action'=>'venta_crear','items'=>[['id_producto'=>$productA,'cantidad'=>1,'precio_unitario'=>1000]],
        'pagos'=>[['metodo'=>'TRANSFERENCIA','monto'=>1100]],'tipo_documento'=>'BOLETA','id_caja'=>$cashA,
        'idempotency_key'=>'tenant-test-invalid-'.bin2hex(random_bytes(8))
    ]);
    assertApi(isset($invalidPayment['error']),'un pago no efectivo no puede generar vuelto');
    $cashAmount=(int)$db->query("SELECT monto_actual FROM pos_caja WHERE id_caja={$cashA}")->fetch_row()[0];
    assertApi($cashAmount===10800,'el cajón suma solo los 800 de efectivo aplicados');
    $closeout=invokeApi($root,$envPath,'ventas.php',$userA,'GET',['accion'=>'cuadre','id_sesion'=>$sessionA]);
    assertApi((int)($closeout['monto_total']??0)===10800,'el cuadre usa el libro de efectivo para el monto esperado');
    assertApi((int)($closeout['debito']??0)===1200&&(int)($closeout['efectivo']??0)===800,'el cuadre separa débito y efectivo aplicado');
    $detailA=(int)$db->query("SELECT id_detalle_pedido FROM detalle_pedido WHERE id_pedido={$orderA}")->fetch_row()[0];
    $partial=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],[
        'action'=>'devolucion_crear','id_pedido'=>$orderA,'motivo'=>'Prueba parcial',
        'items'=>[['id_detalle_pedido'=>$detailA,'id_producto'=>$productA,'cantidad'=>1]]
    ]);
    assertApi(!empty($partial['success'])&&($partial['tipo']??'')==='PARCIAL'&&(int)$partial['monto_devuelto']===1000,'la primera devolución parcial usa monto calculado por servidor');
    $partial2=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],[
        'action'=>'devolucion_crear','id_pedido'=>$orderA,'motivo'=>'Completar devolución',
        'items'=>[['id_detalle_pedido'=>$detailA,'id_producto'=>$productA,'cantidad'=>1]]
    ]);
    assertApi(!empty($partial2['success'])&&($partial2['tipo']??'')==='TOTAL','una segunda devolución completa el pedido sin exceder cantidades');
    $duplicateReturn=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],[
        'action'=>'devolucion_crear','id_pedido'=>$orderA,'motivo'=>'Intento duplicado',
        'items'=>[['id_detalle_pedido'=>$detailA,'id_producto'=>$productA,'cantidad'=>1]]
    ]);
    assertApi(isset($duplicateReturn['error']),'no se puede devolver nuevamente una venta totalmente devuelta');
    $cashAfterReturns=(int)$db->query("SELECT monto_actual FROM pos_caja WHERE id_caja={$cashA}")->fetch_row()[0];
    assertApi($cashAfterReturns===10000,'las devoluciones revierten solo el efectivo original');
    $orderAccount=(int)$db->query("SELECT id_cuenta FROM pedido WHERE id_pedido={$orderA}")->fetch_row()[0];
    assertApi($orderAccount===$accountA,'la venta queda asociada a la cuenta correcta');
    $foreignSale=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],[
        'action'=>'venta_crear','items'=>[['id_producto'=>$productB,'cantidad'=>1,'precio_unitario'=>1000]],
        'pagos'=>[['metodo'=>'EFECTIVO','monto'=>1000]],'tipo_documento'=>'BOLETA',
        'idempotency_key'=>'tenant-test-sale-b-'.bin2hex(random_bytes(8))
    ]);
    assertApi(isset($foreignSale['error']),'el POS rechaza vender un producto de otra cuenta');
    $close=invokeApi($root,$envPath,'pos.php',$userA,'POST',[],['action'=>'caja_cerrar','monto_real'=>10000]);
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

    $logoBytes=file_get_contents($root.'/assets/img/Blue-Cat_logo-removebg.png');
    if ($logoBytes === false) throw new RuntimeException('Logo real de prueba no encontrado');
    $pngLogo='data:image/png;base64,'.base64_encode($logoBytes);
    $savedTemplate=invokeApi($root,$envPath,'core.php',$userA,'POST',[],[
        'accion'=>'config_boleta_guardar','nombre_empresa'=>'Blue Cat Test','rut_empresa'=>'76.000.000-1',
        'direccion'=>'Local de prueba','telefono'=>'+56 9 1111 1111','email'=>'boleta@test.local',
        'logo'=>$pngLogo,'mensaje_pie'=>'Documento de prueba','mensaje_agradecimiento'=>'Gracias',
        'mostrar_rut_cliente'=>1,'mostrar_desglose_iva'=>1,'mostrar_descuento'=>1,'iva_porcentaje'=>19
    ]);
    assertApi(!empty($savedTemplate['success'])&&!empty($savedTemplate['logo_guardado']),'la plantilla guarda un logo PNG real');
    $adminTemplate=invokeApi($root,$envPath,'core.php',$userA,'POST',[],['accion'=>'config_boleta']);
    assertApi(($adminTemplate['logo']??'')===$pngLogo,'configuracion recupera el mismo logo guardado');
    $employeeTemplate=invokeApi($root,$envPath,'pos.php',$employeeA,'GET',['accion'=>'config_boleta']);
    assertApi(($employeeTemplate['config']['logo']??'')===$pngLogo,'el POS del empleado comparte el logo de la cuenta');
    $secondLogoBytes=file_get_contents($root.'/assets/img/Blue-Cat logo.png');
    if ($secondLogoBytes === false) throw new RuntimeException('Segundo logo real de prueba no encontrado');
    $secondLogo='data:image/png;base64,'.base64_encode($secondLogoBytes);
    $replacedTemplate=invokeApi($root,$envPath,'core.php',$userA,'POST',[],[
        'accion'=>'config_boleta_guardar','nombre_empresa'=>'Blue Cat Test','logo'=>$secondLogo,
        'mensaje_agradecimiento'=>'Gracias','mostrar_desglose_iva'=>1,'mostrar_descuento'=>1,'iva_porcentaje'=>19
    ]);
    $replacedInPos=invokeApi($root,$envPath,'pos.php',$employeeA,'GET',['accion'=>'config_boleta']);
    assertApi(!empty($replacedTemplate['success'])&&($replacedInPos['config']['logo']??'')===$secondLogo,'reemplazar el logo actualiza el POS del empleado');
    $foreignTemplate=invokeApi($root,$envPath,'pos.php',$userB,'GET',['accion'=>'config_boleta']);
    assertApi(empty($foreignTemplate['config']),'el logo no se filtra a otra cuenta');
    $svgRejected=invokeApi($root,$envPath,'core.php',$userA,'POST',[],[
        'accion'=>'config_boleta_guardar','nombre_empresa'=>'Blue Cat Test',
        'logo'=>'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>')
    ]);
    assertApi(isset($svgRejected['error']),'la API rechaza logos SVG');
    $fakeImageRejected=invokeApi($root,$envPath,'core.php',$userA,'POST',[],[
        'accion'=>'config_boleta_guardar','nombre_empresa'=>'Blue Cat Test',
        'logo'=>'data:image/png;base64,'.base64_encode('contenido falso')
    ]);
    assertApi(isset($fakeImageRejected['error']),'la API rechaza contenido que no es una imagen');
    $afterRejectedLogo=invokeApi($root,$envPath,'pos.php',$employeeA,'GET',['accion'=>'config_boleta']);
    assertApi(($afterRejectedLogo['config']['logo']??'')===$secondLogo,'un logo rechazado no sobrescribe el logo vigente');
    $removedTemplate=invokeApi($root,$envPath,'core.php',$userA,'POST',[],[
        'accion'=>'config_boleta_guardar','nombre_empresa'=>'Blue Cat Test','logo'=>'',
        'mensaje_agradecimiento'=>'Gracias','mostrar_desglose_iva'=>1,'mostrar_descuento'=>1,'iva_porcentaje'=>19
    ]);
    $removedInPos=invokeApi($root,$envPath,'pos.php',$employeeA,'GET',['accion'=>'config_boleta']);
    assertApi(!empty($removedTemplate['success'])&&empty($removedInPos['config']['logo']),'quitar el logo tambien se refleja en el POS');

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
    foreach ($quoteIds as $id) {$db->query("DELETE FROM pos_cotizacion_detalle WHERE id_cotizacion=".(int)$id);$db->query("DELETE FROM pos_cotizacion WHERE id_cotizacion=".(int)$id);}
    foreach ($orderIds as $id) {
        $db->query("DELETE FROM pos_devolucion_detalle WHERE id_devolucion IN (SELECT id_devolucion FROM pos_devolucion WHERE id_pedido=".(int)$id.")");
        $db->query("DELETE FROM pos_devolucion WHERE id_pedido=".(int)$id);
        $db->query("DELETE FROM pos_venta_idempotencia WHERE id_pedido=".(int)$id);
        $db->query("DELETE FROM pos_documento_snapshot WHERE id_pedido=".(int)$id);
        $db->query("DELETE FROM metodo_de_pago WHERE id_pedido=".(int)$id);
        $db->query("DELETE FROM detalle_pedido WHERE id_pedido=".(int)$id);
        $db->query("DELETE FROM pedido WHERE id_pedido=".(int)$id);
    }
    foreach ($cashIds as $id) $db->query("DELETE FROM pos_caja WHERE id_caja=".(int)$id);
    foreach ($accountIds as $id) $db->query("DELETE FROM pos_caja_fisica WHERE id_cuenta=".(int)$id);
    foreach ($accountIds as $id) $db->query("DELETE FROM pos_folio_contador WHERE id_cuenta=".(int)$id);
    foreach ($sessionIds as $id) $db->query("DELETE FROM sesion WHERE id_sesion=".(int)$id);
    foreach ($productIds as $id) $db->query("DELETE FROM kardex WHERE id_producto=".(int)$id);
    foreach ($warehouseIds as $id) {$db->query("DELETE FROM stock WHERE id_bodega=".(int)$id);$db->query("DELETE FROM bodega WHERE id_bodega=".(int)$id);}
    foreach ($productIds as $id) $db->query("DELETE FROM producto WHERE id_producto=".(int)$id);
    foreach ($clientIds as $id) $db->query("DELETE FROM cliente WHERE id_cliente=".(int)$id);
    foreach ($accountIds as $id) {
        $db->query("DELETE FROM config_boleta WHERE id_cuenta=".(int)$id);
        $db->query("UPDATE cuenta SET id_usuario_propietario=NULL WHERE id_cuenta=".(int)$id);
    }
    foreach ($userIds as $id) {
        foreach (['pos_auditoria','inventario_auditoria','cliente_auditoria','core_auditoria','empleado_auditoria','proveedor_historial'] as $table) {
            try { $db->query("DELETE FROM {$table} WHERE id_user=".(int)$id); } catch (mysqli_sql_exception) {}
        }
        $db->query("DELETE FROM usuario WHERE id_user=".(int)$id);
    }
    foreach ($accountIds as $id) $db->query("DELETE FROM cuenta WHERE id_cuenta=".(int)$id);
}
