<?php

// ──────────────────────────────────────────────────────────────────────────────
// Blue-Cat ERP v1.0 — Core Database Helper
// ──────────────────────────────────────────────────────────────────────────────

// ── Configuration ────────────────────────────────────────────────────────────

defined('DB_HOST') or define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_USER') or define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') or define('DB_PASS', getenv('DB_PASS') ?: '');
defined('DB_NAME') or define('DB_NAME', getenv('DB_NAME') ?: 'erp');

// ── Database Connection (Singleton) ──────────────────────────────────────────

function getDB(): mysqli {
    static $db = null;
    if ($db === null) {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($db->connect_error) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error'   => true,
                'message' => 'Error de conexión a la base de datos',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $db->set_charset('utf8mb4');
    }
    return $db;
}

// ── JSON Response Helper ─────────────────────────────────────────────────────

function json($data, int $code = 200): void {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Session / Auth Helpers ───────────────────────────────────────────────────

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getSessionUserId(): int {
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
}

function requireUser(): int {
    $uid = getSessionUserId();
    if ($uid === 0) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => true,
            'message' => 'No autorizado. Inicie sesión.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $uid;
}

// ── JSON Input Helper ────────────────────────────────────────────────────────

function getJsonInput(): ?object {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}

// ── Permission Checker ───────────────────────────────────────────────────────

$_permisoCache = [];

function verificarPermiso(string $modulo, string $accion): bool {
    global $_permisoCache;
    $uid = getSessionUserId();
    if ($uid === 0) {
        return false;
    }
    $key = "{$uid}:{$modulo}:{$accion}";
    if (array_key_exists($key, $_permisoCache)) {
        return $_permisoCache[$key];
    }

    $conn  = getDB();
    $sql   = "SELECT COUNT(*) AS cnt
              FROM permiso p
              JOIN rol_permiso rp ON p.id_permiso = rp.id_permiso
              JOIN usuario_rol ur ON rp.id_rol = ur.id_rol
              WHERE ur.id_user = ?
                AND p.modulo   = ?
                AND p.accion   = ?";
    $stmt  = $conn->prepare($sql);
    $stmt->bind_param('iss', $uid, $modulo, $accion);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();
    $granted = ((int) $row['cnt']) > 0;
    $stmt->close();

    $_permisoCache[$key] = $granted;

    if (!$granted) {
        logAccess($uid, "permiso:{$modulo}:{$accion}", 'DENEGADO', "Permiso denegado para módulo '$modulo' acción '$accion'");
    }

    return $granted;
}

// ── Audit Logger ─────────────────────────────────────────────────────────────

function logAccess(int $uid, string $accion, string $resultado, string $detalle = ''): void {
    $conn = getDB();
    $payload = json_encode(['detalle' => $detalle], JSON_UNESCAPED_UNICODE);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $nivel = $resultado === 'DENEGADO' ? 'WARNING' : 'INFO';
    $sql  = "INSERT INTO core_auditoria
                (id_user, accion, entidad, id_entidad, valor_nuevo, resultado, ip, user_agent, nivel)
             VALUES (?, ?, 'permiso', NULL, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('issssss', $uid, $accion, $payload, $resultado, $ip, $userAgent, $nivel);
    $stmt->execute();
    $stmt->close();
}

// ── Stock Helpers ────────────────────────────────────────────────────────────
// stock.disponible is the SINGLE source of truth

function getDefaultBodega(mysqli $conn): int {
    $sql  = "SELECT id_bodega FROM bodega WHERE codigo = 'BOD-001' AND activo = 1 LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return (int) $row['id_bodega'];
    }
    $stmt->close();

    $sql  = "SELECT id_bodega FROM bodega WHERE activo = 1 ORDER BY id_bodega ASC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return (int) $row['id_bodega'];
    }
    $stmt->close();

    return 0;
}

function descontarStock(mysqli $conn, int $id_producto, int $id_bodega, int $cantidad): int {
    $sql  = "UPDATE stock
             SET disponible = disponible - ?
             WHERE id_producto = ?
               AND id_bodega   = ?
               AND disponible  >= ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiii', $cantidad, $id_producto, $id_bodega, $cantidad);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function reponerStock(mysqli $conn, int $id_producto, int $id_bodega, int $cantidad): int {
    $sql  = "UPDATE stock
             SET disponible = disponible + ?
             WHERE id_producto = ?
               AND id_bodega   = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $cantidad, $id_producto, $id_bodega);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function actualizarStock(mysqli $conn, int $id_producto, int $id_bodega, string $campo, float $delta): int {
    $allowed = ['disponible', 'reservado', 'comprometido', 'en_transito', 'danado', 'bloqueado', 'devuelto', 'produccion'];
    if (!in_array($campo, $allowed, true)) {
        throw new InvalidArgumentException('Campo de stock invalido.');
    }
    if ($id_producto <= 0 || $id_bodega <= 0) {
        throw new InvalidArgumentException('Producto o bodega invalida.');
    }

    $sql = "SELECT id_stock, {$campo}
            FROM stock
            WHERE id_producto = ?
              AND id_bodega   = ?
            LIMIT 1
            FOR UPDATE";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $id_producto, $id_bodega);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $nuevo = (float) $row[$campo] + $delta;
        if ($nuevo < 0) {
            throw new RuntimeException('Stock insuficiente.');
        }
        $id_stock = (int) $row['id_stock'];
        $sql = "UPDATE stock SET {$campo} = ? WHERE id_stock = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('di', $nuevo, $id_stock);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    } else {
        if ($delta < 0) {
            throw new RuntimeException('Stock insuficiente.');
        }
        $sql = "INSERT INTO stock (id_producto, id_bodega, {$campo}) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iid', $id_producto, $id_bodega, $delta);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COALESCE(SUM(disponible), 0) AS total FROM stock WHERE id_producto = ?");
    $stmt->bind_param('i', $id_producto);
    $stmt->execute();
    $totalRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total = (float) ($totalRow['total'] ?? 0);
    $stmt = $conn->prepare("UPDATE producto SET cantidad = ? WHERE id_producto = ?");
    $stmt->bind_param('di', $total, $id_producto);
    $stmt->execute();
    $stmt->close();

    return $affected;
}

function actualizarKardex(
    mysqli $conn,
    int    $uid,
    int    $id_producto,
    int    $id_bodega,
    string $tipo,
    int    $id_doc,
    string $doc_tipo,
    int    $entrada,
    int    $salida,
    int    $costo_unitario,
    string $obs = ''
): void {
    // Determine running saldo = last saldo + entrada - salida
    $sql  = "SELECT saldo_cantidad
             FROM kardex
             WHERE id_producto = ?
               AND id_bodega   = ?
             ORDER BY id_kardex DESC
             LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $id_producto, $id_bodega);
    $stmt->execute();
    $result = $stmt->get_result();
    $lastSaldo = 0;
    if ($row = $result->fetch_assoc()) {
        $lastSaldo = (int) $row['saldo_cantidad'];
    }
    $stmt->close();

    $nuevoSaldo = $lastSaldo + $entrada - $salida;

    $sql  = "INSERT INTO kardex (id_user, id_producto, id_bodega, tipo, id_doc, doc_tipo, entrada, salida, saldo_cantidad, costo_unitario, obs, fecha)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiisisiiiii', $uid, $id_producto, $id_bodega, $tipo, $id_doc, $doc_tipo, $entrada, $salida, $nuevoSaldo, $costo_unitario, $obs);
    $stmt->execute();
    $stmt->close();
}

// ── Cuenta Helpers (Account — Owner + Employees) ─────────────────────────────

function getCuentaId(mysqli $conn, int $uid): int {
    $sql  = "SELECT id_cuenta FROM usuario WHERE id_user = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return (int) $row['id_cuenta'];
    }
    $stmt->close();

    $sql  = "SELECT id_cuenta FROM empleado WHERE id_user = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stmt->close();
        return (int) $row['id_cuenta'];
    }
    $stmt->close();

    return 0;
}

function getUsuariosCuentaIds(mysqli $conn, int $uid): array {
    $idCuenta = getCuentaId($conn, $uid);
    if ($idCuenta === 0) {
        return [$uid];
    }

    $ids = [$uid];

    $sql  = "SELECT id_user FROM usuario WHERE id_cuenta = ?
             UNION
             SELECT id_user FROM empleado WHERE id_cuenta = ? AND id_user IS NOT NULL";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $idCuenta, $idCuenta);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $id = (int) $row['id_user'];
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    $stmt->close();

    return $ids;
}

function sqlUsuariosCuentaIn(mysqli $conn, int $uid, string $column): string {
    $ids = getUsuariosCuentaIds($conn, $uid);
    if (empty($ids)) {
        return "{$column} IN (0)";
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return "{$column} IN ({$placeholders})";
}
