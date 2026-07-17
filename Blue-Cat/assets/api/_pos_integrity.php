<?php

function posCanonicalPaymentMethod($value): string {
    $method = strtoupper(trim((string) $value));
    $method = strtr($method, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U',
    ]);

    $aliases = [
        'EFECTIVO' => 'EFECTIVO',
        'TARJETA' => 'TARJETA_CREDITO',
        'CREDITO' => 'TARJETA_CREDITO',
        'TARJETA_CREDITO' => 'TARJETA_CREDITO',
        'DEBITO' => 'TARJETA_DEBITO',
        'TARJETA_DEBITO' => 'TARJETA_DEBITO',
        'TRANSFERENCIA' => 'TRANSFERENCIA',
        'OTRO' => 'OTRO',
    ];

    if (!isset($aliases[$method])) {
        throw new InvalidArgumentException('Método de pago no permitido.');
    }

    return $aliases[$method];
}

function posPaymentValue($payment, string $key, $default = null) {
    if (is_array($payment)) return $payment[$key] ?? $default;
    if (is_object($payment)) return $payment->{$key} ?? $default;
    return $default;
}

/**
 * metodo_de_pago.monto represents the amount applied to the sale. Money handed
 * over by the customer and change are stored separately on pedido.
 */
function posNormalizePayments(array $payments, int $saleTotal): array {
    if ($saleTotal <= 0) {
        throw new InvalidArgumentException('El total de la venta debe ser mayor a cero.');
    }
    if (!$payments) {
        throw new InvalidArgumentException('Se requiere al menos un método de pago.');
    }

    $prepared = [];
    $received = 0;
    $cashTendered = 0;
    $nonCashApplied = 0;

    foreach ($payments as $payment) {
        $rawMethod = posPaymentValue($payment, 'metodo', posPaymentValue($payment, 'nombre_metodo_pago', ''));
        $method = posCanonicalPaymentMethod($rawMethod);
        $amount = filter_var(posPaymentValue($payment, 'monto', null), FILTER_VALIDATE_INT);
        if ($amount === false || $amount <= 0) {
            throw new InvalidArgumentException('Cada pago debe tener un monto entero mayor a cero.');
        }
        $reference = trim((string) posPaymentValue($payment, 'referencia', ''));
        if (strlen($reference) > 100) {
            throw new InvalidArgumentException('La referencia de pago supera 100 caracteres.');
        }

        $prepared[] = ['metodo' => $method, 'monto_recibido' => $amount, 'referencia' => $reference];
        $received += $amount;
        if ($method === 'EFECTIVO') $cashTendered += $amount;
        else $nonCashApplied += $amount;
    }

    if ($nonCashApplied > $saleTotal) {
        throw new InvalidArgumentException('Los pagos no efectivos no pueden superar el total de la venta.');
    }
    if ($received < $saleTotal) {
        throw new InvalidArgumentException('El pago es inferior al total de la venta.');
    }

    $cashApplied = $saleTotal - $nonCashApplied;
    if ($cashTendered < $cashApplied) {
        throw new InvalidArgumentException('El efectivo recibido no cubre el saldo pendiente.');
    }
    $change = $cashTendered - $cashApplied;
    if ($change > 0 && $cashTendered === 0) {
        throw new InvalidArgumentException('Solo un pago en efectivo puede generar vuelto.');
    }

    $normalized = [];
    $remainingCash = $cashApplied;
    foreach ($prepared as $payment) {
        $applied = $payment['monto_recibido'];
        if ($payment['metodo'] === 'EFECTIVO') {
            $applied = min($applied, $remainingCash);
            $remainingCash -= $applied;
        }
        if ($applied <= 0) continue;
        $normalized[] = [
            'metodo' => $payment['metodo'],
            'monto' => $applied,
            'referencia' => $payment['referencia'],
        ];
    }

    $appliedTotal = array_sum(array_column($normalized, 'monto'));
    if ($appliedTotal !== $saleTotal) {
        throw new RuntimeException('Los pagos aplicados no coinciden con el total de la venta.');
    }

    return [
        'pagos' => $normalized,
        'pago_total' => $appliedTotal,
        'monto_recibido' => $received,
        'vuelto' => $change,
    ];
}

function posIntegrityNormalize($value) {
    if (is_object($value)) $value = (array) $value;
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) ksort($value);
        foreach ($value as $key => $item) $value[$key] = posIntegrityNormalize($item);
    }
    return $value;
}

function posSaleRequestHash($data): string {
    $payload = [
        'items' => $data->items ?? [],
        'pagos' => $data->pagos ?? [],
        'tipo_documento' => $data->tipo_documento ?? 'BOLETA',
        'id_cliente' => $data->id_cliente ?? null,
        'cliente' => $data->cliente ?? null,
        'cliente_nombre' => $data->cliente_nombre ?? '',
        'cliente_rut' => $data->cliente_rut ?? '',
        'cliente_correo' => $data->cliente_correo ?? '',
        'cliente_telefono' => $data->cliente_telefono ?? '',
        'id_promocion' => $data->id_promocion ?? null,
        'id_cotizacion' => $data->id_cotizacion ?? null,
        'descuento' => $data->descuento ?? 0,
    ];
    return hash('sha256', json_encode(posIntegrityNormalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function posNextFolio(mysqli $conn, int $accountId, string $documentType): array {
    $documentType = strtoupper(trim($documentType));
    $prefixes = ['BOLETA' => 'B', 'FACTURA' => 'F', 'NOTA_VENTA' => 'NV',
        'NOTA_CREDITO' => 'NC', 'NOTA_DEBITO' => 'ND'];
    if (!isset($prefixes[$documentType])) {
        throw new InvalidArgumentException('Tipo de documento no permitido en el POS.');
    }
    $stmt = $conn->prepare("INSERT INTO pos_folio_contador(id_cuenta,tipo_documento,ultimo_folio)
        VALUES (?,?,1) ON DUPLICATE KEY UPDATE ultimo_folio=LAST_INSERT_ID(ultimo_folio+1)");
    $stmt->bind_param('is',$accountId,$documentType);$stmt->execute();
    $folio=$stmt->affected_rows===1?1:(int)$conn->insert_id;$stmt->close();
    if ($folio<=0) throw new RuntimeException('No se pudo asignar el folio del documento.');
    return ['folio'=>$folio,'numero'=>$prefixes[$documentType].'-'.str_pad((string)$folio,8,'0',STR_PAD_LEFT),'tipo'=>$documentType];
}
