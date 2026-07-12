# Sistema de Productos por Peso - Guía de Instalación y Uso

## 🎯 Problema Resuelto

Ahora tu sistema puede manejar productos que se venden por peso o volumen:
- **Pan** (25 kg a $2,500/kg)
- **Frutas** (manzanas por kg)
- **Líquidos** (aceite por litro)
- **Cualquier producto** que no se venda por unidad

## 📋 Instalación

### Paso 1: Ejecutar la Migración de Base de Datos

1. Abre tu navegador y ve a:
   ```
   http://localhost/Blue-Cat/install_peso.php
   ```

2. El script ejecutará automáticamente:
   - ✅ Cambia campos de INT a DECIMAL (soporta 3 decimales)
   - ✅ Agrega campos de unidad de medida a productos
   - ✅ Inserta unidades comunes (kg, g, L, mL, etc.)
   - ✅ Actualiza productos existentes

3. **IMPORTANTE**: Elimina el archivo `install_peso.php` después de la instalación por seguridad.

### Paso 2: Verificar la Instalación

Ve al módulo de **Inventario** y verifica que:
- Puedes ver productos con su unidad de medida
- Puedes crear nuevos productos seleccionando "Tipo de Venta"

## 🛒 Cómo Crear un Producto por Peso

### Ejemplo: Pan (25 kg a $2,500/kg)

1. Ve a **Inventario** → **Productos** → **Crear Producto**

2. Completa los datos:
   - **Nombre**: Pan
   - **Código de barras**: 7801234567890 (o déjalo vacío)
   - **Precio de venta**: 2500 (precio por kg)
   - **Cantidad**: 25 (25 kg disponibles)
   - **Unidad de medida**: Kilogramo (kg)
   - **Tipo de venta**: PESO ⚠️ **IMPORTANTE**
   - **Precio por unidad**: KG

3. Guarda el producto

### Tipos de Venta Disponibles

- **UNIDAD**: Productos que se venden por unidad (botellas, paquetes, etc.)
- **PESO**: Productos que se venden por peso (pan, frutas, carne, etc.)
- **VOLUMEN**: Productos que se venden por volumen (líquidos, aceite, etc.)

## 💰 Cómo Vender Productos por Peso

### Método 1: Desde el POS

1. Ve a **POS** (Punto de Venta)

2. Busca el producto "Pan" (o escanea el código de barras)

3. Al hacer click, aparecerá un **modal especial**:
   ```
   ┌─────────────────────────┐
   │ Pan                     │
   │ Cantidad en kg: [0.500] │
   │ Precio por kg: [2500]   │
   │ Subtotal: $1,250        │
   │ [Agregar]               │
   └─────────────────────────┘
   ```

4. Ingresa la cantidad:
   - **0.5 kg** = 500 gramos
   - **1.25 kg** = 1 kg 250 gramos
   - **0.750 kg** = 750 gramos

5. El sistema calcula automáticamente:
   - 0.5 kg × $2,500/kg = **$1,250**

6. Haz click en **Agregar**

### Método 2: Editar Cantidad en el Carrito

1. Agrega el producto al carrito
2. Haz click en la cantidad para editarla
3. Ingresa la cantidad exacta en kg
4. El subtotal se recalcula automáticamente

### Visualización en el Carrito

```
┌─────────────────────────────────────┐
│ Pan                           $1,250│
│ 7801234567890                       │
│ [−] 0.5 kg [+]   $2,500/kg         │
│                        $1,250       │
└─────────────────────────────────────┘
```

## 📊 Cómo se Refleja en las Ventas

### En el Detalle de Venta

```
Venta #12345 - 05/07/2026 14:30
Cliente: Consumidor Final
─────────────────────────────────────
Productos:
  • Pan (0.5 kg)              $1,250
  • Manzanas (1.2 kg)         $2,400
  • Coca-Cola 2L (2 u)        $3,000
─────────────────────────────────────
Subtotal:                     $6,650
Total:                        $6,650
```

### En el Kardex (Movimientos de Inventario)

```
Producto: Pan
─────────────────────────────────────
Fecha       | Tipo    | Entrada | Salida | Saldo
05/07 10:00 | INGRESO | 25.0 kg |        | 25.0 kg
05/07 14:30 | VENTA   |         | 0.5 kg | 24.5 kg
05/07 15:00 | VENTA   |         | 1.2 kg | 23.3 kg
```

### En el Reporte de Ventas

- **Cantidad vendida**: 0.5 kg
- **Precio unitario**: $2,500/kg
- **Total**: $1,250

## 🔧 Cambios Técnicos Realizados

### Base de Datos

1. **Tabla `stock`**: Campos cambiados de INT a DECIMAL(10,3)
   - `disponible`, `reservado`, `comprometido`, etc.

2. **Tabla `detalle_pedido`**: 
   - `cantidad_pedida` ahora es DECIMAL(10,3)

3. **Tabla `kardex`**:
   - `entrada`, `salida`, `saldo` ahora son DECIMAL(10,3)

4. **Tabla `producto`**: Nuevos campos
   - `id_unidad`: FK a unidad_medida
   - `tipo_venta`: ENUM('UNIDAD','PESO','VOLUMEN')
   - `precio_por_unidad`: VARCHAR(20)

5. **Tabla `unidad_medida`**: Unidades predefinidas
   - Kilogramo (kg), Gramo (g), Libra (lb)
   - Litro (L), Mililitro (mL)
   - Metro (m), Centímetro (cm)
   - Unidad (u)

### Backend (PHP)

1. **`pos.php`**:
   - `ventaCrear()`: Calcula subtotales con decimales
   - `listProductos()`: Devuelve tipo_venta y unidad

2. **`inventario.php`**:
   - `producto_crear`: Acepta tipo_venta y decimales
   - `producto_editar`: Permite cambiar tipo de venta

3. **`_db.php`**:
   - `descontarStock()`: Soporta decimales
   - `actualizarStock()`: Soporta decimales
   - `actualizarKardex()`: Registra movimientos con decimales

### Frontend (JavaScript)

1. **`pos.js`**:
   - `renderProducts()`: Muestra precio por unidad ($2,500/kg)
   - `addToCart()`: Detecta productos por peso y muestra modal
   - `confirmarPeso()`: Confirma cantidad y precio
   - `renderCart()`: Muestra unidad en el carrito (0.5 kg)
   - `changeQty()`: Incrementa en 0.1 para productos por peso
   - `editQty()`: Permite editar cantidad con decimales

## 📝 Ejemplos de Uso

### Ejemplo 1: Panadería

**Productos:**
- Pan: $2,500/kg (25 kg disponibles)
- Marraqueta: $2,800/kg (15 kg disponibles)
- Hallulla: $3,000/kg (10 kg disponibles)

**Venta:**
- 0.75 kg de Pan = $1,875
- 0.5 kg de Marraqueta = $1,400
- **Total: $3,275**

### Ejemplo 2: Frutería

**Productos:**
- Manzanas: $1,500/kg (100 kg disponibles)
- Plátanos: $800/kg (50 kg disponibles)
- Naranjas: $1,200/kg (80 kg disponibles)

**Venta:**
- 1.2 kg de Manzanas = $1,800
- 0.8 kg de Plátanos = $640
- 2.5 kg de Naranjas = $3,000
- **Total: $5,440**

### Ejemplo 3: Licorería

**Productos:**
- Vino tinto: $8,000/unidad (24 unidades)
- Pisco: $12,000/Litro (5 L disponibles)
- Whisky: $25,000/750mL (12 unidades)

**Venta:**
- 2 unidades de Vino = $16,000
- 0.5 L de Pisco = $6,000
- 1 unidad de Whisky = $25,000
- **Total: $47,000**

## ⚠️ Consideraciones Importantes

1. **Precisión de Decimales**: El sistema maneja hasta 3 decimales (gramos)

2. **Stock Mínimo**: Configura el stock mínimo considerando la unidad
   - Ejemplo: Para pan, stock mínimo = 5 kg (no 5 unidades)

3. **Reportes**: Los reportes muestran cantidades en la unidad configurada
   - Ejemplo: "Vendidos: 15.5 kg de Pan"

4. **Cuadre de Caja**: Los montos se redondean a enteros para el total
   - Ejemplo: $1,250.75 → $1,251

5. **Devoluciones**: Funcionan igual, pero con decimales
   - Ejemplo: Devolver 0.3 kg de pan

## 🐛 Solución de Problemas

### Problema: No veo la opción de tipo de venta

**Solución**: 
1. Verifica que ejecutaste `install_peso.php`
2. Recarga la página del inventario
3. Limpia la caché del navegador (Ctrl+F5)

### Problema: El precio no se calcula correctamente

**Solución**:
1. Verifica que el precio sea **por unidad de medida** (por kg, no por gramo)
2. Ejemplo: Si vendes a $2,500/kg, el precio debe ser 2500 (no 2.5)

### Problema: El stock no se actualiza

**Solución**:
1. Verifica que el producto tenga `tipo_venta` configurado
2. Revisa el kardex para ver los movimientos
3. Verifica que la bodega sea la correcta

## 📞 Soporte

Si tienes problemas o preguntas:
1. Revisa los logs en `C:\laragon\tmp\php_errors.log`
2. Verifica la consola del navegador (F12)
3. Revisa que todos los archivos estén actualizados

## ✅ Checklist de Instalación

- [ ] Ejecuté `install_peso.php`
- [ ] Eliminé `install_peso.php` por seguridad
- [ ] Creé un producto de prueba con tipo "PESO"
- [ ] Vendí el producto desde el POS
- [ ] Verifiqué que el stock se actualizó correctamente
- [ ] Revisé el kardex y muestra decimales
- [ ] Verifiqué el reporte de ventas

---

**Versión**: 1.0  
**Fecha**: 05/07/2026  
**Autor**: Blue-Cat ERP Team
