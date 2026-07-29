-- Sprint 1: una devolución física de FACTURA debe estar respaldada por una
-- nota de crédito fiscal concreta y esa nota no puede utilizarse dos veces.
SET NAMES utf8mb4;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_devolucion'
     AND COLUMN_NAME='id_factura_nota_credito')=0,
  'ALTER TABLE pos_devolucion ADD COLUMN id_factura_nota_credito INT NULL AFTER id_pedido',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_devolucion'
     AND INDEX_NAME='uq_pos_devolucion_nota_credito')=0,
  'ALTER TABLE pos_devolucion ADD UNIQUE KEY uq_pos_devolucion_nota_credito (id_cuenta,id_factura_nota_credito)',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pos_devolucion'
     AND CONSTRAINT_NAME='fk_pos_devolucion_nota_credito')=0,
  'ALTER TABLE pos_devolucion ADD CONSTRAINT fk_pos_devolucion_nota_credito FOREIGN KEY (id_factura_nota_credito) REFERENCES factura(id_factura) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
