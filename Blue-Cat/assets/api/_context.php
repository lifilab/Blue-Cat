<?php
declare(strict_types=1);

final class TenantContext
{
    public function __construct(
        public int $userId,
        public int $accountId,
        public ?int $branchId,
        public ?int $employeeId,
        public ?int $companyId,
        public ?int $warehouseId,
        public ?int $sessionId,
        public ?int $cashId,
    ) {}

    public static function load(mysqli $db, int $userId): self
    {
        $stmt = $db->prepare(
            "SELECT u.id_user,u.id_cuenta,u.id_sucursal,u.id_empleado,s.id_empresa
               FROM usuario u
               LEFT JOIN sucursal s
                 ON s.id_sucursal=u.id_sucursal AND s.id_cuenta=u.id_cuenta
              WHERE u.id_user=? AND u.activo=1
              LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user || (int)$user['id_cuenta'] <= 0) {
            throw new RuntimeException('Usuario sin contexto de cuenta activo.');
        }

        $accountId = (int)$user['id_cuenta'];
        $warehouseId = self::firstInt(
            $db,
            "SELECT id_bodega FROM bodega
              WHERE id_cuenta=? AND estado='ACTIVA'
              ORDER BY id_bodega LIMIT 1",
            $accountId
        );
        $sessionId = self::firstInt(
            $db,
            "SELECT id_sesion FROM sesion
              WHERE id_cuenta=? AND id_user=? AND fecha_cierre IS NULL
              ORDER BY fecha_ingreso DESC,id_sesion DESC LIMIT 1",
            $accountId,
            $userId
        );
        $cashId = self::firstInt(
            $db,
            "SELECT id_caja FROM pos_caja
              WHERE id_cuenta=? AND id_user=? AND estado='ABIERTA'
              ORDER BY fecha_apertura DESC,id_caja DESC LIMIT 1",
            $accountId,
            $userId
        );

        return new self(
            $userId,
            $accountId,
            self::nullableInt($user['id_sucursal']),
            self::nullableInt($user['id_empleado']),
            self::nullableInt($user['id_empresa']),
            $warehouseId,
            $sessionId,
            $cashId,
        );
    }

    private static function nullableInt(mixed $value): ?int
    {
        $value = (int)($value ?? 0);
        return $value > 0 ? $value : null;
    }

    private static function firstInt(mysqli $db, string $sql, int ...$values): ?int
    {
        $stmt = $db->prepare($sql);
        $types = str_repeat('i', count($values));
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row ? self::nullableInt($row[0]) : null;
    }
}

function tenantContext(?int $userId = null, bool $refresh = false): TenantContext
{
    static $cache = [];
    $userId ??= getSessionUserId();
    if ($userId <= 0) {
        throw new RuntimeException('No hay un usuario autenticado.');
    }
    if ($refresh || !isset($cache[$userId])) {
        $cache[$userId] = TenantContext::load(getDB(), $userId);
    }
    return $cache[$userId];
}

function requireTenantContext(): TenantContext
{
    $userId = requireUser();
    try {
        return tenantContext($userId);
    } catch (Throwable $error) {
        json(['error' => true, 'message' => 'Contexto de cuenta invalido.'], 403);
    }
}

function tenantUserBelongs(mysqli $db, int $accountId, int $targetUserId): bool
{
    $stmt = $db->prepare('SELECT 1 FROM usuario WHERE id_user=? AND id_cuenta=? LIMIT 1');
    $stmt->bind_param('ii', $targetUserId, $accountId);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

function requireTenantUser(mysqli $db, TenantContext $context, int $targetUserId): void
{
    if (!tenantUserBelongs($db, $context->accountId, $targetUserId)) {
        json(['error' => true, 'message' => 'Recurso no encontrado.'], 404);
    }
}

function tenantRootEntities(): array
{
    return [
        'empresa' => 'id_empresa',
        'empleado' => 'id_empleado',
        'sucursal' => 'id_sucursal',
        'categoria' => 'id_categoria',
        'marca' => 'id_marca',
        'producto' => 'id_producto',
        'bodega' => 'id_bodega',
        'cliente' => 'id_cliente',
        'cliente_etiqueta' => 'id_etiqueta',
        'proveedor' => 'id_proveedor',
        'sesion' => 'id_sesion',
        'pedido' => 'id_pedido',
        'pos_caja' => 'id_caja',
        'pos_promocion' => 'id_promocion',
        'pos_cotizacion' => 'id_cotizacion',
        'pos_reserva' => 'id_reserva',
        'factura' => 'id_factura',
        'config_boleta' => 'id_config',
    ];
}

function tenantEntityBelongs(
    mysqli $db,
    TenantContext $context,
    string $table,
    int $entityId
): bool {
    $entities = tenantRootEntities();
    if (!isset($entities[$table])) {
        throw new InvalidArgumentException('Entidad tenant no registrada.');
    }
    $idColumn = $entities[$table];
    $stmt = $db->prepare("SELECT 1 FROM `{$table}` WHERE `{$idColumn}`=? AND id_cuenta=? LIMIT 1");
    $stmt->bind_param('ii', $entityId, $context->accountId);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

function requireTenantEntity(
    mysqli $db,
    TenantContext $context,
    string $table,
    int $entityId
): void {
    if (!tenantEntityBelongs($db, $context, $table, $entityId)) {
        json(['error' => true, 'message' => 'Recurso no encontrado.'], 404);
    }
}

function tenantRoleAccess(mysqli $db, TenantContext $context, int $roleId, bool $writable): bool
{
    $sql = 'SELECT id_cuenta,es_plantilla FROM rol WHERE id_rol=? AND activo=1 LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $role = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$role) return false;
    if ($writable) {
        return (int)$role['id_cuenta'] === $context->accountId && (int)$role['es_plantilla'] === 0;
    }
    return $role['id_cuenta'] === null || (int)$role['id_cuenta'] === $context->accountId;
}

function requireTenantRole(mysqli $db, TenantContext $context, int $roleId, bool $writable = false): void
{
    if (!tenantRoleAccess($db, $context, $roleId, $writable)) {
        json(['error' => true, 'message' => 'Rol no disponible para esta cuenta.'], 404);
    }
}

function provisionTenantRoles(mysqli $db, int $accountId): void
{
    $stmt = $db->prepare(
        "INSERT IGNORE INTO rol (id_cuenta,nombre,descripcion,activo,es_sistema,es_plantilla)
         SELECT ?,nombre,descripcion,activo,0,0
         FROM rol WHERE id_cuenta IS NULL AND es_plantilla=1"
    );
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        "INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
         SELECT local_role.id_rol,rp.id_permiso
         FROM rol local_role
         JOIN rol template_role
           ON template_role.id_cuenta IS NULL
          AND template_role.es_plantilla=1
          AND template_role.nombre=local_role.nombre
         JOIN rol_permiso rp ON rp.id_rol=template_role.id_rol
         WHERE local_role.id_cuenta=?"
    );
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $stmt->close();
}
