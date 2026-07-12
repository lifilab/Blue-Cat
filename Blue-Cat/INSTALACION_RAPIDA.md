# 🚀 Instalación Rápida - Sistema de Productos por Peso

## 📋 Instrucciones en 3 Pasos

### Paso 1: Ejecutar la Instalación

Abre tu navegador y ve a:

```
http://localhost/Blue-Cat/instalar_peso_completo.php
```

Este script hará automáticamente:
- ✅ Migrará la base de datos (INT → DECIMAL)
- ✅ Agregarán los campos necesarios
- ✅ Insertará las unidades de medida
- ✅ Creará 8 productos de prueba

### Paso 2: Validar la Instalación

Después de la instalación, verifica que todo funcione:

```
http://localhost/Blue-Cat/validar_instalacion_peso.php
```

Este script te mostrará:
- ✅ Estado de la base de datos
- ✅ Unidades de medida configuradas
- ✅ Productos creados
- ✅ Stock con decimales

### Paso 3: Probar en el POS

1. Ve al módulo de **POS**
2. Busca el producto **"Pan"**
3. Haz click en el producto
4. Verás un modal para ingresar el peso:
   - Cantidad: `0.5` kg
   - Precio: `$2,500`/kg
   - Subtotal: `$1,250` (calculado automáticamente)
5. Haz click en **Agregar**
6. Completa la venta
7. Verifica que el stock se actualizó correctamente

## 📦 Productos de Prueba Creados

| Producto | Precio | Stock | Tipo |
|----------|--------|-------|------|
| Pan | $2,500/kg | 25 kg | PESO |
| Manzanas | $1,500/kg | 100 kg | PESO |
| Plátanos | $800/kg | 50 kg | PESO |
| Carne Molida | $6,500/kg | 30 kg | PESO |
| Aceite | $3,200/L | 10 L | VOLUMEN |
| Leche | $1,200/L | 20 L | VOLUMEN |
| Coca-Cola 2L | $1,500/u | 24 u | UNIDAD |
| Arroz 1kg | $1,800/u | 50 u | UNIDAD |

## ⚠️ IMPORTANTE: Seguridad

**Después de la instalación, ELIMINA estos archivos:**

```bash
instalar_peso_completo.php
install_peso.php
actualizar_productos_peso.php
validar_instalacion_peso.php
```

Estos archivos pueden ser un riesgo de seguridad si se dejan en el servidor.

## 📚 Documentación Adicional

- **Guía completa:** `GUIA_PRODUCTOS_PESO.md`
- **Script SQL:** `crear_productos_prueba.sql` (alternativa manual)

## 🐛 Solución de Problemas

### Problema: No veo el modal para ingresar peso

**Solución:**
1. Verifica que el producto tenga `tipo_venta = 'PESO'`
2. Recarga la página del POS (Ctrl+F5)
3. Limpia la caché del navegador

### Problema: El stock no se actualiza

**Solución:**
1. Verifica que la instalación se completó correctamente
2. Revisa el log de errores: `C:\laragon\tmp\php_errors.log`
3. Verifica que el producto tenga stock en la tabla `stock`

### Problema: El precio no se calcula

**Solución:**
1. Verifica que el precio sea por unidad de medida (ej: $2,500 por kg, no por gramo)
2. Revisa la consola del navegador (F12) para ver errores JavaScript

## ✅ Checklist de Instalación

- [ ] Ejecuté `instalar_peso_completo.php`
- [ ] Validé la instalación con `validar_instalacion_peso.php`
- [ ] Probé vender un producto por peso en el POS
- [ ] Verifiqué que el stock se actualizó
- [ ] Revisé el kardex y muestra decimales
- [ ] Eliminé los archivos de instalación por seguridad

## 🎯 Ejemplo de Venta

**Escenario:** Cliente compra 0.75 kg de pan

1. En POS, busca "Pan"
2. Click en el producto
3. Modal aparece:
   - Cantidad: `0.75` kg
   - Precio: `$2,500`/kg
   - Subtotal: `$1,875`
4. Click en "Agregar"
5. Producto aparece en carrito: `Pan - 0.75 kg - $1,875`
6. Completa el pago
7. Stock restante: `24.25 kg`

## 📊 Reflejo en Reportes

### En Detalle de Venta:
```
Venta #12345 - 06/07/2026
Pan - 0.75 kg × $2,500 = $1,875
```

### En Kardex:
```
Fecha       | Tipo  | Entrada | Salida  | Saldo
06/07 10:00 | IN    | 25.0 kg |         | 25.0 kg
06/07 14:30 | VENTA |         | 0.75 kg | 24.25 kg
```

### En Inventario:
```
Producto: Pan
Stock: 24.25 kg
Mínimo: 5 kg
Máximo: 50 kg
```

---

**¿Necesitas ayuda?** Revisa `GUIA_PRODUCTOS_PESO.md` para más detalles.
