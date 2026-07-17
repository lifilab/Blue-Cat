<?php

function POST_devolucion_crear_v2($data): void {
    global $conn, $uid, $tenant;

    $orderId=(int)($data->id_pedido??0);
    $reason=trim((string)($data->motivo??''));
    $requested=(array)($data->items??[]);
    if ($orderId<=0 || !$requested) json(['error'=>true,'message'=>'Pedido e ítems son requeridos.'],400);
    if (strlen($reason)<3) json(['error'=>true,'message'=>'Debe indicar el motivo de la devolución.'],400);

    $contextItems=[];
    foreach ($requested as $item) {
        $contextItems[]=[
            'id_detalle_pedido'=>(int)posPaymentValue($item,'id_detalle_pedido',0),
            'id_producto'=>(int)posPaymentValue($item,'id_producto',0),
            'cantidad'=>(float)posPaymentValue($item,'cantidad',0),
        ];
    }
    $ctx=['entidad_tipo'=>'pedido','entidad_id'=>(string)$orderId,'items'=>$contextItems];
    supervisorRequire('pos.devolucion',$ctx,$data->supervisor_token??null);

    $conn->begin_transaction();
    try {
        $stmt=$conn->prepare("SELECT p.* FROM pedido p WHERE p.id_pedido=? AND p.id_cuenta=? AND p.anulado=0 FOR UPDATE");
        $stmt->bind_param('ii',$orderId,$tenant->accountId);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$order) throw new Exception('Pedido no encontrado o anulado.');
        if ((int)$order['devuelto']===1) throw new Exception('El pedido ya fue devuelto completamente.');

        $stmt=$conn->prepare("SELECT dp.*,pr.nombre_producto,pr.tipo_venta,pr.costo_promedio,
            COALESCE(SUM(dd.cantidad),0) cantidad_devuelta,COALESCE(SUM(dd.subtotal),0) monto_devuelto
            FROM detalle_pedido dp JOIN producto pr ON pr.id_producto=dp.id_producto AND pr.id_cuenta=?
            LEFT JOIN pos_devolucion_detalle dd ON dd.id_detalle_pedido=dp.id_detalle_pedido
            WHERE dp.id_pedido=? GROUP BY dp.id_detalle_pedido FOR UPDATE");
        $stmt->bind_param('ii',$tenant->accountId,$orderId);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        if (!$rows) throw new Exception('El pedido no tiene productos para devolver.');
        $byDetail=[];$byProduct=[];
        foreach ($rows as $row) {
            $id=(int)$row['id_detalle_pedido'];$byDetail[$id]=$row;$byProduct[(int)$row['id_producto']][]=$id;
        }

        $quantities=[];
        foreach ($requested as $item) {
            $detailId=(int)posPaymentValue($item,'id_detalle_pedido',0);
            $productId=(int)posPaymentValue($item,'id_producto',0);
            $quantity=(float)posPaymentValue($item,'cantidad',0);
            if ($quantity<=0) throw new Exception('La cantidad a devolver debe ser mayor a cero.');
            $candidates=$detailId>0?[$detailId]:($byProduct[$productId]??[]);
            if (!$candidates) throw new Exception('Un producto solicitado no pertenece al pedido.');
            foreach ($candidates as $candidate) {
                if ($quantity<=0.000001) break;
                if (!isset($byDetail[$candidate])) continue;
                $line=$byDetail[$candidate];
                $already=(float)$line['cantidad_devuelta']+(float)($quantities[$candidate]??0);
                $available=(float)$line['cantidad_pedida']-$already;
                if ($available<=0.000001) continue;
                $take=min($quantity,$available);$quantities[$candidate]=($quantities[$candidate]??0)+$take;$quantity-=$take;
            }
            if ($quantity>0.000001) throw new Exception('La cantidad supera lo vendido menos devoluciones anteriores.');
        }

        $lines=[];$refundTotal=0;$fullyReturned=true;
        foreach ($byDetail as $detailId=>$line) {
            $quantity=(float)($quantities[$detailId]??0);
            $sold=(float)$line['cantidad_pedida'];$previousQty=(float)$line['cantidad_devuelta'];
            if ($line['tipo_venta']==='UNIDAD' && abs($quantity-round($quantity))>0.000001) {
                throw new Exception("{$line['nombre_producto']} solo admite unidades enteras.");
            }
            if ($line['tipo_venta']!=='UNIDAD' && abs($quantity-round($quantity,3))>0.000001) {
                throw new Exception("{$line['nombre_producto']} admite como máximo 3 decimales.");
            }
            if ($quantity>0) {
                $remainingBefore=$sold-$previousQty;
                $remainingAmount=(int)$line['precio_total']-(int)$line['monto_devuelto'];
                $subtotal=abs($quantity-$remainingBefore)<0.000001
                    ? $remainingAmount
                    : (int)round((int)$line['precio_total']*$quantity/$sold);
                if ($subtotal<=0 || $subtotal>$remainingAmount) throw new Exception('No se pudo calcular un monto válido de devolución.');
                $lines[]=['detail_id'=>$detailId,'product_id'=>(int)$line['id_producto'],'quantity'=>$quantity,
                    'unit_price'=>(int)round((int)$line['precio_total']/$sold),'subtotal'=>$subtotal,
                    'sale_type'=>$line['tipo_venta'],'cost'=>(float)$line['costo_promedio']];
                $refundTotal+=$subtotal;
            }
            if ($previousQty+$quantity<$sold-0.000001) $fullyReturned=false;
        }
        if (!$lines || $refundTotal<=0) throw new Exception('No hay cantidades disponibles para devolver.');

        $stmt=$conn->prepare('SELECT COALESCE(SUM(monto_devuelto),0) total FROM pos_devolucion WHERE id_pedido=?');
        $stmt->bind_param('i',$orderId);$stmt->execute();$previousRefund=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
        if ($previousRefund+$refundTotal>(int)$order['precio_total']) throw new Exception('La devolución supera el total pagado.');

        $returnType=$fullyReturned?'TOTAL':'PARCIAL';
        $stmt=$conn->prepare('INSERT INTO pos_devolucion(id_user,id_pedido,tipo,motivo,monto_devuelto) VALUES(?,?,?,?,?)');
        $stmt->bind_param('iissi',$uid,$orderId,$returnType,$reason,$refundTotal);$stmt->execute();$returnId=(int)$conn->insert_id;$stmt->close();
        $warehouseId=(int)$order['id_bodega'];
        foreach ($lines as $line) {
            $stmt=$conn->prepare('INSERT INTO pos_devolucion_detalle(id_devolucion,id_detalle_pedido,id_producto,cantidad,precio_unitario,subtotal) VALUES(?,?,?,?,?,?)');
            $stmt->bind_param('iiidii',$returnId,$line['detail_id'],$line['product_id'],$line['quantity'],$line['unit_price'],$line['subtotal']);$stmt->execute();$stmt->close();
            actualizarStock($conn,$line['product_id'],$warehouseId,'disponible',$line['quantity']);
            actualizarKardex($conn,$uid,$line['product_id'],$warehouseId,'DEVOLUCION',$returnId,'DEVOLUCION',$line['quantity'],0,$line['cost'],"Devolución #{$returnId}, Pedido #{$orderId}");
        }

        $cash=getOpenCaja($conn,$uid);
        if (!$cash) throw new Exception('Debe abrir caja para procesar la devolución.');
        $cashId=(int)$cash['id_caja'];
        $stmt=$conn->prepare('SELECT nombre_metodo_pago,monto FROM metodo_de_pago WHERE id_pedido=?');
        $stmt->bind_param('i',$orderId);$stmt->execute();$payments=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $original=[];
        foreach ($payments as $payment) {
            $method=posCanonicalPaymentMethod($payment['nombre_metodo_pago']);$original[$method]=($original[$method]??0)+(int)$payment['monto'];
        }
        $stmt=$conn->prepare("SELECT metodo,COALESCE(SUM(monto),0) total FROM pos_movimiento_caja WHERE id_pedido=? AND tipo='EGRESO' GROUP BY metodo");
        $stmt->bind_param('i',$orderId);$stmt->execute();$result=$stmt->get_result();$reversed=[];
        while($row=$result->fetch_assoc()){$method=posCanonicalPaymentMethod($row['metodo']);$reversed[$method]=($reversed[$method]??0)+(int)$row['total'];}$stmt->close();
        $capacity=[];
        foreach($original as $method=>$amount){$capacity[$method]=max(0,$amount-(int)($reversed[$method]??0));}
        if(array_sum($capacity)<$refundTotal) throw new Exception('Los pagos originales no cubren la devolución pendiente.');
        $remaining=$refundTotal;$remainingCapacity=array_sum($capacity);$methodCount=count(array_filter($capacity,fn($v)=>$v>0));$used=0;
        foreach($capacity as $method=>$available){
            if($available<=0||$remaining<=0)continue;$used++;
            $amount=$used===$methodCount?$remaining:min($remaining,$available,(int)round($refundTotal*$available/$remainingCapacity));
            if($amount<=0)continue;
            if($method==='EFECTIVO'){
                $stmt=$conn->prepare('UPDATE pos_caja SET monto_actual=monto_actual-? WHERE id_caja=? AND monto_actual-?>=0');
                $stmt->bind_param('iii',$amount,$cashId,$amount);$stmt->execute();$ok=$stmt->affected_rows===1;$stmt->close();
                if(!$ok)throw new Exception('No hay efectivo suficiente en caja para esta devolución.');
            }
            $concept="Devolución #{$returnId} de venta #{$orderId}";
            $stmt=$conn->prepare("INSERT INTO pos_movimiento_caja(id_caja,id_user,tipo,concepto,monto,metodo,id_pedido) VALUES(?,?,'EGRESO',?,?,?,?)");
            $stmt->bind_param('iisisi',$cashId,$uid,$concept,$amount,$method,$orderId);$stmt->execute();$stmt->close();$remaining-=$amount;
        }
        if($remaining!==0)throw new Exception('No se pudo distribuir completamente la devolución.');

        $returnedFlag=$fullyReturned?1:0;
        $stmt=$conn->prepare('UPDATE pedido SET devuelto=? WHERE id_pedido=?');$stmt->bind_param('ii',$returnedFlag,$orderId);$stmt->execute();$stmt->close();
        insertAuditoria($conn,$uid,'devolucion_crear',"Devolución #{$returnId} del Pedido #{$orderId}. Monto: {$refundTotal}",$returnId,'pos_devolucion');
        $conn->commit();
        json(['success'=>true,'id_devolucion'=>$returnId,'tipo'=>$returnType,'monto_devuelto'=>$refundTotal,'pedido_devuelto'=>$fullyReturned]);
    } catch(Throwable $error) {
        $conn->rollback();
        json(['error'=>true,'message'=>$error->getMessage()],400);
    }
}
