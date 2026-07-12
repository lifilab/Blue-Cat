<?php
date_default_timezone_set('America/Santiago');

require_once __DIR__ . '/env_loader.php';
loadEnv(dirname(__DIR__, 2) . '/.env');

// ──────────────────────────────────────────────────────────────────────────────
// Blue-Cat ERP v1.0 — Core Database Helper
// ──────────────────────────────────────────────────────────────────────────────

// ── Configuration ────────────────────────────────────────────────────────────

defined('DB_HOST') or define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_USER') or define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') or define('DB_PASS', getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''));
defined('DB_NAME') or define('DB_NAME', getenv('DB_NAME') ?: 'erp');
defined('DB_PORT') or define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));

// ── Database Connection (Singleton) ──────────────────────────────────────────

function getDB(): mysqli {
    static $db = null;
    if ($db === null) {
        if (!class_exists('mysqli')) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => true,
                'message' => 'La extensión PHP mysqli no está instalada o habilitada.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
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

function makeJsonInputObject(array $data): ArrayObject {
    $obj = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
    foreach ($data as $key => $value) {
        $obj[$key] = is_array($value) ? makeJsonInputObject($value) : $value;
    }
    return $obj;
}

function getJsonInput(): ?ArrayObject {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? makeJsonInputObject($data) : null;
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
    $nivel = ($resultado === 'OK') ? 'INFO' : 'WARN';
    $sql  = "INSERT INTO core_auditoria (id_user, accion, resultado, valor_nuevo, nivel, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $detalleJson = json_encode(['detalle' => $detalle], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt->bind_param('issss', $uid, $accion, $resultado, $detalleJson, $nivel);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // Un fallo de auditoría nunca debe bloquear la operación solicitada.
    }
    $stmt->close();
}

// ── Stock Helpers ────────────────────────────────────────────────────────────
// stock.disponible is the SINGLE source of truth

function getDefaultBodega(mysqli $conn): int {
    $sql  = "SELECT id_bodega FROM bodega WHERE codigo = 'BOD-001' AND estado = 'ACTIVA' LIMIT 1";
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

function sincronizarCantidadProducto(mysqli $conn, int $id_producto): void {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(disponible),0) AS total FROM stock WHERE id_producto=?");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la sincronizacion de stock: ' . $conn->error);
    }
    $stmt->bind_param('i', $id_producto);
    $stmt->execute();
    $r = $stmt->get_result();
    $total = 0.0;
    if ($r && ($row = $r->fetch_assoc())) {
        $total = (float) ($row['total'] ?? 0);
    }
    $stmt->close();

    $stmt = $conn->prepare("UPDATE producto SET cantidad=? WHERE id_producto=?");
    if (!$stmt) {
        throw new RuntimeException('No se pudo actualizar la cantidad del producto: ' . $conn->error);
    }
    $stmt->bind_param('di', $total, $id_producto);
    $stmt->execute();
    $stmt->close();
}

function actualizarStock(mysqli $conn, int $id_producto, int $id_bodega, string $campo, float $delta): int {
    $allowed = ['disponible', 'reservado', 'comprometido', 'en_transito', 'danado', 'bloqueado', 'devuelto', 'produccion'];
    if (!in_array($campo, $allowed, true)) {
        throw new InvalidArgumentException('Campo de stock invalido');
    }

    $delta = (float) $delta;
    if (abs($delta) < 0.000001) {
        return 0;
    }

    $campoSql = "`{$campo}`";
    $stmt = $conn->prepare("UPDATE stock SET {$campoSql} = {$campoSql} + ? WHERE id_producto=? AND id_bodega=? AND {$campoSql} + ? >= 0 ORDER BY id_stock ASC LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la actualizacion de stock: ' . $conn->error);
    }
    $stmt->bind_param('diid', $delta, $id_producto, $id_bodega, $delta);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        $stmt = $conn->prepare("SELECT id_stock FROM stock WHERE id_producto=? AND id_bodega=? ORDER BY id_stock ASC LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('No se pudo verificar la existencia del stock: ' . $conn->error);
        }
        $stmt->bind_param('ii', $id_producto, $id_bodega);
        $stmt->execute();
        $r = $stmt->get_result();
        $existe = $r && $r->num_rows > 0;
        $stmt->close();

        if ($existe) {
            throw new RuntimeException('Stock insuficiente para completar la operacion');
        }

        if ($delta < 0) {
            throw new RuntimeException('No existe stock para descontar');
        }

        $stmt = $conn->prepare("INSERT INTO stock (id_producto,id_bodega,{$campoSql}) VALUES (?,?,?)");
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar el alta de stock: ' . $conn->error);
        }
        $stmt->bind_param('iid', $id_producto, $id_bodega, $delta);
        $stmt->execute();
        $stmt->close();
        $affected = 1;
    }

    sincronizarCantidadProducto($conn, $id_producto);
    return $affected;
}

function descontarStock(mysqli $conn, int $id_producto, int $id_bodega, int $cantidad): int {
    try {
        return actualizarStock($conn, $id_producto, $id_bodega, 'disponible', -1 * $cantidad);
    } catch (Throwable $e) {
        return 0;
    }
}

function reponerStock(mysqli $conn, int $id_producto, int $id_bodega, int $cantidad): int {
    try {
        return actualizarStock($conn, $id_producto, $id_bodega, 'disponible', $cantidad);
    } catch (Throwable $e) {
        return 0;
    }
}

function actualizarKardex(
    mysqli $conn,
    int    $uid,
    int    $id_producto,
    int    $id_bodega,
    string $tipo,
    int    $id_doc,
    string $doc_tipo,
    float  $entrada,
    float  $salida,
    float  $costo_unitario,
    string $obs = ''
): void {
    // Determine running saldo = last saldo + entrada - salida.
    // The SQL schema uses tipo_movimiento/id_documento/documento_tipo/saldo/observaciones.
    $sql  = "SELECT saldo
             FROM kardex
             WHERE id_producto = ?
               AND id_bodega   = ?
             ORDER BY id_kardex DESC
             LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ii', $id_producto, $id_bodega);
    $stmt->execute();
    $result = $stmt->get_result();
    $lastSaldo = 0;
    if ($row = $result->fetch_assoc()) {
        $lastSaldo = (float) $row['saldo'];
    }
    $stmt->close();

    $nuevoSaldo = $lastSaldo + $entrada - $salida;
    $costoTotal = ($entrada > 0 ? $entrada : $salida) * $costo_unitario;

    $sql  = "INSERT INTO kardex (id_producto, id_bodega, tipo_movimiento, id_documento, documento_tipo, entrada, salida, saldo, costo_unitario, costo_total, id_user, observaciones, fecha)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('iisissddddis', $id_producto, $id_bodega, $tipo, $id_doc, $doc_tipo, $entrada, $salida, $nuevoSaldo, $costo_unitario, $costoTotal, $uid, $obs);
    $stmt->execute();
    $stmt->close();
}

// ── Cuenta Helpers (Account — Owner + Employees) ─────────────────────────────

function getCuentaId(mysqli $conn, int $uid): int {
    $sql  = "SELECT COALESCE(id_cuenta, 0) AS id_cuenta FROM usuario WHERE id_user = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $idCuenta = 0;
    if ($row = $result->fetch_assoc()) {
        $idCuenta = (int) $row['id_cuenta'];
    }
    $stmt->close();
    return $idCuenta;
}

function getUsuariosCuentaIds(mysqli $conn, int $uid): array {
    $idCuenta = getCuentaId($conn, $uid);
    if ($idCuenta <= 0) {
        return [$uid];
    }

    $ids = [];
    $sql = "SELECT id_user FROM usuario WHERE id_cuenta = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $idCuenta);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = (int) $row['id_user'];
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $stmt->close();
    }

    if (!in_array($uid, $ids, true)) {
        $ids[] = $uid;
    }
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
