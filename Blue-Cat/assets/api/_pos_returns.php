<?php

function POST_devolucion_crear_v2($data): void {
    global $conn, $uid, $tenant;

    $orderId=(int)($data->id_pedido??0);
    $reason=trim((string)($data->motivo??''));
    $requested=(array)($data->items??[]);
    $idempotencyKey=trim((string)($data->idempotency_key??''));
    if ($orderId<=0 || !$requested) json(['error'=>true,'message'=>'Pedido e ítems son requeridos.'],400);
    if (strlen($reason)<3 || strlen($reason)>500) json(['error'=>true,'message'=>'El motivo de la devolución debe tener entre 3 y 500 caracteres.'],400);
    if (!preg_match('/^[A-Za-z0-9._:-]{16,64}$/',$idempotencyKey)) {
        json(['error'=>true,'message'=>'La devolución requiere una clave de idempotencia válida.'],400);
    }

    $requestItems=[];
    foreach ($requested as $item) {
        $requestItems[]=[
            'id_detalle_pedido'=>(int)posPaymentValue($item,'id_detalle_pedido',0),
            'id_producto'=>(int)posPaymentValue($item,'id_producto',0),
            'cantidad'=>round((float)posPaymentValue($item,'cantidad',0),3),
        ];
    }
    usort($requestItems,fn(array $a,array $b)=>[$a['id_detalle_pedido'],$a['id_producto'],$a['cantidad']]<=>[$b['id_detalle_pedido'],$b['id_producto'],$b['cantidad']]);
    $requestHash=hash('sha256',json_encode([
        'id_pedido'=>$orderId,
        'motivo'=>$reason,
        'items'=>$requestItems,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    $conn->begin_transaction();
    try {
        $stmt=$conn->prepare("SELECT p.* FROM pedido p WHERE p.id_pedido=? AND p.id_cuenta=? AND p.anulado=0 FOR UPDATE");
        $stmt->bind_param('ii',$orderId,$tenant->accountId);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$order) throw new Exception('Pedido no encontrado o anulado.');
        $stmt=$conn->prepare('SELECT id_devolucion,id_factura_nota_credito,tipo,monto_devuelto,solicitud_hash FROM pos_devolucion WHERE id_cuenta=? AND clave_idempotencia=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('is',$tenant->accountId,$idempotencyKey);$stmt->execute();$previous=$stmt->get_result()->fetch_assoc();$stmt->close();
        if($previous){
            if(!hash_equals((string)$previous['solicitud_hash'],$requestHash)){
                $conn->rollback();
                json(['error'=>true,'message'=>'La clave de idempotencia ya fue usada con otra devolución.'],409);
            }
            $conn->commit();
            json([
                'success'=>true,
                'id_devolucion'=>(int)$previous['id_devolucion'],
                'tipo'=>$previous['tipo'],
                'monto_devuelto'=>(int)$previous['monto_devuelto'],
                'pedido_devuelto'=>(int)$order['devuelto']===1,
                'id_nota_credito'=>$previous['id_factura_nota_credito'] === null
                    ? null
                    : (int)$previous['id_factura_nota_credito'],
                'idempotent_replay'=>true,
            ]);
        }
        if ((int)$order['devuelto']===1) throw new Exception('El pedido ya fue devuelto completamente.');
        $isInvoiceSale=strtoupper((string)($order['tipo_documento']??''))==='FACTURA';
        $stmt=$conn->prepare("SELECT id_factura FROM factura WHERE id_cuenta=? AND id_pedido=? AND tipo='FACTURA' AND estado<>'ANULADA' LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii',$tenant->accountId,$orderId);$stmt->execute();$activeInvoice=$stmt->get_result()->fetch_assoc();$stmt->close();
        if($isInvoiceSale&&!$activeInvoice) throw new Exception('No se encontró la factura fiscal activa de esta venta.');
        if(!$isInvoiceSale&&$activeInvoice) throw new Exception('La venta tiene una factura activa; emita una nota de crédito fiscal.');

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
            $quantity=normalizarCantidadDecimal($quantity);
            $candidates=$detailId>0?[$detailId]:($byProduct[$productId]??[]);
            if (!$candidates) throw new Exception('Un producto solicitado no pertenece al pedido.');
            if($detailId>0 && $productId>0 && isset($byDetail[$detailId])
                && (int)$byDetail[$detailId]['id_producto']!==$productId){
                throw new Exception('El detalle y el producto solicitado no corresponden.');
            }
            foreach ($candidates as $candidate) {
                if ($quantity<=0.000001) break;
                if (!isset($byDetail[$candidate])) continue;
                $line=$byDetail[$candidate];
                $already=round((float)$line['cantidad_devuelta']+(float)($quantities[$candidate]??0),3);
                $available=round((float)$line['cantidad_pedida']-$already,3);
                if ($available<=0.000001) continue;
                $take=min($quantity,$available);
                $quantities[$candidate]=round((float)($quantities[$candidate]??0)+$take,3);
                $quantity=round($quantity-$take,3);
            }
            if ($quantity>0.000001) throw new Exception('La cantidad supera lo vendido menos devoluciones anteriores.');
        }

        $lines=[];$refundTotal=0;$fullyReturned=true;
        foreach ($byDetail as $detailId=>$line) {
            $quantity=round((float)($quantities[$detailId]??0),3);
            $sold=round((float)$line['cantidad_pedida'],3);$previousQty=round((float)$line['cantidad_devuelta'],3);
            if ($quantity>0) {
                $quantity=posNormalizeStockQuantity($quantity,(string)$line['tipo_venta'],(string)$line['nombre_producto']);
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

        $creditNoteId=null;
        if($isInvoiceSale){
            $requestedNoteId=(int)($data->id_nota_credito??0);
            $requestedByProduct=[];
            foreach($lines as $line){
                $productId=(int)$line['product_id'];
                $requestedByProduct[$productId]=round(
                    (float)($requestedByProduct[$productId]??0)+(float)$line['quantity'],
                    3
                );
            }
            ksort($requestedByProduct,SORT_NUMERIC);
            $originInvoiceId=(int)$activeInvoice['id_factura'];
            $stmt=$conn->prepare(
                "SELECT n.id_factura,n.total
                 FROM factura n
                 LEFT JOIN pos_devolucion d
                   ON d.id_cuenta=n.id_cuenta
                  AND d.id_factura_nota_credito=n.id_factura
                 WHERE n.id_cuenta=? AND n.id_factura_origen=?
                   AND n.tipo='NOTA_CREDITO' AND n.estado<>'ANULADA'
                   AND d.id_devolucion IS NULL
                 ORDER BY n.id_factura
                 FOR UPDATE"
            );
            $stmt->bind_param('ii',$tenant->accountId,$originInvoiceId);
            $stmt->execute();$creditNotes=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach($creditNotes as $creditNote){
                $candidateId=(int)$creditNote['id_factura'];
                if($requestedNoteId>0&&$candidateId!==$requestedNoteId)continue;
                if((int)round((float)$creditNote['total'])!==$refundTotal)continue;
                $stmt=$conn->prepare(
                    'SELECT id_producto,SUM(cantidad) cantidad
                     FROM factura_detalle
                     WHERE id_factura=? AND id_producto IS NOT NULL
                     GROUP BY id_producto ORDER BY id_producto'
                );
                $stmt->bind_param('i',$candidateId);$stmt->execute();$noteByProduct=[];
                foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $noteLine){
                    $noteByProduct[(int)$noteLine['id_producto']]=round((float)$noteLine['cantidad'],3);
                }
                $stmt->close();
                if(count($noteByProduct)!==count($requestedByProduct))continue;
                $sameLines=true;
                foreach($requestedByProduct as $productId=>$quantity){
                    if(!isset($noteByProduct[$productId])
                        ||abs((float)$noteByProduct[$productId]-$quantity)>0.000001){
                        $sameLines=false;
                        break;
                    }
                }
                if($sameLines){$creditNoteId=$candidateId;break;}
            }
            if($creditNoteId===null){
                throw new Exception('Emita primero una nota de crédito activa por los mismos productos, cantidades y monto.');
            }
        }

        $contextItems=array_map(static fn(array $line)=>[
            'id_detalle_pedido'=>$line['detail_id'],
            'id_producto'=>$line['product_id'],
            'cantidad'=>$line['quantity'],
            'monto'=>$line['subtotal'],
        ],$lines);
        $ctx=[
            'entidad_tipo'=>'pedido',
            'entidad_id'=>(string)$orderId,
            'items'=>$contextItems,
            'monto'=>$refundTotal,
            'motivo'=>$reason,
            'id_nota_credito'=>$creditNoteId,
        ];
        supervisorRequire('pos.devolucion',$ctx,$data->supervisor_token??null);

        $stmt=$conn->prepare('SELECT COALESCE(SUM(monto_devuelto),0) total FROM pos_devolucion WHERE id_pedido=?');
        $stmt->bind_param('i',$orderId);$stmt->execute();$previousRefund=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
        if ($previousRefund+$refundTotal>(int)$order['precio_total']) throw new Exception('La devolución supera el total pagado.');

        $returnType=$fullyReturned?'TOTAL':'PARCIAL';
        $stmt=$conn->prepare('INSERT INTO pos_devolucion(id_cuenta,id_user,id_pedido,id_factura_nota_credito,clave_idempotencia,solicitud_hash,tipo,motivo,monto_devuelto) VALUES(?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('iiiissssi',$tenant->accountId,$uid,$orderId,$creditNoteId,$idempotencyKey,$requestHash,$returnType,$reason,$refundTotal);$stmt->execute();$returnId=(int)$conn->insert_id;$stmt->close();
        $warehouseId=(int)$order['id_bodega'];
        foreach ($lines as $line) {
            $stockQuantity=posNormalizeStockQuantity($line['quantity'],$line['sale_type']);
            $stmt=$conn->prepare('INSERT INTO pos_devolucion_detalle(id_devolucion,id_detalle_pedido,id_producto,cantidad,precio_unitario,subtotal) VALUES(?,?,?,?,?,?)');
            $stmt->bind_param('iiidii',$returnId,$line['detail_id'],$line['product_id'],$stockQuantity,$line['unit_price'],$line['subtotal']);$stmt->execute();$stmt->close();
            reponerStock($conn,$line['product_id'],$warehouseId,$stockQuantity);
            actualizarKardex($conn,$uid,$line['product_id'],$warehouseId,'DEVOLUCION',$returnId,'DEVOLUCION',$stockQuantity,0,$line['cost'],"Devolución #{$returnId}, Pedido #{$orderId}");
        }

        $stmt=$conn->prepare("SELECT c.id_caja,c.monto_actual
            FROM pos_caja c
            JOIN pos_caja_fisica f ON f.id_caja_fisica=c.id_caja_fisica AND f.id_cuenta=c.id_cuenta
            WHERE c.id_user=? AND c.id_cuenta=? AND c.estado='ABIERTA'
            ORDER BY c.id_caja DESC LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii',$uid,$tenant->accountId);$stmt->execute();$cash=$stmt->get_result()->fetch_assoc();$stmt->close();
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
                $stmt=$conn->prepare("UPDATE pos_caja SET monto_actual=monto_actual-?
                    WHERE id_caja=? AND id_user=? AND id_cuenta=? AND estado='ABIERTA' AND monto_actual-?>=0");
                $stmt->bind_param('iiiii',$amount,$cashId,$uid,$tenant->accountId,$amount);$stmt->execute();$ok=$stmt->affected_rows===1;$stmt->close();
                if(!$ok)throw new Exception('No hay efectivo suficiente en caja para esta devolución.');
            }
            $concept="Devolución #{$returnId} de venta #{$orderId}";
            $stmt=$conn->prepare("INSERT INTO pos_movimiento_caja(id_caja,id_user,tipo,concepto,monto,metodo,id_pedido) VALUES(?,?,'EGRESO',?,?,?,?)");
            $stmt->bind_param('iisisi',$cashId,$uid,$concept,$amount,$method,$orderId);$stmt->execute();$stmt->close();$remaining-=$amount;
        }
        if($remaining!==0)throw new Exception('No se pudo distribuir completamente la devolución.');

        $returnedFlag=$fullyReturned?1:0;
        $stmt=$conn->prepare('UPDATE pedido SET devuelto=? WHERE id_pedido=?');$stmt->bind_param('ii',$returnedFlag,$orderId);$stmt->execute();$stmt->close();
        $fiscalContext=$creditNoteId===null?'':" NC #{$creditNoteId}.";
        insertAuditoria($conn,$uid,'devolucion_crear',"Devolución #{$returnId} del Pedido #{$orderId}.{$fiscalContext} Monto: {$refundTotal}",$returnId,'pos_devolucion');
        $conn->commit();
        json(['success'=>true,'id_devolucion'=>$returnId,'id_nota_credito'=>$creditNoteId,'tipo'=>$returnType,'monto_devuelto'=>$refundTotal,'pedido_devuelto'=>$fullyReturned]);
    } catch(Throwable $error) {
        $conn->rollback();
        json(['error'=>true,'message'=>$error->getMessage()],400);
    }
}
