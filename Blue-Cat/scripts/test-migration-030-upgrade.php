<?php
declare(strict_types=1);

function u030Option(string $name): ?string {
    foreach (array_slice($_SERVER['argv'], 1) as $argument) {
        if (str_starts_with($argument, $name . '=')) return substr($argument, strlen($name) + 1);
    }
    return null;
}

function u030Assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}

/** @return array{0:int,1:string,2:string} */
function u030Run(array $command, string $cwd): array {
    $process = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes, $cwd);
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el migrador');
    $stdout=(string)stream_get_contents($pipes[1]);
    $stderr=(string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),trim($stdout),trim($stderr)];
}

function u030Scalar(mysqli $db, string $sql): mixed {
    $result=$db->query($sql);
    if (!$result) throw new RuntimeException('Consulta inválida: '.$db->error);
    $row=$result->fetch_row();$result->free();
    return $row[0]??null;
}

$root=dirname(__DIR__);
$env=u030Option('--env')??'.env.sprint1-test';
$envPath=preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$env)?$env:$root.'/'.$env;
if(!is_file($envPath)) throw new RuntimeException("Falta el entorno {$envPath}");
putenv('BLUECAT_ENV_FILE='.$envPath);
require_once $root.'/assets/api/_db.php';
if(getenv('APP_ENV')!=='test'||DB_NAME==='erp') throw new RuntimeException('La prueba 030 requiere APP_ENV=test y una base distinta de erp');

$scratch='bluecat_u030_'.bin2hex(random_bytes(6));
$scratchEnv=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$scratch.'.env';
$admin=null;$db=null;$created=false;$failure=null;

try{
    $admin=new mysqli(DB_HOST,DB_USER,DB_PASS,'',DB_PORT);
    $admin->set_charset('utf8mb4');
    if(!$admin->query("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")){
        throw new RuntimeException('No se pudo crear scratch: '.$admin->error);
    }
    $created=true;
    $envLines=[
        'APP_ENV=test',
        'DB_HOST='.DB_HOST,
        'DB_PORT='.DB_PORT,
        'DB_NAME='.$scratch,
        'DB_USER='.DB_USER,
        'DB_PASSWORD='.DB_PASS,
    ];
    if(file_put_contents($scratchEnv,implode(PHP_EOL,$envLines).PHP_EOL,LOCK_EX)===false){
        throw new RuntimeException('No se pudo crear el entorno scratch');
    }

    [$exit,$out,$err]=u030Run(
        [PHP_BINARY,$root.'/scripts/migrate.php','--env='.$scratchEnv,'--to=029_invoice_reconciliation.sql'],
        $root
    );
    if($exit!==0) throw new RuntimeException("No se pudo preparar 029: {$out} {$err}");
    $db=new mysqli(DB_HOST,DB_USER,DB_PASS,$scratch,DB_PORT);
    $db->set_charset('utf8mb4');

    $suffix=strtoupper(bin2hex(random_bytes(5)));
    $accounts=[];$users=[];
    foreach(['A','B'] as $label){
        $name="Cuenta U030 {$label} {$suffix}";
        $stmt=$db->prepare('INSERT INTO cuenta(nombre) VALUES (?)');
        $stmt->bind_param('s',$name);$stmt->execute();$accounts[]=(int)$db->insert_id;$stmt->close();
        $accountId=$accounts[array_key_last($accounts)];
        $user="u030-".strtolower($label)."-".strtolower($suffix);
        $mail=$user.'@test.local';$password=password_hash('U030-test!',PASSWORD_DEFAULT);
        $stmt=$db->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES (?,?,?,?,1,1)');
        $stmt->bind_param('isss',$accountId,$user,$mail,$password);
        $stmt->execute();$users[]=(int)$db->insert_id;$stmt->close();
    }

    $code="DUP-CODE-{$suffix}";$sku="DUP-SKU-{$suffix}";$cross="CROSS-ID-{$suffix}";
    $insert=$db->prepare('INSERT INTO producto(id_user,nombre_producto,codigo_de_barras,sku,categoria,activo) VALUES (?,?,?,?,?,1)');
    $fixtures=[
        [$users[0],"Canon código {$suffix}",$code,"SKU-CAN-{$suffix}",'Abarrotes'],
        [$users[0],"Duplicado código {$suffix}",$code,$sku,'Abarrotes'],
        [$users[0],"Duplicado SKU {$suffix}","CODE-SKU-{$suffix}",$sku,'Abarrotes'],
        [$users[0],"Blancos {$suffix}",'   ','   ',''],
        [$users[0],"Canónico cruzado {$suffix}",$cross,"SKU-CROSS-CAN-{$suffix}",'Abarrotes'],
        [$users[0],"SKU cruzado {$suffix}","CODE-CROSS-SECOND-{$suffix}",$cross,'Abarrotes'],
        [$users[1],"Mismo código otro tenant {$suffix}",$code,$sku,'Abarrotes'],
    ];
    $ids=[];
    foreach($fixtures as [$userId,$name,$barcode,$rowSku,$category]){
        $insert->bind_param('issss',$userId,$name,$barcode,$rowSku,$category);
        $insert->execute();$ids[]=(int)$db->insert_id;
    }
    $insert->close();

    [$exit,$out,$err]=u030Run([PHP_BINARY,$root.'/scripts/migrate.php','--env='.$scratchEnv],$root);
    if($exit!==0) throw new RuntimeException("Falló 030: {$out} {$err}");

    u030Assert((int)u030Scalar($db,"SELECT COUNT(*) FROM schema_migration WHERE version='030_product_identity_and_categories.sql'")===1,'030 queda registrada');
    u030Assert((int)u030Scalar($db,"SELECT COUNT(*) FROM schema_migration WHERE version='033_product_identifier_registry.sql'")===1,'033 queda registrada');
    u030Assert((int)u030Scalar($db,"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto' AND INDEX_NAME='uq_producto_cuenta_barcode'")>0,'barcode es único por cuenta');
    u030Assert((int)u030Scalar($db,"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto' AND INDEX_NAME='uq_producto_cuenta_sku'")>0,'SKU es único por cuenta');
    u030Assert((string)u030Scalar($db,"SELECT codigo_de_barras FROM producto WHERE id_producto={$ids[0]}")===$code,'se conserva el código del producto canónico');
    u030Assert(u030Scalar($db,"SELECT codigo_de_barras FROM producto WHERE id_producto={$ids[1]}")===null,'se retira el código duplicado no canónico');
    u030Assert(u030Scalar($db,"SELECT sku FROM producto WHERE id_producto={$ids[2]}")===null,'se retira el SKU duplicado no canónico');
    u030Assert(
        u030Scalar($db,"SELECT codigo_de_barras FROM producto WHERE id_producto={$ids[3]}")===null
        && u030Scalar($db,"SELECT sku FROM producto WHERE id_producto={$ids[3]}")===null,
        'identificadores blancos se normalizan a NULL'
    );
    u030Assert((string)u030Scalar($db,"SELECT codigo_de_barras FROM producto WHERE id_producto={$ids[4]}")===$cross,'se conserva el código canónico ante una colisión cruzada');
    u030Assert(u030Scalar($db,"SELECT sku FROM producto WHERE id_producto={$ids[5]}")===null,'033 retira un SKU que colisiona con otro código de barras');
    u030Assert((string)u030Scalar($db,"SELECT codigo_de_barras FROM producto WHERE id_producto={$ids[6]}")===$code,'el mismo código puede existir en otra cuenta');
    u030Assert((int)u030Scalar($db,"SELECT COUNT(*) FROM inventario_auditoria WHERE accion='MIGRAR_IDENTIDAD_DUPLICADA'")===2,'cada identidad removida queda auditada');
    u030Assert((int)u030Scalar($db,"SELECT COUNT(*) FROM inventario_auditoria WHERE accion='MIGRAR_IDENTIDAD_CRUZADA'")===1,'la conciliación cruzada queda auditada');
    u030Assert((int)u030Scalar($db,"SELECT COUNT(*) FROM producto_identificador WHERE id_producto IN ({$ids[0]},{$ids[4]})")===4,'el registro unificado conserva código y SKU canónicos');
    u030Assert((int)u030Scalar($db,"SELECT COUNT(*) FROM producto p JOIN categoria c ON c.id_categoria=p.id_categoria AND c.id_cuenta=p.id_cuenta WHERE p.id_producto IN ({$ids[0]},{$ids[1]},{$ids[2]},{$ids[4]},{$ids[5]},{$ids[6]}) AND c.nombre='Abarrotes'")===6,'categoría legacy queda vinculada dentro de su cuenta');

    $duplicateRejected=false;
    try{
        $name="Colisión {$suffix}";$otherSku="UNIQUE-{$suffix}";
        $stmt=$db->prepare('INSERT INTO producto(id_user,nombre_producto,codigo_de_barras,sku,activo) VALUES (?,?,?,?,1)');
        $stmt->bind_param('isss',$users[0],$name,$code,$otherSku);$stmt->execute();$stmt->close();
    }catch(mysqli_sql_exception $error){
        $duplicateRejected=(int)$error->getCode()===1062;
    }
    u030Assert($duplicateRejected,'la base rechaza una colisión nueva en la misma cuenta');
    $crossRejected=false;
    try{
        $name="Colisión cruzada nueva {$suffix}";$newCode="CROSS-NEW-{$suffix}";
        $stmt=$db->prepare('INSERT INTO producto(id_user,nombre_producto,codigo_de_barras,sku,activo) VALUES (?,?,?,?,1)');
        $stmt->bind_param('isss',$users[0],$name,$newCode,$code);$stmt->execute();$stmt->close();
    }catch(mysqli_sql_exception $error){
        $crossRejected=(int)$error->getCode()===1062;
    }
    u030Assert($crossRejected,'la base rechaza un SKU que reutiliza el código de otro producto');
    echo "OK migration 030 upgrade\n";
}catch(Throwable $error){
    $failure=$error;
}finally{
    if($db instanceof mysqli)$db->close();
    if($admin instanceof mysqli){
        if($created)$admin->query("DROP DATABASE IF EXISTS `{$scratch}`");
        $admin->close();
    }
    if(is_file($scratchEnv))@unlink($scratchEnv);
}
if($failure instanceof Throwable)throw $failure;
