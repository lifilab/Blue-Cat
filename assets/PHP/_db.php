<?php

function dbConfig()
{
    return array(
        'host' => getenv('DB_HOST') ?: 'localhost',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'name' => getenv('DB_NAME') ?: 'erp',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    );
}

function conectarBaseDeDatos()
{
    $config = dbConfig();
    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        $config['name'],
        $config['port']
    );

    if ($conn->connect_error) {
        responderJson(array(
            'ok' => false,
            'mensaje' => 'Error de conexion a la base de datos',
        ), 500);
        exit;
    }

    if (!$conn->set_charset($config['charset'])) {
        responderJson(array(
            'ok' => false,
            'mensaje' => 'Error configurando charset de base de datos',
        ), 500);
        exit;
    }

    return $conn;
}

function prepararJson()
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
}

function responderJson($payload, $statusCode = 200)
{
    http_response_code($statusCode);
    prepararJson();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function respuestaOk($mensaje, $extra = array())
{
    return array_merge(array(
        'ok' => true,
        'mensaje' => $mensaje,
    ), $extra);
}

function respuestaError($mensaje, $extra = array())
{
    return array_merge(array(
        'ok' => false,
        'mensaje' => $mensaje,
    ), $extra);
}

function iniciarSesionSiHaceFalta()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function obtenerUsuarioIdSesion()
{
    iniciarSesionSiHaceFalta();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
}

function requerirUsuarioAutenticado()
{
    $idUser = obtenerUsuarioIdSesion();
    if ($idUser <= 0) {
        responderJson(respuestaError('No se ha iniciado sesión'), 401);
        exit;
    }

    return $idUser;
}

function usuarioTienePermiso($conn, $idUser, $modulo, $accion)
{
    $sql = "
        SELECT 1
        FROM usuario_rol ur
        INNER JOIN rol r ON r.id_rol = ur.id_rol
        INNER JOIN rol_permiso rp ON rp.id_rol = r.id_rol
        INNER JOIN permiso p ON p.id_permiso = rp.id_permiso
        WHERE ur.id_user = ?
          AND r.activo = 1
          AND p.modulo = ?
          AND p.accion = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iss", $idUser, $modulo, $accion);
    $stmt->execute();
    $result = $stmt->get_result();
    $tienePermiso = $result->num_rows > 0;
    $stmt->close();

    return $tienePermiso;
}

function requerirPermiso($conn, $idUser, $modulo, $accion, $mensaje = 'No tiene permiso para realizar esta acción.')
{
    if (!usuarioTienePermiso($conn, $idUser, $modulo, $accion)) {
        responderJson(respuestaError($mensaje), 403);
        exit;
    }
}

function obtenerUsuariosCuentaIds($conn, $idUser)
{
    $ids = array($idUser);
    $idCuenta = 0;

    $sql = "SELECT id_cuenta FROM usuario WHERE id_user = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $idUser);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $idCuenta = isset($row['id_cuenta']) ? (int) $row['id_cuenta'] : 0;
        $stmt->close();
    }

    if ($idCuenta <= 0) {
        return $ids;
    }

    $sql = "
        SELECT id_user FROM usuario WHERE id_cuenta = ?
        UNION
        SELECT id_user FROM empleado WHERE id_cuenta = ? AND id_user IS NOT NULL
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $ids;
    }

    $stmt->bind_param("ii", $idCuenta, $idCuenta);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id_user'];
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    $stmt->close();
    return $ids;
}

function normalizarNumeroServidor($valor)
{
    $numero = trim((string) $valor);
    $numero = str_replace(array('$', ' ', "\t", "\n", "\r"), '', $numero);

    if ($numero === '') {
        return null;
    }

    $ultimaComa = strrpos($numero, ',');
    $ultimoPunto = strrpos($numero, '.');

    if ($ultimaComa !== false && $ultimoPunto !== false) {
        if ($ultimaComa > $ultimoPunto) {
            $numero = str_replace('.', '', $numero);
            $numero = str_replace(',', '.', $numero);
        } else {
            $numero = str_replace(',', '', $numero);
        }
    } elseif ($ultimaComa !== false) {
        $numero = str_replace('.', '', $numero);
        $numero = str_replace(',', '.', $numero);
    } else {
        $numero = str_replace(',', '', $numero);
    }

    return is_numeric($numero) ? (float) $numero : null;
}

function registrarAuditoriaCore($conn, $idUser, $accion, $entidad, $idEntidad, $valorAnterior, $valorNuevo, $resultado = 'OK', $nivel = 'INFO')
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $anteriorJson = json_encode($valorAnterior, JSON_UNESCAPED_UNICODE);
    $nuevoJson = json_encode($valorNuevo, JSON_UNESCAPED_UNICODE);

    $sql = "INSERT INTO core_auditoria
        (id_user, accion, entidad, id_entidad, valor_anterior, valor_nuevo, resultado, ip, user_agent, nivel)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ississssss",
        $idUser,
        $accion,
        $entidad,
        $idEntidad,
        $anteriorJson,
        $nuevoJson,
        $resultado,
        $ip,
        $userAgent,
        $nivel
    );
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}
