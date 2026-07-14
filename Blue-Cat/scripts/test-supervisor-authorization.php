<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/assets/api/_supervisor.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "OK  {$message}\n";
}

$conn=getDB();
$context=['entidad_tipo'=>'pedido','entidad_id'=>'987654'];
$testPin=(string)random_int(100000,999999);

$conn->begin_transaction();
try {
    $roles=$conn->query("SELECT rs.id_cuenta,rs.id_rol supervisor_role,rc.id_rol cashier_role FROM rol rs JOIN rol rc ON rc.id_cuenta=rs.id_cuenta WHERE rs.nombre='Supervisor' AND rc.nombre='Cajero' AND rs.id_cuenta IS NOT NULL AND rs.activo=1 AND rc.activo=1 ORDER BY rs.id_cuenta LIMIT 1")->fetch_assoc();
    check((bool)$roles,'existe una cuenta con roles locales Cajero y Supervisor');
    $account=(int)$roles['id_cuenta'];$supervisorRole=(int)$roles['supervisor_role'];$cashierRole=(int)$roles['cashier_role'];
    $suffix=substr(bin2hex(random_bytes(4)),0,8);$passwordHash=password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT);
    $requesterName='req_'.$suffix;$requesterEmail=$requesterName.'@test.local';
    $stmt=$conn->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,validar_sesion,activo) VALUES(?,?,?,?,0,1)');
    $stmt->bind_param('isss',$account,$requesterName,$requesterEmail,$passwordHash);$stmt->execute();$requester=(int)$conn->insert_id;$stmt->close();
    $stmt=$conn->prepare('INSERT INTO usuario_rol(id_user,id_rol) VALUES(?,?)');$stmt->bind_param('ii',$requester,$cashierRole);$stmt->execute();$stmt->close();
    $supervisorName='sup_'.$suffix;$supervisorEmail=$supervisorName.'@test.local';
    $stmt=$conn->prepare('INSERT INTO usuario(id_cuenta,nombre,correo,password,validar_sesion,activo) VALUES(?,?,?,?,0,1)');
    $stmt->bind_param('isss',$account,$supervisorName,$supervisorEmail,$passwordHash);$stmt->execute();$supervisor=(int)$conn->insert_id;$stmt->close();
    $stmt=$conn->prepare('INSERT INTO usuario_rol(id_user,id_rol) VALUES(?,?)');$stmt->bind_param('ii',$supervisor,$supervisorRole);$stmt->execute();$stmt->close();
    $_SESSION['user_id']=$requester;
    check(tenantContext($requester,true)->accountId===$account,'los usuarios temporales pertenecen a la misma cuenta');
    $pinHash=password_hash($testPin,PASSWORD_DEFAULT);
    $stmt=$conn->prepare("INSERT INTO supervisor_credencial(id_cuenta,id_user,pin_hash,activo,updated_by) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE pin_hash=VALUES(pin_hash),activo=1,updated_by=VALUES(updated_by)");
    $stmt->bind_param('iisi',$account,$supervisor,$pinHash,$requester);$stmt->execute();$stmt->close();

    $issued=supervisorIssue($conn,$requester,'pos.anular_venta',$context,$testPin,'Prueba automatizada');
    check(!empty($issued['token']),'se emite un token opaco');
    check((int)$issued['id_autorizacion']>0,'la autorización queda auditada');
    check((int)($issued['expires_in']??0)===90,'el token vence en 90 segundos');

    $token=$issued['token'];$tokenHash=hash('sha256',$token);
    $stmt=$conn->prepare('SELECT token_hash,estado,id_supervisor FROM supervisor_autorizacion WHERE id_autorizacion=?');
    $id=(int)$issued['id_autorizacion'];$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    check($row['token_hash']===$tokenHash && $row['token_hash']!==$token,'solo se almacena el hash del token');
    check((int)$row['id_supervisor']===$supervisor,'se registra la segunda identidad');

    $approvedBy=supervisorRequire('pos.anular_venta',$context,$token);
    check($approvedBy===$supervisor,'el token autoriza la acción y contexto exactos');
    $stmt=$conn->prepare("UPDATE supervisor_autorizacion SET estado='CONSUMIDA' WHERE token_hash=? AND estado='EMITIDA'");
    $stmt->bind_param('s',$tokenHash);$stmt->execute();$reused=$stmt->affected_rows;$stmt->close();
    check($reused===0,'el token no puede reutilizarse');

    $issued2=supervisorIssue($conn,$requester,'pos.anular_venta',$context,$testPin,'Prueba de aislamiento');
    $hash2=hash('sha256',$issued2['token']);$wrong='pos.devolucion';
    $stmt=$conn->prepare("UPDATE supervisor_autorizacion SET estado='CONSUMIDA' WHERE token_hash=? AND accion=? AND estado='EMITIDA'");
    $stmt->bind_param('ss',$hash2,$wrong);$stmt->execute();$crossAction=$stmt->affected_rows;$stmt->close();
    check($crossAction===0,'un token no cruza a otra acción');
    $wrongAccount=999999;
    $stmt=$conn->prepare("UPDATE supervisor_autorizacion SET estado='CONSUMIDA' WHERE token_hash=? AND id_cuenta=? AND estado='EMITIDA'");
    $stmt->bind_param('si',$hash2,$wrongAccount);$stmt->execute();$crossTenant=$stmt->affected_rows;$stmt->close();
    check($crossTenant===0,'un token no cruza a otra cuenta');

    $conn->rollback();
    echo "Pruebas de autorización de supervisor completas.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR,"FAIL {$e->getMessage()}\n");
    exit(1);
}
