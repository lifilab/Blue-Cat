-- El catálogo comercial es dato maestro obligatorio, no dato de demostración.
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO modulo (codigo,nombre,icono,ruta,orden,activo) VALUES
('pos','POS','fa-cash-register','pos.html',2,1),
('inventario','Inventario','fa-box','inventario.html',3,1),
('crm','Clientes CRM','fa-users','crm.html',4,1),
('proveedores','Proveedores','fa-truck','proveedores.html',5,1),
('facturas','Facturación','fa-file-invoice','facturas.html',6,1),
('empleados','Empleados','fa-id-badge','empleados.html',7,1),
('ventas','Ventas','fa-shopping-cart','ventas.html',8,1),
('configuracion','Configuración','fa-cogs','configuracion.html',9,1)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),icono=VALUES(icono),ruta=VALUES(ruta),orden=VALUES(orden),activo=1;

INSERT INTO plan (nombre,descripcion,precio,max_empresas,max_sucursales,max_usuarios,activo)
SELECT 'Blue-Cat Beta Completa','Plan local de evaluación con todos los módulos instalados',0,1,4,10,1
WHERE NOT EXISTS (SELECT 1 FROM plan WHERE nombre='Blue-Cat Beta Completa');

INSERT IGNORE INTO plan_modulo(id_plan,id_modulo)
SELECT p.id_plan,m.id_modulo
FROM plan p CROSS JOIN modulo m
WHERE p.nombre='Blue-Cat Beta Completa' AND p.activo=1 AND m.activo=1;

-- Repara instalaciones de servidor creadas antes de esta migración.
INSERT INTO suscripcion(id_empresa,id_plan,fecha_inicio,estado)
SELECT e.id_empresa,p.id_plan,CURDATE(),'activa'
FROM core_installation ci
JOIN empresa e ON e.id_cuenta=ci.id_cuenta AND e.activo=1
JOIN plan p ON p.nombre='Blue-Cat Beta Completa' AND p.activo=1
WHERE ci.id_installation=1
  AND NOT EXISTS (SELECT 1 FROM suscripcion s WHERE s.id_empresa=e.id_empresa AND s.estado='activa');

-- El propietario de una instalación local es superadministrador de su cuenta.
INSERT IGNORE INTO usuario_rol(id_user,id_rol)
SELECT ci.id_user_admin,r.id_rol
FROM core_installation ci
JOIN rol r ON r.id_cuenta=ci.id_cuenta AND r.nombre='Administrador' AND r.activo=1
WHERE ci.id_installation=1;

INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM core_installation ci
JOIN rol r ON r.id_cuenta=ci.id_cuenta AND r.nombre='Administrador' AND r.activo=1
CROSS JOIN permiso p
WHERE ci.id_installation=1;

COMMIT;
