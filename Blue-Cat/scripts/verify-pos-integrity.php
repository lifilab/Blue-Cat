<?php
declare(strict_types=1);

$root=dirname(__DIR__);$envFile=$root.'/.env';
foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--env=')){$candidate=substr($arg,6);$envFile=preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$candidate)?$candidate:$root.'/'.$candidate;}
if(is_file($envFile))foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){$line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$key,$value]=array_map('trim',explode('=',$line,2));$_ENV[$key]=trim($value,"\"'");}
$value=fn(string $key,$default='')=>$_ENV[$key]??(getenv($key)!==false?getenv($key):$default);
if($value('DB_NAME')===''){fwrite(STDERR,"Falta la configuración de base de datos.\n");exit(2);}
$db=new mysqli($value('DB_HOST','127.0.0.1'),$value('DB_USER'),$value('DB_PASSWORD'),$value('DB_NAME'),(int)$value('DB_PORT',3306));$db->set_charset('utf8mb4');

$checks=[
    'pedidos con pago aplicado distinto del total' => "SELECT COUNT(*) FROM pedido WHERE pago_total<>precio_total OR diferencia<>0 OR vuelto<0",
    'stock disponible negativo' => "SELECT COUNT(*) FROM stock WHERE disponible<0",
    'cajas sin identidad física' => "SELECT COUNT(*) FROM pos_caja WHERE id_caja_fisica IS NULL",
    'folios POS duplicados' => "SELECT COUNT(*) FROM (SELECT 1 FROM pedido WHERE folio IS NOT NULL GROUP BY id_cuenta,tipo_documento,folio HAVING COUNT(*)>1) x",
    'idempotencias cruzadas de cuenta' => "SELECT COUNT(*) FROM pos_venta_idempotencia i JOIN pedido p ON p.id_pedido=i.id_pedido WHERE i.id_cuenta<>p.id_cuenta",
    'ventas idempotentes sin documento reimprimible' => "SELECT COUNT(*) FROM pos_venta_idempotencia i LEFT JOIN pos_documento_snapshot d ON d.id_pedido=i.id_pedido WHERE i.estado='COMPLETADA' AND d.id_pedido IS NULL",
    'métodos de pago no canónicos' => "SELECT COUNT(*) FROM metodo_de_pago WHERE nombre_metodo_pago NOT IN ('EFECTIVO','TARJETA_CREDITO','TARJETA_DEBITO','TRANSFERENCIA','OTRO')",
    'cajas abiertas distintas de su libro efectivo' => "SELECT COUNT(*) FROM pos_caja c WHERE c.estado='ABIERTA' AND c.monto_actual<>(SELECT COALESCE(SUM(CASE WHEN m.tipo IN ('APERTURA','INGRESO') THEN m.monto WHEN m.tipo='EGRESO' THEN -m.monto ELSE 0 END),0) FROM pos_movimiento_caja m WHERE m.id_caja=c.id_caja AND m.tipo<>'CIERRE' AND m.metodo='EFECTIVO')",
    'cotizaciones convertidas sin pedido' => "SELECT COUNT(*) FROM pos_cotizacion WHERE convertida=1 AND id_pedido IS NULL",
    'cantidades devueltas en exceso' => "SELECT COUNT(*) FROM (SELECT dp.id_detalle_pedido,dp.cantidad_pedida,COALESCE(SUM(dd.cantidad),0) returned FROM detalle_pedido dp LEFT JOIN pos_devolucion_detalle dd ON dd.id_detalle_pedido=dp.id_detalle_pedido GROUP BY dp.id_detalle_pedido HAVING returned>dp.cantidad_pedida+0.000001) x",
];
$failed=false;
foreach($checks as $label=>$sql){$result=$db->query($sql);$count=(int)$result->fetch_row()[0];echo ($count===0?'PASS':'FAIL')." {$label}: {$count}\n";if($count!==0)$failed=true;}
exit($failed?1:0);
