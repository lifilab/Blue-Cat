<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

function source(string $path, array &$errors): string
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        $errors[] = 'No se pudo leer ' . $path;
        return '';
    }
    return $contents;
}

function requiresText(string $contents, string $needle, string $message, array &$errors): void
{
    if (!str_contains($contents, $needle)) $errors[] = $message;
}

function forbidsText(string $contents, string $needle, string $message, array &$errors): void
{
    if (str_contains($contents, $needle)) $errors[] = $message;
}

$ventasJs = source($root . '/assets/js/ventas.js', $errors);
$cuadreJs = source($root . '/assets/js/cuadre_de_ventas.js', $errors);
$ventasHtml = source($root . '/public/ventas.html', $errors);
$cuadreHtml = source($root . '/public/cuadre_de_ventas.html', $errors);
$ventasApi = source($root . '/assets/api/ventas.php', $errors);
$posApi = source($root . '/assets/api/pos.php', $errors);
$returnsApi = source($root . '/assets/api/_pos_returns.php', $errors);
$migration = source($root . '/database/migrations/027_sales_immutability_permissions.sql', $errors);

foreach ([$ventasJs, $cuadreJs] as $frontend) {
    forbidsText($frontend, "accion: 'editar'", 'La UI no puede enviar la acción heredada editar.', $errors);
    forbidsText($frontend, "accion:'editar'", 'La UI no puede enviar la acción heredada editar.', $errors);
    forbidsText($frontend, "accion: 'eliminar'", 'La UI no puede enviar la acción heredada eliminar.', $errors);
    forbidsText($frontend, "accion:'eliminar'", 'La UI no puede enviar la acción heredada eliminar.', $errors);
}

foreach ([$ventasHtml, $cuadreHtml] as $page) {
    forbidsText($page, 'id="edit-modal"', 'No debe existir el modal para editar ventas confirmadas.', $errors);
    forbidsText($page, 'id="edit-venta-modal"', 'No debe existir el modal para editar ventas del cuadre.', $errors);
    forbidsText($page, 'id="delete-modal"', 'No debe existir el modal heredado de eliminar ventas.', $errors);
    forbidsText($page, 'id="delete-venta-modal"', 'No debe existir el modal heredado de eliminar ventas del cuadre.', $errors);
}

requiresText($ventasJs, "posPermisos.indexOf('ver')", 'Ventas debe quedar de solo lectura cuando falta pos.ver.', $errors);
requiresText($cuadreJs, "posPermissions.indexOf('ver')", 'Cuadre debe quedar de solo lectura cuando falta pos.ver.', $errors);
requiresText($ventasJs, 'SupervisorApproval.handle', 'Ventas debe reintentar mediante autorización puntual de Supervisor.', $errors);
requiresText($ventasHtml, 'supervisor-approval.js', 'Ventas debe cargar el componente de autorización de Supervisor.', $errors);
requiresText($cuadreJs, 'SupervisorApproval.handle', 'Cuadre debe reintentar mediante autorización puntual de Supervisor.', $errors);
requiresText($cuadreHtml, 'supervisor-approval.js', 'Cuadre debe cargar el componente de autorización de Supervisor.', $errors);
requiresText($ventasJs, "accion: 'venta_anular'", 'Ventas debe usar el endpoint canónico de anulación.', $errors);
requiresText($ventasJs, "accion: 'devolucion_crear'", 'Ventas debe usar el endpoint canónico de devolución.', $errors);
requiresText($ventasJs, 'solicitarMotivoCorreccion', 'Ventas debe solicitar un motivo real antes de corregir.', $errors);
requiresText($ventasJs, 'motivo: motivo', 'Ventas debe enviar el motivo informado al backend.', $errors);
requiresText($cuadreJs, 'solicitarMotivoCorreccion', 'Cuadre debe solicitar un motivo real antes de corregir.', $errors);
requiresText($cuadreJs, 'motivo:motivo', 'Cuadre debe enviar el motivo informado al backend.', $errors);

requiresText($ventasApi, "\$accion === 'editar' || \$accion === 'eliminar'", 'El API de ventas debe mantener el rechazo de compatibilidad.', $errors);
requiresText($ventasApi, '409', 'El API de ventas debe responder conflicto para mutaciones heredadas.', $errors);
requiresText($ventasApi, 'cantidad_disponible_devolucion', 'El listado debe informar la cantidad pendiente de devolución.', $errors);
requiresText($ventasApi, 'SELECT dp.*', 'El listado debe incluir id_detalle_pedido en cada ítem.', $errors);
requiresText($posApi, "supervisorRequire('pos.anular_venta'", 'La anulación debe exigir la política de Supervisor.', $errors);
requiresText($returnsApi, "supervisorRequire('pos.devolucion'", 'La devolución debe exigir la política de Supervisor.', $errors);
requiresText($returnsApi, "'motivo'=>\$reason", 'La autorización de devolución debe quedar ligada al motivo.', $errors);

requiresText($migration, "modulo = 'ventas'", 'La migración debe retirar permisos heredados del módulo ventas.', $errors);
requiresText($migration, "accion IN ('editar', 'eliminar')", 'La migración debe retirar editar y eliminar.', $errors);

if ($errors) {
    fwrite(STDERR, "Contrato de ventas inmutables falló:\n- " . implode("\n- ", array_unique($errors)) . "\n");
    exit(1);
}

echo "Contrato de ventas inmutables válido.\n";
