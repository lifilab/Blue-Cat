-- Sprint 1: contrato decimal y columnas operativas del inventario.
-- Mantiene compatibilidad con instalaciones legacy mediante DDL condicional.
SET NAMES utf8mb4;

-- El flujo existía en la API y la UI, pero el permiso nunca fue creado en el
-- catálogo canónico. Sin él nadie podía iniciar ni registrar un conteo.
INSERT INTO permiso(modulo,accion,descripcion)
VALUES ('inventario','conteo_fisico','Realizar conteos físicos de inventario')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r
JOIN permiso p ON p.modulo='inventario' AND p.accion='conteo_fisico'
WHERE r.nombre IN ('Administrador','Supervisor','Bodeguero') AND r.activo=1;

-- Las instalaciones antiguas podían conservar NULL aunque el contrato actual
-- exige cantidades operativas. El backfill debe ocurrir antes de endurecer el
-- esquema para que la actualización no se interrumpa a mitad de camino.
UPDATE producto SET stock_minimo=COALESCE(stock_minimo,0),stock_maximo=COALESCE(stock_maximo,0),punto_reposicion=COALESCE(punto_reposicion,0),stock_seguridad=COALESCE(stock_seguridad,0);
UPDATE stock SET disponible=COALESCE(disponible,0),reservado=COALESCE(reservado,0),comprometido=COALESCE(comprometido,0),en_transito=COALESCE(en_transito,0),danado=COALESCE(danado,0),bloqueado=COALESCE(bloqueado,0),devuelto=COALESCE(devuelto,0),produccion=COALESCE(produccion,0);
UPDATE kardex SET entrada=COALESCE(entrada,0),salida=COALESCE(salida,0),saldo=COALESCE(saldo,0);
UPDATE movimiento_inventario SET cantidad=COALESCE(cantidad,0);
UPDATE transferencia_detalle SET cantidad=COALESCE(cantidad,0);
UPDATE detalle_pedido SET cantidad_pedida=COALESCE(cantidad_pedida,0);
UPDATE pos_devolucion_detalle SET cantidad=COALESCE(cantidad,0);
UPDATE pos_cotizacion_detalle SET cantidad=COALESCE(cantidad,0);
UPDATE ajuste_inventario SET cantidad_anterior=COALESCE(cantidad_anterior,0),cantidad_nueva=COALESCE(cantidad_nueva,0),diferencia=COALESCE(diferencia,0);

ALTER TABLE producto
  MODIFY cantidad DECIMAL(18,3) NULL DEFAULT NULL,
  MODIFY stock_minimo DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY stock_maximo DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY punto_reposicion DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY stock_seguridad DECIMAL(18,3) NOT NULL DEFAULT 0;

ALTER TABLE stock
  MODIFY disponible DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY reservado DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY comprometido DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY en_transito DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY danado DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY bloqueado DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY devuelto DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY produccion DECIMAL(18,3) NOT NULL DEFAULT 0;

ALTER TABLE kardex
  MODIFY entrada DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY salida DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY saldo DECIMAL(18,3) NOT NULL DEFAULT 0;

ALTER TABLE movimiento_inventario
  MODIFY cantidad DECIMAL(18,3) NOT NULL;

ALTER TABLE transferencia_detalle
  MODIFY cantidad DECIMAL(18,3) NOT NULL;

ALTER TABLE detalle_pedido
  MODIFY cantidad_pedida DECIMAL(18,3) NOT NULL;

ALTER TABLE pos_devolucion_detalle
  MODIFY cantidad DECIMAL(18,3) NOT NULL DEFAULT 1;

ALTER TABLE pos_cotizacion_detalle
  MODIFY cantidad DECIMAL(18,3) NOT NULL DEFAULT 1;

ALTER TABLE factura_detalle
  MODIFY cantidad DECIMAL(18,3) NOT NULL DEFAULT 1;

ALTER TABLE alerta_stock
  MODIFY nivel_actual DECIMAL(18,3) NULL DEFAULT 0,
  MODIFY nivel_minimo DECIMAL(18,3) NULL DEFAULT 0;

ALTER TABLE pos_promocion
  MODIFY cantidad_pagada DECIMAL(18,3) NULL,
  MODIFY cantidad_beneficiada DECIMAL(18,3) NULL;

ALTER TABLE pos_promocion_producto
  MODIFY cantidad_minima DECIMAL(18,3) NOT NULL DEFAULT 1;

ALTER TABLE ajuste_inventario
  MODIFY cantidad_anterior DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY cantidad_nueva DECIMAL(18,3) NOT NULL DEFAULT 0,
  MODIFY diferencia DECIMAL(18,3) NOT NULL;

-- El esquema original usa created_at. La API lo expone como fecha_creacion,
-- pero estos dos datos de recepción sí deben persistir.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND COLUMN_NAME='fecha_recepcion')=0,'ALTER TABLE transferencia ADD COLUMN fecha_recepcion DATETIME NULL AFTER created_at','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND COLUMN_NAME='id_user_recibe')=0,'ALTER TABLE transferencia ADD COLUMN id_user_recibe INT NULL AFTER id_user','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND INDEX_NAME='idx_transferencia_receptor')=0,'ALTER TABLE transferencia ADD KEY idx_transferencia_receptor (id_user_recibe)','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND CONSTRAINT_NAME='fk_transferencia_receptor')=0,'ALTER TABLE transferencia ADD CONSTRAINT fk_transferencia_receptor FOREIGN KEY (id_user_recibe) REFERENCES usuario(id_user) ON DELETE SET NULL','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;

-- Una toma general puede contener líneas de varias bodegas; por eso la línea
-- conserva su bodega y la cabecera admite NULL.
ALTER TABLE inventario_fisico MODIFY id_bodega INT NULL;
UPDATE conteo_inventario SET cantidad_contada=COALESCE(cantidad_contada,0);
ALTER TABLE conteo_inventario MODIFY cantidad_contada DECIMAL(18,3) NOT NULL DEFAULT 0;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteo_inventario' AND COLUMN_NAME='id_bodega')=0,'ALTER TABLE conteo_inventario ADD COLUMN id_bodega INT NULL AFTER id_producto','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteo_inventario' AND COLUMN_NAME='stock_sistema')=0,'ALTER TABLE conteo_inventario ADD COLUMN stock_sistema DECIMAL(18,3) NOT NULL DEFAULT 0 AFTER id_ubicacion','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteo_inventario' AND COLUMN_NAME='conteo1')=0,'ALTER TABLE conteo_inventario ADD COLUMN conteo1 DECIMAL(18,3) NULL AFTER stock_sistema','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteo_inventario' AND COLUMN_NAME='conteo2')=0,'ALTER TABLE conteo_inventario ADD COLUMN conteo2 DECIMAL(18,3) NULL AFTER conteo1','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteo_inventario' AND COLUMN_NAME='conteo3')=0,'ALTER TABLE conteo_inventario ADD COLUMN conteo3 DECIMAL(18,3) NULL AFTER conteo2','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteo_inventario' AND COLUMN_NAME='diferencia')=0,'ALTER TABLE conteo_inventario ADD COLUMN diferencia DECIMAL(18,3) NULL AFTER cantidad_contada','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;

UPDATE conteo_inventario c
JOIN inventario_fisico f ON f.id_inventario=c.id_inventario
SET c.id_bodega=f.id_bodega
WHERE c.id_bodega IS NULL AND f.id_bodega IS NOT NULL;

SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='conteo_inventario' AND INDEX_NAME='idx_conteo_bodega')=0,'ALTER TABLE conteo_inventario ADD KEY idx_conteo_bodega (id_bodega)','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='conteo_inventario' AND CONSTRAINT_NAME='fk_conteo_bodega')=0,'ALTER TABLE conteo_inventario ADD CONSTRAINT fk_conteo_bodega FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega) ON DELETE RESTRICT','DO 0'); PREPARE inv_stmt FROM @ddl; EXECUTE inv_stmt; DEALLOCATE PREPARE inv_stmt;

-- Estas entidades ya eran consumidas por inventario.php, pero nunca formaron
-- parte de las migraciones canónicas. En una instalación limpia el dashboard,
-- lotes, series y valorización respondían 500 por tabla inexistente.
CREATE TABLE IF NOT EXISTS subcategoria (
  id_subcategoria INT AUTO_INCREMENT PRIMARY KEY,
  id_categoria INT NOT NULL,
  codigo VARCHAR(30) NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subcategoria_nombre (id_categoria,nombre),
  KEY idx_subcategoria_categoria (id_categoria),
  CONSTRAINT fk_subcategoria_categoria FOREIGN KEY (id_categoria) REFERENCES categoria(id_categoria) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lote (
  id_lote INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  numero_lote VARCHAR(100) NOT NULL,
  id_proveedor INT NULL,
  fecha_fabricacion DATE NULL,
  fecha_ingreso DATE NOT NULL,
  fecha_vencimiento DATE NULL,
  cantidad DECIMAL(18,3) NOT NULL DEFAULT 0,
  cantidad_original DECIMAL(18,3) NOT NULL DEFAULT 0,
  id_ubicacion INT NULL,
  estado ENUM('DISPONIBLE','AGOTADO','VENCIDO','BLOQUEADO') NOT NULL DEFAULT 'DISPONIBLE',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lote_producto_numero (id_producto,numero_lote),
  KEY idx_lote_vencimiento (fecha_vencimiento),
  KEY idx_lote_proveedor (id_proveedor),
  KEY idx_lote_ubicacion (id_ubicacion),
  CONSTRAINT fk_lote_producto FOREIGN KEY (id_producto) REFERENCES producto(id_producto) ON DELETE CASCADE,
  CONSTRAINT fk_lote_proveedor FOREIGN KEY (id_proveedor) REFERENCES proveedor(id_proveedor) ON DELETE SET NULL,
  CONSTRAINT fk_lote_ubicacion FOREIGN KEY (id_ubicacion) REFERENCES ubicacion(id_ubicacion) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS serie (
  id_serie INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  numero_serie VARCHAR(150) NOT NULL,
  id_lote INT NULL,
  id_ubicacion INT NULL,
  estado ENUM('DISPONIBLE','VENDIDO','DEVUELTO','DANADO','BLOQUEADO') NOT NULL DEFAULT 'DISPONIBLE',
  id_cliente INT NULL,
  garantia_dias INT NULL,
  fecha_venta DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_serie_producto_numero (id_producto,numero_serie),
  KEY idx_serie_lote (id_lote),
  KEY idx_serie_ubicacion (id_ubicacion),
  KEY idx_serie_cliente (id_cliente),
  CONSTRAINT fk_serie_producto FOREIGN KEY (id_producto) REFERENCES producto(id_producto) ON DELETE CASCADE,
  CONSTRAINT fk_serie_lote FOREIGN KEY (id_lote) REFERENCES lote(id_lote) ON DELETE SET NULL,
  CONSTRAINT fk_serie_ubicacion FOREIGN KEY (id_ubicacion) REFERENCES ubicacion(id_ubicacion) ON DELETE SET NULL,
  CONSTRAINT fk_serie_cliente FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS valorizacion_inventario (
  id_valorizacion BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total_productos INT NOT NULL DEFAULT 0,
  total_unidades DECIMAL(18,3) NOT NULL DEFAULT 0,
  total_costo DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_venta DECIMAL(18,2) NOT NULL DEFAULT 0,
  observaciones VARCHAR(500) NULL,
  KEY idx_valorizacion_user_fecha (id_user,fecha),
  CONSTRAINT fk_valorizacion_user FOREIGN KEY (id_user) REFERENCES usuario(id_user) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
