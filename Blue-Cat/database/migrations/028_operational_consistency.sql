-- Sprint 1: consistencia operativa posterior a la migración 026.
-- Esta migración es aditiva porque 026 ya puede estar registrada en clientes.
SET NAMES utf8mb4;

INSERT INTO permiso(modulo,accion,descripcion) VALUES
('facturas','nota_debito','Emitir notas de debito'),
('inventario','precios','Actualizar costo y precio con respaldo')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p
  ON p.modulo='facturas' AND p.accion IN ('nota_credito','nota_debito')
WHERE r.nombre IN ('Administrador','Supervisor') AND r.activo=1;
INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p
  ON p.modulo='inventario' AND p.accion='precios'
WHERE r.nombre IN ('Administrador','Supervisor') AND r.activo=1;

-- La clave de idempotencia evita que un doble clic o reintento de red reserve
-- dos veces el mismo stock. Las transferencias legacy permanecen con clave NULL.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND COLUMN_NAME='id_cuenta')=0,'ALTER TABLE transferencia ADD COLUMN id_cuenta INT NULL AFTER id_transferencia','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
UPDATE transferencia t JOIN bodega b ON b.id_bodega=t.id_bodega_origen SET t.id_cuenta=b.id_cuenta WHERE t.id_cuenta IS NULL;
-- Si queda una transferencia huérfana, el NOT NULL detiene la migración en vez
-- de atribuirla silenciosamente a otra cuenta.
ALTER TABLE transferencia MODIFY id_cuenta INT NOT NULL;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bodega' AND INDEX_NAME='uq_bodega_cuenta_id')=0,'ALTER TABLE bodega ADD UNIQUE KEY uq_bodega_cuenta_id (id_cuenta,id_bodega)','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND COLUMN_NAME='clave_idempotencia')=0,'ALTER TABLE transferencia ADD COLUMN clave_idempotencia VARCHAR(64) NULL AFTER numero','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND COLUMN_NAME='solicitud_hash')=0,'ALTER TABLE transferencia ADD COLUMN solicitud_hash CHAR(64) NULL AFTER clave_idempotencia','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND INDEX_NAME='uq_transferencia_idempotencia')=0,'ALTER TABLE transferencia ADD UNIQUE KEY uq_transferencia_idempotencia (id_cuenta,clave_idempotencia)','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND CONSTRAINT_NAME='fk_transferencia_cuenta')=0,'ALTER TABLE transferencia ADD CONSTRAINT fk_transferencia_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND CONSTRAINT_NAME='fk_transferencia_origen_cuenta')=0,'ALTER TABLE transferencia ADD CONSTRAINT fk_transferencia_origen_cuenta FOREIGN KEY (id_cuenta,id_bodega_origen) REFERENCES bodega(id_cuenta,id_bodega) ON DELETE RESTRICT','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='transferencia' AND CONSTRAINT_NAME='fk_transferencia_destino_cuenta')=0,'ALTER TABLE transferencia ADD CONSTRAINT fk_transferencia_destino_cuenta FOREIGN KEY (id_cuenta,id_bodega_destino) REFERENCES bodega(id_cuenta,id_bodega) ON DELETE RESTRICT','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

-- Conserva las ubicaciones exactas consumidas al enviar para que una
-- cancelación en tránsito pueda devolver cada unidad al mismo bin.
CREATE TABLE IF NOT EXISTS transferencia_asignacion (
  id_asignacion BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_transferencia INT NOT NULL,
  id_producto INT NOT NULL,
  id_stock INT NULL,
  cantidad DECIMAL(18,3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transferencia_asignacion_stock (id_transferencia,id_stock),
  KEY idx_transferencia_asignacion_producto (id_producto),
  CONSTRAINT fk_transferencia_asignacion_transferencia FOREIGN KEY (id_transferencia) REFERENCES transferencia(id_transferencia) ON DELETE CASCADE,
  CONSTRAINT fk_transferencia_asignacion_producto FOREIGN KEY (id_producto) REFERENCES producto(id_producto) ON DELETE RESTRICT,
  CONSTRAINT fk_transferencia_asignacion_stock FOREIGN KEY (id_stock) REFERENCES stock(id_stock) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada caja física opera contra una bodega concreta. Esto impide mostrar stock
-- global y descontar accidentalmente existencias de otra sucursal.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_caja_fisica' AND COLUMN_NAME='id_bodega')=0,'ALTER TABLE pos_caja_fisica ADD COLUMN id_bodega INT NULL AFTER sucursal','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
UPDATE pos_caja_fisica f
SET f.id_bodega=(SELECT MIN(b.id_bodega) FROM bodega b WHERE b.id_cuenta=f.id_cuenta AND b.estado='ACTIVA')
WHERE f.id_bodega IS NULL
  AND (SELECT COUNT(*) FROM bodega b WHERE b.id_cuenta=f.id_cuenta AND b.estado='ACTIVA')=1;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_caja_fisica' AND INDEX_NAME='idx_pos_caja_fisica_bodega')=0,'ALTER TABLE pos_caja_fisica ADD KEY idx_pos_caja_fisica_bodega (id_bodega)','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pos_caja_fisica' AND CONSTRAINT_NAME='fk_pos_caja_fisica_bodega')=0,'ALTER TABLE pos_caja_fisica ADD CONSTRAINT fk_pos_caja_fisica_bodega FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega) ON DELETE RESTRICT','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pos_caja_fisica' AND CONSTRAINT_NAME='fk_pos_caja_fisica_bodega_cuenta')=0,'ALTER TABLE pos_caja_fisica ADD CONSTRAINT fk_pos_caja_fisica_bodega_cuenta FOREIGN KEY (id_cuenta,id_bodega) REFERENCES bodega(id_cuenta,id_bodega) ON DELETE RESTRICT','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

-- Alinea el esquema histórico de facturación con el contrato utilizado por
-- su API. Los importes monetarios se conservan con dos decimales y el detalle
-- mantiene el precio unitario canónico, sin duplicar la columna `precio`.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='metodo_pago')=0,'ALTER TABLE factura ADD COLUMN metodo_pago VARCHAR(30) NULL AFTER saldo','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='sucursal')=0,'ALTER TABLE factura ADD COLUMN sucursal VARCHAR(150) NULL AFTER metodo_pago','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='vendedor')=0,'ALTER TABLE factura ADD COLUMN vendedor VARCHAR(150) NULL AFTER sucursal','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='orden_compra')=0,'ALTER TABLE factura ADD COLUMN orden_compra VARCHAR(100) NULL AFTER vendedor','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='pagado')=0,'ALTER TABLE factura ADD COLUMN pagado DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='id_factura_origen')=0,'ALTER TABLE factura ADD COLUMN id_factura_origen INT NULL AFTER id_pedido','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND INDEX_NAME='idx_factura_cuenta_pedido_tipo')=0,'ALTER TABLE factura ADD KEY idx_factura_cuenta_pedido_tipo (id_cuenta,id_pedido,tipo)','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND INDEX_NAME='idx_factura_cuenta_origen_tipo')=0,'ALTER TABLE factura ADD KEY idx_factura_cuenta_origen_tipo (id_cuenta,id_factura_origen,tipo)','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND INDEX_NAME='uq_factura_cuenta_id')=0,'ALTER TABLE factura ADD UNIQUE KEY uq_factura_cuenta_id (id_cuenta,id_factura)','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND CONSTRAINT_NAME='fk_factura_origen_cuenta')=0,'ALTER TABLE factura ADD CONSTRAINT fk_factura_origen_cuenta FOREIGN KEY (id_cuenta,id_factura_origen) REFERENCES factura(id_cuenta,id_factura) ON DELETE RESTRICT','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
UPDATE factura SET subtotal=COALESCE(subtotal,0),descuento=COALESCE(descuento,0),neto=COALESCE(neto,0),iva=COALESCE(iva,0),total=COALESCE(total,0),saldo=COALESCE(saldo,0);
ALTER TABLE factura
  MODIFY subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
  MODIFY descuento DECIMAL(18,2) NOT NULL DEFAULT 0,
  MODIFY neto DECIMAL(18,2) NOT NULL DEFAULT 0,
  MODIFY iva DECIMAL(18,2) NOT NULL DEFAULT 0,
  MODIFY total DECIMAL(18,2) NOT NULL DEFAULT 0,
  MODIFY pagado DECIMAL(18,2) NOT NULL DEFAULT 0,
  MODIFY saldo DECIMAL(18,2) NOT NULL DEFAULT 0;
UPDATE factura f
LEFT JOIN (SELECT id_factura,SUM(monto) AS monto_pagado FROM factura_pago GROUP BY id_factura) fp ON fp.id_factura=f.id_factura
SET f.pagado=LEAST(f.total,GREATEST(COALESCE(fp.monto_pagado,0),GREATEST(f.total-COALESCE(f.saldo,f.total),0)))
WHERE f.pagado=0 AND (COALESCE(fp.monto_pagado,0)>0 OR COALESCE(f.saldo,f.total)<f.total);
UPDATE factura SET pagado=LEAST(GREATEST(pagado,0),GREATEST(total,0));
UPDATE factura
SET saldo=GREATEST(total-pagado,0),
    estado=CASE WHEN total>0 AND pagado>=total THEN 'PAGADA' WHEN pagado>0 THEN 'PARCIAL' ELSE estado END
WHERE estado<>'ANULADA';

UPDATE factura_detalle SET precio_unitario=0 WHERE precio_unitario IS NULL;
UPDATE factura_detalle SET total=0 WHERE total IS NULL;
ALTER TABLE factura_detalle
  MODIFY precio_unitario DECIMAL(18,2) NOT NULL DEFAULT 0,
  MODIFY total DECIMAL(18,2) NOT NULL DEFAULT 0;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND COLUMN_NAME='descuento')=0,'ALTER TABLE factura_detalle ADD COLUMN descuento DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER precio_unitario','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND COLUMN_NAME='neto')=0,'ALTER TABLE factura_detalle ADD COLUMN neto DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER descuento','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND COLUMN_NAME='iva')=0,'ALTER TABLE factura_detalle ADD COLUMN iva DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER neto','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND COLUMN_NAME='id_detalle_origen')=0,'ALTER TABLE factura_detalle ADD COLUMN id_detalle_origen INT NULL AFTER id_factura','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND INDEX_NAME='idx_factura_detalle_origen')=0,'ALTER TABLE factura_detalle ADD KEY idx_factura_detalle_origen (id_detalle_origen)','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='factura_detalle' AND CONSTRAINT_NAME='fk_factura_detalle_origen')=0,'ALTER TABLE factura_detalle ADD CONSTRAINT fk_factura_detalle_origen FOREIGN KEY (id_detalle_origen) REFERENCES factura_detalle(id_factura_detalle) ON DELETE RESTRICT','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
UPDATE factura_detalle d
JOIN factura f ON f.id_factura=d.id_factura
SET d.descuento=GREATEST(ROUND(d.precio_unitario*d.cantidad,2)-d.total,0),
    d.iva=CASE WHEN COALESCE(f.neto,0)+COALESCE(f.iva,0)>0 THEN ROUND(d.total*COALESCE(f.iva,0)/(COALESCE(f.neto,0)+COALESCE(f.iva,0)),2) ELSE 0 END,
    d.neto=d.total-(CASE WHEN COALESCE(f.neto,0)+COALESCE(f.iva,0)>0 THEN ROUND(d.total*COALESCE(f.iva,0)/(COALESCE(f.neto,0)+COALESCE(f.iva,0)),2) ELSE 0 END)
WHERE d.total>0 AND d.neto=0 AND d.iva=0;

SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME='banco')=0,'ALTER TABLE factura_pago ADD COLUMN banco VARCHAR(100) NULL AFTER monto','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME='observacion')=0,'ALTER TABLE factura_pago ADD COLUMN observacion VARCHAR(500) NULL AFTER referencia','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
ALTER TABLE factura_pago MODIFY monto DECIMAL(18,2) NOT NULL;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME='id_metodo_de_pago')=0,'ALTER TABLE factura_pago ADD COLUMN id_metodo_de_pago INT NULL AFTER id_factura','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND INDEX_NAME='uq_factura_pago_metodo_pos')=0,'ALTER TABLE factura_pago ADD UNIQUE KEY uq_factura_pago_metodo_pos (id_metodo_de_pago)','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND CONSTRAINT_NAME='fk_factura_pago_metodo_pos')=0,'ALTER TABLE factura_pago ADD CONSTRAINT fk_factura_pago_metodo_pos FOREIGN KEY (id_metodo_de_pago) REFERENCES metodo_de_pago(id_metodo_de_pago) ON DELETE RESTRICT','DO 0'); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
