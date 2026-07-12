-- ============================================================
-- Blue-Cat ERP v1.0 — Datos iniciales (seed)
-- ============================================================
SET NAMES utf8mb4;
START TRANSACTION;

-- ─── MÓDULOS DEL SISTEMA ───
INSERT IGNORE INTO modulo (codigo, nombre, icono, ruta, orden) VALUES
('pos', 'POS', 'fa-cash-register', 'pos.html', 2),
('inventario', 'Inventario', 'fa-box', 'inventario.html', 3),
('crm', 'Clientes CRM', 'fa-users', 'crm.html', 4),
('proveedores', 'Proveedores', 'fa-truck', 'proveedores.html', 5),
('facturas', 'Facturación', 'fa-file-invoice', 'facturas.html', 6),
('empleados', 'Empleados', 'fa-id-badge', 'empleados.html', 7),
('ventas', 'Ventas', 'fa-shopping-cart', 'ventas.html', 8),
('configuracion', 'Configuración', 'fa-cogs', 'configuracion.html', 9);

-- ─── ROLES DEL SISTEMA ───
INSERT IGNORE INTO rol (nombre, descripcion, es_sistema) VALUES
('Administrador', 'Control total del sistema', 1),
('Cajero', 'Operaciones básicas de caja y ventas', 1),
('Bodeguero', 'Control de inventario y bodega', 1),
('Vendedor', 'Ventas y atención al cliente', 1);

-- ─── PERMISOS ───
INSERT IGNORE INTO permiso (modulo, accion, descripcion) VALUES
-- POS
('pos','ver','Ver módulo POS'),
('pos','abrir_caja','Abrir caja'),
('pos','cerrar_caja','Cerrar caja'),
('pos','realizar_venta','Realizar ventas'),
('pos','cancelar_venta','Cancelar/anular ventas'),
('pos','aplicar_descuento','Aplicar descuentos/promociones'),
('pos','devoluciones','Procesar devoluciones'),
('pos','cambiar_precios','Cambiar precios en POS'),
-- Ventas
('ventas','ver','Ver ventas propias'),
('ventas','ver_todos','Ver ventas de todos los empleados'),
('ventas','cuadre','Realizar cuadre de caja'),
('ventas','editar','Editar ventas'),
('ventas','eliminar','Eliminar/anular ventas'),
('ventas','exportar','Exportar ventas'),
-- Inventario
('inventario','ver','Ver inventario'),
('inventario','crear','Crear productos'),
('inventario','editar','Editar productos'),
('inventario','eliminar','Eliminar/desactivar productos'),
('inventario','movimientos','Realizar movimientos'),
('inventario','transferencias','Realizar transferencias'),
('inventario','ajustes','Realizar ajustes'),
('inventario','ver_costos','Ver costos de productos'),
-- CRM
('crm','ver','Ver clientes'),
('crm','crear','Crear clientes'),
('crm','editar','Editar clientes'),
('crm','eliminar','Eliminar clientes'),
-- Proveedores
('proveedores','ver','Ver proveedores'),
('proveedores','crear','Crear proveedores'),
('proveedores','editar','Editar proveedores'),
('proveedores','eliminar','Eliminar proveedores'),
-- Facturas
('facturas','ver','Ver facturas'),
('facturas','crear','Crear facturas'),
('facturas','editar','Editar facturas'),
('facturas','eliminar','Anular facturas'),
('facturas','nota_credito','Emitir notas de crédito'),
-- Empleados
('empleados','ver','Ver empleados'),
('empleados','crear','Crear empleados'),
('empleados','editar','Editar empleados'),
('empleados','eliminar','Eliminar empleados'),
-- Configuración
('configuracion','ver','Ver configuración'),
('configuracion','editar','Editar configuración'),
('configuracion','gestionar_usuarios','Gestionar usuarios'),
('configuracion','gestionar_roles','Gestionar roles y permisos'),
-- Usuarios (para cambio de password y cuentas)
('usuarios','ver','Ver usuarios de la cuenta'),
('usuarios','editar_cuentas','Editar cuentas de empleados');

-- ─── ASIGNAR PERMISOS A ROLES ───
-- Admin: todos los permisos
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT (SELECT id_rol FROM rol WHERE nombre='Administrador'), id_permiso FROM permiso;

-- Cajero: solo POS y ventas básicas
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT (SELECT id_rol FROM rol WHERE nombre='Cajero'), id_permiso FROM permiso
WHERE (modulo='pos' AND accion IN ('ver','abrir_caja','cerrar_caja','realizar_venta','cancelar_venta'))
   OR (modulo='ventas' AND accion IN ('ver','cuadre'));

-- Bodeguero: solo inventario
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT (SELECT id_rol FROM rol WHERE nombre='Bodeguero'), id_permiso FROM permiso
WHERE (modulo='inventario' AND accion IN ('ver','crear','editar','movimientos','transferencias','ajustes','ver_costos'));

-- Vendedor: POS + ventas + CRM
INSERT IGNORE INTO rol_permiso (id_rol, id_permiso)
SELECT (SELECT id_rol FROM rol WHERE nombre='Vendedor'), id_permiso FROM permiso
WHERE (modulo='pos' AND accion IN ('ver','abrir_caja','cerrar_caja','realizar_venta','cancelar_venta','aplicar_descuento','devoluciones'))
   OR (modulo='ventas' AND accion IN ('ver','cuadre'))
   OR (modulo='crm' AND accion IN ('ver','crear','editar'));

-- ─── PLAN POR DEFECTO ───
INSERT IGNORE INTO plan (nombre, descripcion, precio, max_empresas, max_sucursales, max_usuarios) VALUES
('Plan Básico', 'Plan gratuito para un usuario', 0, 1, 1, 5);

-- ─── ASIGNAR TODOS LOS MÓDULOS AL PLAN ───
INSERT IGNORE INTO plan_modulo (id_plan, id_modulo)
SELECT (SELECT id_plan FROM plan WHERE nombre='Plan Básico'), id_modulo FROM modulo;

-- ─── MONEDAS ───
INSERT IGNORE INTO moneda (codigo, nombre, simbolo, decimales) VALUES
('CLP', 'Peso Chileno', '$', 0),
('USD', 'Dólar Estadounidense', 'US$', 2);

-- ─── IMPUESTOS ───
INSERT IGNORE INTO impuesto (nombre, codigo, tasa, tipo) VALUES
('IVA 19%', 'IVA19', 19.00, 'IVA');

-- ─── DEPARTAMENTOS ───
INSERT IGNORE INTO departamento (nombre) VALUES
('Administración'),('Ventas'),('Bodega'),('Contabilidad'),('RRHH');

-- ─── UNIDADES DE MEDIDA ───
INSERT IGNORE INTO unidad_medida (nombre, abreviatura, tipo) VALUES
('Unidad', 'u', 'UNIDAD'),
('Kilogramo', 'kg', 'PESO'),
('Gramo', 'g', 'PESO'),
('Libra', 'lb', 'PESO'),
('Litro', 'L', 'VOLUMEN'),
('Mililitro', 'mL', 'VOLUMEN');

COMMIT;
