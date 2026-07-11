<?php
require_once __DIR__ . '/_db.php';
$uid = requireUser();

function requierePermiso($modulo, $accion) {
    if (!verificarPermiso($modulo, $accion)) {
        json(['error'=>'Permiso denegado: '.$modulo.'.'.$accion], 403);
    }
}

$conn = getDB();

// Debug: catch fatal errors
register_shutdown_function(function() use ($conn) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'PHP Fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']], JSON_UNESCAPED_UNICODE);
    }
});

// Ensure usuario.id_empleado column exists
$r = $conn->query("SHOW COLUMNS FROM usuario LIKE 'id_empleado'");
if (!$r->num_rows) {
    $conn->query("ALTER TABLE usuario ADD COLUMN id_empleado INT DEFAULT NULL");
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id) getEmpleado($conn, $uid, $id);
        else listEmpleados($conn, $uid);
        break;
    case 'POST':
        $input = getJsonInput();
        if (is_object($input)) {
            $input = json_decode(json_encode($input), true);
        }
        if (!is_array($input)) {
            $input = [];
        }
        $accion = $input['accion'] ?? 'crear';
        if ($accion === 'crear') { requierePermiso('empleados','crear'); crearEmpleado($conn, $uid, $input); }
        elseif ($accion === 'editar') { requierePermiso('empleados','editar'); editarEmpleado($conn, $uid, $input); }
        elseif ($accion === 'cambiar_estado') { requierePermiso('empleados','editar'); cambiarEstado($conn, $uid, $input); }
        elseif ($accion === 'contrato_crear') { requierePermiso('empleados','editar'); crearContrato($conn, $uid, $input); }
        elseif ($accion === 'contrato_eliminar') { requierePermiso('empleados','editar'); eliminarContrato($conn, $uid, $input); }
        elseif ($accion === 'documento_crear') { requierePermiso('empleados','editar'); crearDocumento($conn, $uid, $input); }
        elseif ($accion === 'documento_eliminar') { requierePermiso('empleados','editar'); eliminarDocumento($conn, $uid, $input); }
        elseif ($accion === 'asistencia_crear') { requierePermiso('empleados','editar'); crearAsistencia($conn, $uid, $input); }
        elseif ($accion === 'asistencia_editar') editarAsistencia($conn, $uid, $input);
        elseif ($accion === 'asistencia_eliminar') { requierePermiso('empleados','editar'); eliminarAsistencia($conn, $uid, $input); }
        elseif ($accion === 'turno_crear') { requierePermiso('empleados','editar'); crearTurno($conn, $uid, $input); }
        elseif ($accion === 'turno_editar') editarTurno($conn, $uid, $input);
        elseif ($accion === 'vacacion_crear') { requierePermiso('empleados','editar'); crearVacacion($conn, $uid, $input); }
        elseif ($accion === 'vacacion_aprobar') aprobarVacacion($conn, $uid, $input);
        elseif ($accion === 'vacacion_eliminar') { requierePermiso('empleados','editar'); eliminarVacacion($conn, $uid, $input); }
        elseif ($accion === 'permiso_crear') { requierePermiso('empleados','editar'); crearPermiso($conn, $uid, $input); }
        elseif ($accion === 'permiso_aprobar') aprobarPermiso($conn, $uid, $input);
        elseif ($accion === 'permiso_eliminar') { requierePermiso('empleados','editar'); eliminarPermiso($conn, $uid, $input); }
        elseif ($accion === 'licencia_crear') { requierePermiso('empleados','editar'); crearLicencia($conn, $uid, $input); }
        elseif ($accion === 'licencia_eliminar') { requierePermiso('empleados','editar'); eliminarLicencia($conn, $uid, $input); }
        elseif ($accion === 'hora_extra_crear') { requierePermiso('empleados','editar'); crearHoraExtra($conn, $uid, $input); }
        elseif ($accion === 'hora_extra_aprobar') aprobarHoraExtra($conn, $uid, $input);
        elseif ($accion === 'hora_extra_eliminar') { requierePermiso('empleados','editar'); eliminarHoraExtra($conn, $uid, $input); }
        elseif ($accion === 'remuneracion_crear') { requierePermiso('empleados','editar'); crearRemuneracion($conn, $uid, $input); }
        elseif ($accion === 'remuneracion_eliminar') { requierePermiso('empleados','editar'); eliminarRemuneracion($conn, $uid, $input); }
        elseif ($accion === 'beneficio_crear') { requierePermiso('empleados','editar'); crearBeneficio($conn, $uid, $input); }
        elseif ($accion === 'beneficio_eliminar') { requierePermiso('empleados','editar'); eliminarBeneficio($conn, $uid, $input); }
        elseif ($accion === 'capacitacion_crear') { requierePermiso('empleados','editar'); crearCapacitacion($conn, $uid, $input); }
        elseif ($accion === 'capacitacion_editar') editarCapacitacion($conn, $uid, $input);
        elseif ($accion === 'capacitacion_eliminar') { requierePermiso('empleados','editar'); eliminarCapacitacion($conn, $uid, $input); }
        elseif ($accion === 'evaluacion_crear') { requierePermiso('empleados','editar'); crearEvaluacion($conn, $uid, $input); }
        elseif ($accion === 'evaluacion_eliminar') { requierePermiso('empleados','editar'); eliminarEvaluacion($conn, $uid, $input); }
        elseif ($accion === 'activo_crear') { requierePermiso('empleados','editar'); crearActivo($conn, $uid, $input); }
        elseif ($accion === 'activo_devolver') devolverActivo($conn, $uid, $input);
        elseif ($accion === 'activo_eliminar') { requierePermiso('empleados','editar'); eliminarActivo($conn, $uid, $input); }
        elseif ($accion === 'historial_crear') crearHistorial($conn, $uid, $input);
        elseif ($accion === 'vincular_usuario') vincularUsuario($conn, $uid, $input);
        elseif ($accion === 'crear_credenciales') { requierePermiso('empleados','crear'); crearCredenciales($conn, $uid, $input); }
        elseif ($accion === 'empleado_eliminar') { requierePermiso('empleados','eliminar'); eliminarEmpleado($conn, $uid, $input); }
        else json(['error' => 'Acción no válida'], 400);
        break;
    default:
        json(['error' => 'Método no soportado'], 405);
}

/* ── HELPERS ── */
function addAuditoria($conn, $id_empleado, $id_user, $accion, $detalle = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_auditoria (id_empleado, id_user, accion, detalle, ip) VALUES (?,?,?,?,?)");
    $stmt->bind_param("iisss", $id_empleado, $id_user, $accion, $detalle, $ip);
    $stmt->execute();
    $stmt->close();
}

/* ── LIST ── */
function listEmpleados($conn, $uid) {
    $q = $_GET['q'] ?? '';
    $estado = $_GET['estado'] ?? '';
    $departamento = $_GET['departamento'] ?? '';
    $cargo = $_GET['cargo'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page-1)*$limit;

    $where = " WHERE 1=1";
    $params = [];
    $types = '';

    if ($q) {
        $where .= " AND (e.nombres LIKE ? OR e.apellidos LIKE ? OR e.rut LIKE ? OR e.codigo LIKE ? OR e.correo_corporativo LIKE ? OR e.telefono LIKE ? OR e.cargo LIKE ? OR e.departamento LIKE ?)";
        $like = '%' . $q . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like]);
        $types .= 'ssssssss';
    }
    if ($estado) {
        $where .= " AND e.estado = ?";
        $params[] = $estado;
        $types .= 's';
    }
    if ($departamento) {
        $where .= " AND e.departamento LIKE ?";
        $params[] = '%' . $departamento . '%';
        $types .= 's';
    }
    if ($cargo) {
        $where .= " AND e.cargo LIKE ?";
        $params[] = '%' . $cargo . '%';
        $types .= 's';
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS t FROM empleado e" . $where);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    $sql = "SELECT e.*, (SELECT COUNT(*) FROM empleado_documento WHERE id_empleado = e.id_empleado) as docs FROM empleado e" . $where . " ORDER BY e.apellidos ASC, e.nombres ASC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types . "ii", ...array_merge($params, [$limit, $offset]));
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    json(['items'=>$items, 'total'=>$total, 'page'=>$page]);
}

/* ── GET PROFILE ── */
function getEmpleado($conn, $uid, $id) {
    $stmt = $conn->prepare("SELECT * FROM empleado WHERE id_empleado = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $e = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$e)
        json(['error' => 'Empleado no encontrado'], 404);

    // Age calculation
    if ($e['fecha_nacimiento']) {
        $bday = new DateTime($e['fecha_nacimiento']);
        $today = new DateTime();
        $e['edad'] = $bday->diff($today)->y;
    }

    // Sub-tables
    $stmt = $conn->prepare("SELECT * FROM empleado_contrato WHERE id_empleado = ? ORDER BY fecha_creacion DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['contratos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_documento WHERE id_empleado = ? ORDER BY fecha_creacion DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['documentos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_turno WHERE id_empleado = ? OR id_empleado IS NULL ORDER BY activo DESC, nombre ASC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['turnos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_asistencia WHERE id_empleado = ? ORDER BY fecha DESC LIMIT 30");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['asistencias'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_vacacion WHERE id_empleado = ? ORDER BY fecha_creacion DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['vacaciones'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_permiso WHERE id_empleado = ? ORDER BY fecha_creacion DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['permisos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_licencia WHERE id_empleado = ? ORDER BY fecha_inicio DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['licencias'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_hora_extra WHERE id_empleado = ? ORDER BY fecha DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['horas_extras'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_remuneracion WHERE id_empleado = ? ORDER BY periodo DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['remuneraciones'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_beneficio WHERE id_empleado = ? ORDER BY estado ASC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['beneficios'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_capacitacion WHERE id_empleado = ? ORDER BY fecha DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['capacitaciones'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_evaluacion WHERE id_empleado = ? ORDER BY fecha DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['evaluaciones'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_activo WHERE id_empleado = ? ORDER BY estado ASC, fecha_entrega DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['activos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT * FROM empleado_historial WHERE id_empleado = ? ORDER BY fecha DESC");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['historial'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $stmt = $conn->prepare("SELECT a.*, u.nombre as usuario FROM empleado_auditoria a LEFT JOIN usuario u ON a.id_user = u.id_user WHERE a.id_empleado = ? ORDER BY a.fecha DESC LIMIT 30");
    $stmt->bind_param("i", $id); $stmt->execute(); $e['auditoria'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    json($e);
}

/* ── CREATE ── */
function crearEmpleado($conn, $uid, $input) {
    $nombres = $input['nombres'] ?? '';
    $apellidos = $input['apellidos'] ?? '';
    if (empty($nombres) || empty($apellidos))
        json(['error' => 'Nombres y apellidos son obligatorios'], 400);

    // Map correo to correo_corporativo if provided
    if (isset($input['correo']) && !isset($input['correo_corporativo'])) {
        $input['correo_corporativo'] = $input['correo'];
    }

    // Determine linked user ID (use provided id_user or default to owner)
    $link_user_id = isset($input['id_user']) && $input['id_user'] ? (int)$input['id_user'] : $uid;

    // Auto-code
    $stmt = $conn->prepare("SELECT COALESCE(MAX(id_empleado), 0) + 1 as n FROM empleado WHERE id_user = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    $codigo = 'EMP-' . str_pad((int) $r->fetch_assoc()['n'], 4, '0', STR_PAD_LEFT);
    $stmt->close();

    // String fields
    $fields = ['rut', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'estado_civil', 'nacionalidad', 'fotografia', 'correo_personal', 'correo_corporativo', 'telefono', 'celular', 'direccion', 'comuna', 'ciudad', 'region', 'pais', 'contacto_emergencia_nombre', 'contacto_emergencia_telefono', 'cargo', 'departamento', 'sucursal', 'centro_costo', 'jefe_directo', 'fecha_ingreso', 'fecha_termino', 'tipo_contrato', 'modalidad', 'horario', 'afp', 'salud', 'caja_compensacion', 'mutual', 'banco', 'tipo_cuenta', 'numero_cuenta', 'forma_pago', 'tramo_impuesto', 'retenciones', 'observaciones', 'estado'];
    $intFields = ['sueldo_base', 'asignaciones', 'bonos', 'comisiones'];
    $vals = [$codigo, $link_user_id];
    $phs = ['?', '?'];
    $sql_fields = 'codigo, id_user';
    $types = 'si';

    foreach ($fields as $f) {
        if (isset($input[$f]) && $input[$f] !== '') {
            $sql_fields .= ", $f";
            $vals[] = $input[$f];
            $phs[] = '?';
            $types .= 's';
        }
    }
    foreach ($intFields as $f) {
        if (isset($input[$f]) && $input[$f] !== '') {
            $sql_fields .= ", $f";
            $vals[] = (int) $input[$f];
            $phs[] = '?';
            $types .= 'i';
        }
    }

    $sql = "INSERT INTO empleado ($sql_fields) VALUES (" . implode(',', $phs) . ")";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    // If id_user was explicitly provided, update the usuario table
    if (isset($input['id_user']) && $input['id_user']) {
        $link_uid = (int) $input['id_user'];
        $nombre_completo = $nombres . ' ' . $apellidos;
        $stmt2 = $conn->prepare("UPDATE usuario SET nombre_completo = ?, id_empleado = ? WHERE id_user = ?");
        $stmt2->bind_param("sii", $nombre_completo, $id, $link_uid);
        $stmt2->execute();
        $stmt2->close();
    }

    addAuditoria($conn, $id, $uid, 'CREAR', "Empleado $nombres $apellidos creado");
    json(['success' => true, 'id_empleado' => $id, 'codigo' => $codigo], 201);
}

/* ── EDIT ── */
function editarEmpleado($conn, $uid, $input) {
    $id = (int) ($input['id_empleado'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);

    $fields = ['rut', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'estado_civil', 'nacionalidad', 'fotografia', 'correo_personal', 'correo_corporativo', 'telefono', 'celular', 'direccion', 'comuna', 'ciudad', 'region', 'pais', 'contacto_emergencia_nombre', 'contacto_emergencia_telefono', 'cargo', 'departamento', 'sucursal', 'centro_costo', 'jefe_directo', 'fecha_ingreso', 'fecha_termino', 'tipo_contrato', 'modalidad', 'horario', 'afp', 'salud', 'caja_compensacion', 'mutual', 'banco', 'tipo_cuenta', 'numero_cuenta', 'forma_pago', 'tramo_impuesto', 'retenciones', 'observaciones'];
    $intFields = ['sueldo_base', 'asignaciones', 'bonos', 'comisiones'];

    $sets = [];
    $params = [];
    $types = '';
    foreach ($fields as $f) {
        if (isset($input[$f]) && $input[$f] !== '') {
            $sets[] = "$f = ?";
            $params[] = $input[$f];
            $types .= 's';
        }
    }
    foreach ($intFields as $f) {
        if (isset($input[$f]) && $input[$f] !== '') {
            $sets[] = "$f = ?";
            $params[] = (int) $input[$f];
            $types .= 'i';
        }
    }
    if (empty($sets))
        json(['error' => 'Sin cambios'], 400);
    $params[] = $id;
    $types .= 'i';
    $params[] = $uid;
    $types .= 'i';

    $sql = "UPDATE empleado SET " . implode(', ', $sets) . " WHERE id_empleado = ? AND id_user = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    addAuditoria($conn, $id, $uid, 'EDITAR', 'Datos actualizados');
    json(['success' => true]);
}

/* ── CAMBIAR ESTADO ── */
function cambiarEstado($conn, $uid, $input) {
    $id = (int) ($input['id_empleado'] ?? 0);
    $estado = $input['estado'] ?? '';
    if (!$id || !$estado)
        json(['error' => 'Datos inválidos'], 400);
    $stmt = $conn->prepare("SELECT estado FROM empleado WHERE id_empleado = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc()['estado'];
    $stmt->close();
    $stmt = $conn->prepare("UPDATE empleado SET estado = ? WHERE id_empleado = ?");
    $stmt->bind_param("si", $estado, $id);
    $stmt->execute();
    $stmt->close();
    addAuditoria($conn, $id, $uid, 'ESTADO', "$old -> $estado");
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   CONTRATOS
   ═══════════════════════════════════════════ */
function crearContrato($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    if (!$id_emp)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT id_empleado FROM empleado WHERE id_empleado = ? AND id_user = ?");
    $stmt->bind_param("ii", $id_emp, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $tipo = $input['tipo'] ?? '';
    $inicio = $input['fecha_inicio'] ?? '';
    $fin = $input['fecha_termino'] ?? '';
    $sb = (int) ($input['sueldo_base'] ?? 0);
    $asig = (int) ($input['asignaciones'] ?? 0);
    $bonos = (int) ($input['bonos'] ?? 0);
    $archivo = $input['archivo'] ?? '';
    $notas = $input['notas'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_contrato (id_empleado, tipo, fecha_inicio, fecha_termino, sueldo_base, asignaciones, bonos, archivo, notas) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssiiiss", $id_emp, $tipo, $inicio, $fin, $sb, $asig, $bonos, $archivo, $notas);
    $stmt->execute();
    $id_c = (int) $conn->insert_id;
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'CONTRATO_CREAR', "Contrato $tipo creado");
    json(['success' => true, 'id_contrato' => $id_c], 201);
}

function eliminarContrato($conn, $uid, $input) {
    $id = (int) ($input['id_contrato'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT ec.id_contrato FROM empleado_contrato ec JOIN empleado e ON ec.id_empleado=e.id_empleado WHERE ec.id_contrato = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_contrato WHERE id_contrato = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   DOCUMENTOS
   ═══════════════════════════════════════════ */
function crearDocumento($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $tipo = $input['tipo'] ?? '';
    $nombre = $input['nombre'] ?? '';
    if (!$id_emp || empty($nombre))
        json(['error' => 'Datos inválidos'], 400);
    $stmt = $conn->prepare("SELECT id_empleado FROM empleado WHERE id_empleado = ? AND id_user = ?");
    $stmt->bind_param("ii", $id_emp, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $archivo = $input['archivo'] ?? '';
    $emision = $input['fecha_emision'] ?? '';
    $venc = $input['fecha_vencimiento'] ?? '';
    $notas = $input['notas'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_documento (id_empleado, tipo, nombre, archivo, fecha_emision, fecha_vencimiento, notas) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("issssss", $id_emp, $tipo, $nombre, $archivo, $emision, $venc, $notas);
    $stmt->execute();
    $id_d = (int) $conn->insert_id;
    $stmt->close();
    json(['success' => true, 'id_documento' => $id_d], 201);
}

function eliminarDocumento($conn, $uid, $input) {
    $id = (int) ($input['id_documento'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT ed.id_documento FROM empleado_documento ed JOIN empleado e ON ed.id_empleado=e.id_empleado WHERE ed.id_documento = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_documento WHERE id_documento = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   TURNOS
   ═══════════════════════════════════════════ */
function crearTurno($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $nombre = $input['nombre'] ?? 'Diurno';
    $hora_ini = $input['hora_inicio'] ?? '09:00';
    $hora_fin = $input['hora_fin'] ?? '18:00';
    $dias = $input['dias_semana'] ?? '1,2,3,4,5';
    $color = $input['color'] ?? '#4f46e5';
    $stmt = $conn->prepare("INSERT INTO empleado_turno (id_empleado, nombre, hora_inicio, hora_fin, dias_semana, color) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isssss", $id_emp ?: null, $nombre, $hora_ini, $hora_fin, $dias, $color);
    $stmt->execute();
    $id_t = (int) $conn->insert_id;
    $stmt->close();
    json(['success' => true, 'id_turno' => $id_t], 201);
}

function editarTurno($conn, $uid, $input) {
    $id = (int) ($input['id_turno'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $fields = ['nombre', 'hora_inicio', 'hora_fin', 'dias_semana', 'color'];
    $sets = [];
    $params = [];
    $types = '';
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $sets[] = "$f = ?";
            $params[] = $input[$f];
            $types .= 's';
        }
    }
    if (isset($input['activo'])) {
        $sets[] = "activo = ?";
        $params[] = $input['activo'] ? 1 : 0;
        $types .= 'i';
    }
    if (empty($sets))
        json(['error' => 'Sin cambios'], 400);
    $params[] = $id;
    $types .= 'i';
    $stmt = $conn->prepare("UPDATE empleado_turno SET " . implode(', ', $sets) . " WHERE id_turno = ?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   ASISTENCIA
   ═══════════════════════════════════════════ */
function crearAsistencia($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $fecha = $input['fecha'] ?? date('Y-m-d');
    if (!$id_emp)
        json(['error' => 'ID requerido'], 400);
    $entrada = $input['entrada'] ?? '';
    $salida = $input['salida'] ?? '';
    $colacion = $input['colacion'] ?? '';
    $ht = $input['horas_trabajadas'] ? (float) $input['horas_trabajadas'] : null;
    $he = $input['horas_extra'] ? (float) $input['horas_extra'] : null;
    $retraso = (int) ($input['retraso'] ?? 0);
    $tipo = $input['tipo'] ?? 'NORMAL';
    $obs = $input['observaciones'] ?? '';

    // Upsert
    $sql = "INSERT INTO empleado_asistencia (id_empleado, fecha, entrada, salida, colacion, horas_trabajadas, horas_extra, retraso, tipo, observaciones) VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE entrada=VALUES(entrada), salida=VALUES(salida), colacion=VALUES(colacion), horas_trabajadas=VALUES(horas_trabajadas), horas_extra=VALUES(horas_extra), retraso=VALUES(retraso), tipo=VALUES(tipo), observaciones=VALUES(observaciones)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssddiss", $id_emp, $fecha, $entrada, $salida, $colacion, $ht, $he, $retraso, $tipo, $obs);
    $stmt->execute();
    $stmt->close();
    json(['success' => true], 201);
}

function editarAsistencia($conn, $uid, $input) {
    $id = (int) ($input['id_asistencia'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $fields = ['entrada', 'salida', 'colacion', 'tipo', 'observaciones'];
    $sets = [];
    $params = [];
    $types = '';
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $sets[] = "$f = ?";
            $params[] = $input[$f];
            $types .= 's';
        }
    }
    foreach (['horas_trabajadas', 'horas_extra'] as $f) {
        if (isset($input[$f])) {
            $sets[] = "$f = ?";
            $params[] = (float) $input[$f];
            $types .= 'd';
        }
    }
    if (isset($input['retraso'])) {
        $sets[] = "retraso = ?";
        $params[] = (int) $input['retraso'];
        $types .= 'i';
    }
    if (empty($sets))
        json(['error' => 'Sin cambios'], 400);
    $params[] = $id;
    $types .= 'i';
    $stmt = $conn->prepare("UPDATE empleado_asistencia SET " . implode(', ', $sets) . " WHERE id_asistencia = ?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

function eliminarAsistencia($conn, $uid, $input) {
    $id = (int) ($input['id_asistencia'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT a.id_asistencia FROM empleado_asistencia a JOIN empleado e ON a.id_empleado=e.id_empleado WHERE a.id_asistencia = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_asistencia WHERE id_asistencia = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   VACACIONES
   ═══════════════════════════════════════════ */
function crearVacacion($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $inicio = $input['fecha_inicio'] ?? '';
    $fin = $input['fecha_fin'] ?? '';
    $dias = (int) ($input['dias'] ?? 0);
    if (!$id_emp || !$inicio || !$fin || !$dias)
        json(['error' => 'Datos inválidos'], 400);
    $tipo = $input['tipo'] ?? 'PROGRESIVAS';
    $comentarios = $input['comentarios'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_vacacion (id_empleado, fecha_inicio, fecha_fin, dias, tipo, comentarios) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("ississ", $id_emp, $inicio, $fin, $dias, $tipo, $comentarios);
    $stmt->execute();
    $id_v = (int) $conn->insert_id;
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'VACACION_SOLICITAR', "$dias días ($inicio - $fin)");
    json(['success' => true, 'id_vacacion' => $id_v], 201);
}

function aprobarVacacion($conn, $uid, $input) {
    $id = (int) ($input['id_vacacion'] ?? 0);
    $estado = $input['estado'] ?? 'APROBADA';
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT v.id_vacacion FROM empleado_vacacion v JOIN empleado e ON v.id_empleado=e.id_empleado WHERE v.id_vacacion = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("UPDATE empleado_vacacion SET estado = ?, aprobado_por = ? WHERE id_vacacion = ?");
    $stmt->bind_param("sii", $estado, $uid, $id);
    $stmt->execute();
    $stmt->close();
    addAuditoria($conn, 0, $uid, 'VACACION_APROBAR', "Vacación #$id: $estado");
    json(['success' => true]);
}

function eliminarVacacion($conn, $uid, $input) {
    $id = (int) ($input['id_vacacion'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT v.id_vacacion FROM empleado_vacacion v JOIN empleado e ON v.id_empleado=e.id_empleado WHERE v.id_vacacion = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_vacacion WHERE id_vacacion = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   PERMISOS
   ═══════════════════════════════════════════ */
function crearPermiso($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $tipo = $input['tipo'] ?? '';
    $inicio = $input['fecha_inicio'] ?? '';
    $fin = $input['fecha_fin'] ?? '';
    if (!$id_emp || !$inicio || !$fin)
        json(['error' => 'Datos inválidos'], 400);
    $horas = (int) ($input['horas'] ?? 0);
    $motivo = $input['motivo'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_permiso (id_empleado, tipo, fecha_inicio, fecha_fin, horas, motivo) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isssis", $id_emp, $tipo, $inicio, $fin, $horas, $motivo);
    $stmt->execute();
    $id_p = (int) $conn->insert_id;
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'PERMISO_SOLICITAR', "$tipo ($inicio - $fin)");
    json(['success' => true, 'id_permiso' => $id_p], 201);
}

function aprobarPermiso($conn, $uid, $input) {
    $id = (int) ($input['id_permiso'] ?? 0);
    $estado = $input['estado'] ?? 'APROBADO';
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT p.id_permiso FROM empleado_permiso p JOIN empleado e ON p.id_empleado=e.id_empleado WHERE p.id_permiso = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("UPDATE empleado_permiso SET estado = ?, aprobado_por = ? WHERE id_permiso = ?");
    $stmt->bind_param("sii", $estado, $uid, $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

function eliminarPermiso($conn, $uid, $input) {
    $id = (int) ($input['id_permiso'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT p.id_permiso FROM empleado_permiso p JOIN empleado e ON p.id_empleado=e.id_empleado WHERE p.id_permiso = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_permiso WHERE id_permiso = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   LICENCIAS
   ═══════════════════════════════════════════ */
function crearLicencia($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $tipo = $input['tipo'] ?? '';
    $inicio = $input['fecha_inicio'] ?? '';
    $fin = $input['fecha_fin'] ?? '';
    if (!$id_emp || !$inicio || !$fin)
        json(['error' => 'Datos inválidos'], 400);
    $diag = $input['diagnostico'] ?? '';
    $entidad = $input['entidad_emisora'] ?? '';
    $folio = $input['folio'] ?? '';
    $subsidio = (int) ($input['subsidio'] ?? 0);
    $archivo = $input['archivo'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_licencia (id_empleado, tipo, fecha_inicio, fecha_fin, diagnostico, entidad_emisora, folio, subsidio, archivo) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("issssssis", $id_emp, $tipo, $inicio, $fin, $diag, $entidad, $folio, $subsidio, $archivo);
    $stmt->execute();
    $id_l = (int) $conn->insert_id;
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'LICENCIA_CREAR', "$tipo ($inicio - $fin)");
    json(['success' => true, 'id_licencia' => $id_l], 201);
}

function eliminarLicencia($conn, $uid, $input) {
    $id = (int) ($input['id_licencia'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT l.id_licencia FROM empleado_licencia l JOIN empleado e ON l.id_empleado=e.id_empleado WHERE l.id_licencia = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_licencia WHERE id_licencia = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   HORAS EXTRAS
   ═══════════════════════════════════════════ */
function crearHoraExtra($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $fecha = $input['fecha'] ?? date('Y-m-d');
    $cantidad = (float) ($input['cantidad'] ?? 0);
    if (!$id_emp || !$cantidad)
        json(['error' => 'Datos inválidos'], 400);
    $motivo = $input['motivo'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_hora_extra (id_empleado, fecha, cantidad, motivo) VALUES (?,?,?,?)");
    $stmt->bind_param("isds", $id_emp, $fecha, $cantidad, $motivo);
    $stmt->execute();
    $id_h = (int) $conn->insert_id;
    $stmt->close();
    json(['success' => true, 'id_hora_extra' => $id_h], 201);
}

function aprobarHoraExtra($conn, $uid, $input) {
    $id = (int) ($input['id_hora_extra'] ?? 0);
    $estado = $input['estado'] ?? 'APROBADO';
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT h.id_hora_extra FROM empleado_hora_extra h JOIN empleado e ON h.id_empleado=e.id_empleado WHERE h.id_hora_extra = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $pago = (int) ($input['pago'] ?? 0);
    $comp = $input['compensacion'] ? 1 : 0;
    $stmt = $conn->prepare("UPDATE empleado_hora_extra SET estado = ?, aprobado_por = ?, pago = ?, compensacion = ? WHERE id_hora_extra = ?");
    $stmt->bind_param("siiii", $estado, $uid, $pago, $comp, $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

function eliminarHoraExtra($conn, $uid, $input) {
    $id = (int) ($input['id_hora_extra'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT h.id_hora_extra FROM empleado_hora_extra h JOIN empleado e ON h.id_empleado=e.id_empleado WHERE h.id_hora_extra = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_hora_extra WHERE id_hora_extra = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   REMUNERACIONES
   ═══════════════════════════════════════════ */
function crearRemuneracion($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $periodo = $input['periodo'] ?? '';
    if (!$id_emp || !$periodo)
        json(['error' => 'Datos inválidos'], 400);
    $sb = (int) ($input['sueldo_base'] ?? 0);
    $bonif = (int) ($input['bonificaciones'] ?? 0);
    $comis = (int) ($input['comisiones'] ?? 0);
    $he = (int) ($input['horas_extra'] ?? 0);
    $dctos = (int) ($input['descuentos'] ?? 0);
    $anticipos = (int) ($input['anticipos'] ?? 0);
    $liquido = (int) ($input['liquido'] ?? ($sb + $bonif + $comis + $he - $dctos - $anticipos));
    $archivo = $input['archivo_pdf'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_remuneracion (id_empleado, periodo, sueldo_base, bonificaciones, comisiones, horas_extra, descuentos, anticipos, liquido, archivo_pdf) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isiiiiiiis", $id_emp, $periodo, $sb, $bonif, $comis, $he, $dctos, $anticipos, $liquido, $archivo);
    $stmt->execute();
    $id_r = (int) $conn->insert_id;
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'REMUNERACION_CREAR', "Período $periodo: \$$liquido");
    json(['success' => true, 'id_remuneracion' => $id_r], 201);
}

function eliminarRemuneracion($conn, $uid, $input) {
    $id = (int) ($input['id_remuneracion'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT r.id_remuneracion FROM empleado_remuneracion r JOIN empleado e ON r.id_empleado=e.id_empleado WHERE r.id_remuneracion = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_remuneracion WHERE id_remuneracion = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   BENEFICIOS
   ═══════════════════════════════════════════ */
function crearBeneficio($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $tipo = $input['tipo'] ?? '';
    if (!$id_emp || empty($tipo))
        json(['error' => 'Datos inválidos'], 400);
    $desc = $input['descripcion'] ?? '';
    $monto = (int) ($input['monto'] ?? 0);
    $vigencia = $input['vigencia'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_beneficio (id_empleado, tipo, descripcion, monto, vigencia) VALUES (?,?,?,?,?)");
    $stmt->bind_param("issis", $id_emp, $tipo, $desc, $monto, $vigencia);
    $stmt->execute();
    $id_b = (int) $conn->insert_id;
    $stmt->close();
    json(['success' => true, 'id_beneficio' => $id_b], 201);
}

function eliminarBeneficio($conn, $uid, $input) {
    $id = (int) ($input['id_beneficio'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT b.id_beneficio FROM empleado_beneficio b JOIN empleado e ON b.id_empleado=e.id_empleado WHERE b.id_beneficio = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_beneficio WHERE id_beneficio = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   CAPACITACIONES
   ═══════════════════════════════════════════ */
function crearCapacitacion($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $curso = $input['curso'] ?? '';
    if (!$id_emp || empty($curso))
        json(['error' => 'Datos inválidos'], 400);
    $prov = $input['proveedor'] ?? '';
    $fecha = $input['fecha'] ?? '';
    $horas = (int) ($input['horas'] ?? 0);
    $costo = (int) ($input['costo'] ?? 0);
    $cert = $input['certificado'] ?? '';
    $venc = $input['vencimiento'] ?? '';
    $renov = $input['renovacion'] ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO empleado_capacitacion (id_empleado, curso, proveedor, fecha, horas, costo, certificado, vencimiento, renovacion) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssiissi", $id_emp, $curso, $prov, $fecha, $horas, $costo, $cert, $venc, $renov);
    $stmt->execute();
    $id_c = (int) $conn->insert_id;
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'CAPACITACION_CREAR', "$curso");
    json(['success' => true, 'id_capacitacion' => $id_c], 201);
}

function editarCapacitacion($conn, $uid, $input) {
    $id = (int) ($input['id_capacitacion'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $fields = ['curso', 'proveedor', 'fecha', 'certificado', 'vencimiento'];
    $sets = [];
    $params = [];
    $types = '';
    foreach ($fields as $f) {
        if (isset($input[$f])) {
            $sets[] = "$f = ?";
            $params[] = $input[$f];
            $types .= 's';
        }
    }
    foreach (['horas', 'costo'] as $f) {
        if (isset($input[$f])) {
            $sets[] = "$f = ?";
            $params[] = (int) $input[$f];
            $types .= 'i';
        }
    }
    if (isset($input['estado'])) {
        $sets[] = "estado = ?";
        $params[] = $input['estado'];
        $types .= 's';
    }
    if (empty($sets))
        json(['error' => 'Sin cambios'], 400);
    $params[] = $id;
    $types .= 'i';
    $stmt = $conn->prepare("UPDATE empleado_capacitacion SET " . implode(', ', $sets) . " WHERE id_capacitacion = ?");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

function eliminarCapacitacion($conn, $uid, $input) {
    $id = (int) ($input['id_capacitacion'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT c.id_capacitacion FROM empleado_capacitacion c JOIN empleado e ON c.id_empleado=e.id_empleado WHERE c.id_capacitacion = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_capacitacion WHERE id_capacitacion = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   EVALUACIONES
   ═══════════════════════════════════════════ */
function crearEvaluacion($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    if (!$id_emp)
        json(['error' => 'ID requerido'], 400);
    $periodo = $input['periodo'] ?? '';
    $fecha = $input['fecha'] ?? date('Y-m-d');
    $comp = (int) ($input['competencias'] ?? 0);
    $obj = (int) ($input['objetivos'] ?? 0);
    $prod = (int) ($input['productividad'] ?? 0);
    $teq = (int) ($input['trabajo_equipo'] ?? 0);
    $punt = (int) ($input['puntualidad'] ?? 0);
    $resp = (int) ($input['responsabilidad'] ?? 0);
    $cal = (int) ($input['calidad'] ?? 0);
    $total = $comp + $obj + $prod + $teq + $punt + $resp + $cal;
    $comentarios = $input['comentarios'] ?? '';
    $plan = $input['plan_mejora'] ?? '';
    $eval = $input['evaluador'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_evaluacion (id_empleado, periodo, fecha, competencias, objetivos, productividad, trabajo_equipo, puntualidad, responsabilidad, calidad, puntaje_total, comentarios, plan_mejora, evaluador) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("issiiiiiiiiss", $id_emp, $periodo, $fecha, $comp, $obj, $prod, $teq, $punt, $resp, $cal, $total, $comentarios, $plan, $eval);
    $stmt->execute();
    $id_e = (int) $conn->insert_id;
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'EVALUACION_CREAR', "$periodo - Puntaje: $total");
    json(['success' => true, 'id_evaluacion' => $id_e], 201);
}

function eliminarEvaluacion($conn, $uid, $input) {
    $id = (int) ($input['id_evaluacion'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT ev.id_evaluacion FROM empleado_evaluacion ev JOIN empleado e ON ev.id_empleado=e.id_empleado WHERE ev.id_evaluacion = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_evaluacion WHERE id_evaluacion = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   ACTIVOS
   ═══════════════════════════════════════════ */
function crearActivo($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $tipo = $input['tipo'] ?? '';
    if (!$id_emp || empty($tipo))
        json(['error' => 'Datos inválidos'], 400);
    $cod = $input['codigo_activo'] ?? '';
    $desc = $input['descripcion'] ?? '';
    $entrega = $input['fecha_entrega'] ?? date('Y-m-d');
    $resp = $input['responsable'] ?? '';
    $obs = $input['observaciones'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_activo (id_empleado, tipo, codigo_activo, descripcion, fecha_entrega, responsable, observaciones) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param("issssss", $id_emp, $tipo, $cod, $desc, $entrega, $resp, $obs);
    $stmt->execute();
    $id_a = (int) $conn->insert_id;
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'ACTIVO_ASIGNAR', "$tipo: $cod");
    json(['success' => true, 'id_activo' => $id_a], 201);
}

function devolverActivo($conn, $uid, $input) {
    $id = (int) ($input['id_activo'] ?? 0);
    $devolucion = $input['fecha_devolucion'] ?? date('Y-m-d');
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT a.id_activo FROM empleado_activo a JOIN empleado e ON a.id_empleado=e.id_empleado WHERE a.id_activo = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("UPDATE empleado_activo SET estado = 'DEVUELTO', fecha_devolucion = ? WHERE id_activo = ?");
    $stmt->bind_param("si", $devolucion, $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

function eliminarActivo($conn, $uid, $input) {
    $id = (int) ($input['id_activo'] ?? 0);
    if (!$id)
        json(['error' => 'ID requerido'], 400);
    $stmt = $conn->prepare("SELECT a.id_activo FROM empleado_activo a JOIN empleado e ON a.id_empleado=e.id_empleado WHERE a.id_activo = ? AND e.id_user = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("DELETE FROM empleado_activo WHERE id_activo = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    json(['success' => true]);
}

/* ═══════════════════════════════════════════
   HISTORIAL
   ═══════════════════════════════════════════ */
function crearHistorial($conn, $uid, $input) {
    $id_emp = (int) ($input['id_empleado'] ?? 0);
    $tipo = $input['tipo'] ?? '';
    if (!$id_emp || empty($tipo))
        json(['error' => 'Datos inválidos'], 400);
    $stmt = $conn->prepare("SELECT id_empleado FROM empleado WHERE id_empleado = ? AND id_user = ?");
    $stmt->bind_param("ii", $id_emp, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error'=>'No autorizado'], 403); }
    $stmt->close();
    $fecha = $input['fecha'] ?? date('Y-m-d');
    $ant = $input['valor_anterior'] ?? '';
    $nuevo = $input['valor_nuevo'] ?? '';
    $desc = $input['descripcion'] ?? '';
    $stmt = $conn->prepare("INSERT INTO empleado_historial (id_empleado, tipo, fecha, valor_anterior, valor_nuevo, descripcion) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isssss", $id_emp, $tipo, $fecha, $ant, $nuevo, $desc);
    $stmt->execute();
    $stmt->close();
    addAuditoria($conn, $id_emp, $uid, 'HISTORIAL_CREAR', "$tipo: $desc");
    json(['success' => true], 201);
}

/* ═══════════════════════════════════════════
   CREAR CREDENCIALES
   ═══════════════════════════════════════════ */
function crearCredenciales($conn, $uid, $input) {
    $id_emp = (int)($input['id_empleado'] ?? 0);
    $correo = $input['correo'] ?? '';
    $password = $input['password'] ?? '';
    $id_rol = (int)($input['id_rol'] ?? 0);

    if (!$id_emp || !$correo || !$password) json(['error'=>'Empleado, correo y contraseña requeridos'], 400);

    $stmt = $conn->prepare("SELECT e.id_empleado, e.nombres, e.apellidos, e.id_user FROM empleado e WHERE e.id_empleado = ?");
    $stmt->bind_param("i", $id_emp);
    $stmt->execute();
    $emp = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$emp) json(['error'=>'Empleado no encontrado'], 404);
    // Only block if empleado already has its own user account (id_empleado matches)
    if ($emp['id_user']) {
        $stmt = $conn->prepare("SELECT id_user FROM usuario WHERE id_user = ? AND id_empleado = ?");
        $emp_id_user = (int)$emp['id_user'];
        $stmt->bind_param("ii", $emp_id_user, $id_emp);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($r && $r->num_rows) { $stmt->close(); json(['error'=>'El empleado ya tiene credenciales vinculadas (user #'.$emp['id_user'].')'], 400); }
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT id_user FROM usuario WHERE correo = ?");
    $stmt->bind_param("s", $correo);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r->num_rows) { $stmt->close(); json(['error'=>'El correo ya está registrado'], 400); }
    $stmt->close();

    $conn->begin_transaction();
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $nombre_completo = $emp['nombres'] . ' ' . $emp['apellidos'];
        $username = explode('@', $correo)[0];

        $stmt = $conn->prepare("INSERT INTO usuario (nombre, correo, password, nombre_completo, activo, validar_sesion, id_empleado) VALUES (?,?,?,?,1,1,?)");
        $stmt->bind_param("ssssi", $username, $correo, $hash, $nombre_completo, $id_emp);
        $stmt->execute();
        $id_user = (int)$conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE empleado SET id_user = ? WHERE id_empleado = ?");
        $stmt->bind_param("ii", $id_user, $id_emp);
        $stmt->execute();
        $stmt->close();

        if ($id_rol) {
            $stmt = $conn->prepare("INSERT INTO usuario_rol (id_user, id_rol) VALUES (?, ?)");
            $stmt->bind_param("ii", $id_user, $id_rol);
            $stmt->execute();
            $stmt->close();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $detalle = "Usuario #$id_user creado";
        $stmt = $conn->prepare("INSERT INTO empleado_auditoria (id_empleado, id_user, accion, detalle, ip, fecha) VALUES (?, ?, 'CREAR_CREDENCIALES', ?, ?, NOW())");
        $stmt->bind_param("iiss", $id_emp, $uid, $detalle, $ip);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        json(['success'=>true, 'id_user'=>$id_user, 'usuario'=>$username, 'correo'=>$correo], 201);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'], 500);
    }
}

/* ═══════════════════════════════════════════
   ELIMINAR EMPLEADO
   ═══════════════════════════════════════════ */
function eliminarEmpleado($conn, $uid, $input) {
    $id = (int)($input['id_empleado'] ?? 0);
    if (!$id) json(['error'=>'ID requerido'],400);
    $stmt = $conn->prepare("SELECT id_empleado, nombres, apellidos, id_user FROM empleado WHERE id_empleado = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $emp = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$emp) json(['error'=>'Empleado no encontrado'],404);
    $conn->begin_transaction();
    try {
        // Desactivar usuario vinculado
        if ($emp['id_user']) {
            $stmt = $conn->prepare("UPDATE usuario SET activo=0 WHERE id_user = ?");
            $emp_id_user = (int)$emp['id_user'];
            $stmt->bind_param("i", $emp_id_user);
            $stmt->execute();
            $stmt->close();
        }
        // Auditoría ANTES de eliminar
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $detalle = "Empleado {$emp['nombres']} {$emp['apellidos']} eliminado";
        $stmt = $conn->prepare("INSERT INTO empleado_auditoria (id_empleado, id_user, accion, detalle, ip, fecha) VALUES (?, ?, 'ELIMINAR', ?, ?, NOW())");
        $stmt->bind_param("iiss", $id, $uid, $detalle, $ip);
        $stmt->execute();
        $stmt->close();
        // Eliminar empleado
        $stmt = $conn->prepare("DELETE FROM empleado WHERE id_empleado = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        json(['success'=>true]);
    } catch (Exception $e) {
        $conn->rollback();
        json(['error'=>'Error interno del servidor'],500);
    }
}

/* ═══════════════════════════════════════════
   VINCULAR USUARIO
   ═══════════════════════════════════════════ */
function vincularUsuario($conn, $uid, $input) {
    $id_emp = (int)($input['id_empleado'] ?? 0);
    $id_user_v = (int)($input['id_user'] ?? 0);
    if (!$id_emp || !$id_user_v) json(['error' => 'Datos requeridos'], 400);
    // Verify empleado belongs to user
    $stmt = $conn->prepare("SELECT id_empleado FROM empleado WHERE id_empleado = ? AND id_user = ?");
    $stmt->bind_param("ii", $id_emp, $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    if (!$r->num_rows) { $stmt->close(); json(['error' => 'No autorizado'], 403); }
    $stmt->close();
    $stmt = $conn->prepare("UPDATE empleado SET id_user = ? WHERE id_empleado = ?");
    $stmt->bind_param("ii", $id_user_v, $id_emp);
    $stmt->execute();
    $stmt->close();
    // Also update usuario with empleado data
    $stmt = $conn->prepare("SELECT nombres, apellidos, correo_corporativo FROM empleado WHERE id_empleado = ?");
    $stmt->bind_param("i", $id_emp);
    $stmt->execute();
    $emp = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($emp) {
        $nombre_completo = $emp['nombres'] . ' ' . $emp['apellidos'];
        $stmt = $conn->prepare("UPDATE usuario SET nombre_completo = ?, id_empleado = ? WHERE id_user = ?");
        $stmt->bind_param("sii", $nombre_completo, $id_emp, $id_user_v);
        $stmt->execute();
        $stmt->close();
    }
    addAuditoria($conn, $id_emp, $uid, 'VINCULAR_USUARIO', "Vinculado a usuario #$id_user_v");
    json(['success' => true]);
}
