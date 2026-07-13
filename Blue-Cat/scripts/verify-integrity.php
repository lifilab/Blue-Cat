<?php
declare(strict_types=1);

$options = getopt('', ['env:', 'source:', 'target:']);
$root = dirname(__DIR__);
$envFile = $options['env'] ?? ($root . '/.env');
if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $envFile)) $envFile = $root . '/' . $envFile;
if (!is_file($envFile)) throw new RuntimeException('Archivo de entorno no encontrado.');
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line=trim($line);
    if ($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue;
    [$key,$value]=array_map('trim',explode('=',$line,2));
    $_ENV[$key]=trim($value,"\"'");
}
$source=(string)($options['source'] ?? '');
$target=(string)($options['target'] ?? '');
foreach ([$source,$target] as $schema) {
    if (!preg_match('/^[A-Za-z0-9_]+$/',$schema)) throw new RuntimeException('Nombre de esquema inválido.');
}
$db=new mysqli($_ENV['DB_HOST']??'127.0.0.1',$_ENV['DB_USER']??'',$_ENV['DB_PASSWORD']??'',null,(int)($_ENV['DB_PORT']??3306));
$stmt=$db->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=? AND table_type='BASE TABLE' ORDER BY TABLE_NAME");
$stmt->bind_param('s',$source);$stmt->execute();$result=$stmt->get_result();
$differences=[];$equal=0;
while($row=$result->fetch_assoc()){
    $table=$row['TABLE_NAME'];
    $a=(int)$db->query("SELECT COUNT(*) c FROM {$source}.{$table}")->fetch_assoc()['c'];
    $b=(int)$db->query("SELECT COUNT(*) c FROM {$target}.{$table}")->fetch_assoc()['c'];
    if($a!==$b)$differences[]=['tabla'=>$table,'origen'=>$a,'destino'=>$b];else$equal++;
}
$stmt->close();
$fk=(int)$db->query("SELECT COUNT(*) c FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='{$target}'")->fetch_assoc()['c'];
$report=['source'=>$source,'target'=>$target,'tablas_iguales'=>$equal,'diferencias'=>$differences,'foreign_keys'=>$fk,'ok'=>count($differences)===0];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($report['ok']?0:1);
