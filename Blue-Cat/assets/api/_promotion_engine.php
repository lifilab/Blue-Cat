<?php

function promotionValue($value, string $key, $default = null) {
    if (is_array($value)) return $value[$key] ?? $default;
    if (is_object($value)) return $value->{$key} ?? $default;
    return $default;
}

function promotionJson($value): array {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function promotionCanonicalType(string $type): string {
    $type = strtoupper(trim($type));
    if (preg_match('/^\d+X\d+$/', $type)) return 'CANTIDAD';
    return match ($type) {
        'PORCENTAJE', 'DESCUENTO_PCT', 'PORCENTAJE_PRODUCTO' => 'PORCENTAJE',
        'FIJO', 'DESCUENTO_MONTO', 'MONTO_FIJO' => 'MONTO',
        'PRECIO_ESPECIAL' => 'PRECIO_ESPECIAL',
        'COMPRA_X_DESCUENTO_Y', 'BUY_X_GET_Y' => 'COMPRA_X_DESCUENTO_Y',
        'COMBO' => 'COMBO',
        '2X1', '3X2', 'NXM', 'CANTIDAD' => 'CANTIDAD',
        default => $type,
    };
}

function promotionPrepareCart(mysqli $conn, int $accountId, array $items, bool $lock = false, array $priceOverrides = []): array {
    $quantities = [];
    foreach ($items as $item) {
        $id = (int) promotionValue($item, 'id_producto', 0);
        $quantity = round((float) promotionValue($item, 'cantidad', 0), 3);
        if ($id <= 0 || $quantity <= 0) throw new InvalidArgumentException('Producto o cantidad inválidos para evaluar promociones.');
        $quantities[$id] = ($quantities[$id] ?? 0.0) + $quantity;
    }
    if (!$quantities) return [];

    $rows = [];
    $sql = "SELECT p.id_producto,p.nombre_producto,p.precio_venta,p.codigo_de_barras,p.sku,p.id_categoria,p.id_marca,
                   COALESCE(c.nombre,p.categoria) categoria_nombre,m.nombre marca_nombre
            FROM producto p
            LEFT JOIN categoria c ON c.id_categoria=p.id_categoria
            LEFT JOIN marca m ON m.id_marca=p.id_marca
            WHERE p.id_cuenta=? AND p.activo=1 AND p.id_producto IN (" . implode(',', array_fill(0, count($quantities), '?')) . ")";
    if ($lock) $sql .= ' FOR UPDATE';
    $stmt = $conn->prepare($sql);
    $params = array_merge([$accountId], array_keys($quantities));
    $types = 'i' . str_repeat('i', count($quantities));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id_producto'];
        $price = array_key_exists($id,$priceOverrides) ? (int)$priceOverrides[$id] : (int) round((float) $row['precio_venta']);
        $row['cantidad'] = $quantities[$id];
        $row['precio_original'] = $price;
        $row['subtotal_original'] = (int) round($price * $quantities[$id]);
        $row['descuento'] = 0;
        $row['promociones'] = [];
        $row['unidades_beneficiadas'] = 0.0;
        $rows[$id] = $row;
    }
    $stmt->close();
    if (count($rows) !== count($quantities)) throw new InvalidArgumentException('Uno o más productos no existen, están inactivos o pertenecen a otra cuenta.');
    return $rows;
}

function promotionProductMatches(array $line, array $scope): bool {
    if ((int) ($scope['id_producto'] ?? 0) > 0 && (int) $scope['id_producto'] === (int) $line['id_producto']) return true;
    $code = strtoupper(trim((string) ($scope['codigo_producto'] ?? '')));
    $sku = strtoupper(trim((string) ($scope['sku'] ?? '')));
    if ($code !== '' && $code === strtoupper(trim((string) $line['codigo_de_barras']))) return true;
    if ($sku !== '' && $sku === strtoupper(trim((string) $line['sku']))) return true;
    return false;
}

function promotionEligibleIds(array $cart, array $promo, array $scopes, string $role = 'ELEGIBLE'): array {
    $roleScopes = array_values(array_filter($scopes, fn($scope) => strtoupper((string) $scope['rol']) === $role));
    $ids = [];
    foreach ($cart as $id => $line) {
        $matchesProduct = $roleScopes && array_filter($roleScopes, fn($scope) => promotionProductMatches($line, $scope));
        $matchesCategory = trim((string) ($promo['aplica_categoria'] ?? '')) !== ''
            && strcasecmp(trim((string) $promo['aplica_categoria']), trim((string) $line['categoria_nombre'])) === 0;
        $matchesBrand = trim((string) ($promo['aplica_marca'] ?? '')) !== ''
            && strcasecmp(trim((string) $promo['aplica_marca']), trim((string) $line['marca_nombre'])) === 0;
        $hasAnyScope = (bool) $roleScopes || trim((string) ($promo['aplica_categoria'] ?? '')) !== '' || trim((string) ($promo['aplica_marca'] ?? '')) !== '';
        if (!$hasAnyScope || $matchesProduct || $matchesCategory || $matchesBrand) $ids[] = (int) $id;
    }
    return $ids;
}

function promotionAllocateByQuantity(array &$cart, array $ids, float $benefitQuantity, array $promo, callable $unitDiscount): int {
    usort($ids, fn($a, $b) => $cart[$a]['precio_original'] <=> $cart[$b]['precio_original']);
    $discount = 0;
    foreach ($ids as $id) {
        if ($benefitQuantity <= 0.0001) break;
        $line =& $cart[$id];
        $quantity = min((float) $line['cantidad'], $benefitQuantity);
        $availablePerUnit = max(0, $line['precio_original'] - ($line['descuento'] / max(0.001, (float) $line['cantidad'])));
        $perUnit = max(0, min($availablePerUnit, (float) $unitDiscount($line)));
        $lineDiscount = (int) round($perUnit * $quantity);
        $remaining = max(0, $line['subtotal_original'] - $line['descuento']);
        $lineDiscount = min($lineDiscount, $remaining);
        if ($lineDiscount > 0) {
            $line['descuento'] += $lineDiscount;
            $line['unidades_beneficiadas'] += $quantity;
            $line['promociones'][] = ['id_promocion'=>(int)$promo['id_promocion'],'codigo'=>$promo['codigo'],'nombre'=>$promo['nombre'],'descuento'=>$lineDiscount];
            $discount += $lineDiscount;
        }
        $benefitQuantity -= $quantity;
        unset($line);
    }
    return $discount;
}

function promotionReject(array &$rejected, array $promo, string $reason): void {
    $rejected[] = ['id_promocion'=>(int)$promo['id_promocion'],'codigo'=>$promo['codigo'],'nombre'=>$promo['nombre'],'motivo'=>$reason];
}

function promotionEvaluate(mysqli $conn, int $accountId, int $userId, array $items, ?int $clientId = null, array $couponCodes = [], array $context = [], bool $lock = false): array {
    $cart = promotionPrepareCart($conn, $accountId, $items, $lock,(array)($context['price_overrides']??[]));
    $subtotal = array_sum(array_column($cart, 'subtotal_original'));
    $couponSet = [];
    foreach ($couponCodes as $code) {
        $code = strtoupper(trim((string) $code));
        if ($code !== '') $couponSet[$code] = true;
    }

    $client = null;
    if ($clientId) {
        $stmt = $conn->prepare('SELECT id_cliente,categoria,clasificacion,lista_precios,canal FROM cliente WHERE id_cliente=? AND id_cuenta=? AND COALESCE(activo,1)=1');
        $stmt->bind_param('ii',$clientId,$accountId);$stmt->execute();$client=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$client) throw new InvalidArgumentException('El cliente seleccionado no existe o pertenece a otra cuenta.');
    }

    $sql = "SELECT * FROM pos_promocion WHERE id_cuenta=? AND activo=1 AND estado='ACTIVA' ORDER BY prioridad DESC,id_promocion ASC";
    if ($lock) $sql .= ' FOR UPDATE';
    $stmt = $conn->prepare($sql);$stmt->bind_param('i',$accountId);$stmt->execute();$result=$stmt->get_result();
    $promotions=[];while($row=$result->fetch_assoc())$promotions[]=$row;$stmt->close();

    $scopeMap=[];
    if ($promotions) {
        $ids=array_map(fn($p)=>(int)$p['id_promocion'],$promotions);
        $r=$conn->query('SELECT * FROM pos_promocion_producto WHERE id_promocion IN ('.implode(',',$ids).') ORDER BY id');
        while($scope=$r->fetch_assoc())$scopeMap[(int)$scope['id_promocion']][]=$scope;
    }

    $applied=[];$rejected=[];$nonCombinableApplied=false;
    $now=new DateTimeImmutable('now',new DateTimeZone('America/Santiago'));
    foreach($promotions as $promo){
        $pid=(int)$promo['id_promocion'];$code=strtoupper(trim((string)$promo['codigo']));$conditions=promotionJson($promo['condiciones_json']);$benefit=promotionJson($promo['beneficio_json']);
        if($nonCombinableApplied){promotionReject($rejected,$promo,'No acumulable con una promoción de mayor prioridad.');continue;}
        if((int)$promo['requiere_codigo']===1&&!isset($couponSet[$code])){continue;}
        if($promo['fecha_inicio']&&$promo['fecha_inicio']>$now->format('Y-m-d')){promotionReject($rejected,$promo,'Aún no comienza.');continue;}
        if($promo['fecha_fin']&&$promo['fecha_fin']<$now->format('Y-m-d')){promotionReject($rejected,$promo,'Promoción vencida.');continue;}
        if($promo['hora_inicio']&&$now->format('H:i:s')<$promo['hora_inicio']){promotionReject($rejected,$promo,'Fuera del horario configurado.');continue;}
        if($promo['hora_fin']&&$now->format('H:i:s')>$promo['hora_fin']){promotionReject($rejected,$promo,'Fuera del horario configurado.');continue;}
        if($promo['dias_semana']){
            $days=array_map('trim',explode(',',strtoupper($promo['dias_semana'])));$day=(string)$now->format('N');
            if(!in_array($day,$days,true)){promotionReject($rejected,$promo,'No aplica el día de hoy.');continue;}
        }
        if((int)$promo['monto_minimo']>0&&$subtotal<(int)$promo['monto_minimo']){promotionReject($rejected,$promo,'No alcanza el monto mínimo.');continue;}
        if($promo['id_sucursal']&&(int)($context['id_sucursal']??0)!==(int)$promo['id_sucursal']){promotionReject($rejected,$promo,'No aplica en esta sucursal.');continue;}
        if($promo['canal']&&strcasecmp((string)$promo['canal'],(string)($context['canal']??'POS'))!==0){promotionReject($rejected,$promo,'No aplica en este canal.');continue;}
        if($promo['segmento_cliente']&&(!$client||!in_array(strtolower((string)$promo['segmento_cliente']),[strtolower((string)$client['categoria']),strtolower((string)$client['clasificacion'])],true))){promotionReject($rejected,$promo,'Cliente fuera del segmento autorizado.');continue;}
        if($promo['lista_precios']&&(!$client||strcasecmp((string)$promo['lista_precios'],(string)$client['lista_precios'])!==0)){promotionReject($rejected,$promo,'Lista de precios no autorizada.');continue;}
        if($clientId&&(int)$promo['max_usos_cliente']>0){
            $q=$conn->prepare('SELECT COUNT(*) n FROM pos_promocion_aplicacion WHERE id_cuenta=? AND id_cliente=? AND id_promocion=?');$q->bind_param('iii',$accountId,$clientId,$pid);$q->execute();$uses=(int)$q->get_result()->fetch_assoc()['n'];$q->close();
            if($uses>=(int)$promo['max_usos_cliente']){promotionReject($rejected,$promo,'El cliente alcanzó el límite de uso.');continue;}
        }

        $scopes=$scopeMap[$pid]??[];$eligible=promotionEligibleIds($cart,$promo,$scopes,'ELEGIBLE');
        $eligibleQty=array_sum(array_map(fn($id)=>(float)$cart[$id]['cantidad'],$eligible));
        $minimum=max(0,(float)$promo['cantidad_minima']);
        if(!$eligible||($minimum>0&&$eligibleQty+0.0001<$minimum)){promotionReject($rejected,$promo,'No alcanza la cantidad mínima de productos elegibles.');continue;}
        $type=promotionCanonicalType((string)$promo['tipo']);$discount=0;$applications=1.0;$benefited=0.0;
        if($type==='CANTIDAD'){
            $x=$minimum>0?$minimum:2.0;$y=(float)($promo['cantidad_pagada']??0);
            if(preg_match('/^(\d+)X(\d+)$/',strtoupper((string)$promo['tipo']),$m)){$x=(float)$m[1];$y=(float)$m[2];}
            if($y<=0)$y=max(1,$x-1);$remainingLimit=(int)$promo['max_aplicaciones_transaccion']>0?(int)$promo['max_aplicaciones_transaccion']:PHP_INT_MAX;
            $applications=0;$benefited=0;
            // Quantity offers never combine different SKUs to complete a pack.
            foreach($eligible as $eligibleId){$lineApps=min(floor((float)$cart[$eligibleId]['cantidad']/$x),$remainingLimit);if($lineApps<1)continue;$lineBenefit=$lineApps*max(0,$x-$y);$discount+=promotionAllocateByQuantity($cart,[$eligibleId],$lineBenefit,$promo,fn($line)=>(float)$line['precio_original']);$applications+=$lineApps;$benefited+=$lineBenefit;$remainingLimit-=$lineApps;if($remainingLimit<=0)break;}
            if($applications<1||$benefited<=0){promotionReject($rejected,$promo,"Requiere al menos {$x} unidades del mismo código o SKU.");continue;}
        }elseif($type==='PORCENTAJE'){
            $pct=max(0,min(100,(float)$promo['valor']));$benefited=$eligibleQty;
            $discount=promotionAllocateByQuantity($cart,$eligible,$benefited,$promo,fn($line)=>$line['precio_original']*$pct/100);
        }elseif($type==='MONTO'){
            $benefited=$eligibleQty;$perUnit=($benefit['por_unidad']??true)?(float)$promo['valor']:((float)$promo['valor']/max(.001,$eligibleQty));
            $discount=promotionAllocateByQuantity($cart,$eligible,$benefited,$promo,fn($line)=>$perUnit);
        }elseif($type==='PRECIO_ESPECIAL'){
            $special=max(0,(float)$promo['valor']);$benefited=$eligibleQty;
            $discount=promotionAllocateByQuantity($cart,$eligible,$benefited,$promo,fn($line)=>max(0,$line['precio_original']-$special));
        }elseif($type==='COMPRA_X_DESCUENTO_Y'){
            $benefitIds=promotionEligibleIds($cart,$promo,$scopes,'BENEFICIO');$x=max(1,$minimum);$applications=floor($eligibleQty/$x);
            if((int)$promo['max_aplicaciones_transaccion']>0)$applications=min($applications,(int)$promo['max_aplicaciones_transaccion']);
            $benefited=$applications*max(1,(float)($promo['cantidad_beneficiada']??1));
            if($applications<1||!$benefitIds){promotionReject($rejected,$promo,'Falta el producto requisito o el producto beneficiado.');continue;}
            $pct=(float)($benefit['porcentaje']??$promo['valor']);
            $discount=promotionAllocateByQuantity($cart,$benefitIds,$benefited,$promo,fn($line)=>$line['precio_original']*max(0,min(100,$pct))/100);
        }elseif($type==='COMBO'){
            $applications=PHP_FLOAT_MAX;
            foreach($scopes as $scope){if(strtoupper((string)$scope['rol'])!=='ELEGIBLE')continue;$matched=array_filter($eligible,fn($id)=>promotionProductMatches($cart[$id],$scope));$qty=array_sum(array_map(fn($id)=>(float)$cart[$id]['cantidad'],$matched));$applications=min($applications,floor($qty/max(.001,(float)$scope['cantidad_minima'])));}
            if($applications===PHP_FLOAT_MAX)$applications=0;
            if((int)$promo['max_aplicaciones_transaccion']>0)$applications=min($applications,(int)$promo['max_aplicaciones_transaccion']);
            if($applications<1){promotionReject($rejected,$promo,'El combo está incompleto.');continue;}
            $target=min((int)round((float)$promo['valor']*$applications),array_sum(array_map(fn($id)=>max(0,$cart[$id]['subtotal_original']-$cart[$id]['descuento']),$eligible)));
            $benefited=$eligibleQty;$remaining=$target;
            foreach($eligible as $id){if($remaining<=0)break;$part=min($remaining,max(0,$cart[$id]['subtotal_original']-$cart[$id]['descuento']));if($part>0){$cart[$id]['descuento']+=$part;$cart[$id]['promociones'][]=['id_promocion'=>$pid,'codigo'=>$promo['codigo'],'nombre'=>$promo['nombre'],'descuento'=>$part];$remaining-=$part;$discount+=$part;}}
        }else{promotionReject($rejected,$promo,'Tipo de promoción no soportado.');continue;}
        if($discount<=0){promotionReject($rejected,$promo,'La regla no generó un beneficio aplicable.');continue;}
        $applied[]=['id_promocion'=>$pid,'codigo'=>$promo['codigo'],'nombre'=>$promo['nombre'],'tipo'=>$type,'descuento'=>$discount,'aplicaciones'=>$applications,'unidades_beneficiadas'=>$benefited,'motivo'=>'Condiciones cumplidas','combinable'=>(int)$promo['combinable']===1];
        if((int)$promo['combinable']!==1)$nonCombinableApplied=true;
    }

    foreach(array_keys($couponSet) as $coupon){
        $known=false;foreach($promotions as $promo)if(strtoupper((string)$promo['codigo'])===$coupon){$known=true;break;}
        if(!$known)$rejected[]=['id_promocion'=>null,'codigo'=>$coupon,'nombre'=>$coupon,'motivo'=>'Cupón inexistente o inactivo.'];
    }
    $lines=[];$totalDiscount=0;
    foreach($cart as $line){$line['descuento']=min((int)$line['descuento'],(int)$line['subtotal_original']);$totalDiscount+=$line['descuento'];$line['subtotal_final']=$line['subtotal_original']-$line['descuento'];$line['precio_final_promedio']=$line['cantidad']>0?(int)round($line['subtotal_final']/$line['cantidad']):0;$lines[]=$line;}
    $total=max(0,$subtotal-$totalDiscount);
    return ['subtotal'=>$subtotal,'descuento'=>$totalDiscount,'total'=>$total,'lineas'=>$lines,'aplicadas'=>$applied,'rechazadas'=>$rejected,'cupones'=>array_keys($couponSet),'firma'=>hash('sha256',json_encode([$accountId,$clientId,$subtotal,$totalDiscount,$applied],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
}

function promotionAudit(mysqli $conn, int $accountId, int $userId, ?int $promotionId, ?int $orderId, ?int $clientId, string $event, string $reason, int $discount, array $context = []): void {
    try {
        $json=json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt=$conn->prepare('INSERT INTO pos_promocion_auditoria(id_cuenta,id_promocion,id_pedido,id_cliente,id_user,evento,motivo,descuento,contexto_json) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('iiiiissis',$accountId,$promotionId,$orderId,$clientId,$userId,$event,$reason,$discount,$json);$stmt->execute();$stmt->close();
    } catch(Throwable $ignored) {}
}
