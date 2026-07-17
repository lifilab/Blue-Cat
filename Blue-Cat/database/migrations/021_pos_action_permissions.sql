-- Phase 3: explicit permission for associating an existing customer in POS.
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO permiso(modulo,accion,descripcion) VALUES
('pos','asociar_cliente','Buscar y asociar clientes existentes a una venta POS')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r JOIN permiso p ON p.modulo='pos' AND p.accion='asociar_cliente'
WHERE r.activo=1 AND r.nombre IN ('Administrador','Supervisor','Cajero','Vendedor');

COMMIT;
