<?php
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_pos_integrity.php';
$uid = requireUser();
requireModuleEntitlement('facturas');

function requierePermiso($modulo, $accion) {
    requirePermission($modulo, $accion);
}

function addHistorial(mysqli $conn, int $idFactura, int $uid, string $accion, ?string $valorAnterior, ?string $valorNuevo): void {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $stmt = $conn->prepare('INSERT INTO factura_historial (id_factura,id_user,accion,valor_anterior,valor_nuevo,ip) VALUES (?,?,?,?,?,?)');
    if (!$stmt) throw new RuntimeException('No se pudo preparar el historial de facturación: '.$conn->error);
    $stmt->bind_param('iissss', $idFactura, $uid, $accion, $valorAnterior, $valorNuevo, $ip);
    $stmt->execute();
    $stmt->close();
}

function facturaCentavos(mixed $valor): int {
    if (is_float($valor)) {
        if (!is_finite($valor)) throw new InvalidArgumentException('El importe debe ser finito');
        $texto = (string)$valor;
    } elseif (is_int($valor)) {
        $texto = (string)$valor;
    } else {
        $texto = trim((string)$valor);
        if (str_contains($texto, ',') && !str_contains($texto, '.')) $texto = str_replace(',', '.', $texto);
    }
    if (!preg_match('/^(-?)(\d{1,16})(?:\.(\d{1,2}))?$/', $texto, $partes)) {
        throw new InvalidArgumentException('El importe admite hasta dos decimales');
    }
    $centavos = ((int)$partes[2]) * 100 + (int)str_pad($partes[3] ?? '', 2, '0');
    return ($partes[1] ?? '') === '-' ? -$centavos : $centavos;
}

function facturaDecimal(int $centavos): float {
    return $centavos / 100;
}

function facturaMiles(mixed $valor, bool $permitirCero = false): int {
    if (is_float($valor)) {
        if (!is_finite($valor)) throw new InvalidArgumentException('La cantidad debe ser finita');
        $texto = (string)$valor;
    } else {
        $texto = trim((string)$valor);
    }
    if (!preg_match('/^(\d{1,15})(?:\.(\d{1,3}))?$/', $texto, $partes)) {
        throw new InvalidArgumentException('La cantidad admite hasta tres decimales');
    }
    $miles = ((int)$partes[1]) * 1000 + (int)str_pad($partes[2] ?? '', 3, '0');
    if ($miles < 0 || (!$permitirCero && $miles === 0)) {
        throw new InvalidArgumentException($permitirCero
            ? 'La cantidad no puede ser negativa'
            : 'La cantidad debe ser mayor a cero');
    }
    return $miles;
}

function facturaDivisionRedondeada(int $numerador, int $denominador): int {
    if ($numerador < 0 || $denominador <= 0) throw new InvalidArgumentException('No se puede prorratear un importe inválido');
    return intdiv($numerador + intdiv($denominador, 2), $denominador);
}

function facturaProrratearCentavos(int $monto, int $parte, int $total): int {
    if ($monto < 0 || $parte < 0 || $total <= 0 || $parte > $total) {
        throw new InvalidArgumentException('No se puede prorratear un importe inválido');
    }
    if ($monto === 0 || $parte === 0) return 0;
    if ($parte === $total) return $monto;
    $cociente = intdiv($monto, $total);
    $resto = $monto % $total;
    return ($cociente * $parte) + (int)round(($resto / $total) * $parte, 0, PHP_ROUND_HALF_UP);
}

function facturaIdempotencyKey(array|ArrayAccess $input, string $operacion): string {
    $key = trim((string)($input['idempotency_key'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,63}$/', $key)) {
        throw new InvalidArgumentException("Cada {$operacion} requiere una clave de idempotencia valida");
    }
    return $key;
}

/** @return array<string,mixed>|null */
function facturaNotaIdempotente(
    mysqli $conn,
    int $accountId,
    string $tipo,
    string $idempotencyKey,
    string $solicitudHash
): ?array {
    $stmt = $conn->prepare('SELECT id_factura,numero,total,estado,solicitud_hash
        FROM factura
        WHERE id_cuenta=? AND tipo=? AND idempotency_key=?
        LIMIT 1 FOR UPDATE');
    if (!$stmt) throw new RuntimeException('No se pudo verificar la idempotencia fiscal: '.$conn->error);
    $stmt->bind_param('iss', $accountId, $tipo, $idempotencyKey);
    $stmt->execute();
    $existente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existente) return null;
    if (!is_string($existente['solicitud_hash'])
        || !hash_equals($existente['solicitud_hash'], $solicitudHash)) {
        throw new DomainException('La clave de idempotencia ya fue usada con otra nota fiscal');
    }
    return $existente;
}

function bloquearNotaCreditoLegacy(mysqli $conn, int $accountId, int $idFacturaOriginal): void {
    $stmt = $conn->prepare("SELECT r.id_factura_nota
        FROM factura_legacy_reconciliacion r
        JOIN factura n ON n.id_factura=r.id_factura_nota AND n.id_cuenta=r.id_cuenta
        LEFT JOIN factura_legacy_origen_candidato c ON c.id_factura_nota=r.id_factura_nota AND c.id_cuenta=r.id_cuenta
        WHERE r.id_cuenta=? AND n.tipo='NOTA_CREDITO' AND n.estado<>'ANULADA'
          AND r.bloquea_nueva_nc=1 AND r.resuelta_manual=0
          AND (n.id_factura_origen=? OR c.id_factura_origen=?)
        LIMIT 1");
    if (!$stmt) throw new RuntimeException('No se pudo verificar la conciliacion legacy: '.$conn->error);
    $stmt->bind_param('iii', $accountId, $idFacturaOriginal, $idFacturaOriginal);
    $stmt->execute();
    $bloqueada = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($bloqueada) {
        throw new DomainException('Existe una nota de credito legacy ambigua; debe conciliarse antes de emitir una nueva nota');
    }
}

function facturaLegacyProductoNormalizado(mixed $valor): string {
    $texto = preg_replace('/\s+/u', ' ', trim((string)$valor)) ?? trim((string)$valor);
    return function_exists('mb_strtolower')
        ? mb_strtolower($texto, 'UTF-8')
        : strtolower($texto);
}

function listarConciliacionesLegacy(mysqli $conn, int $uid): void {
    $tenant = tenantContext($uid);
    $stmt = $conn->prepare("SELECT r.id_factura_nota,r.estado,r.bloquea_nueva_nc,r.detalle,
            r.created_at,r.updated_at,n.numero,n.folio,n.tipo,n.estado AS estado_documento,
            n.fecha_emision,n.total,n.id_factura_origen,c.razon_social,c.rut,
            (SELECT COUNT(*) FROM factura_legacy_origen_candidato oc
             WHERE oc.id_cuenta=r.id_cuenta AND oc.id_factura_nota=r.id_factura_nota) AS cantidad_candidatos
        FROM factura_legacy_reconciliacion r
        JOIN factura n ON n.id_cuenta=r.id_cuenta AND n.id_factura=r.id_factura_nota
        LEFT JOIN cliente c ON c.id_cuenta=n.id_cuenta AND c.id_cliente=n.id_cliente
        WHERE r.id_cuenta=? AND r.resuelta_manual=0 AND r.estado<>'VINCULADA'
        ORDER BY r.created_at,r.id_factura_nota
        LIMIT 100");
    if (!$stmt) throw new RuntimeException('No se pudo preparar la lista de conciliaciones legacy: '.$conn->error);
    $stmt->bind_param('i', $tenant->accountId);
    $stmt->execute();
    $pendientes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $porNota = [];
    $stmt = $conn->prepare("SELECT oc.id_factura_nota,oc.id_factura_origen,oc.evidencia,
            o.numero,o.folio,o.fecha_emision,o.total,o.estado,c.razon_social,c.rut
        FROM factura_legacy_origen_candidato oc
        JOIN factura_legacy_reconciliacion r
          ON r.id_cuenta=oc.id_cuenta AND r.id_factura_nota=oc.id_factura_nota
        JOIN factura o
          ON o.id_cuenta=oc.id_cuenta AND o.id_factura=oc.id_factura_origen AND o.tipo='FACTURA'
        LEFT JOIN cliente c ON c.id_cuenta=o.id_cuenta AND c.id_cliente=o.id_cliente
        WHERE oc.id_cuenta=? AND r.resuelta_manual=0
        ORDER BY oc.id_factura_nota,o.fecha_emision DESC,o.id_factura DESC");
    if (!$stmt) throw new RuntimeException('No se pudo preparar los candidatos legacy: '.$conn->error);
    $stmt->bind_param('i', $tenant->accountId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $candidato) {
        $porNota[(int)$candidato['id_factura_nota']][] = $candidato;
    }
    $stmt->close();
    foreach ($pendientes as &$pendiente) {
        $pendiente['candidatos'] = $porNota[(int)$pendiente['id_factura_nota']] ?? [];
    }
    unset($pendiente);

    $stmt = $conn->prepare("SELECT f.id_factura,f.numero,f.folio,f.fecha_emision,f.total,f.estado,
            c.razon_social,c.rut
        FROM factura f
        LEFT JOIN cliente c ON c.id_cuenta=f.id_cuenta AND c.id_cliente=f.id_cliente
        WHERE f.id_cuenta=? AND f.tipo='FACTURA'
        ORDER BY f.fecha_emision DESC,f.id_factura DESC
        LIMIT 200");
    if (!$stmt) throw new RuntimeException('No se pudo preparar los documentos de origen: '.$conn->error);
    $stmt->bind_param('i', $tenant->accountId);
    $stmt->execute();
    $origenes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json(['data'=>$pendientes,'origenes'=>$origenes]);
}

function conciliarNotaLegacy(mysqli $conn, int $uid, array|ArrayAccess $input): void {
    $tenant = tenantContext($uid);
    $idNota = (int)($input['id_factura_nota'] ?? 0);
    $decision = strtolower(trim((string)($input['decision'] ?? '')));
    $idOrigen = (int)($input['id_factura_origen'] ?? 0);
    $motivo = trim((string)($input['motivo'] ?? ''));
    if ($idNota <= 0) json(['error'=>'Debe seleccionar una nota legacy pendiente'], 400);
    if (!in_array($decision, ['vincular','sin_origen'], true)) json(['error'=>'La decisión de conciliación no es válida'], 400);
    if ($decision === 'vincular' && $idOrigen <= 0) json(['error'=>'Debe seleccionar la FACTURA de origen'], 400);
    if ($motivo === '') json(['error'=>'El motivo de conciliación es obligatorio'], 400);
    $motivo = substr($motivo, 0, 1000);

    $conn->begin_transaction();
    try {
        // Respeta el orden de locks del resto del módulo: primero los posibles
        // documentos originales y luego la nota hija. Así no se cruza con una
        // anulación que bloquea FACTURA -> notas asociadas.
        $stmt = $conn->prepare("SELECT n.id_factura_origen
            FROM factura_legacy_reconciliacion r
            JOIN factura n ON n.id_cuenta=r.id_cuenta AND n.id_factura=r.id_factura_nota
            WHERE r.id_cuenta=? AND r.id_factura_nota=?");
        if (!$stmt) throw new RuntimeException('No se pudo consultar la conciliación legacy: '.$conn->error);
        $stmt->bind_param('ii', $tenant->accountId, $idNota);
        $stmt->execute();
        $preliminar = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$preliminar) throw new DomainException('Nota legacy pendiente no encontrada');
        $origenAnteriorLeido = $preliminar['id_factura_origen'] === null
            ? 0
            : (int)$preliminar['id_factura_origen'];
        $idsOrigen = [];
        if ($decision === 'vincular') $idsOrigen[] = $idOrigen;
        if ($origenAnteriorLeido > 0) $idsOrigen[] = $origenAnteriorLeido;
        $idsOrigen = array_values(array_unique($idsOrigen));
        sort($idsOrigen, SORT_NUMERIC);
        $origenesBloqueados = [];
        foreach ($idsOrigen as $idOrigenBloqueo) {
            $stmt = $conn->prepare('SELECT * FROM factura WHERE id_cuenta=? AND id_factura=? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('No se pudo bloquear un documento de origen: '.$conn->error);
            $stmt->bind_param('ii', $tenant->accountId, $idOrigenBloqueo);
            $stmt->execute();
            $filaOrigen = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$filaOrigen) throw new DomainException('Un documento de origen ya no existe en esta cuenta');
            $origenesBloqueados[$idOrigenBloqueo] = $filaOrigen;
        }
        if ($decision === 'vincular'
            && (string)($origenesBloqueados[$idOrigen]['tipo'] ?? '') !== 'FACTURA') {
            throw new DomainException('FACTURA de origen no encontrada en esta cuenta');
        }

        $stmt = $conn->prepare("SELECT r.estado AS estado_conciliacion,r.resuelta_manual,
                r.bloquea_nueva_nc,n.*
            FROM factura_legacy_reconciliacion r
            JOIN factura n ON n.id_cuenta=r.id_cuenta AND n.id_factura=r.id_factura_nota
            WHERE r.id_cuenta=? AND r.id_factura_nota=?
            FOR UPDATE");
        if (!$stmt) throw new RuntimeException('No se pudo bloquear la conciliación legacy: '.$conn->error);
        $stmt->bind_param('ii', $tenant->accountId, $idNota);
        $stmt->execute();
        $nota = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$nota) throw new DomainException('Nota legacy pendiente no encontrada');
        if (!in_array((string)$nota['tipo'], ['NOTA_CREDITO','NOTA_DEBITO'], true)) {
            throw new DomainException('El documento seleccionado no es una nota fiscal');
        }
        if ((int)$nota['resuelta_manual'] === 1) throw new DomainException('La nota legacy ya fue conciliada manualmente');

        $origenAnterior = $nota['id_factura_origen'] === null ? 0 : (int)$nota['id_factura_origen'];
        if ($origenAnterior !== $origenAnteriorLeido) {
            throw new DomainException('La nota legacy cambió durante la conciliación; recargue y vuelva a intentarlo');
        }

        $stmt = $conn->prepare('SELECT * FROM factura_detalle WHERE id_factura=? ORDER BY id_factura_detalle FOR UPDATE');
        if (!$stmt) throw new RuntimeException('No se pudo bloquear el detalle de la nota: '.$conn->error);
        $stmt->bind_param('i', $idNota);
        $stmt->execute();
        $detallesNota = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if ($decision === 'sin_origen') {
            $stmt = $conn->prepare('UPDATE factura_detalle SET id_detalle_origen=NULL WHERE id_factura=?');
            $stmt->bind_param('i', $idNota);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('UPDATE factura SET id_factura_origen=NULL WHERE id_cuenta=? AND id_factura=?');
            $stmt->bind_param('ii', $tenant->accountId, $idNota);
            $stmt->execute();
            $stmt->close();
            $estadoConciliacion = 'SIN_ORIGEN_CONFIRMADO';
            $detalleConciliacion = substr("Confirmada sin origen por usuario #{$uid}: {$motivo}", 0, 500);
            $stmt = $conn->prepare('UPDATE factura_legacy_reconciliacion
                SET estado=?,bloquea_nueva_nc=0,resuelta_manual=1,detalle=?
                WHERE id_cuenta=? AND id_factura_nota=?');
            $stmt->bind_param('ssii', $estadoConciliacion, $detalleConciliacion, $tenant->accountId, $idNota);
            $stmt->execute();
            $stmt->close();
            addHistorial(
                $conn,
                $idNota,
                $uid,
                'CONCILIACION_LEGACY_SIN_ORIGEN',
                json_encode(['id_factura_origen'=>$origenAnterior ?: null], JSON_UNESCAPED_UNICODE),
                json_encode(['motivo'=>$motivo], JSON_UNESCAPED_UNICODE)
            );
            if ($origenAnterior > 0) {
                addHistorial(
                    $conn,
                    $origenAnterior,
                    $uid,
                    'NOTA_LEGACY_DESVINCULADA',
                    json_encode(['id_factura_nota'=>$idNota], JSON_UNESCAPED_UNICODE),
                    json_encode(['decision'=>'sin_origen','motivo'=>$motivo], JSON_UNESCAPED_UNICODE)
                );
            }
            $conn->commit();
            json(['success'=>true,'id_factura_nota'=>$idNota,'estado'=>$estadoConciliacion]);
        }

        if (!$detallesNota && (string)$nota['tipo'] === 'NOTA_CREDITO') {
            throw new DomainException('La nota de crédito no tiene líneas conciliables');
        }
        $stmt = $conn->prepare('SELECT * FROM factura_detalle WHERE id_factura=? ORDER BY id_factura_detalle FOR UPDATE');
        if (!$stmt) throw new RuntimeException('No se pudo bloquear el detalle de la FACTURA original: '.$conn->error);
        $stmt->bind_param('i', $idOrigen);
        $stmt->execute();
        $detallesOrigen = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (!$detallesOrigen) throw new DomainException('La FACTURA de origen no tiene líneas conciliables');

        $origenPorId = [];
        foreach ($detallesOrigen as $detalleOrigen) {
            $origenPorId[(int)$detalleOrigen['id_factura_detalle']] = $detalleOrigen;
        }
        $mapeos = [];
        foreach ($detallesNota as $detalleNota) {
            $candidatos = [];
            $productoNotaNormalizado = facturaLegacyProductoNormalizado($detalleNota['producto']);
            foreach ($detallesOrigen as $detalleOrigen) {
                if ($detalleNota['id_producto'] !== null) {
                    if ($detalleOrigen['id_producto'] !== null
                        && (int)$detalleOrigen['id_producto'] === (int)$detalleNota['id_producto']) {
                        $candidatos[] = $detalleOrigen;
                    }
                } elseif ($productoNotaNormalizado !== ''
                    && $detalleOrigen['id_producto'] === null
                    && facturaLegacyProductoNormalizado($detalleOrigen['producto'])
                        === $productoNotaNormalizado) {
                    $candidatos[] = $detalleOrigen;
                }
            }
            $idDetalleNota = (int)$detalleNota['id_factura_detalle'];
            if (count($candidatos) === 1) {
                $mapeos[$idDetalleNota] = (int)$candidatos[0]['id_factura_detalle'];
            } elseif ((string)$nota['tipo'] === 'NOTA_CREDITO') {
                throw new DomainException("La línea #{$idDetalleNota} de la nota de crédito no tiene un candidato de origen inequívoco");
            } else {
                $mapeos[$idDetalleNota] = null;
            }
        }

        if ((string)$nota['tipo'] === 'NOTA_CREDITO' && (string)$nota['estado'] !== 'ANULADA') {
            $stmt = $conn->prepare("SELECT d.id_detalle_origen,SUM(d.cantidad) AS cantidad,
                    SUM(d.descuento) AS descuento,SUM(d.neto) AS neto,SUM(d.iva) AS iva,SUM(d.total) AS total
                FROM factura n
                JOIN factura_detalle d ON d.id_factura=n.id_factura
                WHERE n.id_cuenta=? AND n.id_factura_origen=? AND n.tipo='NOTA_CREDITO'
                  AND n.estado<>'ANULADA' AND n.id_factura<>? AND d.id_detalle_origen IS NOT NULL
                GROUP BY d.id_detalle_origen");
            if (!$stmt) throw new RuntimeException('No se pudo bloquear las acreditaciones existentes: '.$conn->error);
            $stmt->bind_param('iii', $tenant->accountId, $idOrigen, $idNota);
            $stmt->execute();
            $acreditado = [];
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $fila) {
                $acreditado[(int)$fila['id_detalle_origen']] = $fila;
            }
            $stmt->close();

            $totalesNota = [];
            foreach ($detallesNota as $detalleNota) {
                $idDetalleNota = (int)$detalleNota['id_factura_detalle'];
                $idDetalleOrigen = $mapeos[$idDetalleNota] ?? null;
                if ($idDetalleOrigen === null || !isset($origenPorId[$idDetalleOrigen])) {
                    throw new DomainException('Una línea de la nota de crédito quedó sin origen verificable');
                }
                $actual = $totalesNota[$idDetalleOrigen] ?? [
                    'cantidad'=>0,'descuento'=>0,'neto'=>0,'iva'=>0,'total'=>0,
                ];
                $actual['cantidad'] += facturaMiles($detalleNota['cantidad']);
                foreach (['descuento','neto','iva','total'] as $campo) {
                    $importe = facturaCentavos($detalleNota[$campo]);
                    if ($importe < 0) throw new DomainException('La nota de crédito contiene importes negativos');
                    $actual[$campo] += $importe;
                }
                $totalesNota[$idDetalleOrigen] = $actual;
            }
            foreach ($totalesNota as $idDetalleOrigen => $actual) {
                $detalleOrigen = $origenPorId[$idDetalleOrigen];
                $previo = $acreditado[$idDetalleOrigen] ?? [
                    'cantidad'=>0,'descuento'=>0,'neto'=>0,'iva'=>0,'total'=>0,
                ];
                if (facturaMiles($previo['cantidad'], true) + $actual['cantidad']
                    > facturaMiles($detalleOrigen['cantidad'])) {
                    throw new DomainException('La conciliación excede la cantidad original considerando otras notas activas');
                }
                foreach (['descuento','neto','iva','total'] as $campo) {
                    if (facturaCentavos($previo[$campo]) + $actual[$campo]
                        > facturaCentavos($detalleOrigen[$campo])) {
                        throw new DomainException("La conciliación excede el importe {$campo} original considerando otras notas activas");
                    }
                }
            }
        }

        $stmt = $conn->prepare('UPDATE factura_detalle SET id_detalle_origen=? WHERE id_factura_detalle=? AND id_factura=?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar el mapeo del detalle legacy: '.$conn->error);
        foreach ($mapeos as $idDetalleNota => $idDetalleOrigen) {
            $stmt->bind_param('iii', $idDetalleOrigen, $idDetalleNota, $idNota);
            $stmt->execute();
        }
        $stmt->close();

        $stmt = $conn->prepare('UPDATE factura SET id_factura_origen=? WHERE id_cuenta=? AND id_factura=?');
        $stmt->bind_param('iii', $idOrigen, $tenant->accountId, $idNota);
        $stmt->execute();
        $stmt->close();
        $evidencia = substr('manual:usuario#'.$uid, 0, 255);
        $stmt = $conn->prepare('INSERT IGNORE INTO factura_legacy_origen_candidato
            (id_cuenta,id_factura_nota,id_factura_origen,evidencia) VALUES (?,?,?,?)');
        $stmt->bind_param('iiis', $tenant->accountId, $idNota, $idOrigen, $evidencia);
        $stmt->execute();
        $stmt->close();
        $estadoConciliacion = 'VINCULADA_MANUAL';
        $detalleConciliacion = substr("Vinculada a FACTURA #{$idOrigen} por usuario #{$uid}: {$motivo}", 0, 500);
        $stmt = $conn->prepare('UPDATE factura_legacy_reconciliacion
            SET estado=?,bloquea_nueva_nc=0,resuelta_manual=1,detalle=?
            WHERE id_cuenta=? AND id_factura_nota=?');
        $stmt->bind_param('ssii', $estadoConciliacion, $detalleConciliacion, $tenant->accountId, $idNota);
        $stmt->execute();
        $stmt->close();
        addHistorial(
            $conn,
            $idNota,
            $uid,
            'CONCILIACION_LEGACY_VINCULADA',
            json_encode(['id_factura_origen'=>$origenAnterior ?: null], JSON_UNESCAPED_UNICODE),
            json_encode(['id_factura_origen'=>$idOrigen,'motivo'=>$motivo], JSON_UNESCAPED_UNICODE)
        );
        addHistorial(
            $conn,
            $idOrigen,
            $uid,
            'NOTA_LEGACY_VINCULADA',
            null,
            json_encode(['id_factura_nota'=>$idNota,'tipo'=>$nota['tipo'],'motivo'=>$motivo], JSON_UNESCAPED_UNICODE)
        );
        if ($origenAnterior > 0 && $origenAnterior !== $idOrigen) {
            addHistorial(
                $conn,
                $origenAnterior,
                $uid,
                'NOTA_LEGACY_DESVINCULADA',
                json_encode(['id_factura_nota'=>$idNota], JSON_UNESCAPED_UNICODE),
                json_encode(['nuevo_origen'=>$idOrigen,'motivo'=>$motivo], JSON_UNESCAPED_UNICODE)
            );
        }
        $conn->commit();
        json(['success'=>true,'id_factura_nota'=>$idNota,'id_factura_origen'=>$idOrigen,'estado'=>$estadoConciliacion]);
    } catch (DomainException|InvalidArgumentException $e) {
        $conn->rollback();
        json(['error'=>$e->getMessage()], 400);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('conciliarNotaLegacy: '.$e->getMessage());
        json(['error'=>'Error interno del servidor'], 500);
    }
}

/** @return array{filas:array<int,array<string,mixed>>,total_centavos:int,metodo:string,cantidad:int} */
function obtenerPagosPos(mysqli $conn, int $idPedido, array $pagosSnapshot): array {
    $stmt = $conn->prepare('SELECT id_metodo_de_pago,nombre_metodo_pago,monto FROM metodo_de_pago WHERE id_pedido=? ORDER BY id_metodo_de_pago FOR UPDATE');
    if (!$stmt) throw new RuntimeException('No se pudo preparar el ledger de pagos POS: '.$conn->error);
    $stmt->bind_param('i', $idPedido);
    $stmt->execute();
    $pagos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$pagos) throw new DomainException('La venta no tiene pagos POS verificables');

    if (count($pagosSnapshot) !== count($pagos)) throw new DomainException('El ledger POS no coincide con su snapshot fiscal; requiere conciliación');
    $totalCentavos = 0;
    $metodos = [];
    $filas = [];
    foreach ($pagos as $indice => $pago) {
        $idOrigen = (int)$pago['id_metodo_de_pago'];
        $metodo = posCanonicalPaymentMethod((string)$pago['nombre_metodo_pago']);
        $montoCentavos = facturaCentavos($pago['monto']);
        $snapshot = is_array($pagosSnapshot[$indice] ?? null) ? $pagosSnapshot[$indice] : (array)($pagosSnapshot[$indice] ?? []);
        $metodoSnapshot = posCanonicalPaymentMethod((string)($snapshot['metodo'] ?? $snapshot['nombre_metodo_pago'] ?? ''));
        $montoSnapshot = facturaCentavos($snapshot['monto'] ?? -1);
        if ($idOrigen <= 0 || $montoCentavos <= 0 || $metodo !== $metodoSnapshot || $montoCentavos !== $montoSnapshot) {
            throw new DomainException('La venta contiene un pago POS inválido');
        }
        $referencia = trim((string)($snapshot['referencia'] ?? ''));
        if (strlen($referencia) > 100) throw new DomainException('Una referencia del snapshot POS es demasiado larga');
        $filas[] = ['id_metodo_de_pago'=>$idOrigen,'metodo'=>$metodo,'monto_centavos'=>$montoCentavos,'referencia'=>$referencia];
        $totalCentavos += $montoCentavos;
        $metodos[$metodo] = true;
    }
    $metodoResumen = count($metodos) === 1 ? (string)array_key_first($metodos) : 'MIXTO';
    return ['filas'=>$filas,'total_centavos'=>$totalCentavos,'metodo'=>substr($metodoResumen, 0, 30),'cantidad'=>count($pagos)];
}

function insertarPagosPos(mysqli $conn, int $idFactura, int $idPedido, string $fecha, array $pagos): void {
    foreach ($pagos['filas'] as $pago) {
        $idOrigen = (int)$pago['id_metodo_de_pago'];
        $stmt = $conn->prepare('SELECT id_factura FROM factura_pago WHERE id_metodo_de_pago=? FOR UPDATE');
        $stmt->bind_param('i', $idOrigen);
        $stmt->execute();
        $existente = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existente) throw new DomainException('Un pago POS ya está ligado a otro documento; requiere conciliación');
        $metodo = (string)$pago['metodo'];
        $monto = facturaDecimal((int)$pago['monto_centavos']);
        $referencia = (string)$pago['referencia'];
        $observacion = 'Pago POS canónico del pedido #'.$idPedido;
        $stmt = $conn->prepare('INSERT INTO factura_pago (id_factura,id_metodo_de_pago,metodo,monto,fecha,referencia,observacion) VALUES (?,?,?,?,?,?,?)');
        $stmt->bind_param('iisdsss', $idFactura, $idOrigen, $metodo, $monto, $fecha, $referencia, $observacion);
        $stmt->execute();
        $stmt->close();
    }
}

function facturaRutValido(?string $rut): bool {
    return posRutValido($rut);
}

/** @return array<string,mixed> */
function construirFacturaPosCanonica(mysqli $conn, int $accountId, array $pedido, array $snapshot): array {
    $idPedido = (int)$pedido['id_pedido'];
    if (strtoupper((string)($snapshot['tipo_documento'] ?? '')) !== 'FACTURA'
        || (string)($snapshot['folio'] ?? '') !== (string)$pedido['folio']
        || (string)($snapshot['numero_documento'] ?? '') !== (string)$pedido['numero_documento']) {
        throw new DomainException('El snapshot fiscal no coincide con el folio POS; requiere conciliación');
    }
    if (!isset($snapshot['config']['iva_porcentaje']) || !is_numeric($snapshot['config']['iva_porcentaje'])) {
        throw new DomainException('La venta FACTURA no conserva su tasa de IVA en el snapshot; requiere conciliación');
    }
    $ivaBase = facturaCentavos($snapshot['config']['iva_porcentaje']);
    if ($ivaBase < 0 || $ivaBase > 10000) throw new DomainException('La tasa de IVA del snapshot no es válida');
    $itemsSnapshot = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
    $pagosSnapshot = is_array($snapshot['pagos'] ?? null) ? $snapshot['pagos'] : [];
    if (!$itemsSnapshot || !$pagosSnapshot) throw new DomainException('El snapshot fiscal está incompleto; requiere conciliación');

    $stmt = $conn->prepare('SELECT id_detalle_pedido,id_producto,cantidad_pedida,precio_unitario_original,descuento,precio_total FROM detalle_pedido WHERE id_pedido=? ORDER BY id_detalle_pedido FOR UPDATE');
    $stmt->bind_param('i', $idPedido);
    $stmt->execute();
    $detallesPedido = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($detallesPedido) !== count($itemsSnapshot)) throw new DomainException('El detalle POS no coincide con su snapshot; requiere conciliación');

    $lineas = [];
    $subtotalCentavos = 0;
    $descuentoCentavos = 0;
    $netoCentavos = 0;
    $ivaCentavos = 0;
    $totalCentavos = 0;
    foreach ($detallesPedido as $indice => $detalle) {
        $item = is_array($itemsSnapshot[$indice] ?? null) ? $itemsSnapshot[$indice] : (array)($itemsSnapshot[$indice] ?? []);
        $cantidadMiles = facturaMiles($detalle['cantidad_pedida']);
        $precioCentavos = facturaCentavos($detalle['precio_unitario_original']);
        $descuentoLinea = facturaCentavos($detalle['descuento']);
        $totalLinea = facturaCentavos($detalle['precio_total']);
        $nombre = trim((string)($item['nombre'] ?? ''));
        if ((int)($item['id_producto'] ?? 0) !== (int)$detalle['id_producto']
            || facturaMiles($item['cantidad'] ?? 0) !== $cantidadMiles
            || facturaCentavos($item['precio_original'] ?? -1) !== $precioCentavos
            || facturaCentavos($item['descuento'] ?? -1) !== $descuentoLinea
            || facturaCentavos($item['subtotal'] ?? -1) !== $totalLinea
            || $nombre === '' || strlen($nombre) > 150) {
            throw new DomainException('Una línea POS no coincide con su snapshot fiscal; requiere conciliación');
        }
        $subtotalLinea = facturaCentavos($item['subtotal_original'] ?? -1);
        if ($subtotalLinea - $descuentoLinea !== $totalLinea) throw new DomainException('Una línea POS contiene importes inconsistentes');
        $ivaLinea = $ivaBase > 0 ? facturaDivisionRedondeada($totalLinea * $ivaBase, 10000 + $ivaBase) : 0;
        $netoLinea = $totalLinea - $ivaLinea;
        $lineas[] = [
            'id_producto'=>(int)$detalle['id_producto'],'producto'=>$nombre,'cantidad_miles'=>$cantidadMiles,
            'precio_centavos'=>$precioCentavos,'descuento_centavos'=>$descuentoLinea,
            'neto_centavos'=>$netoLinea,'iva_centavos'=>$ivaLinea,'total_centavos'=>$totalLinea,
        ];
        $subtotalCentavos += $subtotalLinea;
        $descuentoCentavos += $descuentoLinea;
        $netoCentavos += $netoLinea;
        $ivaCentavos += $ivaLinea;
        $totalCentavos += $totalLinea;
    }
    $pedidoTotalCentavos = facturaCentavos($pedido['precio_total']);
    if ($totalCentavos !== $pedidoTotalCentavos
        || facturaCentavos($snapshot['total'] ?? -1) !== $pedidoTotalCentavos
        || $subtotalCentavos - $descuentoCentavos !== $totalCentavos
        || $netoCentavos + $ivaCentavos !== $totalCentavos) {
        throw new DomainException('Los totales POS no coinciden exactamente; requiere conciliación');
    }
    $pagos = obtenerPagosPos($conn, $idPedido, $pagosSnapshot);
    if ($pagos['total_centavos'] !== $pedidoTotalCentavos || facturaCentavos($pedido['pago_total']) !== $pedidoTotalCentavos) {
        throw new DomainException('Los pagos POS no coinciden exactamente con el total; requiere conciliación');
    }
    return [
        'lineas'=>$lineas,'subtotal_centavos'=>$subtotalCentavos,'descuento_centavos'=>$descuentoCentavos,
        'neto_centavos'=>$netoCentavos,'iva_centavos'=>$ivaCentavos,'total_centavos'=>$totalCentavos,
        'pagos'=>$pagos,'metodo_pago'=>$pagos['metodo'],
    ];
}

function validarFacturaPosExistente(mysqli $conn, array $factura, array $pedido, array $canonica): void {
    $esperados = [
        'subtotal'=>(int)$canonica['subtotal_centavos'],'descuento'=>(int)$canonica['descuento_centavos'],
        'neto'=>(int)$canonica['neto_centavos'],'iva'=>(int)$canonica['iva_centavos'],
        'total'=>(int)$canonica['total_centavos'],'pagado'=>(int)$canonica['total_centavos'],'saldo'=>0,
    ];
    if ((int)$factura['id_cliente'] !== (int)$pedido['id_cliente']
        || (string)$factura['folio'] !== (string)$pedido['folio']
        || (string)$factura['numero'] !== (string)$pedido['numero_documento']
        || (string)$factura['estado'] !== 'PAGADA'
        || (string)$factura['metodo_pago'] !== (string)$canonica['metodo_pago']
        || (string)($factura['sucursal'] ?? '') !== (string)($pedido['sucursal'] ?? '')
        || (string)($factura['vendedor'] ?? '') !== (string)($pedido['vendedor'] ?? '')
        || (string)$factura['fecha_emision'] !== substr((string)$pedido['fecha'], 0, 10)) {
        throw new DomainException('La factura existente difiere del documento POS; requiere conciliación y no fue modificada');
    }
    foreach ($esperados as $campo => $centavos) {
        if (facturaCentavos($factura[$campo]) !== $centavos) {
            throw new DomainException('Los totales de la factura existente requieren conciliación y no fueron modificados');
        }
    }

    $stmt = $conn->prepare('SELECT id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total FROM factura_detalle WHERE id_factura=? ORDER BY id_factura_detalle FOR UPDATE');
    $idFactura = (int)$factura['id_factura'];
    $stmt->bind_param('i', $idFactura);
    $stmt->execute();
    $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($detalles) !== count($canonica['lineas'])) throw new DomainException('El detalle de la factura existente requiere conciliación y no fue modificado');
    foreach ($detalles as $indice => $detalle) {
        $esperada = $canonica['lineas'][$indice];
        if ((int)$detalle['id_producto'] !== (int)$esperada['id_producto']
            || (string)$detalle['producto'] !== (string)$esperada['producto']
            || facturaMiles($detalle['cantidad']) !== (int)$esperada['cantidad_miles']
            || facturaCentavos($detalle['precio_unitario']) !== (int)$esperada['precio_centavos']
            || facturaCentavos($detalle['descuento']) !== (int)$esperada['descuento_centavos']
            || facturaCentavos($detalle['neto']) !== (int)$esperada['neto_centavos']
            || facturaCentavos($detalle['iva']) !== (int)$esperada['iva_centavos']
            || facturaCentavos($detalle['total']) !== (int)$esperada['total_centavos']) {
            throw new DomainException('Una línea de la factura existente requiere conciliación y no fue modificada');
        }
    }

    $stmt = $conn->prepare('SELECT id_metodo_de_pago,metodo,monto,referencia,idempotency_key FROM factura_pago WHERE id_factura=? ORDER BY id_factura_pago FOR UPDATE');
    $stmt->bind_param('i', $idFactura);
    $stmt->execute();
    $pagos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (count($pagos) !== count($canonica['pagos']['filas'])) throw new DomainException('El ledger de la factura existente requiere conciliación y no fue modificado');
    foreach ($pagos as $indice => $pago) {
        $esperado = $canonica['pagos']['filas'][$indice];
        if ((int)$pago['id_metodo_de_pago'] !== (int)$esperado['id_metodo_de_pago']
            || (string)$pago['metodo'] !== (string)$esperado['metodo']
            || facturaCentavos($pago['monto']) !== (int)$esperado['monto_centavos']
            || (string)($pago['referencia'] ?? '') !== (string)$esperado['referencia']
            || $pago['idempotency_key'] !== null) {
            throw new DomainException('Un pago de la factura existente requiere conciliación y no fue modificado');
        }
    }
}

$conn = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $accionGet = strtolower(trim((string)($_GET['accion'] ?? '')));
        if ($accionGet === 'legacy_pendientes') {
            requierePermiso('facturas','editar');
            listarConciliacionesLegacy($conn, $uid);
        } else {
            requierePermiso('facturas','ver');
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id) getFactura($conn, $uid, $id);
            else listFacturas($conn, $uid);
        }
        break;
    case 'POST':
        $input = getJsonInput();
        $accion = $input['accion'] ?? 'crear';
        if ($accion === 'crear') { requierePermiso('facturas','crear'); crearFactura($conn, $uid, $input); }
        elseif ($accion === 'pagar') { requierePermiso('facturas','editar'); registrarPago($conn, $uid, $input); }
        elseif ($accion === 'anular') { requierePermiso('facturas','eliminar'); anularFactura($conn, $uid, $input); }
        elseif ($accion === 'nota_credito') { requierePermiso('facturas','nota_credito'); crearNotaCredito($conn, $uid, $input); }
        elseif ($accion === 'nota_debito') { requierePermiso('facturas','nota_debito'); crearNotaDebito($conn, $uid, $input); }
        elseif ($accion === 'legacy_conciliar') { requierePermiso('facturas','editar'); conciliarNotaLegacy($conn, $uid, $input); }
        else json(['error'=>'Acción no válida'], 400);
        break;
    default: json(['error'=>'Método no soportado'], 405);
}

function listFacturas($conn, $uid) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = "WHERE f.id_cuenta = (SELECT id_cuenta FROM usuario WHERE id_user=?)";
    $params = [$uid];
    $types = 'i';

    if (!empty($_GET['estado']))       { $where .= " AND f.estado = ?"; $params[] = $_GET['estado']; $types .= 's'; }
    if (!empty($_GET['tipo']))         { $where .= " AND f.tipo = ?"; $params[] = $_GET['tipo']; $types .= 's'; }
    if (!empty($_GET['q'])) {
        $q = '%' . $_GET['q'] . '%';
        $where .= " AND (f.numero LIKE ? OR f.folio LIKE ? OR c.razon_social LIKE ? OR c.rut LIKE ? OR c.nombre LIKE ?)";
        $params = array_merge($params, [$q, $q, $q, $q, $q]);
        $types .= 'sssss';
    }
    if (!empty($_GET['desde']))        { $where .= " AND f.fecha_emision >= ?"; $params[] = $_GET['desde']; $types .= 's'; }
    if (!empty($_GET['hasta']))        { $where .= " AND f.fecha_emision <= ?"; $params[] = $_GET['hasta'] . ' 23:59:59'; $types .= 's'; }
    if (!empty($_GET['cliente']))      { $where .= " AND f.id_cliente = ?"; $params[] = (int)$_GET['cliente']; $types .= 'i'; }
    if (!empty($_GET['vendedor']))     { $where .= " AND f.vendedor LIKE ?"; $params[] = '%' . $_GET['vendedor'] . '%'; $types .= 's'; }
    if (!empty($_GET['min']))          { $where .= " AND f.total >= ?"; $params[] = (int)$_GET['min']; $types .= 'i'; }
    if (!empty($_GET['max']))          { $where .= " AND f.total <= ?"; $params[] = (int)$_GET['max']; $types .= 'i'; }

    $countSql = "SELECT COUNT(*) as total FROM factura f LEFT JOIN cliente c ON f.id_cliente = c.id_cliente AND c.id_cuenta=f.id_cuenta $where";
    $stmt = $conn->prepare($countSql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $listParams = $params;
    $listTypes = $types;
    $listParams[] = $limit;
    $listParams[] = $offset;
    $listTypes .= 'ii';

    $sql = "SELECT f.*, c.razon_social, c.rut, c.nombre as cliente_nombre
            FROM factura f
            LEFT JOIN cliente c ON f.id_cliente = c.id_cliente AND c.id_cuenta=f.id_cuenta
            $where
            ORDER BY f.id_factura DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($listParams) $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json([
        'data' => $rows,
        'total' => (int)$total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ]);
}

function getFactura($conn, $uid, $id) {
    $stmt = $conn->prepare("SELECT f.*, c.razon_social, c.rut, c.nombre as cliente_nombre, c.direccion, c.correo, c.telefono, c.giro
        FROM factura f LEFT JOIN cliente c ON f.id_cliente = c.id_cliente AND c.id_cuenta=f.id_cuenta WHERE f.id_factura = ? AND f.id_cuenta = (SELECT id_cuenta FROM usuario WHERE id_user=?)");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
    $factura = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$factura) json(['error'=>'Factura no encontrada'], 404);

    $stmt = $conn->prepare("SELECT * FROM factura_detalle WHERE id_factura = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $factura['detalle'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM factura_pago WHERE id_factura = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $factura['pagos'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT h.*,h.created_at AS fecha,u.nombre as usuario FROM factura_historial h LEFT JOIN usuario u ON h.id_user = u.id_user WHERE h.id_factura = ? ORDER BY h.created_at DESC LIMIT 50");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $factura['historial'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json($factura);
}

function crearFactura($conn, $uid, $input) {
    $idClienteSolicitado = (int)($input['id_cliente'] ?? 0);
    $idPedido = (int)($input['id_pedido'] ?? 0);
    $tipoSolicitado = strtoupper(trim((string)($input['tipo'] ?? 'FACTURA')));
    $observaciones = trim((string)($input['observaciones'] ?? ''));
    $ordenCompra = trim((string)($input['orden_compra'] ?? ''));
    $tenant = tenantContext($uid);

    if ($idPedido <= 0) json(['error'=>'Debe seleccionar una venta confirmada'], 400);
    if ($tipoSolicitado !== 'FACTURA') json(['error'=>'Tipo de documento no permitido en este flujo'], 400);
    if ($idClienteSolicitado > 0) requireTenantEntity($conn, $tenant, 'cliente', $idClienteSolicitado);
    requireTenantEntity($conn, $tenant, 'pedido', $idPedido);

    $conn->begin_transaction();
    try {
        // El pedido es el documento fiscal canónico. Facturas.php solamente
        // materializa su vista y ledger; nunca asigna un segundo folio.
        $stmt = $conn->prepare("SELECT p.id_pedido,p.id_cliente,p.tipo_documento,p.folio,p.numero_documento,p.precio_total,p.pago_total,p.anulado,p.devuelto,p.fecha,COALESCE(c.sucursal,'') AS sucursal,COALESCE(u.nombre,'') AS vendedor
            FROM pedido p
            JOIN sesion s ON s.id_sesion=p.id_sesion AND s.id_cuenta=?
            LEFT JOIN pos_caja c ON c.id_caja=p.id_caja AND c.id_cuenta=s.id_cuenta
            LEFT JOIN usuario u ON u.id_user=s.id_user
            WHERE p.id_pedido=? FOR UPDATE");
        $stmt->bind_param('ii', $tenant->accountId, $idPedido);
        $stmt->execute();
        $pedido = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pedido) throw new DomainException('Venta no encontrada para esta cuenta');
        if ((int)$pedido['anulado'] === 1) throw new DomainException('No se puede facturar una venta anulada');
        if ((int)($pedido['devuelto'] ?? 0) === 1) {
            throw new DomainException('No se puede facturar una venta que ya fue devuelta');
        }
        $stmt = $conn->prepare('SELECT id_devolucion FROM pos_devolucion WHERE id_pedido=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $idPedido);
        $stmt->execute();
        $devolucionExistente = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($devolucionExistente) {
            throw new DomainException('No se puede facturar una venta con devoluciones registradas');
        }
        if (strtoupper((string)$pedido['tipo_documento']) !== 'FACTURA') {
            throw new DomainException('Una boleta POS no puede convertirse en factura. Emita la venta como FACTURA o use un flujo fiscal de reemplazo explícito');
        }
        $folio = trim((string)($pedido['folio'] ?? ''));
        $numero = trim((string)($pedido['numero_documento'] ?? ''));
        if ($folio === '' || $numero === '') throw new DomainException('La venta FACTURA no tiene un folio POS canónico');

        $pedidoCliente = (int)($pedido['id_cliente'] ?? 0);
        if ($pedidoCliente <= 0) {
            throw new DomainException('Una FACTURA requiere que la venta POS tenga un cliente asociado');
        }
        if ($pedidoCliente > 0 && $idClienteSolicitado > 0 && $pedidoCliente !== $idClienteSolicitado) {
            throw new DomainException('El cliente seleccionado no coincide con la venta confirmada');
        }
        $stmt = $conn->prepare('SELECT id_cliente,rut FROM cliente WHERE id_cliente=? AND id_cuenta=? AND COALESCE(activo,1)=1');
        $stmt->bind_param('ii', $pedidoCliente, $tenant->accountId);
        $stmt->execute();
        $clienteFiscal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$clienteFiscal || !facturaRutValido($clienteFiscal['rut'] ?? null)) {
            throw new DomainException('El cliente de la FACTURA debe pertenecer a la cuenta y tener un RUT válido');
        }
        $facturaCliente = $pedidoCliente;

        $stmt = $conn->prepare('SELECT contenido_json FROM pos_documento_snapshot WHERE id_cuenta=? AND id_pedido=? LIMIT 1');
        $stmt->bind_param('ii', $tenant->accountId, $idPedido);
        $stmt->execute();
        $snapshotRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $documentoSnapshot = json_decode((string)($snapshotRow['contenido_json'] ?? ''), true);
        if (!is_array($documentoSnapshot)) throw new DomainException('La venta FACTURA no tiene un snapshot fiscal válido; requiere conciliación');
        $canonica = construirFacturaPosCanonica($conn, $tenant->accountId, $pedido, $documentoSnapshot);

        $stmt = $conn->prepare("SELECT * FROM factura WHERE id_cuenta=? AND id_pedido=? AND tipo='FACTURA' ORDER BY id_factura LIMIT 1 FOR UPDATE");
        $stmt->bind_param('ii', $tenant->accountId, $idPedido);
        $stmt->execute();
        $existente = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existente) {
            if ((string)$existente['estado'] === 'ANULADA') {
                throw new DomainException('La factura canónica de esta venta está anulada; su reemisión requiere un flujo fiscal explícito');
            }
            validarFacturaPosExistente($conn, $existente, $pedido, $canonica);
            $conn->commit();
            json(['success'=>true,'id_factura'=>(int)$existente['id_factura'],'numero'=>$numero,'folio'=>$folio,'total'=>facturaDecimal($canonica['total_centavos']),'idempotent_replay'=>true]);
        }

        $tipo = 'FACTURA';
        $fechaDocumento = substr((string)$pedido['fecha'], 0, 10);
        $sucursal = (string)$pedido['sucursal'];
        $vendedor = (string)$pedido['vendedor'];
        $subtotal = facturaDecimal($canonica['subtotal_centavos']);
        $descuento = facturaDecimal($canonica['descuento_centavos']);
        $neto = facturaDecimal($canonica['neto_centavos']);
        $iva = facturaDecimal($canonica['iva_centavos']);
        $total = facturaDecimal($canonica['total_centavos']);
        $metodoPago = (string)$canonica['metodo_pago'];
        $stmt = $conn->prepare("INSERT INTO factura (id_user,id_cuenta,id_cliente,id_pedido,folio,numero,tipo,estado,fecha_emision,fecha_vencimiento,subtotal,descuento,neto,iva,total,pagado,saldo,metodo_pago,sucursal,vendedor,observaciones,orden_compra)
            VALUES (?,?,?,?,?,?,?,'PAGADA',?,?,?,?,?,?,?, ?,0,?,?,?,?,?)");
        $stmt->bind_param('iiiisssssddddddsssss', $uid, $tenant->accountId, $facturaCliente, $idPedido, $folio, $numero, $tipo, $fechaDocumento, $fechaDocumento, $subtotal, $descuento, $neto, $iva, $total, $total, $metodoPago, $sucursal, $vendedor, $observaciones, $ordenCompra);
        $stmt->execute();
        $idFactura = (int)$conn->insert_id;
        $stmt->close();

        foreach ($canonica['lineas'] as $linea) {
            $idProducto = (int)$linea['id_producto'];
            $producto = (string)$linea['producto'];
            $cantidad = (int)$linea['cantidad_miles'] / 1000;
            $precio = facturaDecimal((int)$linea['precio_centavos']);
            $descuentoLinea = facturaDecimal((int)$linea['descuento_centavos']);
            $netoLinea = facturaDecimal((int)$linea['neto_centavos']);
            $ivaLinea = facturaDecimal((int)$linea['iva_centavos']);
            $totalLinea = facturaDecimal((int)$linea['total_centavos']);
            $stmt = $conn->prepare('INSERT INTO factura_detalle (id_factura,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iisdddddd', $idFactura, $idProducto, $producto, $cantidad, $precio, $descuentoLinea, $netoLinea, $ivaLinea, $totalLinea);
            $stmt->execute();
            $stmt->close();
        }
        insertarPagosPos($conn, $idFactura, $idPedido, $fechaDocumento, $canonica['pagos']);
        addHistorial($conn, $idFactura, $uid, 'CREAR', null, "Factura POS $numero materializada por \$$total con {$canonica['pagos']['cantidad']} pago(s)");
        $conn->commit();
        json(['success'=>true,'id_factura'=>$idFactura,'numero'=>$numero,'folio'=>$folio,'total'=>$total], 201);
    } catch (DomainException|InvalidArgumentException $e) {
        $conn->rollback();
        json(['error'=>$e->getMessage()], 400);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('crearFactura: '.$e->getMessage());
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function registrarPago($conn, $uid, $input) {
    $id_factura = (int)($input['id_factura'] ?? 0);
    $montoRaw = $input['monto'] ?? null;
    $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
    $banco = trim((string)($input['banco'] ?? ''));
    $referencia = trim((string)($input['referencia'] ?? ''));
    $observacion = trim((string)($input['observacion'] ?? ''));
    if ($id_factura <= 0 || !is_numeric($montoRaw)) json(['error'=>'Datos de pago inválidos'], 400);
    try {
        $metodo = posCanonicalPaymentMethod((string)($input['metodo'] ?? ''));
    } catch (InvalidArgumentException $e) {
        json(['error'=>$e->getMessage()], 400);
    }
    try {
        $montoCentavos = facturaCentavos($montoRaw);
    } catch (InvalidArgumentException $e) {
        json(['error'=>$e->getMessage()], 400);
    }
    if ($montoCentavos <= 0 || $montoCentavos > 999999999999999999) json(['error'=>'Monto de pago inválido'], 400);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,63}$/', $idempotencyKey)) {
        json(['error'=>'Cada pago requiere una clave de idempotencia válida'], 400);
    }
    if (strlen($banco) > 100 || strlen($referencia) > 100 || strlen($observacion) > 500) {
        json(['error'=>'Los datos complementarios del pago exceden el largo permitido'], 400);
    }
    $solicitudHash = hash('sha256', json_encode([
        'id_factura'=>$id_factura,'metodo'=>$metodo,'monto_centavos'=>$montoCentavos,
        'banco'=>$banco,'referencia'=>$referencia,'observacion'=>$observacion,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT total,pagado,saldo,estado FROM factura WHERE id_factura=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?) FOR UPDATE");
        $stmt->bind_param("ii", $id_factura, $uid);
        $stmt->execute();
        $f = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$f) throw new DomainException('Factura no encontrada');

        $stmt = $conn->prepare('SELECT id_factura_pago,solicitud_hash,monto FROM factura_pago WHERE id_factura=? AND idempotency_key=? FOR UPDATE');
        $stmt->bind_param('is', $id_factura, $idempotencyKey);
        $stmt->execute();
        $pagoExistente = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($pagoExistente) {
            if (!hash_equals((string)$pagoExistente['solicitud_hash'], $solicitudHash)) {
                throw new DomainException('La clave de idempotencia ya fue usada con otro pago');
            }
            $conn->commit();
            json([
                'success'=>true,'id_factura_pago'=>(int)$pagoExistente['id_factura_pago'],
                'pagado'=>facturaDecimal(facturaCentavos($f['pagado'])),'saldo'=>facturaDecimal(facturaCentavos($f['saldo'])),
                'estado'=>$f['estado'],'idempotent_replay'=>true,
            ]);
        }
        if ($f['estado'] === 'ANULADA') throw new DomainException('No se pueden registrar pagos en una factura anulada');
        $totalCentavos = facturaCentavos($f['total']);
        $pagadoCentavos = facturaCentavos($f['pagado']);
        $saldoCentavos = facturaCentavos($f['saldo']);
        if ($saldoCentavos <= 0) throw new DomainException('La factura ya se encuentra saldada');
        if ($totalCentavos - $pagadoCentavos !== $saldoCentavos) throw new DomainException('El saldo del documento requiere conciliación antes de registrar pagos');
        if ($montoCentavos > $saldoCentavos) throw new DomainException('El pago no puede superar el saldo pendiente');

        $nuevoPagadoCentavos = $pagadoCentavos + $montoCentavos;
        $nuevoSaldoCentavos = $totalCentavos - $nuevoPagadoCentavos;
        $nuevo_estado = $nuevoSaldoCentavos === 0 ? 'PAGADA' : 'PARCIAL';
        $monto = facturaDecimal($montoCentavos);
        $nuevo_pagado = facturaDecimal($nuevoPagadoCentavos);
        $nuevo_saldo = facturaDecimal($nuevoSaldoCentavos);

        $stmt = $conn->prepare("INSERT INTO factura_pago (id_factura,metodo,monto,banco,referencia,observacion,fecha,idempotency_key,solicitud_hash) VALUES (?,?,?,?,?,?,CURDATE(),?,?)");
        $stmt->bind_param('isdsssss', $id_factura, $metodo, $monto, $banco, $referencia, $observacion, $idempotencyKey, $solicitudHash);
        $stmt->execute();
        $idPago = (int)$conn->insert_id;
        $stmt->close();

        $stmt_upd = $conn->prepare("UPDATE factura SET pagado=?, saldo=?, estado=? WHERE id_factura=?");
        $stmt_upd->bind_param('ddsi', $nuevo_pagado, $nuevo_saldo, $nuevo_estado, $id_factura);
        $stmt_upd->execute();
        $stmt_upd->close();

        addHistorial($conn, $id_factura, $uid, 'PAGO', "Pagado: \${$f['pagado']}", "Pagado: \$$nuevo_pagado, Saldo: \$$nuevo_saldo");

        $conn->commit();
        json(['success'=>true,'id_factura_pago'=>$idPago,'pagado'=>$nuevo_pagado,'saldo'=>$nuevo_saldo,'estado'=>$nuevo_estado,'idempotent_replay'=>false]);
    } catch (DomainException|InvalidArgumentException $e) {
        $conn->rollback();
        json(['error'=>$e->getMessage()], 400);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('registrarPago: '.$e->getMessage());
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function anularFactura($conn, $uid, $input) {
    $id_factura = (int)($input['id_factura'] ?? 0);
    $motivo = trim((string)($input['motivo'] ?? 'Anulación manual'));
    if ($id_factura <= 0) json(['error'=>'Factura requerida'], 400);
    if ($motivo === '') $motivo = 'Anulación manual';
    $motivo = substr($motivo, 0, 1000);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id_factura,numero,tipo,total,estado FROM factura WHERE id_factura=? AND id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=?) FOR UPDATE");
        $stmt->bind_param("ii", $id_factura, $uid);
        $stmt->execute();
        $f = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$f) throw new DomainException('Factura no encontrada');
        if ($f['estado'] === 'ANULADA') throw new DomainException('La factura ya está anulada');
        if ((string)$f['tipo'] === 'FACTURA') {
            $stmt = $conn->prepare("SELECT id_factura FROM factura WHERE id_factura_origen=? AND tipo IN ('NOTA_CREDITO','NOTA_DEBITO') AND estado<>'ANULADA' FOR UPDATE");
            $stmt->bind_param('i', $id_factura);
            $stmt->execute();
            $notasActivas = $stmt->get_result()->num_rows;
            $stmt->close();
            if ($notasActivas > 0) throw new DomainException('No se puede anular una factura con notas de crédito o débito activas');
        }

        // Anular el documento fiscal no revierte el despacho. Una devolución
        // física debe procesarse desde POS para generar stock, Kardex y caja.

        $estado_anterior = $f['estado'];
        $stmt_upd = $conn->prepare("UPDATE factura SET estado='ANULADA' WHERE id_factura=?");
        $stmt_upd->bind_param("i", $id_factura);
        $stmt_upd->execute();
        $stmt_upd->close();
        addHistorial($conn, $id_factura, $uid, 'ANULAR', "Estado: $estado_anterior", "Estado: ANULADA - $motivo");

        $conn->commit();
        json(['success'=>true, 'msg'=>"Factura {$f['numero']} anulada. El stock no fue modificado."]);
    } catch (DomainException|InvalidArgumentException $e) {
        $conn->rollback();
        json(['error'=>$e->getMessage()], 400);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('anularFactura: '.$e->getMessage());
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function crearNotaCredito($conn, $uid, $input) {
    $idFacturaOriginal = (int)($input['id_factura'] ?? 0);
    $itemsInput = $input['items'] ?? [];
    $motivo = trim((string)($input['motivo'] ?? 'Nota de crédito'));
    $tenant = tenantContext($uid);
    if ($idFacturaOriginal <= 0 || (!is_array($itemsInput) && !($itemsInput instanceof Traversable))) json(['error'=>'Debe indicar la factura y al menos una línea original'], 400);
    $items = (array)$itemsInput;
    if (!$items) json(['error'=>'Debe indicar la factura y al menos una línea original'], 400);
    if (count($items) > 500) json(['error'=>'La nota de crédito supera el máximo de líneas permitido'], 400);
    if ($motivo === '') $motivo = 'Nota de crédito';
    $motivo = substr($motivo, 0, 1000);
    try {
        $idempotencyKey = facturaIdempotencyKey($input, 'nota de credito');
        $solicitadas = [];
        foreach ($items as $itemInput) {
            if (!is_array($itemInput) && !($itemInput instanceof Traversable)) {
                throw new InvalidArgumentException('Formato de línea inválido');
            }
            $item = (array)$itemInput;
            $idDetalle = (int)($item['id_factura_detalle'] ?? $item['id_detalle'] ?? 0);
            if ($idDetalle <= 0) throw new InvalidArgumentException('Cada línea debe identificar el detalle original');
            $cantidadMiles = facturaMiles($item['cantidad'] ?? 0);
            $solicitadas[$idDetalle] = ($solicitadas[$idDetalle] ?? 0) + $cantidadMiles;
        }
    } catch (InvalidArgumentException $error) {
        json(['error'=>$error->getMessage()], 400);
    }
    ksort($solicitadas, SORT_NUMERIC);
    $solicitudHash = hash('sha256', (string)json_encode([
        'id_factura'=>$idFacturaOriginal,
        'motivo'=>$motivo,
        'items_miles'=>$solicitadas,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $conn->begin_transaction();
    try {
        // El bloqueo del documento original serializa notas concurrentes. Así
        // dos solicitudes no pueden consumir la misma cantidad disponible.
        $stmt = $conn->prepare("SELECT * FROM factura WHERE id_factura=? AND id_cuenta=? FOR UPDATE");
        $stmt->bind_param('ii', $idFacturaOriginal, $tenant->accountId);
        $stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$original) throw new DomainException('Factura original no encontrada');
        if ((string)$original['tipo'] !== 'FACTURA') throw new DomainException('La nota de crédito solo puede referenciar una FACTURA');

        $replay = facturaNotaIdempotente(
            $conn,
            $tenant->accountId,
            'NOTA_CREDITO',
            $idempotencyKey,
            $solicitudHash
        );
        if ($replay) {
            $conn->commit();
            json([
                'success'=>true,
                'id_factura'=>(int)$replay['id_factura'],
                'numero'=>(string)$replay['numero'],
                'total'=>facturaDecimal(facturaCentavos($replay['total'])),
                'estado'=>(string)$replay['estado'],
                'idempotent_replay'=>true,
            ]);
        }
        if ((string)$original['estado'] === 'ANULADA') throw new DomainException('No se puede acreditar una factura anulada');

        bloquearNotaCreditoLegacy($conn, $tenant->accountId, $idFacturaOriginal);

        $stmt = $conn->prepare('SELECT id_factura_detalle,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total FROM factura_detalle WHERE id_factura=? ORDER BY id_factura_detalle FOR UPDATE');
        $stmt->bind_param('i', $idFacturaOriginal);
        $stmt->execute();
        $lineasOriginales = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $linea) {
            $lineasOriginales[(int)$linea['id_factura_detalle']] = $linea;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT d.id_detalle_origen,SUM(d.cantidad) AS cantidad,SUM(d.descuento) AS descuento,SUM(d.neto) AS neto,SUM(d.iva) AS iva,SUM(d.total) AS total
            FROM factura n JOIN factura_detalle d ON d.id_factura=n.id_factura
            WHERE n.id_cuenta=? AND n.id_factura_origen=? AND n.tipo='NOTA_CREDITO' AND n.estado<>'ANULADA'
            GROUP BY d.id_detalle_origen");
        $stmt->bind_param('ii', $tenant->accountId, $idFacturaOriginal);
        $stmt->execute();
        $acreditado = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $linea) {
            $acreditado[(int)$linea['id_detalle_origen']] = $linea;
        }
        $stmt->close();

        $lineasNota = [];
        $subtotalNotaCentavos = 0;
        $descuentoNotaCentavos = 0;
        $netoNotaCentavos = 0;
        $ivaNotaCentavos = 0;
        $totalNotaCentavos = 0;
        foreach ($solicitadas as $idDetalle => $cantidadSolicitadaMiles) {
            if (!isset($lineasOriginales[$idDetalle])) throw new DomainException('Una línea no pertenece a la factura original');
            $linea = $lineasOriginales[$idDetalle];
            $previo = $acreditado[$idDetalle] ?? ['cantidad'=>0,'descuento'=>0,'neto'=>0,'iva'=>0,'total'=>0];
            $cantidadOriginalMiles = facturaMiles($linea['cantidad']);
            $cantidadAcreditadaMiles = facturaMiles($previo['cantidad'], true);
            $restanteMiles = $cantidadOriginalMiles - $cantidadAcreditadaMiles;
            if ($restanteMiles <= 0 || $cantidadSolicitadaMiles > $restanteMiles) {
                throw new DomainException('La cantidad acumulada de la nota de crédito excede la línea original');
            }

            $totalOriginalCentavos = facturaCentavos($linea['total']);
            $ivaOriginalCentavos = facturaCentavos($linea['iva']);
            $descuentoOriginalCentavos = facturaCentavos($linea['descuento']);
            $totalPrevioCentavos = facturaCentavos($previo['total']);
            $ivaPrevioCentavos = facturaCentavos($previo['iva']);
            $descuentoPrevioCentavos = facturaCentavos($previo['descuento']);
            $esCierre = $cantidadSolicitadaMiles === $restanteMiles;
            if ($esCierre) {
                $totalLineaCentavos = $totalOriginalCentavos - $totalPrevioCentavos;
                $ivaLineaCentavos = $ivaOriginalCentavos - $ivaPrevioCentavos;
                $descuentoLineaCentavos = $descuentoOriginalCentavos - $descuentoPrevioCentavos;
            } else {
                $totalLineaCentavos = facturaProrratearCentavos($totalOriginalCentavos, $cantidadSolicitadaMiles, $cantidadOriginalMiles);
                $ivaLineaCentavos = facturaProrratearCentavos($ivaOriginalCentavos, $cantidadSolicitadaMiles, $cantidadOriginalMiles);
                $descuentoLineaCentavos = facturaProrratearCentavos($descuentoOriginalCentavos, $cantidadSolicitadaMiles, $cantidadOriginalMiles);
            }
            $netoLineaCentavos = $totalLineaCentavos - $ivaLineaCentavos;
            if ($totalLineaCentavos <= 0 || $netoLineaCentavos < 0 || $ivaLineaCentavos < 0 || $descuentoLineaCentavos < 0) {
                throw new DomainException('La línea original no contiene importes acreditables válidos');
            }
            $subtotalLineaCentavos = $totalLineaCentavos + $descuentoLineaCentavos;
            $lineasNota[] = [
                'id_detalle'=>$idDetalle,
                'id_producto'=>$linea['id_producto'] === null ? null : (int)$linea['id_producto'],
                'producto'=>(string)$linea['producto'],
                'cantidad'=>$cantidadSolicitadaMiles / 1000,
                'precio'=>facturaDecimal(facturaCentavos($linea['precio_unitario'])),
                'descuento'=>facturaDecimal($descuentoLineaCentavos),
                'neto'=>facturaDecimal($netoLineaCentavos),
                'iva'=>facturaDecimal($ivaLineaCentavos),
                'total'=>facturaDecimal($totalLineaCentavos),
            ];
            $subtotalNotaCentavos += $subtotalLineaCentavos;
            $descuentoNotaCentavos += $descuentoLineaCentavos;
            $netoNotaCentavos += $netoLineaCentavos;
            $ivaNotaCentavos += $ivaLineaCentavos;
            $totalNotaCentavos += $totalLineaCentavos;
        }
        if ($totalNotaCentavos <= 0 || $netoNotaCentavos + $ivaNotaCentavos !== $totalNotaCentavos) {
            throw new DomainException('Los importes de la nota de crédito no son consistentes');
        }

        $subtotalNota = facturaDecimal($subtotalNotaCentavos);
        $descuentoNota = facturaDecimal($descuentoNotaCentavos);
        $netoNota = facturaDecimal($netoNotaCentavos);
        $ivaNota = facturaDecimal($ivaNotaCentavos);
        $totalNota = facturaDecimal($totalNotaCentavos);

        $documento = posNextFolio($conn, $tenant->accountId, 'NOTA_CREDITO');
        $folio = (string)$documento['folio'];
        $numero = $documento['numero'];
        $idCliente = $original['id_cliente'] === null ? null : (int)$original['id_cliente'];
        $sucursal = trim((string)($original['sucursal'] ?? ''));
        $vendedor = trim((string)($original['vendedor'] ?? ''));
        $stmt = $conn->prepare("INSERT INTO factura (id_user,id_cuenta,id_cliente,id_pedido,id_factura_origen,idempotency_key,solicitud_hash,folio,numero,tipo,estado,fecha_emision,fecha_vencimiento,subtotal,descuento,neto,iva,total,pagado,saldo,metodo_pago,sucursal,vendedor,observaciones)
            VALUES (?,?,?,NULL,?,?,?,?,?,'NOTA_CREDITO','EMITIDA',NOW(),NOW(),?,?,?,?,?,0,0,NULL,?,?,?)");
        $stmt->bind_param('iiiissssdddddsss', $uid, $tenant->accountId, $idCliente, $idFacturaOriginal, $idempotencyKey, $solicitudHash, $folio, $numero, $subtotalNota, $descuentoNota, $netoNota, $ivaNota, $totalNota, $sucursal, $vendedor, $motivo);
        $stmt->execute();
        $idNota = (int)$conn->insert_id;
        $stmt->close();

        foreach ($lineasNota as $linea) {
            $idDetalle = $linea['id_detalle'];
            $idProducto = $linea['id_producto'];
            $producto = $linea['producto'];
            $cantidad = $linea['cantidad'];
            $precio = $linea['precio'];
            $descuento = $linea['descuento'];
            $neto = $linea['neto'];
            $iva = $linea['iva'];
            $total = $linea['total'];
            $stmt = $conn->prepare('INSERT INTO factura_detalle (id_factura,id_detalle_origen,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iiisdddddd', $idNota, $idDetalle, $idProducto, $producto, $cantidad, $precio, $descuento, $neto, $iva, $total);
            $stmt->execute();
            $stmt->close();
        }

        addHistorial($conn, $idNota, $uid, 'NOTA_CREDITO', null, "NC $numero vinculada a factura #$idFacturaOriginal por \$$totalNota - $motivo");
        addHistorial($conn, $idFacturaOriginal, $uid, 'NOTA_CREDITO_ASOCIADA', null, "NC $numero por \$$totalNota sin alterar totales ni stock");
        $conn->commit();
        json(['success'=>true,'id_factura'=>$idNota,'numero'=>$numero,'total'=>$totalNota,'idempotent_replay'=>false], 201);
    } catch (DomainException|InvalidArgumentException $e) {
        $conn->rollback();
        json(['error'=>$e->getMessage()], 400);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('crearNotaCredito: '.$e->getMessage());
        json(['error'=>'Error interno del servidor'], 500);
    }
}

function crearNotaDebito($conn, $uid, $input) {
    $idFacturaOriginal = (int)($input['id_factura'] ?? 0);
    $montoRaw = $input['monto'] ?? null;
    $motivo = trim((string)($input['motivo'] ?? 'Nota de débito'));
    if ($idFacturaOriginal <= 0) json(['error'=>'Factura y monto válidos son obligatorios'], 400);
    try {
        $montoCentavos = facturaCentavos($montoRaw);
        $idempotencyKey = facturaIdempotencyKey($input, 'nota de debito');
    } catch (InvalidArgumentException $error) {
        json(['error'=>$error->getMessage()], 400);
    }
    if ($montoCentavos <= 0 || $montoCentavos > 999999999999999999) {
        json(['error'=>'El monto de la nota de débito debe ser positivo y válido'], 400);
    }
    $monto = facturaDecimal($montoCentavos);
    if ($motivo === '') $motivo = 'Nota de débito';
    $motivo = substr($motivo, 0, 1000);
    $tenant = tenantContext($uid);
    $solicitudHash = hash('sha256', (string)json_encode([
        'id_factura'=>$idFacturaOriginal,
        'monto_centavos'=>$montoCentavos,
        'motivo'=>$motivo,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM factura WHERE id_factura=? AND id_cuenta=? FOR UPDATE');
        $stmt->bind_param('ii', $idFacturaOriginal, $tenant->accountId);
        $stmt->execute();
        $original = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$original) throw new DomainException('Factura original no encontrada');
        if ((string)$original['tipo'] !== 'FACTURA') throw new DomainException('La nota de débito solo puede referenciar una FACTURA');

        $replay = facturaNotaIdempotente(
            $conn,
            $tenant->accountId,
            'NOTA_DEBITO',
            $idempotencyKey,
            $solicitudHash
        );
        if ($replay) {
            $conn->commit();
            json([
                'success'=>true,
                'id_factura'=>(int)$replay['id_factura'],
                'numero'=>(string)$replay['numero'],
                'total'=>facturaDecimal(facturaCentavos($replay['total'])),
                'estado'=>(string)$replay['estado'],
                'idempotent_replay'=>true,
            ]);
        }
        if ((string)$original['estado'] === 'ANULADA') throw new DomainException('No se puede debitar una factura anulada');

        $netoOriginalCentavos = facturaCentavos($original['neto']);
        $ivaOriginalCentavos = facturaCentavos($original['iva']);
        $baseTributariaCentavos = $netoOriginalCentavos + $ivaOriginalCentavos;
        $ivaCentavos = $baseTributariaCentavos > 0
            ? facturaProrratearCentavos($montoCentavos, $ivaOriginalCentavos, $baseTributariaCentavos)
            : 0;
        $ivaCentavos = max(0, min($montoCentavos, $ivaCentavos));
        $netoCentavos = $montoCentavos - $ivaCentavos;
        $iva = facturaDecimal($ivaCentavos);
        $neto = facturaDecimal($netoCentavos);
        $documento = posNextFolio($conn, $tenant->accountId, 'NOTA_DEBITO');
        $folio = (string)$documento['folio'];
        $numero = $documento['numero'];
        $idCliente = $original['id_cliente'] === null ? null : (int)$original['id_cliente'];
        $sucursal = trim((string)($original['sucursal'] ?? ''));
        $vendedor = trim((string)($original['vendedor'] ?? ''));
        $stmt = $conn->prepare("INSERT INTO factura (id_user,id_cuenta,id_cliente,id_pedido,id_factura_origen,idempotency_key,solicitud_hash,folio,numero,tipo,estado,fecha_emision,fecha_vencimiento,subtotal,descuento,neto,iva,total,pagado,saldo,metodo_pago,sucursal,vendedor,observaciones)
            VALUES (?,?,?,NULL,?,?,?,?,?,'NOTA_DEBITO','EMITIDA',NOW(),NOW(),?,0,?,?,?,0,?,NULL,?,?,?)");
        $stmt->bind_param('iiiissssdddddsss', $uid, $tenant->accountId, $idCliente, $idFacturaOriginal, $idempotencyKey, $solicitudHash, $folio, $numero, $monto, $neto, $iva, $monto, $monto, $sucursal, $vendedor, $motivo);
        $stmt->execute();
        $idNota = (int)$conn->insert_id;
        $stmt->close();

        $descripcion = substr('Ajuste por nota de débito: '.$motivo, 0, 150);
        $cantidad = 1.0;
        $cero = 0.0;
        $stmt = $conn->prepare('INSERT INTO factura_detalle (id_factura,id_detalle_origen,id_producto,producto,cantidad,precio_unitario,descuento,neto,iva,total) VALUES (?,NULL,NULL,?,?,?,?,?,?,?)');
        $stmt->bind_param('isdddddd', $idNota, $descripcion, $cantidad, $monto, $cero, $neto, $iva, $monto);
        $stmt->execute();
        $stmt->close();

        addHistorial($conn, $idNota, $uid, 'NOTA_DEBITO', null, "ND $numero vinculada a factura #$idFacturaOriginal por \$$monto - $motivo");
        addHistorial($conn, $idFacturaOriginal, $uid, 'NOTA_DEBITO_ASOCIADA', null, "ND $numero por \$$monto sin alterar el documento original");
        $conn->commit();
        json(['success'=>true,'id_factura'=>$idNota,'numero'=>$numero,'total'=>$monto,'idempotent_replay'=>false], 201);
    } catch (DomainException|InvalidArgumentException $e) {
        $conn->rollback();
        json(['error'=>$e->getMessage()], 400);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('crearNotaDebito: '.$e->getMessage());
        json(['error'=>'Error interno del servidor'], 500);
    }
}
?>
