# ADR 0005: Integridad transaccional e idempotencia del POS

- Estado: aceptada
- Fecha: 2026-07-13
- Fase: 2

## Contexto

Una petición de venta podía repetirse por doble clic o pérdida de respuesta. El navegador enviaba precios, descuentos y pagos que el servidor aceptaba parcialmente como fuente de verdad. Además, `pos_caja.monto_actual` sumaba tarjeta y transferencia como si fueran efectivo físico.

## Decisión

1. Cada intento lógico de venta usa una `idempotency_key` creada por el cliente y reutilizada en todos sus reintentos.
2. `pos_venta_idempotencia` impone unicidad por cuenta. La reserva, el pedido, los detalles, el stock, los pagos, los movimientos y la finalización de la clave se confirman en una sola transacción InnoDB.
3. Repetir la misma clave y contenido devuelve el pedido original. Reutilizarla con otro contenido responde conflicto y no modifica datos.
4. El servidor vuelve a leer y bloquea producto, sesión y caja. Un precio distinto al catálogo requiere la autorización de supervisor existente.
5. Los métodos canónicos son `EFECTIVO`, `TARJETA_CREDITO`, `TARJETA_DEBITO`, `TRANSFERENCIA` y `OTRO`.
6. `metodo_de_pago.monto` representa monto aplicado a la venta. `pedido.monto_recibido` registra lo entregado y `pedido.vuelto` el cambio. Solo efectivo puede producir vuelto.
7. `pos_caja.monto_actual` representa efectivo físico. Los movimientos no efectivos se conservan en el libro de caja para el cuadre por método, pero no alteran el efectivo esperado.
8. Cantidades por unidad deben ser enteras. Peso y volumen admiten hasta tres decimales.

## Consecuencias

- Un timeout ya no obliga al cajero a decidir si debe cobrar nuevamente: puede repetir la misma petición.
- Dos POS no pueden confirmar simultáneamente la última unidad.
- Los reportes deben agrupar los nombres canónicos, aunque la migración normaliza alias históricos.
- Clientes POS antiguos que no envíen clave idempotente deben actualizarse; la API rechaza ventas sin ella.
- Folios, devoluciones parciales y caja física tendrán contratos separados construidos sobre esta transacción.
