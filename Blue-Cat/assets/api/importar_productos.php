<?php
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_xlsx.php';
$uid = requireUser();
requireModuleEntitlement('inventario');
requirePermission('inventario','importar');
requirePermission('inventario','precios');
requirePermission('inventario','ver_costos');
$conn = getDB();
$accountId = tenantContext($uid)->accountId;

const IMPORTAR_PRECIO_MAXIMO = 99999999.99;
const IMPORTAR_MAX_FILAS = 5000;

function importarNumero($valor) {
    if ($valor === null || $valor === '') return null;
    $normalizado = trim((string)$valor);
    if (str_contains($normalizado, ',') && str_contains($normalizado, '.')) $normalizado = str_replace(['.', ','], ['', '.'], $normalizado);
    elseif (str_contains($normalizado, ',')) $normalizado = str_replace(',', '.', $normalizado);
    return is_numeric($normalizado) ? (float)$normalizado : null;
}

function importarRegistrarError(array &$details, int $rowNumber, string $reason): void {
    if (count($details) >= 20) return;
    $reason = trim((string)(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', strip_tags($reason)) ?? ''));
    if ($reason === '') $reason = 'No se pudo procesar la fila';
    $details[] = ['fila'=>$rowNumber, 'motivo'=>mb_substr($reason, 0, 240)];
}

function importarNormalizarCabecera($value): string {
    $text = ltrim(trim((string)$value), "\xEF\xBB\xBF");
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return trim((string)(preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? ''));
}

function importarCabeceraValida(array $header): bool {
    $normalized = array_map('importarNormalizarCabecera', array_values($header));
    $canonical = ['nombre','precio','codigo','cantidad','categoria','sku','costo','tipo venta','unidad','activo'];
    $previous = ['nombre','precio venta','codigo de barras','cantidad','categoria','sku','precio costo','tipo venta','unidad','activo'];
    if (count($normalized) === 10) return $normalized === $canonical || $normalized === $previous;
    if (count($normalized) === 4) return $normalized === array_slice($canonical,0,4) || $normalized === array_slice($previous,0,4);
    return false;
}

function importarAuditar(mysqli $conn, int $uid, array $detail): void {
    try {
        $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $action = 'IMPORTACION_PRODUCTOS';
        $entity = 'producto';
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $stmt = $conn->prepare('INSERT INTO inventario_auditoria(id_user,accion,entidad,detalle,ip) VALUES (?,?,?,?,?)');
        $stmt->bind_param('issss', $uid, $action, $entity, $encoded, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('importacion_productos_auditoria: ' . $error->getMessage());
    }
}

function importarBuscarUnico(mysqli_stmt $stmt, int $accountId, string $value, string $fieldLabel): ?array {
    $stmt->bind_param('is',$accountId,$value);
    $stmt->execute();
    $matches=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if(count($matches)>1) throw new InvalidArgumentException("Hay más de un producto coincidente por {$fieldLabel}; corrija el catálogo antes de importar");
    return $matches[0]??null;
}

function importarBuscarIdentificador(mysqli_stmt $stmt, int $accountId, string $value, string $fieldLabel): ?array {
    $stmt->bind_param('iss',$accountId,$value,$value);
    $stmt->execute();
    $matches=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if(count($matches)>1) throw new InvalidArgumentException("El {$fieldLabel} coincide con más de un producto entre código y SKU; concilie el catálogo");
    return $matches[0]??null;
}

function importarEsCantidadEntera(mixed $valor): bool {
    $numero = (float)$valor;
    return is_finite($numero) && abs($numero - round($numero)) <= 0.000001;
}

function importarAjustarStock(mysqli $conn, int $uid, int $productId, int $warehouseId, float $target, float $cost): void {
    $stmt = $conn->prepare("SELECT s.disponible,s.reservado,s.comprometido,s.bloqueado FROM stock s WHERE s.id_producto=? AND s.id_bodega=? ORDER BY s.id_ubicacion IS NULL DESC,s.id_stock FOR UPDATE");
    $stmt->bind_param('ii', $productId, $warehouseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $current = 0.0;
    $protected = 0.0;
    foreach ($rows as $row) {
        $current = round($current + max(0.0, (float)($row['disponible'] ?? 0)), 3);
        $protected = round($protected
            + max(0.0, (float)($row['reservado'] ?? 0))
            + max(0.0, (float)($row['comprometido'] ?? 0))
            + max(0.0, (float)($row['bloqueado'] ?? 0)), 3);
    }

    $difference = round($target - $current, 3);
    if (abs($difference) <= 0.000001) {
        sincronizarCantidadProducto($conn, $productId);
        return;
    }

    if ($difference > 0) {
        actualizarStock($conn, $productId, $warehouseId, 'disponible', $difference);
        actualizarKardex($conn, $uid, $productId, $warehouseId, 'IMPORTACION', 0, 'IMPORTACION', $difference, 0, $cost, 'Ajuste de stock por importación de productos');
        return;
    }

    if ($target + 0.000001 < $protected) {
        throw new DomainException('La cantidad importada es menor que el stock reservado, comprometido o bloqueado');
    }
    $deduction = -$difference;
    actualizarStock($conn, $productId, $warehouseId, 'disponible', -$deduction, true);
    actualizarKardex($conn, $uid, $productId, $warehouseId, 'IMPORTACION', 0, 'IMPORTACION', 0, $deduction, $cost, 'Ajuste de stock por importación de productos');
}

$documentReference = trim((string)($_POST['referencia_documento'] ?? ''));
if ($documentReference === '') json(['success'=>false,'msg'=>'Debe indicar el número de factura, guía u otro documento de respaldo'],400);
if (mb_strlen($documentReference)>120||preg_match('/[\x00-\x1F\x7F]/u',$documentReference)) json(['success'=>false,'msg'=>'La referencia del documento no es válida'],400);

$warehouseId = (int)($_POST['id_bodega'] ?? 0);
if ($warehouseId <= 0) json(['success'=>false,'msg'=>'Debe seleccionar una bodega activa para importar'],400);
$warehouseScope = $conn->prepare("SELECT id_bodega FROM bodega WHERE id_bodega=? AND id_cuenta=? AND estado='ACTIVA' LIMIT 1");
if (!$warehouseScope) json(['success'=>false,'msg'=>'No se pudo validar la bodega de importación'],500);
$warehouseScope->bind_param('ii', $warehouseId, $accountId);
$warehouseScope->execute();
$validWarehouse = (bool)$warehouseScope->get_result()->fetch_row();
$warehouseScope->close();
if (!$validWarehouse) json(['success'=>false,'msg'=>'La bodega seleccionada no existe, está inactiva o no pertenece a su cuenta'],400);

if (!isset($_FILES['file'])) json(['success'=>false,'msg'=>'No se recibió ningún archivo'],400);
$file=$_FILES['file'];
if ($file['error']!==UPLOAD_ERR_OK) json(['success'=>false,'msg'=>'Error en la subida: código '.$file['error']],400);
if (!is_uploaded_file($file['tmp_name'])) json(['success'=>false,'msg'=>'Carga de archivo no válida'],400);
if ((int)$file['size']<=0 || (int)$file['size']>5*1024*1024) json(['success'=>false,'msg'=>'El archivo debe pesar entre 1 byte y 5 MB'],400);
$fileHash = hash_file('sha256', $file['tmp_name']);
$safeFileName = mb_substr(basename(str_replace('\\','/',(string)$file['name'])), 0, 180);
$type=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
if (!in_array($type,['csv','xls','xlsx'],true)) json(['success'=>false,'msg'=>'El archivo debe ser XLSX, XLS compatible o CSV'],400);
$fileSignature=(string)file_get_contents($file['tmp_name'],false,null,0,8);
if ($type==='xls'&&$fileSignature==="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
    json(['success'=>false,'msg'=>'El formato XLS binario heredado no es compatible. Guarde el archivo como XLSX o CSV.'],400);
}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])?:'';
$allowedMime=match($type) {
    'csv' => ['text/plain','text/csv','application/csv','application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'],
    default => ['text/html','text/plain','application/vnd.ms-excel'],
};
if (!in_array($mime,$allowedMime,true)) json(['success'=>false,'msg'=>'El contenido no coincide con el formato indicado'],400);

$header=null;$rows=[];
if ($type==='csv') {
    $fh=fopen($file['tmp_name'],'r');
    if (!$fh) json(['success'=>false,'msg'=>'No se pudo abrir el CSV'],400);
    $header=fgetcsv($fh);
    while (($row=fgetcsv($fh))!==false) { $rows[]=$row; if(count($rows)>IMPORTAR_MAX_FILAS) json(['success'=>false,'msg'=>'El archivo supera 5.000 filas'],400); }
    fclose($fh);
} elseif ($type==='xlsx') {
    try {
        $rows=bluecatXlsxReadRows($file['tmp_name'],BLUECAT_XLSX_MAX_ROWS,64);
    } catch (Throwable $error) {
        $xlsxReason=($error instanceof InvalidArgumentException||$error instanceof RuntimeException)
            ? $error->getMessage()
            : 'estructura no válida';
        json(['success'=>false,'msg'=>'No se pudo leer el XLSX: '.$xlsxReason],400);
    }
    if ($rows) $header=array_shift($rows);
    if (count($rows)>IMPORTAR_MAX_FILAS) json(['success'=>false,'msg'=>'El archivo supera 5.000 filas'],400);
} else {
    $html=file_get_contents($file['tmp_name']);
    if (!is_string($html)||stripos($html,'<table')===false) json(['success'=>false,'msg'=>'El XLS compatible debe contener una tabla HTML válida'],400);
    $htmlWithoutSafeDoctype=preg_replace('/<!DOCTYPE\s+html\s*>/i','',$html);
    if (!is_string($htmlWithoutSafeDoctype)||stripos($htmlWithoutSafeDoctype,'<!DOCTYPE')!==false||stripos($html,'<!ENTITY')!==false) json(['success'=>false,'msg'=>'El XLS compatible contiene declaraciones no permitidas'],400);
    $dom=new DOMDocument();
    libxml_use_internal_errors(true);
    $loaded=$dom->loadHTML($html,LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_NONET);
    libxml_clear_errors();
    if (!$loaded) json(['success'=>false,'msg'=>'No se pudo leer la tabla del XLS compatible'],400);
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        $headings=[];$cells=[];
        foreach ($tr->getElementsByTagName('th') as $th) $headings[]=trim($th->textContent);
        foreach ($tr->getElementsByTagName('td') as $td) $cells[]=trim($td->textContent);
        if ($header===null&&$headings) {$header=$headings;continue;}
        if ($header===null&&$cells) {$header=$cells;continue;}
        if ($cells) { $rows[]=$cells; if(count($rows)>IMPORTAR_MAX_FILAS) json(['success'=>false,'msg'=>'El archivo supera 5.000 filas'],400); }
    }
}
if (!is_array($header)||!importarCabeceraValida($header)) json(['success'=>false,'msg'=>'La cabecera no coincide con las 10 columnas canónicas ni con el formato legacy de 4 columnas'],400);
if (!$rows) json(['success'=>false,'msg'=>'El archivo no contiene productos'],400);

$duplicateFileRows=[];
$seenFileIdentifiers=[];
foreach($rows as $rowIndex=>$data){
    if(!is_array($data)) continue;
    $code=mb_strtolower(trim((string)($data[2]??'')),'UTF-8');
    $sku=mb_strtolower(trim((string)($data[5]??'')),'UTF-8');
    $name=mb_strtolower(trim((string)($data[0]??'')),'UTF-8');
    $keys=[];
    if($code!=='') $keys[]='identificador:'.$code;
    if($sku!=='') $keys[]='identificador:'.$sku;
    if(!$keys&&$name!=='') $keys[]='nombre:'.$name;
    foreach($keys as $key){
        if(isset($seenFileIdentifiers[$key])){
            $duplicateFileRows[$rowIndex]=true;
            $duplicateFileRows[$seenFileIdentifiers[$key]]=true;
        }else{
            $seenFileIdentifiers[$key]=$rowIndex;
        }
    }
}

$productFields='id_producto,tipo_venta,sku,precio_costo,costo_promedio,ultimo_costo,activo,id_unidad,id_categoria,categoria,cantidad,(SELECT tipo FROM unidad_medida um WHERE um.id_unidad=producto.id_unidad) AS unidad_tipo';
$byIdentifier=$conn->prepare("SELECT {$productFields} FROM producto WHERE id_cuenta=? AND (codigo_de_barras=? OR sku=?) ORDER BY activo DESC,id_producto FOR UPDATE");
$byName=$conn->prepare("SELECT {$productFields} FROM producto WHERE id_cuenta=? AND nombre_producto=? ORDER BY id_producto FOR UPDATE");
$lockWarehouse=$conn->prepare("SELECT id_bodega FROM bodega WHERE id_bodega=? AND id_cuenta=? AND estado='ACTIVA' LIMIT 1 FOR UPDATE");
$lockProductStock=$conn->prepare('SELECT disponible,reservado,comprometido,bloqueado FROM stock WHERE id_producto=? FOR UPDATE');
$findUnit=$conn->prepare('SELECT id_unidad,tipo FROM unidad_medida WHERE LOWER(abreviatura)=LOWER(?) OR LOWER(nombre)=LOWER(?) ORDER BY id_unidad');
$findCategory=$conn->prepare('SELECT id_categoria FROM categoria WHERE id_cuenta=? AND nombre=? ORDER BY id_categoria LIMIT 1');
$createCategory=$conn->prepare('INSERT INTO categoria(id_user,id_cuenta,nombre,descripcion,activo) VALUES (?,?,?,NULL,1)');
$update=$conn->prepare('UPDATE producto SET nombre_producto=?,precio_venta=?,codigo_de_barras=?,categoria=?,id_categoria=?,sku=?,precio_costo=?,costo_promedio=?,ultimo_costo=?,tipo_venta=?,id_unidad=?,activo=? WHERE id_producto=? AND id_cuenta=?');
$insert=$conn->prepare('INSERT INTO producto (id_user,id_cuenta,nombre_producto,precio_venta,codigo_de_barras,categoria,id_categoria,sku,precio_costo,costo_promedio,ultimo_costo,tipo_venta,id_unidad,activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
if(!$byIdentifier||!$byName||!$lockWarehouse||!$lockProductStock||!$findUnit||!$findCategory||!$createCategory||!$update||!$insert) json(['success'=>false,'msg'=>'Error al preparar la importación'],500);

$insertados=0;$actualizados=0;$errores=0;$total=0;$detalleErrores=[];
foreach($rows as $rowIndex=>$data) {
    $total++;
    $fileRow=(int)$rowIndex+2;
    if(isset($duplicateFileRows[$rowIndex])){$errores++;importarRegistrarError($detalleErrores,$fileRow,'El archivo repite el mismo código, SKU o nombre sin identificadores');continue;}
    if(count($data)<4){$errores++;importarRegistrarError($detalleErrores,$fileRow,'Faltan columnas obligatorias: nombre, precio, código y cantidad');continue;}
    $nombre=trim((string)$data[0]);
    $precio=importarNumero($data[1]);
    $codigo=trim((string)$data[2]);
    $codigoDb=$codigo!==''?$codigo:null;
    $cantidad=importarNumero($data[3]);
    $hasCategory=array_key_exists(4,$data);
    $hasSku=array_key_exists(5,$data);
    $hasCost=array_key_exists(6,$data)&&trim((string)$data[6])!=='';
    $hasSaleType=array_key_exists(7,$data)&&trim((string)$data[7])!=='';
    $hasUnit=array_key_exists(8,$data)&&trim((string)$data[8])!=='';
    $hasActive=array_key_exists(9,$data)&&trim((string)$data[9])!=='';
    $importedCategory=$hasCategory?trim((string)$data[4]):null;
    $importedSku=$hasSku?trim((string)$data[5]):null;
    $importedCost=$hasCost?importarNumero($data[6]):null;
    $importedSaleType=$hasSaleType?strtoupper(trim((string)$data[7])):null;
    $importedUnit=$hasUnit?trim((string)$data[8]):null;
    $activeText=$hasActive?mb_strtolower(trim((string)$data[9])):null;
    $validationError=match(true){
        $nombre==='' => 'El nombre es obligatorio',
        mb_strlen($nombre)>100 => 'El nombre supera 100 caracteres',
        mb_strlen($codigo)>30 => 'El código de barras supera 30 caracteres',
        $importedCategory!==null&&mb_strlen($importedCategory)>100 => 'La categoría supera 100 caracteres',
        $importedSku!==null&&mb_strlen($importedSku)>50 => 'El SKU supera 50 caracteres',
        $precio===null => 'El precio de venta no es numérico',
        $precio<0 => 'El precio de venta no puede ser negativo',
        $precio>IMPORTAR_PRECIO_MAXIMO => 'El precio de venta supera el máximo de 99.999.999,99',
        $hasCost&&$importedCost===null => 'El costo no es numérico',
        $importedCost!==null&&$importedCost<0 => 'El costo no puede ser negativo',
        $importedCost!==null&&$importedCost>IMPORTAR_PRECIO_MAXIMO => 'El costo supera el máximo de 99.999.999,99',
        $cantidad===null => 'La cantidad no es numérica',
        $cantidad<0 => 'La cantidad no puede ser negativa',
        $cantidad>1000000000 => 'La cantidad supera el máximo permitido',
        default => null,
    };
    if($validationError!==null){$errores++;importarRegistrarError($detalleErrores,$fileRow,$validationError);continue;}

    try {
        $quantity=normalizarCantidadDecimal((float)$cantidad,true);
        $conn->begin_transaction();
        $lockWarehouse->bind_param('ii',$warehouseId,$accountId);$lockWarehouse->execute();
        if(!$lockWarehouse->get_result()->fetch_row()) throw new DomainException('La bodega seleccionada dejó de estar activa');
        $existingByCode=$codigo!==''?importarBuscarIdentificador($byIdentifier,$accountId,$codigo,'código de barras'):null;
        $existingBySku=$importedSku!==null&&$importedSku!==''?importarBuscarIdentificador($byIdentifier,$accountId,$importedSku,'SKU'):null;
        if($existingByCode&&$existingBySku&&(int)$existingByCode['id_producto']!==(int)$existingBySku['id_producto']){
            throw new DomainException('El código de barras y el SKU pertenecen a productos distintos');
        }
        $existing=$existingByCode?:$existingBySku;
        if(!$existing&&$codigo===''&&($importedSku===null||$importedSku==='')) {
            $existing=importarBuscarUnico($byName,$accountId,$nombre,'nombre');
        }
        $wasExisting=(bool)$existing;
        $categoria=$hasCategory?$importedCategory:(string)($existing['categoria']??'');
        $sku=$hasSku?($importedSku!==''?$importedSku:null):($existing['sku']??null);
        $categoryId=isset($existing['id_categoria'])&&$existing['id_categoria']!==null?(int)$existing['id_categoria']:null;
        if($hasCategory){
            if($categoria===''){
                $categoria=null;
                $categoryId=null;
            }else{
                $findCategory->bind_param('is',$accountId,$categoria);$findCategory->execute();
                $categoryRow=$findCategory->get_result()->fetch_assoc();
                if($categoryRow){
                    $categoryId=(int)$categoryRow['id_categoria'];
                }else{
                    $createCategory->bind_param('iis',$uid,$accountId,$categoria);$createCategory->execute();
                    $categoryId=(int)$conn->insert_id;
                }
            }
        }
        $cost=$hasCost?(float)$importedCost:(float)($existing['precio_costo']??0);
        $averageCost=$hasCost?$cost:(float)($existing['costo_promedio']??$cost);
        $lastCost=$hasCost?$cost:(float)($existing['ultimo_costo']??$cost);
        $saleType=$hasSaleType?$importedSaleType:strtoupper((string)($existing['tipo_venta']??'UNIDAD'));
        if($hasActive&&!in_array($activeText,['1','sí','si','activo','true','0','no','inactivo','false'],true)) throw new InvalidArgumentException('Estado activo inválido');
        $active=$hasActive?(in_array($activeText,['0','no','inactivo','false'],true)?0:1):(int)($existing['activo']??1);
        $unitId=isset($existing['id_unidad'])&&$existing['id_unidad']!==null?(int)$existing['id_unidad']:null;
        $unitType=strtoupper((string)($existing['unidad_tipo']??''));
        if($hasUnit){
            $findUnit->bind_param('ss',$importedUnit,$importedUnit);$findUnit->execute();$unitRows=$findUnit->get_result()->fetch_all(MYSQLI_ASSOC);
            if(count($unitRows)>1) throw new InvalidArgumentException('La unidad de medida es ambigua; use una abreviatura única');
            $unitRow=$unitRows[0]??null;
            if(!$unitRow) throw new InvalidArgumentException('Unidad de medida no reconocida');
            $unitId=(int)$unitRow['id_unidad'];
            $unitType=strtoupper((string)$unitRow['tipo']);
        }
        if(!in_array($saleType,['UNIDAD','PESO','VOLUMEN'],true)) throw new InvalidArgumentException('Tipo de venta inválido');
        if($unitId!==null&&$unitType!==''&&$unitType!==$saleType) throw new InvalidArgumentException('La unidad de medida no corresponde al tipo de venta');
        if($saleType==='UNIDAD'&&abs($quantity-round($quantity))>0.000001) throw new InvalidArgumentException('Los productos por unidad requieren cantidades enteras');
        if($existing&&$saleType==='UNIDAD'){
            if(!importarEsCantidadEntera($existing['cantidad']??0)) throw new InvalidArgumentException('No se puede cambiar a UNIDAD mientras el stock total sea fraccionario');
            $idExisting=(int)$existing['id_producto'];
            $lockProductStock->bind_param('i',$idExisting);$lockProductStock->execute();
            foreach($lockProductStock->get_result()->fetch_all(MYSQLI_ASSOC) as $stockRow){
                foreach(['disponible','reservado','comprometido','bloqueado'] as $stockField){
                    if(!importarEsCantidadEntera($stockRow[$stockField]??0)){
                        throw new InvalidArgumentException('No se puede cambiar a UNIDAD mientras alguna bodega tenga stock fraccionario');
                    }
                }
            }
        }

        if($existing){
            $id=(int)$existing['id_producto'];
            $update->bind_param('sdssisdddsiiii',$nombre,$precio,$codigoDb,$categoria,$categoryId,$sku,$cost,$averageCost,$lastCost,$saleType,$unitId,$active,$id,$accountId);
            $update->execute();
        } else {
            $insert->bind_param('iisdssisdddsii',$uid,$accountId,$nombre,$precio,$codigoDb,$categoria,$categoryId,$sku,$cost,$averageCost,$lastCost,$saleType,$unitId,$active);
            $insert->execute();
            $id=(int)$conn->insert_id;
        }
        importarAjustarStock($conn,$uid,$id,$warehouseId,$quantity,$cost);
        $conn->commit();
        if($wasExisting)$actualizados++;else$insertados++;
    } catch(Throwable $error) {
        try{$conn->rollback();}catch(Throwable){}
        $errores++;
        if($error instanceof mysqli_sql_exception&&(int)$error->getCode()===1062){
            $error=new DomainException('El código de barras o SKU ya pertenece a otro producto');
        }
        $safeReason=($error instanceof DomainException||$error instanceof InvalidArgumentException)
            ? $error->getMessage()
            : 'No se pudo guardar la fila por una restricción de datos';
        importarRegistrarError($detalleErrores,$fileRow,$safeReason);
    }
}
$byIdentifier->close();$byName->close();$lockWarehouse->close();$lockProductStock->close();$findUnit->close();$findCategory->close();$createCategory->close();$update->close();$insert->close();
importarAuditar($conn,$uid,[
    'id_cuenta'=>$accountId,
    'id_bodega'=>$warehouseId,
    'referencia_documento'=>$documentReference,
    'archivo'=>$safeFileName,
    'sha256'=>is_string($fileHash)?$fileHash:null,
    'formato'=>$type,
    'filas'=>$total,
    'insertados'=>$insertados,
    'actualizados'=>$actualizados,
    'errores'=>$errores,
]);
$conn->close();
$aplicados=$insertados+$actualizados;
$success=$aplicados>0;
$partial=$success&&$errores>0;
$status=$success?($partial?207:200):422;
$message=$success
    ? "Importación completada: $insertados nuevos, $actualizados actualizados, $errores errores de $total filas."
    : "No se aplicó ninguna fila: $errores errores de $total filas.";
json(['success'=>$success,'parcial'=>$partial,'msg'=>$message,'insertados'=>$insertados,'actualizados'=>$actualizados,'errores'=>$errores,'total'=>$total,'detalle_errores'=>$detalleErrores],$status);
