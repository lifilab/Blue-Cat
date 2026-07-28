-- Catálogo funcional obligatorio. Los módulos no son datos de demostración:
-- se necesitan para resolver navegación, permisos y capacidades de licencia.
INSERT INTO modulo (codigo, nombre, icono, ruta, orden, activo) VALUES
('pos', 'POS', 'fa-cash-register', 'pos.html', 2, 1),
('inventario', 'Inventario', 'fa-box', 'inventario.html', 3, 1),
('crm', 'Clientes CRM', 'fa-users', 'crm.html', 4, 1),
('proveedores', 'Proveedores', 'fa-truck', 'proveedores.html', 5, 1),
('facturas', 'Facturación', 'fa-file-invoice', 'facturas.html', 6, 1),
('empleados', 'Empleados', 'fa-id-badge', 'empleados.html', 7, 1),
('ventas', 'Ventas', 'fa-shopping-cart', 'ventas.html', 8, 1),
('configuracion', 'Configuración', 'fa-cogs', 'configuracion.html', 9, 1)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  icono = VALUES(icono),
  ruta = VALUES(ruta),
  orden = VALUES(orden);
