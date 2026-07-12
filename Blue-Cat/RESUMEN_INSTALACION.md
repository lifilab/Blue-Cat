# ✅ Sistema de Productos por Peso - Instalación Completa

## 📦 Archivos Creados

### Scripts de Instalación
1. **`instalar_peso_completo.php`** ⭐ Principal
   - Ejecuta toda la migración automáticamente
   - Crea productos de prueba
   - Interfaz visual con pasos
   - URL: `http://localhost/Blue-Cat/instalar_peso_completo.php`

2. **`validar_instalacion_peso.php`** 🔍
   - Verifica que la instalación fue exitosa
   - Muestra productos creados
   - Valida campos DECIMAL
   - URL: `http://localhost/Blue-Cat/validar_instalacion_peso.php`

3. **`install_peso.php`** (Alternativa)
   - Solo migración de BD
   - Sin productos de prueba

4. **`actualizar_productos_peso.php`** (Opcional)
   - Para configurar productos existentes
   - Interfaz para seleccionar tipo de venta

### Documentación
5. **`INSTALACION_RAPIDA.md`** ⭐ Empezar aquí
   - Guía rápida en 3 pasos
   - Ejemplos prácticos
   - Solución de problemas

6. **`GUIA_PRODUCTOS_PESO.md`**
   - Documentación completa
   - Ejemplos detallados
   - Casos de uso

7. **`crear_productos_prueba.sql`**
   - Script SQL alternativo
   - Para ejecución manual

### Archivos Modificados
- `assets/api/_db.php` - Soporte DECIMAL
- `assets/api/pos.php` - Cálculo por peso
- `assets/api/inventario.php` - Crear productos con unidades
- `assets/js/pos.js` - Modal para peso, carrito con decimales

## 🚀 Cómo Instalar

### Opción 1: Instalación Automática (Recomendada)

```
1. Abre: http://localhost/Blue-Cat/instalar_peso_completo.php
2. Espera a que termine
3. Valida: http://localhost/Blue-Cat/validar_instalacion_peso.php
4. Prueba en el POS
```

### Opción 2: Instalación Manual

```bash
1. Ejecuta: install_peso.php
2. Ejecuta: crear_productos_prueba.sql (en MySQL)
3. Valida: validar_instalacion_peso.php
```

## 🧪 Productos de Prueba

Se crearán automáticamente 8 productos:

| Producto | Precio | Stock | Tipo | Código |
|----------|--------|-------|------|--------|
| Pan | $2,500/kg | 25 kg | PESO | 7801001001001 |
| Manzanas | $1,500/kg | 100 kg | PESO | 7801001001002 |
| Plátanos | $800/kg | 50 kg | PESO | 7801001001003 |
| Carne Molida | $6,500/kg | 30 kg | PESO | 7801001001004 |
| Aceite | $3,200/L | 10 L | VOLUMEN | 7801001001005 |
| Leche | $1,200/L | 20 L | VOLUMEN | 7801001001006 |
| Coca-Cola 2L | $1,500/u | 24 u | UNIDAD | 7801001001007 |
| Arroz 1kg | $1,800/u | 50 u | UNIDAD | 7801001001008 |

## 💡 Ejemplo de Uso

### Vender Pan (0.75 kg)

1. Ve al **POS**
2. Busca "Pan"
3. Click en el producto
4. Modal aparece:
   ```
   Cantidad en kg: [0.750]
   Precio por kg: [2500]
   Subtotal: $1,875
   ```
5. Click en "Agregar"
6. Carrito muestra: `Pan - 0.75 kg - $1,875`
7. Completa la venta
8. Stock restante: `24.25 kg`

## 📊 Reflejo en el Sistema

### En Ventas
```
Venta #12345 - 06/07/2026 14:30
─────────────────────────────────
Pan - 0.75 kg × $2,500 = $1,875
Manzanas - 1.2 kg × $1,500 = $1,800
Coca-Cola 2L - 2 u × $1,500 = $3,000
─────────────────────────────────
Total: $6,675
```

### En Kardex
```
Producto: Pan
─────────────────────────────────
Fecha       | Tipo  | Entrada | Salida  | Saldo
06/07 10:00 | IN    | 25.0    |         | 25.0
06/07 14:30 | VENTA |         | 0.75    | 24.25
```

### En Inventario
```
Producto: Pan
Stock: 24.25 kg
Mínimo: 5 kg
Máximo: 50 kg
Estado: OK
```

## ⚠️ IMPORTANTE: Seguridad

**Después de la instalación, ELIMINA estos archivos:**

```bash
instalar_peso_completo.php
install_peso.php
actualizar_productos_peso.php
validar_instalacion_peso.php
```

## 🐛 Solución de Problemas

### No veo el modal para peso
- Verifica que el producto tenga `tipo_venta = 'PESO'`
- Recarga con Ctrl+F5
- Revisa consola del navegador (F12)

### Stock no se actualiza
- Verifica instalación con `validar_instalacion_peso.php`
- Revisa log: `C:\laragon\tmp\php_errors.log`
- Verifica tabla `stock`

### Precio incorrecto
- El precio debe ser **por unidad** (ej: $2,500 por kg, no por gramo)
- Verifica campo `precio_venta` en producto

## 📞 Soporte

1. Revisa `INSTALACION_RAPIDA.md`
2. Consulta `GUIA_PRODUCTOS_PESO.md`
3. Valida con `validar_instalacion_peso.php`
4. Revisa logs de errores

## ✅ Checklist Final

- [ ] Ejecuté `instalar_peso_completo.php`
- [ ] Validé con `validar_instalacion_peso.php`
- [ ] Creé un producto de prueba con tipo "PESO"
- [ ] Vendí el producto desde el POS
- [ ] Verifiqué que el stock se actualizó
- [ ] Revisé el kardex y muestra decimales
- [ ] Eliminé los archivos de instalación

---

**Versión:** 1.0  
**Fecha:** 06/07/2026  
**Estado:** ✅ Listo para producción
