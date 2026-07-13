-- Catalogo de seguridad obligatorio. No depende de datos demo.
INSERT INTO rol (id_cuenta,nombre,descripcion,es_sistema,es_plantilla,activo)
SELECT NULL,'Administrador','Control total del sistema',1,1,1
WHERE NOT EXISTS (SELECT 1 FROM rol WHERE id_cuenta IS NULL AND nombre='Administrador');
INSERT INTO rol (id_cuenta,nombre,descripcion,es_sistema,es_plantilla,activo)
SELECT NULL,'Cajero','Operaciones de caja y ventas',1,1,1
WHERE NOT EXISTS (SELECT 1 FROM rol WHERE id_cuenta IS NULL AND nombre='Cajero');
INSERT INTO rol (id_cuenta,nombre,descripcion,es_sistema,es_plantilla,activo)
SELECT NULL,'Bodeguero','Control de inventario y bodega',1,1,1
WHERE NOT EXISTS (SELECT 1 FROM rol WHERE id_cuenta IS NULL AND nombre='Bodeguero');
INSERT INTO rol (id_cuenta,nombre,descripcion,es_sistema,es_plantilla,activo)
SELECT NULL,'Vendedor','Ventas y atencion al cliente',1,1,1
WHERE NOT EXISTS (SELECT 1 FROM rol WHERE id_cuenta IS NULL AND nombre='Vendedor');

INSERT IGNORE INTO permiso (modulo,accion,descripcion) VALUES
('pos','ver','Ver modulo POS'),
('pos','abrir_caja','Abrir caja'),
('pos','cerrar_caja','Cerrar caja'),
('pos','realizar_venta','Realizar ventas'),
('pos','cancelar_venta','Cancelar ventas'),
('pos','aplicar_descuento','Aplicar descuentos'),
('pos','devoluciones','Procesar devoluciones'),
('pos','cambiar_precios','Cambiar precios en POS'),
('ventas','ver','Ver ventas propias'),
('ventas','ver_todos','Ver ventas de todos los empleados'),
('ventas','cuadre','Realizar cuadre de caja'),
('ventas','editar','Editar ventas'),
('ventas','eliminar','Anular ventas'),
('ventas','exportar','Exportar ventas'),
('inventario','ver','Ver inventario'),
('inventario','crear','Crear productos'),
('inventario','editar','Editar productos'),
('inventario','eliminar','Eliminar productos'),
('inventario','movimientos','Realizar movimientos'),
('inventario','transferencias','Realizar transferencias'),
('inventario','ajustes','Realizar ajustes'),
('inventario','ver_costos','Ver costos'),
('inventario','importar','Importar productos'),
('inventario','exportar','Exportar productos'),
('crm','ver','Ver clientes'),
('crm','crear','Crear clientes'),
('crm','editar','Editar clientes'),
('crm','eliminar','Eliminar clientes'),
('proveedores','ver','Ver proveedores'),
('proveedores','crear','Crear proveedores'),
('proveedores','editar','Editar proveedores'),
('proveedores','eliminar','Eliminar proveedores'),
('facturas','ver','Ver facturas'),
('facturas','crear','Crear facturas'),
('facturas','editar','Editar facturas'),
('facturas','eliminar','Anular facturas'),
('facturas','nota_credito','Emitir notas de credito'),
('empleados','ver','Ver empleados'),
('empleados','crear','Crear empleados'),
('empleados','editar','Editar empleados'),
('empleados','eliminar','Eliminar empleados'),
('configuracion','ver','Ver configuracion'),
('configuracion','editar','Editar configuracion'),
('configuracion','gestionar_usuarios','Gestionar usuarios'),
('configuracion','gestionar_roles','Gestionar roles y permisos'),
('usuarios','ver','Ver usuarios de la cuenta'),
('usuarios','editar_cuentas','Editar cuentas de empleados');

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r CROSS JOIN permiso p
WHERE r.id_cuenta IS NULL AND r.nombre='Administrador';

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p
  ON (p.modulo='pos' AND p.accion IN ('ver','abrir_caja','cerrar_caja','realizar_venta','cancelar_venta'))
  OR (p.modulo='ventas' AND p.accion IN ('ver','cuadre'))
WHERE r.id_cuenta IS NULL AND r.nombre='Cajero';

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p
  ON p.modulo='inventario' AND p.accion IN ('ver','crear','editar','movimientos','transferencias','ajustes','ver_costos')
WHERE r.id_cuenta IS NULL AND r.nombre='Bodeguero';

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p
  ON (p.modulo='pos' AND p.accion IN ('ver','abrir_caja','cerrar_caja','realizar_venta','cancelar_venta','aplicar_descuento','devoluciones'))
  OR (p.modulo='ventas' AND p.accion IN ('ver','cuadre'))
  OR (p.modulo='crm' AND p.accion IN ('ver','crear','editar'))
WHERE r.id_cuenta IS NULL AND r.nombre='Vendedor';

INSERT IGNORE INTO rol (id_cuenta,nombre,descripcion,activo,es_sistema,es_plantilla)
SELECT c.id_cuenta,t.nombre,t.descripcion,t.activo,0,0
FROM cuenta c CROSS JOIN rol t
WHERE t.id_cuenta IS NULL AND t.es_plantilla=1;

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT local_role.id_rol,rp.id_permiso
FROM rol local_role
JOIN rol template_role
  ON template_role.id_cuenta IS NULL
 AND template_role.es_plantilla=1
 AND template_role.nombre=local_role.nombre
JOIN rol_permiso rp ON rp.id_rol=template_role.id_rol
WHERE local_role.id_cuenta IS NOT NULL;

UPDATE usuario_rol ur
JOIN usuario u ON u.id_user=ur.id_user
JOIN rol template_role ON template_role.id_rol=ur.id_rol AND template_role.id_cuenta IS NULL
JOIN rol local_role ON local_role.id_cuenta=u.id_cuenta AND local_role.nombre=template_role.nombre
SET ur.id_rol=local_role.id_rol;
