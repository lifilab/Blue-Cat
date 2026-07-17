<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/assets/api/_pos_integrity.php';

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function expectFailure(callable $operation, string $contains): void {
    try {
        $operation();
    } catch (Throwable $error) {
        expect(str_contains($error->getMessage(), $contains), "Error inesperado: {$error->getMessage()}");
        return;
    }
    throw new RuntimeException("Se esperaba un error que contuviera: {$contains}");
}

$cash = posNormalizePayments([['metodo' => 'Efectivo', 'monto' => 1500]], 1200);
expect($cash['pago_total'] === 1200, 'El efectivo aplicado debe igualar el total.');
expect($cash['monto_recibido'] === 1500, 'Debe conservarse el monto entregado.');
expect($cash['vuelto'] === 300, 'Debe calcularse el vuelto.');
expect($cash['pagos'][0]['monto'] === 1200, 'El cajón recibe efectivo neto de vuelto.');

$mixed = posNormalizePayments([
    ['metodo' => 'Débito', 'monto' => 700, 'referencia' => 'AUTH-1'],
    ['metodo' => 'EFECTIVO', 'monto' => 500],
], 1000);
expect($mixed['pago_total'] === 1000, 'El pago mixto debe cuadrar.');
expect($mixed['vuelto'] === 200, 'El vuelto mixto solo proviene del efectivo.');
expect($mixed['pagos'][0]['metodo'] === 'TARJETA_DEBITO', 'Débito debe normalizarse.');
expect($mixed['pagos'][1]['monto'] === 300, 'Solo se aplican 300 de efectivo.');

expectFailure(fn() => posNormalizePayments([['metodo' => 'TRANSFERENCIA', 'monto' => 1100]], 1000), 'no efectivos');
expectFailure(fn() => posNormalizePayments([['metodo' => 'EFECTIVO', 'monto' => 999]], 1000), 'inferior');
expectFailure(fn() => posNormalizePayments([['metodo' => 'CRIPTOMONEDA', 'monto' => 1000]], 1000), 'no permitido');
expectFailure(fn() => posNormalizePayments([['metodo' => 'EFECTIVO', 'monto' => 10.5]], 1000), 'entero');

$requestA = (object) ['items' => [(object) ['id_producto' => 1, 'cantidad' => 1]], 'pagos' => [(object) ['metodo' => 'EFECTIVO', 'monto' => 1000]]];
$requestB = (object) ['pagos' => [(object) ['monto' => 1000, 'metodo' => 'EFECTIVO']], 'items' => [(object) ['cantidad' => 1, 'id_producto' => 1]]];
expect(posSaleRequestHash($requestA) === posSaleRequestHash($requestB), 'El hash debe ser estable ante el orden de propiedades.');
$requestB->pagos[0]->monto = 999;
expect(posSaleRequestHash($requestA) !== posSaleRequestHash($requestB), 'Una petición distinta debe producir otro hash.');

echo "OK: pagos normalizados, vuelto e idempotencia estable.\n";
