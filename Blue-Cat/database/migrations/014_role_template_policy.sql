-- Phase 1: make global role templates deterministic on legacy installations.
-- Account-local roles are intentionally preserved because they may be customized.
SET NAMES utf8mb4;
START TRANSACTION;

DELETE rp
FROM rol_permiso rp
JOIN rol r ON r.id_rol=rp.id_rol
WHERE r.id_cuenta IS NULL
  AND r.es_plantilla=1
  AND r.nombre IN ('Administrador','Cajero','Bodeguero','Vendedor');

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r CROSS JOIN permiso p
WHERE r.id_cuenta IS NULL AND r.es_plantilla=1 AND r.nombre='Administrador';

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r JOIN permiso p
  ON (p.modulo='pos' AND p.accion IN ('ver','abrir_caja','cerrar_caja','realizar_venta','cancelar_venta'))
  OR (p.modulo='ventas' AND p.accion IN ('ver','cuadre'))
WHERE r.id_cuenta IS NULL AND r.es_plantilla=1 AND r.nombre='Cajero';

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r JOIN permiso p
  ON p.modulo='inventario'
 AND p.accion IN ('ver','crear','editar','movimientos','transferencias','ajustes','ver_costos')
WHERE r.id_cuenta IS NULL AND r.es_plantilla=1 AND r.nombre='Bodeguero';

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r JOIN permiso p
  ON (p.modulo='pos' AND p.accion IN ('ver','abrir_caja','cerrar_caja','realizar_venta','cancelar_venta','aplicar_descuento','devoluciones'))
  OR (p.modulo='ventas' AND p.accion IN ('ver','cuadre'))
  OR (p.modulo='crm' AND p.accion IN ('ver','crear','editar'))
WHERE r.id_cuenta IS NULL AND r.es_plantilla=1 AND r.nombre='Vendedor';

COMMIT;
