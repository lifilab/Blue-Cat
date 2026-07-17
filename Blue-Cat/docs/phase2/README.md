# Fase 2: Integridad funcional del POS

## Objetivo de salida

Ninguna venta confirmada puede dejar pedido, detalle, stock, pagos o caja en estados contradictorios. Los escenarios críticos deben poder repetirse automáticamente y el stock no puede quedar negativo.

## Auditoría inicial

| Área | Estado encontrado | Riesgo | Tratamiento |
|---|---|---|---|
| Transacción de venta | Ya existía una transacción general | Base útil, pero faltaba proteger reintentos y datos enviados por el navegador | Fortalecer la misma transacción |
| Idempotencia | Inexistente | Doble venta y doble descuento de stock | Clave única por cuenta y respuesta reproducible |
| Stock concurrente | Actualización condicional no negativa | Kardex y orden de bloqueos aún requerían pruebas | Bloqueo de producto y prueba de dos procesos |
| Precios y descuentos | El cliente enviaba importes | Manipulación o precio obsoleto | Precio de catálogo bloqueado y promoción recalculada |
| Pagos | Alias libres y sobrepago no validado | Totales incoherentes | Catálogo canónico y reglas de vuelto |
| Caja | Sumaba todos los métodos a `monto_actual` | Cuadre físico falso | Solo efectivo altera el cajón |
| Apertura | Caja asociada al usuario, sin identidad física estable | Dos terminales no se distinguen correctamente | Pendiente en corte 2 |
| Anulación | Reponía stock, pero registraba reversas como efectivo | Caja y reporte por método incorrectos | Conservar método original y afectar cajón solo si es efectivo |
| Devolución | Marca booleana del pedido y acepta subtotales enviados | Impide varias devoluciones parciales y permite montos inválidos | Pendiente en corte 3 |
| Folios | Facturación usa `MAX(folio)+1` | Folios repetidos bajo concurrencia | Pendiente en corte 4 |
| Impresión | Depende del HTML vivo del modal | Difícil reimprimir tras cierre/falla | Pendiente en corte 4 |

## Entregas de la fase

### Corte 1 — Venta, pagos y concurrencia

Estado: implementado y probado.

- Migración `016_pos_integrity.sql`.
- Migración `017_pos_quote_conversion.sql` para que una cotización solo se convierta mediante la venta canónica.
- Idempotencia transaccional y conflicto por contenido distinto.
- Precio y promoción verificados por el servidor.
- Caja y sesión bloqueadas durante la venta.
- Pagos normalizados, monto recibido y vuelto explícitos.
- Efectivo físico separado de tarjeta y transferencia.
- Precisión: unidad entera; peso/volumen con tres decimales.
- Reversas conservan el método de pago.
- Pruebas de reintento, pago mixto y última unidad concurrente.

### Corte 2 — Caja física y cuadre

Estado: implementado y probado.

- Identidad estable de caja/terminal dentro de la cuenta.
- Una apertura activa por caja física y asignación de cajero.
- Totales por efectivo, crédito, débito, transferencia y otro.
- Ingresos, retiros y diferencias con libro verificable.
- Compatibilidad y migración de sesiones históricas.

### Corte 3 — Anulaciones y devoluciones

Estado: implementado y probado para anulaciones y devoluciones parciales sucesivas.

- Motivo estructurado y supervisor vinculados a cada reversa.
- Devoluciones parciales múltiples limitadas a cantidad vendida menos cantidad devuelta.
- Monto de devolución calculado por el servidor.
- Política explícita de devolución a stock vendible, dañado o bloqueado.
- Pruebas de doble devolución y reversa concurrente.

### Corte 4 — Documentos e impresión

Estado: implementado y probado para folios, snapshot y reimpresión POS.

- Contador de folios transaccional por cuenta y tipo documental.
- Restricción única para impedir duplicados.
- Snapshot persistente de boleta y endpoint de reimpresión.
- Una falla de impresora nunca revierte ni repite una venta confirmada.
- Historial de impresión y reimpresión auditado.

La emisión electrónica tributaria y la integración con SII no forman parte de esta fase; el folio implementado es el correlativo interno del sistema.

## Evidencia automatizada actual

- `php scripts/test-pos-integrity.php`: reglas puras de pagos, vuelto y hash estable.
- `php scripts/test-api-tenant-isolation.php`: venta real, reintento idempotente, conflicto, pago mixto y caja física.
- `php scripts/test-pos-concurrency.php`: dos procesos venden simultáneamente una unidad; solo uno confirma.
- `php scripts/test-promotion-engine.php`: reglas por cantidad, descuentos, precio especial, compra X/Y, combos, límites, vigencia y segmentación. Véase [PROMOTIONS.md](PROMOTIONS.md).
- La migración 016 se valida desde cero y sobre una copia de la base local antes de aplicarse a datos reales.

## Decisiones operativas

- Una venta confirmada es inmutable. Los endpoints antiguos de edición y eliminación responden conflicto; toda corrección usa anulación o devolución.
- La reimpresión usa el contenido monetario y comercial persistido al vender. El logo se carga desde la configuración vigente para no duplicar imágenes grandes en cada documento.
- Una devolución en efectivo requiere una caja abierta con saldo suficiente.
- `pos_caja_fisica` identifica el puesto físico; dos cajeros no pueden abrir el mismo código al mismo tiempo.

## Criterio de cierre

La fase termina cuando los cuatro cortes están completos, las migraciones desde una copia real y desde cero pasan, el rollback está documentado y todos los escenarios se ejecutan en CI sobre MySQL.
