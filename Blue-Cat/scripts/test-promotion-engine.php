<?php
declare(strict_types=1);

function promoArg(string $name): ?string {foreach(array_slice($_SERVER['argv'],1) as $arg)if(str_starts_with($arg,$name.'='))return substr($arg,strlen($name)+1);return null;}
$root=dirname(__DIR__);$env=promoArg('--env');if($env){$envPath=preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$env)?$env:$root.'/'.$env;putenv('BLUECAT_ENV_FILE='.$envPath);}
require_once $root.'/assets/api/_db.php';
require_once $root.'/assets/api/_promotion_engine.php';

$db=getDB();$failures=[];
$assert=function(bool $condition,string $message)use(&$failures){echo($condition?'PASS ':'FAIL ').$message."\n";if(!$condition)$failures[]=$message;};
$application=function(array $result,int $promotionId):?array{foreach($result['aplicadas'] as $item)if((int)$item['id_promocion']===$promotionId)return $item;return null;};
$insertPromotion=function(string $code,string $type,float $value,float $minimum=0,array $extra=[])use($db,&$user,&$account):int{
    $name='Prueba '.$code;$paid=$extra['cantidad_pagada']??null;$benefited=$extra['cantidad_beneficiada']??null;$max=$extra['max_aplicaciones']??null;$segment=$extra['segmento']??null;$start=$extra['fecha_inicio']??null;$end=$extra['fecha_fin']??null;
    $stmt=$db->prepare("INSERT INTO pos_promocion(id_user,id_cuenta,codigo,nombre,tipo,valor,cantidad_minima,cantidad_pagada,cantidad_beneficiada,max_aplicaciones_transaccion,segmento_cliente,fecha_inicio,fecha_fin,requiere_codigo,combinable,prioridad,activo,estado) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,9999,1,'ACTIVA')");
    $stmt->bind_param('iisssddddisss',$user,$account,$code,$name,$type,$value,$minimum,$paid,$benefited,$max,$segment,$start,$end);$stmt->execute();$id=(int)$db->insert_id;$stmt->close();return $id;
};
$scope=function(int $promotion,int $product,string $role='ELEGIBLE',float $minimum=1)use($db):void{$stmt=$db->prepare("INSERT INTO pos_promocion_producto(id_promocion,id_producto,rol,codigo_producto,sku,cantidad_minima) SELECT ?,id_producto,?,codigo_de_barras,sku,? FROM producto WHERE id_producto=?");$stmt->bind_param('isdi',$promotion,$role,$minimum,$product);$stmt->execute();$stmt->close();};
$evaluate=function(int $promotion,string $code,array $items,?int $client=null)use($db,&$account,&$user,$application):array{$result=promotionEvaluate($db,$account,$user,$items,$client,[$code],['canal'=>'POS']);return [$result,$application($result,$promotion)];};

$db->begin_transaction();
try{
    $db->query("INSERT INTO cuenta(nombre,estado) VALUES('Promotion Engine Test','ACTIVA')");$account=(int)$db->insert_id;
    $hash=password_hash('Promotion-Test-2026',PASSWORD_DEFAULT);$stmt=$db->prepare("INSERT INTO usuario(id_cuenta,nombre,correo,password,activo,validar_sesion) VALUES(?,'promo-test','promo-test@local',?,1,0)");$stmt->bind_param('is',$account,$hash);$stmt->execute();$user=(int)$db->insert_id;$stmt->close();
    $stmt=$db->prepare("INSERT INTO producto(id_user,id_cuenta,nombre_producto,precio_venta,codigo_de_barras,sku,activo) VALUES(?,?,?, ?,?,?,1)");
    $name='Producto A';$price=1000;$barcode='PROMO-A';$sku='SKU-A';$stmt->bind_param('iisiss',$user,$account,$name,$price,$barcode,$sku);$stmt->execute();$productA=(int)$db->insert_id;
    $name='Producto B';$price=2000;$barcode='PROMO-B';$sku='SKU-B';$stmt->bind_param('iisiss',$user,$account,$name,$price,$barcode,$sku);$stmt->execute();$productB=(int)$db->insert_id;$stmt->close();
    $stmt=$db->prepare("INSERT INTO cliente(id_user,id_cuenta,codigo,nombre,razon_social,categoria,clasificacion,lista_precios,activo) VALUES(?,?,'PROMO-CLIENT','Cliente VIP','Cliente VIP','VIP','VIP','MAYORISTA',1)");$stmt->bind_param('ii',$user,$account);$stmt->execute();$client=(int)$db->insert_id;$stmt->close();

    $p=$insertPromotion('T-2X1','2X1',0,2,['cantidad_pagada'=>1]);$scope($p,$productA);
    [$one,$app]=$evaluate($p,'T-2X1',[['id_producto'=>$productA,'cantidad'=>1]]);$assert($app===null,'2x1 no beneficia una unidad');
    [$two,$app]=$evaluate($p,'T-2X1',[['id_producto'=>$productA,'cantidad'=>1],['id_producto'=>$productA,'cantidad'=>1]]);$assert($app&&(int)$app['descuento']===1000&&(int)$two['total']===1000,'2x1 acumula líneas del mismo SKU');

    $p=$insertPromotion('T-3X2','3X2',0,3,['cantidad_pagada'=>2]);$scope($p,$productA);[$r,$app]=$evaluate($p,'T-3X2',[['id_producto'=>$productA,'cantidad'=>3]]);$assert($app&&(int)$app['descuento']===1000,'3x2 descuenta una de tres unidades');
    $p=$insertPromotion('T-PCT','DESCUENTO_PCT',10,1);$scope($p,$productA);[$r,$app]=$evaluate($p,'T-PCT',[['id_producto'=>$productA,'cantidad'=>1]]);$assert($app&&(int)$app['descuento']===100,'porcentaje se aplica desde la primera unidad');
    $p=$insertPromotion('T-FIX','DESCUENTO_MONTO',150,1);$scope($p,$productA);[$r,$app]=$evaluate($p,'T-FIX',[['id_producto'=>$productA,'cantidad'=>1]]);$assert($app&&(int)$app['descuento']===150,'monto fijo se aplica al producto elegible');
    $p=$insertPromotion('T-SPECIAL','PRECIO_ESPECIAL',700,1);$scope($p,$productA);[$r,$app]=$evaluate($p,'T-SPECIAL',[['id_producto'=>$productA,'cantidad'=>1]]);$assert($app&&(int)$app['descuento']===300&&(int)$r['total']===700,'precio especial resta la diferencia correcta');

    $p=$insertPromotion('T-BUYGET','COMPRA_X_DESCUENTO_Y',50,1,['cantidad_beneficiada'=>1]);$scope($p,$productA,'ELEGIBLE');$scope($p,$productB,'BENEFICIO');[$r,$app]=$evaluate($p,'T-BUYGET',[['id_producto'=>$productA,'cantidad'=>1],['id_producto'=>$productB,'cantidad'=>1]]);$assert($app&&(int)$app['descuento']===1000,'compra X aplica porcentaje al producto Y');
    $p=$insertPromotion('T-COMBO','COMBO',500,0);$scope($p,$productA,'ELEGIBLE',1);$scope($p,$productB,'ELEGIBLE',1);[$r,$app]=$evaluate($p,'T-COMBO',[['id_producto'=>$productA,'cantidad'=>1],['id_producto'=>$productB,'cantidad'=>1]]);$assert($app&&(int)$app['descuento']===500,'combo exige todos sus productos y descuenta el monto configurado');

    $p=$insertPromotion('T-CAP','2X1',0,2,['cantidad_pagada'=>1,'max_aplicaciones'=>1]);$scope($p,$productA);[$r,$app]=$evaluate($p,'T-CAP',[['id_producto'=>$productA,'cantidad'=>4]]);$assert($app&&(int)$app['descuento']===1000,'límite por transacción evita duplicar aplicaciones');
    $p=$insertPromotion('T-EXPIRED','DESCUENTO_PCT',50,1,['fecha_inicio'=>'2020-01-01','fecha_fin'=>'2020-01-02']);$scope($p,$productA);[$r,$app]=$evaluate($p,'T-EXPIRED',[['id_producto'=>$productA,'cantidad'=>1]]);$assert($app===null,'promoción vencida es rechazada');
    $p=$insertPromotion('T-SEGMENT','DESCUENTO_PCT',10,1,['segmento'=>'VIP']);$scope($p,$productA);[$r,$app]=$evaluate($p,'T-SEGMENT',[['id_producto'=>$productA,'cantidad'=>1]],$client);$assert($app&&(int)$app['descuento']===100,'segmentación de cliente habilita la promoción correcta');
    [$r,$app]=$evaluate($p,'T-SEGMENT',[['id_producto'=>$productA,'cantidad'=>1]],null);$assert($app===null,'segmentación rechaza consumidor no autorizado');

    $db->rollback();
}catch(Throwable $e){$db->rollback();fwrite(STDERR,'FAIL '.$e->getMessage().' at line '.$e->getLine()."\n");exit(1);}
exit($failures?1:0);
