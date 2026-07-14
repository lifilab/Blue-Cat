<?php
require_once __DIR__ . '/_db.php';

function supervisorPolicy(string $action): ?array {
    $map = [
        'pos.anular_venta' => ['modulo'=>'pos','permiso'=>'cancelar_venta','aprobador'=>'aprobar_pos'],
        'pos.devolucion' => ['modulo'=>'pos','permiso'=>'devoluciones','aprobador'=>'aprobar_pos'],
        'pos.cambiar_precio' => ['modulo'=>'pos','permiso'=>'cambiar_precios','aprobador'=>'aprobar_pos'],
        'pos.retiro_caja' => ['modulo'=>'pos','permiso'=>'caja','aprobador'=>'aprobar_pos'],
        'pos.cierre_diferencia' => ['modulo'=>'pos','permiso'=>'cerrar_caja','aprobador'=>'aprobar_pos'],
        'inventario.ajuste' => ['modulo'=>'inventario','permiso'=>'ajustes','aprobador'=>'aprobar_inventario'],
        'inventario.transferencia_enviar' => ['modulo'=>'inventario','permiso'=>'transferencias','aprobador'=>'aprobar_inventario'],
        'inventario.conteo_cerrar' => ['modulo'=>'inventario','permiso'=>'conteo_fisico','aprobador'=>'aprobar_inventario'],
    ];
    return $map[$action] ?? null;
}

function supervisorNormalize($value) {
    if (is_object($value)) $value=(array)$value;
    if (is_array($value)) {
        if (array_keys($value)!==range(0,count($value)-1)) ksort($value);
        foreach ($value as $k=>$v) $value[$k]=supervisorNormalize($v);
    }
    return $value;
}

function supervisorContextHash($context): string {
    return hash('sha256', json_encode(supervisorNormalize($context), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function supervisorRequire(string $action, $context, ?string $token): ?int {
    $policy=supervisorPolicy($action);
    if (!$policy) json(['error'=>true,'message'=>'Acción supervisada no reconocida'],400);
    // Ejecutar la operación no equivale a poder aprobar una excepción.
    // Solo Supervisor/Administrador con ambas capacidades continúan sin token.
    if (verificarPermiso('supervisor',$policy['aprobador']) && verificarPermiso($policy['modulo'],$policy['permiso'])) return null;
    if (!$token) json(['error'=>true,'message'=>'Se requiere autorización de un supervisor','supervisor_required'=>true,'supervisor_action'=>$action,'supervisor_context'=>$context],403);
    $conn=getDB(); $uid=getSessionUserId(); $account=tenantContext($uid)->accountId;
    $tokenHash=hash('sha256',$token); $contextHash=supervisorContextHash($context);
    $stmt=$conn->prepare("UPDATE supervisor_autorizacion SET estado='CONSUMIDA',consumed_at=NOW() WHERE id_cuenta=? AND id_solicitante=? AND accion=? AND contexto_hash=? AND token_hash=? AND estado='EMITIDA' AND expires_at>=NOW()");
    $stmt->bind_param('iisss',$account,$uid,$action,$contextHash,$tokenHash); $stmt->execute(); $ok=$stmt->affected_rows===1; $stmt->close();
    if (!$ok) json(['error'=>true,'message'=>'La autorización venció, ya fue usada o no corresponde a esta operación','supervisor_required'=>true,'supervisor_action'=>$action,'supervisor_context'=>$context],403);
    $stmt=$conn->prepare("SELECT id_supervisor FROM supervisor_autorizacion WHERE token_hash=? LIMIT 1");
    $stmt->bind_param('s',$tokenHash); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['id_supervisor']??0);
}

function supervisorIssue(mysqli $conn, int $uid, string $action, $context, string $credential, string $reason): array {
    $policy=supervisorPolicy($action);
    if (!$policy) json(['error'=>true,'message'=>'Acción no autorizable'],400);
    $account=tenantContext($uid)->accountId;
    if (trim($reason)==='') json(['error'=>true,'message'=>'Debe indicar el motivo'],400);
    if (strlen($credential)<4) json(['error'=>true,'message'=>'Credencial inválida'],400);
    $stmt=$conn->prepare("SELECT COUNT(*) n FROM supervisor_autorizacion WHERE id_cuenta=? AND id_solicitante=? AND estado='RECHAZADA' AND created_at>=DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
    $stmt->bind_param('ii',$account,$uid); $stmt->execute(); $attempts=(int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
    if ($attempts>=5) json(['error'=>true,'message'=>'Demasiados intentos. Espere 10 minutos.'],429);
    $sql="SELECT DISTINCT u.id_user,u.nombre,c.pin_hash,c.tarjeta_hash FROM usuario u JOIN supervisor_credencial c ON c.id_user=u.id_user AND c.id_cuenta=u.id_cuenta JOIN usuario_rol ur ON ur.id_user=u.id_user JOIN rol_permiso rp ON rp.id_rol=ur.id_rol JOIN permiso pa ON pa.id_permiso=rp.id_permiso AND pa.modulo='supervisor' AND pa.accion=? JOIN rol_permiso rp2 ON rp2.id_rol=ur.id_rol JOIN permiso px ON px.id_permiso=rp2.id_permiso AND px.modulo=? AND px.accion=? WHERE u.id_cuenta=? AND u.activo=1 AND c.activo=1 AND u.id_user<>?";
    $stmt=$conn->prepare($sql); $stmt->bind_param('sssii',$policy['aprobador'],$policy['modulo'],$policy['permiso'],$account,$uid); $stmt->execute(); $r=$stmt->get_result();
    $supervisor=null; while($row=$r->fetch_assoc()){if(($row['pin_hash']&&password_verify($credential,$row['pin_hash']))||($row['tarjeta_hash']&&password_verify($credential,$row['tarjeta_hash']))){$supervisor=$row;break;}} $stmt->close();
    $ctxHash=supervisorContextHash($context); $entityType=(string)($context['entidad_tipo']??''); $entityId=(string)($context['entidad_id']??''); $ip=$_SERVER['REMOTE_ADDR']??''; $ua=substr($_SERVER['HTTP_USER_AGENT']??'',0,255);
    if (!$supervisor) {
        $state='RECHAZADA'; $stmt=$conn->prepare("INSERT INTO supervisor_autorizacion(id_cuenta,id_solicitante,modulo,accion,entidad_tipo,entidad_id,contexto_hash,motivo,estado,ip,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iisssssssss',$account,$uid,$policy['modulo'],$action,$entityType,$entityId,$ctxHash,$reason,$state,$ip,$ua); $stmt->execute(); $stmt->close();
        json(['error'=>true,'message'=>'PIN o tarjeta de supervisor inválidos'],403);
    }
    $plain=bin2hex(random_bytes(32)); $tokenHash=hash('sha256',$plain); $state='EMITIDA'; $sid=(int)$supervisor['id_user'];
    $stmt=$conn->prepare("INSERT INTO supervisor_autorizacion(id_cuenta,id_solicitante,id_supervisor,modulo,accion,entidad_tipo,entidad_id,contexto_hash,motivo,token_hash,estado,ip,user_agent,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 90 SECOND))");
    $stmt->bind_param('iiissssssssss',$account,$uid,$sid,$policy['modulo'],$action,$entityType,$entityId,$ctxHash,$reason,$tokenHash,$state,$ip,$ua); $stmt->execute(); $id=(int)$conn->insert_id; $stmt->close();
    return ['success'=>true,'token'=>$plain,'expires_in'=>90,'id_autorizacion'=>$id,'supervisor'=>$supervisor['nombre']];
}
