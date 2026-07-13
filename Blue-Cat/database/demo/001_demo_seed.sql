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

-- Los roles y permisos son datos obligatorios versionados en 011_role_templates.sql.
-- El seed demo no modifica el modelo de seguridad.

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
