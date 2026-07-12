<?php
require_once __DIR__ . '/_db.php';
$id_user = requerirUsuarioAutenticado();
prepararJson();

if (!isset($_FILES['file'])) responderJson(['success'=>false,'msg'=>'No se recibió ningún archivo'],400);
$file=$_FILES['file'];
if ($file['error']!==UPLOAD_ERR_OK) responderJson(['success'=>false,'msg'=>'Error en la subida: código '.$file['error']],400);
$type=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
if (!in_array($type,['csv','xls'],true)) responderJson(['success'=>false,'msg'=>'El archivo debe ser XLS o CSV'],400);

$rows=[];
if ($type==='csv') {
    $fh=fopen($file['tmp_name'],'r');
    if (!$fh) responderJson(['success'=>false,'msg'=>'No se pudo abrir el CSV'],400);
    fgetcsv($fh);
    while (($row=fgetcsv($fh))!==false) $rows[]=$row;
    fclose($fh);
} else {
    $html=file_get_contents($file['tmp_name']);
    $dom=new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html,LIBXML_NOERROR|LIBXML_NOWARNING);
    libxml_clear_errors();
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $cells=[];
        foreach ($tr->getElementsByTagName('td') as $td) $cells[]=trim($td->textContent);
        if ($cells) $rows[]=$cells;
    }
}
if (!$rows) responderJson(['success'=>false,'msg'=>'El archivo no contiene productos'],400);

$conn=conectarBaseDeDatos();
$byCode=$conn->prepare('SELECT id_producto FROM producto WHERE id_user=? AND codigo_de_barras=? LIMIT 1');
$byName=$conn->prepare('SELECT id_producto FROM producto WHERE id_user=? AND nombre_producto=? LIMIT 1');
$update=$conn->prepare('UPDATE producto SET nombre_producto=?,precio_venta=?,codigo_de_barras=?,cantidad=?,categoria=? WHERE id_producto=? AND id_user=?');
$insert=$conn->prepare('INSERT INTO producto (id_user,nombre_producto,precio_venta,codigo_de_barras,cantidad,categoria) VALUES (?,?,?,?,?,?)');
if(!$byCode||!$byName||!$update||!$insert) responderJson(['success'=>false,'msg'=>'Error al preparar la importación'],500);

$insertados=0;$actualizados=0;$errores=0;$total=0;
foreach($rows as $data) {
    $total++;
    if(count($data)<4){$errores++;continue;}
    $nombre=trim((string)$data[0]);
    $precio=normalizarNumeroServidor($data[1]);
    $codigo=trim((string)$data[2]);
    $cantidad=normalizarNumeroServidor($data[3]);
    $categoria=isset($data[4])?trim((string)$data[4]):'';
    if($nombre===''||$precio===null||$cantidad===null){$errores++;continue;}
    if($codigo!==''){$byCode->bind_param('is',$id_user,$codigo);$byCode->execute();$result=$byCode->get_result();}
    else{$byName->bind_param('is',$id_user,$nombre);$byName->execute();$result=$byName->get_result();}
    if($result&&$result->num_rows){
        $id=(int)$result->fetch_assoc()['id_producto'];
        $update->bind_param('sdsdsii',$nombre,$precio,$codigo,$cantidad,$categoria,$id,$id_user);
        $update->execute()?$actualizados++:$errores++;
    } else {
        $insert->bind_param('isdsds',$id_user,$nombre,$precio,$codigo,$cantidad,$categoria);
        $insert->execute()?$insertados++:$errores++;
    }
}
$byCode->close();$byName->close();$update->close();$insert->close();$conn->close();
responderJson(['success'=>true,'msg'=>"Importación completada: $insertados nuevos, $actualizados actualizados, $errores errores de $total filas.",'insertados'=>$insertados,'actualizados'=>$actualizados,'errores'=>$errores,'total'=>$total]);
