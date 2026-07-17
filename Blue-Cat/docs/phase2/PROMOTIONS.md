# Motor de promociones del POS

El navegador nunca decide el descuento monetario. Solicita una vista previa y el servidor vuelve a evaluar las mismas reglas dentro de la transacción de venta, con productos, precios, cliente, sucursal, fecha y promociones bloqueados.

## Reglas soportadas

- `2X1`, `3X2`, `NXM` y `CANTIDAD`: cada código/SKU completa sus grupos por separado.
- `DESCUENTO_PCT`: porcentaje sobre productos elegibles.
- `DESCUENTO_MONTO`: monto fijo, limitado al precio de la línea.
- `PRECIO_ESPECIAL`: reduce el producto al precio configurado.
- `COMPRA_X_DESCUENTO_Y`: productos requisito y productos beneficiados separados.
- `COMBO`: exige todos los productos y cantidades configurados.

Todas admiten vigencia, horario, días, categoría, marca/familia, cliente/segmento, lista de precios, sucursal, canal, prioridad, acumulabilidad y límites. Los productos se resuelven por identificador interno y se conservan código de barras y SKU como claves de negocio verificables.

## Recálculo

El POS recalcula al agregar, modificar o eliminar artículos; seleccionar o quitar cliente; ingresar o quitar cupones; recuperar una cotización y antes de abrir el cobro. La venta vuelve a calcular dentro de su transacción, por lo que una vista previa vencida o manipulada no puede alterar el total persistido.

## Persistencia y auditoría

- `detalle_pedido` conserva precio original, descuento y precio final.
- `pos_promocion_aplicacion` conserva la regla y asignación exactas por pedido.
- `pos_promocion_auditoria` registra creación, aplicación, rechazo de cupones y desactivación con usuario, fecha, motivo y descuento.
- `pos_documento_snapshot` conserva las promociones y líneas beneficiadas para reimpresión.

Las promociones utilizadas se desactivan; no se eliminan físicamente. Esto preserva las claves foráneas y la explicación histórica de ventas y devoluciones.

## Contrato principal

`POST pos.php`, acción `promociones_evaluar`:

```json
{
  "items": [{"id_producto": 10, "cantidad": 2}],
  "id_cliente": 7,
  "cupones": ["VERANO"],
  "id_sucursal": 1,
  "canal": "POS"
}
```

La respuesta contiene `subtotal`, `descuento`, `total`, `lineas`, `aplicadas`, `rechazadas` y una firma informativa. La firma no concede autoridad: el checkout siempre reevalúa.
