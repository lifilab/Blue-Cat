-- Permisos explícitos para operaciones masivas de inventario
INSERT IGNORE INTO permiso (modulo, accion, descripcion) VALUES
('inventario','importar','Importar productos mediante XLS o CSV'),
('inventario','exportar','Exportar productos a XLS');

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r CROSS JOIN permiso p
WHERE r.nombre='Administrador'
  AND p.modulo='inventario'
  AND p.accion IN ('importar','exportar');
