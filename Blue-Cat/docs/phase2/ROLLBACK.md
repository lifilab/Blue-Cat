# Recuperación y rollback de las migraciones 016 y 017

## Regla

Nunca se prueba el rollback por primera vez sobre la base activa. Antes de migrar se crea un `mysqldump --single-transaction` y se valida que el archivo no esté vacío.

## Recuperación recomendada

La migración agrega contratos que el código nuevo necesita. El rollback seguro es restaurar conjuntamente:

1. detener el acceso al POS;
2. conservar aparte la base fallida para diagnóstico;
3. restaurar el backup previo en una base nueva;
4. volver al commit anterior de la aplicación;
5. verificar conteos de `pedido`, `detalle_pedido`, `metodo_de_pago`, `stock`, `pos_caja` y `pos_movimiento_caja`;
6. reabrir el acceso solo después de una venta de prueba.

No se ofrece un `DOWN.sql` que elimine columnas automáticamente porque podría borrar ventas, folios, snapshots o devoluciones creadas después de actualizar. Una restauración completa mantiene aplicación y datos en la misma versión.

## Verificaciones posteriores

- Todos los pedidos tienen `pago_total = precio_total` y `vuelto >= 0`.
- Ningún stock disponible es negativo.
- Cada caja histórica tiene `id_caja_fisica`.
- No existen folios duplicados por cuenta, tipo y folio.
- Cada clave idempotente completada apunta a un pedido de la misma cuenta.
- Las cantidades devueltas no superan las cantidades vendidas.
