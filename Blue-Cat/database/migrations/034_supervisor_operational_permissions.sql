-- Sprint 1: el Supervisor necesita contexto comercial para autorizar cambios
-- de costo y precio. `inventario.precios` aislado no habilita ni la vista ni
-- los costos y dejaba el flujo configurado pero inutilizable.
SET NAMES utf8mb4;

INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r
JOIN permiso p
  ON p.modulo='inventario'
 AND p.accion IN ('ver','ver_costos','precios')
WHERE r.nombre='Supervisor'
  AND r.activo=1;
