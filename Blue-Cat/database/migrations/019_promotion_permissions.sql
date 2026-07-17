-- Canonical permission for promotion rule administration.
INSERT INTO permiso(modulo,accion,descripcion)
SELECT 'pos','crear_promocion','Crear, configurar y desactivar promociones POS'
WHERE NOT EXISTS (SELECT 1 FROM permiso WHERE modulo='pos' AND accion='crear_promocion');

INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r
JOIN permiso p ON p.modulo='pos' AND p.accion='crear_promocion'
WHERE r.activo=1 AND r.nombre IN ('Administrador','Supervisor');
