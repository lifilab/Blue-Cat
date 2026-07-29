-- Sprint 1: una devolución debe poder reintentarse sin duplicar stock ni caja.
SET NAMES utf8mb4;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_devolucion' AND COLUMN_NAME='id_cuenta')=0,
  'ALTER TABLE pos_devolucion ADD COLUMN id_cuenta INT NULL AFTER id_devolucion',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

UPDATE pos_devolucion d
JOIN pedido p ON p.id_pedido=d.id_pedido
SET d.id_cuenta=p.id_cuenta
WHERE d.id_cuenta IS NULL;

ALTER TABLE pos_devolucion MODIFY id_cuenta INT NOT NULL;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_devolucion' AND COLUMN_NAME='clave_idempotencia')=0,
  'ALTER TABLE pos_devolucion ADD COLUMN clave_idempotencia VARCHAR(64) NULL AFTER id_pedido',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_devolucion' AND COLUMN_NAME='solicitud_hash')=0,
  'ALTER TABLE pos_devolucion ADD COLUMN solicitud_hash CHAR(64) NULL AFTER clave_idempotencia',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_devolucion' AND INDEX_NAME='uq_pos_devolucion_idempotencia')=0,
  'ALTER TABLE pos_devolucion ADD UNIQUE KEY uq_pos_devolucion_idempotencia (id_cuenta,clave_idempotencia)',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pos_devolucion' AND CONSTRAINT_NAME='fk_pos_devolucion_cuenta')=0,
  'ALTER TABLE pos_devolucion ADD CONSTRAINT fk_pos_devolucion_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE',
  'DO 0'
);
PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
