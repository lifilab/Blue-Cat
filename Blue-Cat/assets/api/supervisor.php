<?php
require_once __DIR__.'/_supervisor.php';
$uid=requireUser(); $conn=getDB();
if ($_SERVER['REQUEST_METHOD']!=='POST') json(['error'=>true,'message'=>'Método no permitido'],405);
$input=getJsonInput();
if (!$input) json(['error'=>true,'message'=>'Datos JSON requeridos'],400);
$action=(string)($input['action']??'');
if ($action!=='autorizar') json(['error'=>true,'message'=>'Acción no válida'],400);
$operation=(string)($input['operation']??'');
$context=(array)($input['context']??[]);
$credential=trim((string)($input['credential']??''));
$reason=trim((string)($input['reason']??''));
json(supervisorIssue($conn,$uid,$operation,$context,$credential,$reason));
