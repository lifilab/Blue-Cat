<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();
requirePermission('inventario','importar');
$conn = getDB();
$accountId = tenantContext($uid)->accountId;


function importarNumero($valor) {
    if ($valor === null || $valor === '') return null;
    $normalizado = trim((string)$valor);
    if (str_contains($normalizado, ',') && str_contains($normalizado, '.')) $normalizado = str_replace(['.', ','], ['', '.'], $normalizado);
    elseif (str_contains($normalizado, ',')) $normalizado = str_replace(',', '.', $normalizado);
    return is_numeric($normalizado) ? (float)$normalizado : null;
}

if (!isset($_FILES['file'])) json(['success'=>false,'msg'=>'No se recibió ningún archivo'],400);
$file=$_FILES['file'];
if ($file['error']!==UPLOAD_ERR_OK) json(['success'=>false,'msg'=>'Error en la subida: código '.$file['error']],400);
if (!is_uploaded_file($file['tmp_name'])) json(['success'=>false,'msg'=>'Carga de archivo no válida'],400);
if ((int)$file['size']<=0 || (int)$file['size']>5*1024*1024) json(['success'=>false,'msg'=>'El archivo debe pesar entre 1 byte y 5 MB'],400);
$type=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
if (!in_array($type,['csv','xls'],true)) json(['success'=>false,'msg'=>'El archivo debe ser XLS o CSV'],400);
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])?:'';
$allowedMime=$type==='csv'
    ? ['text/plain','text/csv','application/csv','application/vnd.ms-excel']
    : ['text/html','text/plain','application/vnd.ms-excel'];
if (!in_array($mime,$allowedMime,true)) json(['success'=>false,'msg'=>'El contenido no coincide con el formato indicado'],400);

$rows=[];
if ($type==='csv') {
    $fh=fopen($file['tmp_name'],'r');
    if (!$fh) json(['success'=>false,'msg'=>'No se pudo abrir el CSV'],400);
    fgetcsv($fh);
    while (($row=fgetcsv($fh))!==false) { $rows[]=$row; if(count($rows)>50000) json(['success'=>false,'msg'=>'El archivo supera 50.000 filas'],400); }
    fclose($fh);
} else {
    $html=file_get_contents($file['tmp_name']);
    $dom=new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html,LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_NONET);
    libxml_clear_errors();
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $cells=[];
        foreach ($tr->getElementsByTagName('td') as $td) $cells[]=trim($td->textContent);
        if ($cells) { $rows[]=$cells; if(count($rows)>50000) json(['success'=>false,'msg'=>'El archivo supera 50.000 filas'],400); }
    }
}
if (!$rows) json(['success'=>false,'msg'=>'El archivo no contiene productos'],400);

$byCode=$conn->prepare('SELECT id_producto FROM producto WHERE id_cuenta=? AND codigo_de_barras=? LIMIT 1');
$byName=$conn->prepare('SELECT id_producto FROM producto WHERE id_cuenta=? AND nombre_producto=? LIMIT 1');
$update=$conn->prepare('UPDATE producto SET nombre_producto=?,precio_venta=?,codigo_de_barras=?,cantidad=?,categoria=? WHERE id_producto=? AND id_cuenta=?');
$insert=$conn->prepare('INSERT INTO producto (id_user,id_cuenta,nombre_producto,precio_venta,codigo_de_barras,cantidad,categoria) VALUES (?,?,?,?,?,?,?)');
if(!$byCode||!$byName||!$update||!$insert) json(['success'=>false,'msg'=>'Error al preparar la importación'],500);

$insertados=0;$actualizados=0;$errores=0;$total=0;
foreach($rows as $data) {
    $total++;
    if(count($data)<4){$errores++;continue;}
    $nombre=trim((string)$data[0]);
    $precio=importarNumero($data[1]);
    $codigo=trim((string)$data[2]);
    $cantidad=importarNumero($data[3]);
    $categoria=isset($data[4])?trim((string)$data[4]):'';
    if($nombre===''||mb_strlen($nombre)>200||mb_strlen($codigo)>100||mb_strlen($categoria)>100||$precio===null||$precio<0||$precio>2000000000||$cantidad===null||$cantidad<0||$cantidad>1000000000){$errores++;continue;}
    if($codigo!==''){$byCode->bind_param('is',$accountId,$codigo);$byCode->execute();$result=$byCode->get_result();}
    else{$byName->bind_param('is',$accountId,$nombre);$byName->execute();$result=$byName->get_result();}
    if($result&&$result->num_rows){
        $id=(int)$result->fetch_assoc()['id_producto'];
        $update->bind_param('sdsdsii',$nombre,$precio,$codigo,$cantidad,$categoria,$id,$accountId);
        $update->execute()?$actualizados++:$errores++;
    } else {
        $insert->bind_param('iisdsds',$uid,$accountId,$nombre,$precio,$codigo,$cantidad,$categoria);
        $insert->execute()?$insertados++:$errores++;
    }
}
$byCode->close();$byName->close();$update->close();$insert->close();$conn->close();
json(['success'=>true,'msg'=>"Importación completada: $insertados nuevos, $actualizados actualizados, $errores errores de $total filas.",'insertados'=>$insertados,'actualizados'=>$actualizados,'errores'=>$errores,'total'=>$total]);
