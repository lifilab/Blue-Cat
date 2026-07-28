-- Sprint 1: código de barras y SKU comparten un único espacio de identidad.
-- Los índices separados de 030 no impedían que el SKU de un producto fuera
-- igual al código de barras de otro producto de la misma cuenta.
SET NAMES utf8mb4;

CREATE TEMPORARY TABLE tmp_producto_identificador AS
SELECT id_cuenta,id_producto,'CODIGO' AS tipo,codigo_de_barras AS identificador
FROM producto WHERE codigo_de_barras IS NOT NULL
UNION ALL
SELECT id_cuenta,id_producto,'SKU' AS tipo,sku AS identificador
FROM producto WHERE sku IS NOT NULL;

CREATE TEMPORARY TABLE tmp_producto_identificador_canonico AS
SELECT id_cuenta,identificador,MIN(id_producto) AS id_producto,
       CAST(NULL AS CHAR(6)) AS tipo
FROM tmp_producto_identificador
GROUP BY id_cuenta,identificador;

UPDATE tmp_producto_identificador_canonico c
SET c.tipo=CASE
  WHEN EXISTS(
    SELECT 1 FROM tmp_producto_identificador i
    WHERE i.id_cuenta=c.id_cuenta
      AND i.identificador=c.identificador
      AND i.id_producto=c.id_producto
      AND i.tipo='CODIGO'
  ) THEN 'CODIGO'
  ELSE 'SKU'
END;

INSERT INTO inventario_auditoria(id_user,accion,entidad,id_entidad,detalle,ip)
SELECT p.id_user,'MIGRAR_IDENTIDAD_CRUZADA','producto',i.id_producto,
       JSON_OBJECT(
         'campo',LOWER(i.tipo),
         'valor',i.identificador,
         'producto_canonico',c.id_producto,
         'campo_canonico',LOWER(c.tipo),
         'resolucion','identificador removido; requiere conciliacion manual'
       ),''
FROM tmp_producto_identificador i
JOIN tmp_producto_identificador_canonico c
  ON c.id_cuenta=i.id_cuenta AND c.identificador=i.identificador
JOIN producto p ON p.id_producto=i.id_producto
WHERE i.id_producto<>c.id_producto OR i.tipo<>c.tipo;

UPDATE producto p
JOIN tmp_producto_identificador i
  ON i.id_producto=p.id_producto AND i.tipo='CODIGO'
JOIN tmp_producto_identificador_canonico c
  ON c.id_cuenta=i.id_cuenta AND c.identificador=i.identificador
SET p.codigo_de_barras=NULL
WHERE i.id_producto<>c.id_producto OR c.tipo<>'CODIGO';

UPDATE producto p
JOIN tmp_producto_identificador i
  ON i.id_producto=p.id_producto AND i.tipo='SKU'
JOIN tmp_producto_identificador_canonico c
  ON c.id_cuenta=i.id_cuenta AND c.identificador=i.identificador
SET p.sku=NULL
WHERE i.id_producto<>c.id_producto OR c.tipo<>'SKU';

DROP TEMPORARY TABLE tmp_producto_identificador_canonico;
DROP TEMPORARY TABLE tmp_producto_identificador;

CREATE TABLE producto_identificador (
  id_cuenta INT NOT NULL,
  identificador VARCHAR(50) NOT NULL,
  id_producto INT NOT NULL,
  tipo ENUM('CODIGO','SKU') NOT NULL,
  PRIMARY KEY (id_cuenta,identificador),
  UNIQUE KEY uq_producto_identificador_tipo (id_producto,tipo),
  CONSTRAINT fk_producto_identificador_cuenta
    FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE CASCADE,
  CONSTRAINT fk_producto_identificador_producto
    FOREIGN KEY (id_producto) REFERENCES producto(id_producto) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO producto_identificador(id_cuenta,identificador,id_producto,tipo)
SELECT id_cuenta,codigo_de_barras,id_producto,'CODIGO'
FROM producto WHERE codigo_de_barras IS NOT NULL
UNION ALL
SELECT id_cuenta,sku,id_producto,'SKU'
FROM producto WHERE sku IS NOT NULL;

DROP TRIGGER IF EXISTS ai_producto_identificador;
CREATE TRIGGER ai_producto_identificador AFTER INSERT ON producto FOR EACH ROW
INSERT INTO producto_identificador(id_cuenta,identificador,id_producto,tipo)
SELECT NEW.id_cuenta,NEW.codigo_de_barras,NEW.id_producto,'CODIGO'
WHERE NEW.codigo_de_barras IS NOT NULL
UNION ALL
SELECT NEW.id_cuenta,NEW.sku,NEW.id_producto,'SKU'
WHERE NEW.sku IS NOT NULL;

DROP TRIGGER IF EXISTS bu_producto_identificador;
CREATE TRIGGER bu_producto_identificador BEFORE UPDATE ON producto FOR EACH ROW
DELETE FROM producto_identificador WHERE id_producto=OLD.id_producto;

DROP TRIGGER IF EXISTS au_producto_identificador;
CREATE TRIGGER au_producto_identificador AFTER UPDATE ON producto FOR EACH ROW
INSERT INTO producto_identificador(id_cuenta,identificador,id_producto,tipo)
SELECT NEW.id_cuenta,NEW.codigo_de_barras,NEW.id_producto,'CODIGO'
WHERE NEW.codigo_de_barras IS NOT NULL
UNION ALL
SELECT NEW.id_cuenta,NEW.sku,NEW.id_producto,'SKU'
WHERE NEW.sku IS NOT NULL;
