-- Sprint 1: una identidad inequívoca por producto y una categoría canónica.
SET NAMES utf8mb4;

-- Los identificadores opcionales vacíos deben ser NULL para que UNIQUE permita
-- múltiples productos sin código/SKU, pero nunca dos productos con el mismo.
UPDATE producto SET codigo_de_barras=NULL
WHERE codigo_de_barras IS NOT NULL AND TRIM(codigo_de_barras)='';
UPDATE producto SET sku=NULL
WHERE sku IS NOT NULL AND TRIM(sku)='';

CREATE TEMPORARY TABLE tmp_producto_codigo_duplicado AS
SELECT id_cuenta,codigo_de_barras,MIN(id_producto) AS id_canonico
FROM producto
WHERE codigo_de_barras IS NOT NULL
GROUP BY id_cuenta,codigo_de_barras
HAVING COUNT(*)>1;

INSERT INTO inventario_auditoria(id_user,accion,entidad,id_entidad,detalle,ip)
SELECT p.id_user,'MIGRAR_IDENTIDAD_DUPLICADA','producto',p.id_producto,
       JSON_OBJECT(
         'campo','codigo_de_barras',
         'valor',p.codigo_de_barras,
         'producto_canonico',d.id_canonico,
         'resolucion','identificador removido; requiere conciliacion manual'
       ),''
FROM producto p
JOIN tmp_producto_codigo_duplicado d
  ON d.id_cuenta=p.id_cuenta AND d.codigo_de_barras=p.codigo_de_barras
WHERE p.id_producto<>d.id_canonico;

UPDATE producto p
JOIN tmp_producto_codigo_duplicado d
  ON d.id_cuenta=p.id_cuenta AND d.codigo_de_barras=p.codigo_de_barras
SET p.codigo_de_barras=NULL
WHERE p.id_producto<>d.id_canonico;
DROP TEMPORARY TABLE tmp_producto_codigo_duplicado;

CREATE TEMPORARY TABLE tmp_producto_sku_duplicado AS
SELECT id_cuenta,sku,MIN(id_producto) AS id_canonico
FROM producto
WHERE sku IS NOT NULL
GROUP BY id_cuenta,sku
HAVING COUNT(*)>1;

INSERT INTO inventario_auditoria(id_user,accion,entidad,id_entidad,detalle,ip)
SELECT p.id_user,'MIGRAR_IDENTIDAD_DUPLICADA','producto',p.id_producto,
       JSON_OBJECT(
         'campo','sku',
         'valor',p.sku,
         'producto_canonico',d.id_canonico,
         'resolucion','identificador removido; requiere conciliacion manual'
       ),''
FROM producto p
JOIN tmp_producto_sku_duplicado d
  ON d.id_cuenta=p.id_cuenta AND d.sku=p.sku
WHERE p.id_producto<>d.id_canonico;

UPDATE producto p
JOIN tmp_producto_sku_duplicado d
  ON d.id_cuenta=p.id_cuenta AND d.sku=p.sku
SET p.sku=NULL
WHERE p.id_producto<>d.id_canonico;
DROP TEMPORARY TABLE tmp_producto_sku_duplicado;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto'
     AND INDEX_NAME='uq_producto_cuenta_barcode')=0,
  'ALTER TABLE producto ADD UNIQUE KEY uq_producto_cuenta_barcode (id_cuenta,codigo_de_barras)',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto'
     AND INDEX_NAME='uq_producto_cuenta_sku')=0,
  'ALTER TABLE producto ADD UNIQUE KEY uq_producto_cuenta_sku (id_cuenta,sku)',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

-- Crea solo las categorías legacy aún inexistentes y luego vincula todos los
-- productos por cuenta. id_categoria queda como fuente canónica; el texto se
-- conserva sincronizado durante la transición de clientes antiguos.
INSERT INTO categoria(id_cuenta,id_user,nombre,descripcion,activo)
SELECT p.id_cuenta,MIN(p.id_user),TRIM(p.categoria),
       'Categoría migrada desde producto.categoria',1
FROM producto p
WHERE NULLIF(TRIM(p.categoria),'') IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM categoria c
    WHERE c.id_cuenta=p.id_cuenta AND c.nombre=TRIM(p.categoria)
  )
GROUP BY p.id_cuenta,TRIM(p.categoria);

UPDATE producto p
JOIN (
  SELECT id_cuenta,nombre,MIN(id_categoria) AS id_categoria
  FROM categoria
  GROUP BY id_cuenta,nombre
) c ON c.id_cuenta=p.id_cuenta AND c.nombre=TRIM(p.categoria)
SET p.id_categoria=c.id_categoria
WHERE p.id_categoria IS NULL AND NULLIF(TRIM(p.categoria),'') IS NOT NULL;

UPDATE producto p
JOIN categoria c
  ON c.id_categoria=p.id_categoria AND c.id_cuenta=p.id_cuenta
SET p.categoria=c.nombre
WHERE p.categoria IS NULL OR p.categoria<>c.nombre;
