# Manual de Usuario - Blue-Cat ERP

## Introducción

Blue-Cat ERP es un sistema integral de gestión empresarial que incluye:
- Punto de Venta (POS)
- Gestión de Inventario
- Gestión de Ventas
- CRM (Clientes)
- Gestión de Empleados
- Facturación
- Gestión de Proveedores
- Configuración del Sistema

---

## Primeros Pasos

### Inicio de Sesión

1. Abrir navegador y navegar a la URL del sistema
2. Ingresar nombre de usuario y contraseña
3. Click en "Iniciar Sesión"

### Cerrar Sesión

1. Click en el ícono de usuario (esquina superior derecha)
2. Seleccionar "Cerrar Sesión"
3. Confirmar cierre de sesión

---

## Módulo POS (Punto de Venta)

### Abrir Caja

1. Navegar a **POS**
2. Click en **"Abrir Caja"**
3. Ingresar:
   - Monto de apertura
   - Nombre de caja
   - Empleado
   - Sucursal
   - Nota (opcional)
4. Click en **"Abrir Caja"**

### Realizar Venta

1. **Buscar producto:**
   - Escanear código de barras, O
   - Buscar por nombre en el campo de búsqueda

2. **Agregar al carrito:**
   - Click en el producto
   - Para productos por peso: ingresar cantidad (ej: 0.5 kg)
   - Para productos por unidad: cantidad se incrementa automáticamente

3. **Modificar carrito:**
   - Cambiar cantidad: click en la cantidad y editar
   - Cambiar precio: click en el precio y editar (requiere permiso)
   - Eliminar producto: click en el ícono X

4. **Aplicar descuento:**
   - Ingresar código promocional en el campo correspondiente
   - Click en "Aplicar"

5. **Seleccionar cliente:**
   - Click en "Consumidor Final"
   - Buscar o crear cliente

6. **Cobrar:**
   - Click en **"Cobrar"**
   - Seleccionar método(s) de pago:
     - Efectivo
     - Tarjeta
     - Transferencia
     - Débito
   - Ingresar monto por cada método
   - Click en **"Cobrar"**

7. **Imprimir boleta:**
   - La boleta se muestra automáticamente
   - Click en **"Imprimir"** para imprimir
   - Click en **"Cerrar"** para finalizar

### Anular Venta

1. Navegar a **Ventas**
2. Buscar la venta a anular
3. Click en el ícono de anular (🚫)
4. Ingresar motivo de anulación
5. Click en **"Confirmar Anulación"**

### Cerrar Caja

1. Navegar a **POS**
2. Click en **"Cerrar Caja"**
3. Se redirige a **Cuadre de Ventas**
4. Revisar resumen de ventas
5. Ingresar monto real en caja
6. Click en **"Cerrar Caja y Finalizar Sesión"**

---

## Módulo Inventario

### Ver Productos

1. Navegar a **Inventario**
2. Se muestra lista de productos con:
   - Nombre
   - Código de barras
   - SKU
   - Categoría
   - Marca
   - Stock
   - Precio costo
   - Precio venta
   - Estado

### Crear Producto

1. Click en **"+ Nuevo Producto"**
2. Completar formulario:
   - **Nombre** (requerido)
   - **Código de Barras**
   - **SKU**
   - **Categoría** (seleccionar o crear)
   - **Marca** (seleccionar o crear)
   - **Unidad de Medida** (Unidad, Kilogramo, Litro, etc.)
   - **Tipo de Venta** (Por Unidad, Por Peso, Por Volumen)
   - **Tipo** (Producto, Servicio, Materia Prima, etc.)
   - **Precio Costo**
   - **Precio Venta** (requerido)
   - **Cantidad Inicial** (stock inicial)
   - **Stock Mínimo** (alerta de stock bajo)
   - **Stock Máximo**
   - **Punto de Reposición**
   - **Stock de Seguridad**
   - **Control Lote** (Sí/No)
   - **Control Serie** (Sí/No)
   - **Peso** (kg)
   - **Volumen** (m³)
   - **Descripción**
3. Click en **"Crear Producto"**

### Editar Producto

1. Buscar producto en la lista
2. Click en el ícono de editar (✏️)
3. Modificar campos necesarios
4. Click en **"Guardar"**

### Eliminar Producto

1. Buscar producto en la lista
2. Click en el ícono de eliminar (🗑️)
3. Confirmar eliminación

**Nota:** Los productos no se eliminan físicamente, se marcan como inactivos.

### Ver Detalle de Producto

1. Buscar producto en la lista
2. Click en el ícono de ver (👁️)
3. Se muestra:
   - Información general
   - Stock por bodega
   - Lotes (si aplica)
   - Historial de movimientos

### Gestionar Categorías

1. Navegar a **Inventario → Categorías**
2. **Crear categoría:**
   - Click en **"+ Nueva Categoría"**
   - Ingresar nombre y descripción
   - Click en **"Crear"**
3. **Editar categoría:**
   - Click en el ícono de editar
   - Modificar campos
   - Click en **"Guardar"**

### Gestionar Marcas

1. Navegar a **Inventario → Marcas**
2. **Crear marca:**
   - Click en **"+ Nueva Marca"**
   - Ingresar nombre y descripción
   - Click en **"Crear"**
3. **Editar marca:**
   - Click en el ícono de editar
   - Modificar campos
   - Click en **"Guardar"**

### Gestionar Bodegas

1. Navegar a **Inventario → Bodegas**
2. **Crear bodega:**
   - Click en **"+ Nueva Bodega"**
   - Ingresar código, nombre, responsable
   - Ingresar dirección, teléfono, correo
   - Click en **"Crear"**
3. **Editar bodega:**
   - Click en el ícono de editar
   - Modificar campos
   - Click en **"Guardar"**

### Movimientos de Inventario

1. Navegar a **Inventario → Movimientos**
2. **Crear movimiento:**
   - Click en **"+ Nuevo Movimiento"**
   - Seleccionar tipo:
     - Ingreso
     - Egreso
     - Transferencia
     - Ajuste
   - Seleccionar producto
   - Ingresar cantidad
   - Seleccionar bodega origen/destino
   - Ingresar motivo
   - Click en **"Crear"**

### Transferencias

1. Navegar a **Inventario → Transferencias**
2. **Crear transferencia:**
   - Click en **"+ Nueva Transferencia"**
   - Seleccionar bodega origen
   - Seleccionar bodega destino
   - Agregar productos y cantidades
   - Click en **"Crear"**
3. **Aprobar transferencia:**
   - Buscar transferencia pendiente
   - Click en el ícono de aprobar
   - Confirmar

### Ajustes de Inventario

1. Navegar a **Inventario → Ajustes**
2. **Crear ajuste:**
   - Click en **"+ Nuevo Ajuste"**
   - Seleccionar tipo:
     - Aumento
     - Disminución
   - Seleccionar producto
   - Ingresar cantidad
   - Ingresar motivo
   - Click en **"Crear"**

### Inventario Físico

1. Navegar a **Inventario → Inventario Físico**
2. **Crear conteo:**
   - Click en **"+ Nuevo Conteo"**
   - Seleccionar bodega
   - Ingresar fecha
   - Click en **"Crear"**
3. **Realizar conteo:**
   - Abrir conteo
   - Ingresar cantidades contadas por producto
   - Click en **"Guardar"**
4. **Conciliar:**
   - Click en **"Conciliar"**
   - Revisar diferencias
   - Aceptar ajustes
   - Click en **"Finalizar"**

### Kardex

1. Navegar a **Inventario → Kardex**
2. Filtrar por:
   - Producto
   - Bodega
   - Fecha desde/hasta
   - Tipo de movimiento
3. Se muestra historial completo de movimientos

---

## Módulo Ventas

### Ver Ventas

1. Navegar a **Ventas**
2. Se muestra lista de ventas con:
   - Número de venta
   - Fecha
   - Cliente
   - Total
   - Estado
   - Métodos de pago

### Filtrar Ventas

1. Usar filtros en la parte superior:
   - **Periodo:** Hoy, Ayer, Esta Semana, Este Mes, Este Año, Personalizado
   - **Empleado:** Seleccionar empleado específico
   - **Método de pago:** Efectivo, Tarjeta, Transferencia
   - **Estado:** Completada, Anulada, Pendiente
   - **Búsqueda:** Por número, cliente, empleado

### Ver Detalle de Venta

1. Click en el número de venta
2. Se muestra:
   - Información del cliente
   - Lista de productos
   - Métodos de pago
   - Totales
   - Auditoría

### Editar Venta

1. Buscar venta
2. Click en el ícono de editar (✏️)
3. Modificar:
   - Cantidades de productos
   - Precios
   - Métodos de pago
4. Ingresar motivo de edición
5. Click en **"Guardar"**

### Anular Venta

1. Buscar venta
2. Click en el ícono de anular (🚫)
3. Ingresar motivo de anulación
4. Click en **"Confirmar Anulación"**

### Exportar Ventas

1. Navegar a **Ventas**
2. Aplicar filtros deseados
3. Click en **"Exportar CSV"**
4. Se descarga archivo CSV con las ventas filtradas

### Cuadre de Ventas

1. Navegar a **Ventas → Cuadre**
2. Se muestra resumen de:
   - Ventas por método de pago
   - Ventas por empleado
   - Totales
   - Diferencias
3. Ingresar monto real
4. Click en **"Cerrar Caja"**

---

## Módulo CRM (Clientes)

### Ver Clientes

1. Navegar a **CRM**
2. Se muestra lista de clientes con:
   - Nombre
   - RUT
   - Correo
   - Teléfono
   - Ciudad
   - Estado

### Crear Cliente

1. Click en **"+ Nuevo Cliente"**
2. Completar formulario:
   - **Nombre/Razón Social** (requerido)
   - **RUT**
   - **Correo**
   - **Teléfono**
   - **Dirección**
   - **Ciudad**
   - **Giro**
   - **Contacto**
   - **Notas**
3. Click en **"Crear Cliente"**

### Editar Cliente

1. Buscar cliente
2. Click en el ícono de editar (✏️)
3. Modificar campos necesarios
4. Click en **"Guardar"**

### Eliminar Cliente

1. Buscar cliente
2. Click en el ícono de eliminar (🗑️)
3. Confirmar eliminación

### Ver Detalle de Cliente

1. Buscar cliente
2. Click en el ícono de ver (👁️)
3. Se muestra:
   - Información general
   - Historial de compras
   - Créditos
   - Contactos
   - Etiquetas

### Gestionar Créditos

1. Abrir detalle de cliente
2. Ir a pestaña **"Créditos"**
3. **Crear crédito:**
   - Click en **"+ Nuevo Crédito"**
   - Ingresar monto
   - Ingresar plazo
   - Ingresar motivo
   - Click en **"Crear"**
4. **Registrar pago:**
   - Click en el ícono de pago
   - Ingresar monto pagado
   - Click en **"Registrar"**

### Etiquetas de Clientes

1. Abrir detalle de cliente
2. Ir a pestaña **"Etiquetas"**
3. **Crear etiqueta:**
   - Ingresar nombre
   - Seleccionar color
   - Click en **"Crear"**
4. **Asignar etiqueta:**
   - Seleccionar etiqueta
   - Click en **"Asignar"**

---

## Módulo Empleados

### Ver Empleados

1. Navegar a **Empleados**
2. Se muestra lista de empleados con:
   - Nombre
   - RUT
   - Cargo
   - Departamento
   - Estado

### Crear Empleado

1. Click en **"+ Nuevo Empleado"**
2. Completar formulario:
   - **Nombres** (requerido)
   - **Apellidos** (requerido)
   - **RUT**
   - **Correo**
   - **Teléfono**
   - **Dirección**
   - **Cargo**
   - **Departamento**
   - **Fecha Ingreso**
   - **Salario**
   - **Tipo Contrato**
3. Click en **"Crear Empleado"**

### Crear Credenciales de Acceso

1. Buscar empleado
2. Click en el ícono de credenciales (🔑)
3. Ingresar:
   - Nombre de usuario
   - Contraseña
   - Confirmar contraseña
4. Seleccionar roles:
   - Administrador
   - Vendedor
   - Cajero
   - Bodeguero
   - etc.
5. Click en **"Crear Credenciales"**

### Editar Empleado

1. Buscar empleado
2. Click en el ícono de editar (✏️)
3. Modificar campos necesarios
4. Click en **"Guardar"**

### Eliminar Empleado

1. Buscar empleado
2. Click en el ícono de eliminar (🗑️)
3. Confirmar eliminación

**Nota:** Los empleados no se eliminan físicamente, se marcan como inactivos.

### Gestionar Contratos

1. Abrir detalle de empleado
2. Ir a pestaña **"Contratos"**
3. **Crear contrato:**
   - Click en **"+ Nuevo Contrato"**
   - Ingresar tipo de contrato
   - Ingresar fecha inicio/fin
   - Ingresar salario
   - Click en **"Crear"**

### Gestionar Asistencia

1. Navegar a **Empleados → Asistencia**
2. **Registrar asistencia:**
   - Seleccionar empleado
   - Ingresar fecha
   - Ingresar hora entrada/salida
   - Click en **"Registrar"**

### Gestionar Vacaciones

1. Navegar a **Empleados → Vacaciones**
2. **Solicitar vacaciones:**
   - Click en **"+ Nueva Solicitud"**
   - Seleccionar empleado
   - Ingresar fecha inicio/fin
   - Ingresar motivo
   - Click en **"Solicitar"**
3. **Aprobar vacaciones:**
   - Buscar solicitud pendiente
   - Click en el ícono de aprobar
   - Confirmar

---

## Módulo Facturas

### Ver Facturas

1. Navegar a **Facturas**
2. Se muestra lista de facturas con:
   - Número
   - Cliente
   - Fecha
   - Total
   - Estado

### Crear Factura

1. Click en **"+ Nueva Factura"**
2. Seleccionar cliente
3. Agregar productos:
   - Buscar producto
   - Ingresar cantidad
   - Ingresar precio
4. Seleccionar método de pago
5. Ingresar observaciones
6. Click en **"Crear Factura"**

### Anular Factura

1. Buscar factura
2. Click en el ícono de anular (🚫)
3. Ingresar motivo
4. Click en **"Confirmar Anulación"**

### Emitir Nota de Crédito

1. Buscar factura original
2. Click en el ícono de nota de crédito
3. Ingresar monto
4. Ingresar motivo
5. Click en **"Emitir"**

### Exportar Facturas

1. Navegar a **Facturas**
2. Aplicar filtros
3. Click en **"Exportar CSV"**

---

## Módulo Proveedores

### Ver Proveedores

1. Navegar a **Proveedores**
2. Se muestra lista de proveedores con:
   - Nombre
   - RUT
   - Correo
   - Teléfono
   - Estado

### Crear Proveedor

1. Click en **"+ Nuevo Proveedor"**
2. Completar formulario:
   - **Razón Social** (requerido)
   - **RUT**
   - **Correo**
   - **Teléfono**
   - **Dirección**
   - **Contacto**
   - **Banco**
   - **Cuenta**
   - **Tipo Cuenta**
3. Click en **"Crear Proveedor"**

### Editar Proveedor

1. Buscar proveedor
2. Click en el ícono de editar (✏️)
3. Modificar campos necesarios
4. Click en **"Guardar"**

### Eliminar Proveedor

1. Buscar proveedor
2. Click en el ícono de eliminar (🗑️)
3. Confirmar eliminación

### Asociar Productos

1. Abrir detalle de proveedor
2. Ir a pestaña **"Productos"**
3. Click en **"+ Asociar Producto"**
4. Seleccionar producto
5. Ingresar precio de compra
6. Click en **"Asociar"**

---

## Módulo Configuración

### Dashboard

1. Navegar a **Configuración**
2. Se muestra resumen de:
   - Empresas
   - Sucursales
   - Usuarios
   - Roles
   - Monedas
   - Impuestos
   - Auditoría

### Gestionar Empresas

1. Ir a **Configuración → Empresas**
2. **Crear empresa:**
   - Click en **"+ Nueva Empresa"**
   - Ingresar razón social, RUT, giro
   - Ingresar dirección, teléfono, correo
   - Click en **"Crear"**
3. **Editar empresa:**
   - Click en el ícono de editar
   - Modificar campos
   - Click en **"Guardar"**

### Gestionar Sucursales

1. Ir a **Configuración → Sucursales**
2. **Crear sucursal:**
   - Click en **"+ Nueva Sucursal"**
   - Seleccionar empresa
   - Ingresar código, nombre
   - Ingresar dirección, responsable
   - Click en **"Crear"**
3. **Editar sucursal:**
   - Click en el ícono de editar
   - Modificar campos
   - Click en **"Guardar"**

### Gestionar Usuarios

1. Ir a **Configuración → Usuarios**
2. **Crear usuario:**
   - Click en **"+ Nuevo Usuario"**
   - Ingresar nombre, correo
   - Ingresar contraseña
   - Seleccionar roles
   - Click en **"Crear"**
3. **Editar usuario:**
   - Click en el ícono de editar
   - Modificar campos
   - Click en **"Guardar"**
4. **Cambiar contraseña:**
   - Click en el ícono de contraseña (🔑)
   - Ingresar nueva contraseña
   - Click en **"Cambiar"**

### Gestionar Roles y Permisos

1. Ir a **Configuración → Roles y Permisos**
2. **Crear rol:**
   - Click en **"+ Nuevo Rol"**
   - Ingresar nombre
   - Seleccionar permisos
   - Click en **"Crear"**
3. **Editar rol:**
   - Seleccionar rol
   - Modificar permisos
   - Click en **"Guardar"**

### Gestionar Monedas

1. Ir a **Configuración → Monedas**
2. **Crear moneda:**
   - Click en **"+ Nueva Moneda"**
   - Ingresar código, nombre, símbolo
   - Click en **"Crear"**
3. **Editar moneda:**
   - Click en el ícono de editar
   - Modificar campos
   - Click en **"Guardar"**

### Gestionar Impuestos

1. Ir a **Configuración → Impuestos**
2. **Crear impuesto:**
   - Click en **"+ Nuevo Impuesto"**
   - Ingresar nombre, código, tasa
   - Click en **"Crear"**
3. **Editar impuesto:**
   - Click en el ícono de editar
   - Modificar campos
   - Click en **"Guardar"**

### Plantilla de Boletas

1. Ir a **Configuración → Plantilla de Boletas**
2. Configurar:
   - Nombre de empresa
   - RUT
   - Dirección
   - Teléfono
   - Email
   - Logo (subir imagen)
   - Mensaje de agradecimiento
   - Mensaje pie de página
   - IVA (%)
   - Opciones:
     - Mostrar RUT cliente
     - Mostrar desglose IVA
     - Mostrar descuento
3. Click en **"Guardar Configuración"**

### Auditoría

1. Ir a **Configuración → Auditoría**
2. Se muestra log de:
   - Acciones de usuarios
   - Cambios en el sistema
   - Errores
   - Accesos denegados
3. Filtrar por:
   - Nivel (INFO, WARNING, ERROR)
   - Usuario
   - Fecha

---

## Reportes

### Reporte de Ventas

1. Navegar a **Ventas**
2. Aplicar filtros
3. Click en **"Exportar CSV"**

### Reporte de Inventario

1. Navegar a **Inventario → Reportes**
2. Seleccionar tipo:
   - Existencias
   - Valorización
   - Stock crítico
   - Próximos a vencer
3. Aplicar filtros
4. Click en **"Generar Reporte"**

### Reporte de Clientes

1. Navegar a **CRM → Reportes**
2. Seleccionar tipo:
   - Clientes frecuentes
   - Clientes inactivos
   - Top clientes
3. Aplicar filtros
4. Click en **"Generar Reporte"**

### Reporte de Empleados

1. Navegar a **Empleados → Reportes**
2. Seleccionar tipo:
   - Asistencia
   - Vacaciones
   - Performance
3. Aplicar filtros
4. Click en **"Generar Reporte"**

---

## Atajos de Teclado

### POS

- **F1:** Abrir ayuda
- **F2:** Buscar producto
- **F3:** Cobrar
- **F4:** Anular venta
- **F5:** Cerrar caja
- **ESC:** Cancelar operación
- **Enter:** Confirmar

### General

- **Ctrl + S:** Guardar
- **Ctrl + Z:** Deshacer
- **Ctrl + Y:** Rehacer
- **Ctrl + F:** Buscar
- **Ctrl + P:** Imprimir

---

## Preguntas Frecuentes

### ¿Cómo recupero mi contraseña?

Contactar al administrador del sistema para restablecer la contraseña.

### ¿Puedo cambiar el precio de un producto en el POS?

Solo si tienes el permiso "pos.cambiar_precios" asignado.

### ¿Cómo anulo una venta?

1. Navegar a Ventas
2. Buscar la venta
3. Click en el ícono de anular
4. Ingresar motivo

### ¿Cómo hago un backup?

Los backups se realizan automáticamente. Para backup manual, contactar al administrador.

### ¿Puedo exportar datos?

Sí, la mayoría de las listas tienen opción de exportar a CSV.

### ¿Cómo creo un nuevo usuario?

1. Ir a Configuración → Usuarios
2. Click en "+ Nuevo Usuario"
3. Completar formulario
4. Asignar roles
5. Click en "Crear"

---

## Soporte

Para asistencia técnica:
- **Email:** soporte@bluecat.com
- **Teléfono:** +56 9 1234 5678
- **Horario:** Lunes a Viernes, 9:00 - 18:00

---

**Última actualización:** Julio 2026  
**Versión:** 1.0.0
