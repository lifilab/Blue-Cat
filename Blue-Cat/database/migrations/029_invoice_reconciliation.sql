-- Sprint 1: conciliacion fiscal e idempotencia de cobros.
--
-- Esta migracion es aditiva a 028 para que tambien repare instalaciones de
-- desarrollo donde la primera version de 028 ya hubiese sido registrada.
SET NAMES utf8mb4;

-- Permisos que consume el modulo de facturacion. Supervisor necesita poder
-- consultar el documento que autoriza; exportar es una capacidad separada.
INSERT INTO permiso(modulo,accion,descripcion) VALUES
('facturas','exportar','Exportar documentos de facturacion')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r
JOIN permiso p
  ON p.modulo='facturas'
 AND p.accion IN ('ver','nota_credito','nota_debito','exportar')
WHERE r.nombre IN ('Administrador','Supervisor') AND r.activo=1;

-- Un reintento de red del mismo cobro debe devolver la operacion original.
SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME='idempotency_key')=0,
  'ALTER TABLE factura_pago ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER observacion',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND COLUMN_NAME='solicitud_hash')=0,
  'ALTER TABLE factura_pago ADD COLUMN solicitud_hash CHAR(64) NULL AFTER idempotency_key',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura_pago' AND INDEX_NAME='uq_factura_pago_idempotencia')=0,
  'ALTER TABLE factura_pago ADD UNIQUE KEY uq_factura_pago_idempotencia (id_factura,idempotency_key)',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

-- NC y ND tambien son operaciones mutables: un timeout no puede emitir dos
-- documentos fiscales. FACTURA usa la unicidad por pedido y deja estas columnas
-- en NULL.
SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='idempotency_key')=0,
  'ALTER TABLE factura ADD COLUMN idempotency_key VARCHAR(64) NULL AFTER id_factura_origen',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND COLUMN_NAME='solicitud_hash')=0,
  'ALTER TABLE factura ADD COLUMN solicitud_hash CHAR(64) NULL AFTER idempotency_key',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND INDEX_NAME='uq_factura_nota_idempotencia')=0,
  'ALTER TABLE factura ADD UNIQUE KEY uq_factura_nota_idempotencia (id_cuenta,tipo,idempotency_key)',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

-- Una FK historica por id_cliente permitia asociar una factura de una cuenta
-- con un cliente de otra. Se registra y separa el vinculo corrupto antes de
-- imponer la relacion compuesta; nunca se reasigna silenciosamente.
INSERT INTO factura_historial(id_factura,id_user,accion,valor_anterior,valor_nuevo,ip)
SELECT f.id_factura,f.id_user,'CONCILIAR_CLIENTE_TENANT',
       CONCAT('Cliente #',f.id_cliente),
       'Cliente separado porque no pertenece a la cuenta de la factura',
       'migration-029'
FROM factura f
LEFT JOIN cliente c
  ON c.id_cuenta=f.id_cuenta AND c.id_cliente=f.id_cliente
WHERE f.id_cliente IS NOT NULL AND c.id_cliente IS NULL;

UPDATE factura f
LEFT JOIN cliente c
  ON c.id_cuenta=f.id_cuenta AND c.id_cliente=f.id_cliente
SET f.id_cliente=NULL
WHERE f.id_cliente IS NOT NULL AND c.id_cliente IS NULL;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cliente' AND INDEX_NAME='uq_cliente_cuenta_id')=0,
  'ALTER TABLE cliente ADD UNIQUE KEY uq_cliente_cuenta_id (id_cuenta,id_cliente)',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;
SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND CONSTRAINT_NAME='fk_factura_cliente_cuenta')=0,
  'ALTER TABLE factura ADD CONSTRAINT fk_factura_cliente_cuenta FOREIGN KEY (id_cuenta,id_cliente) REFERENCES cliente(id_cuenta,id_cliente) ON DELETE RESTRICT ON UPDATE CASCADE',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

-- Antes de imponer la unicidad pedido/documento se conserva como canonico el
-- documento no anulado mas antiguo. Solo cuando todos los duplicados estan
-- anulados se usa el documento mas antiguo como respaldo. Asi una anulacion
-- legacy no desplaza a la factura vigente del pedido.
DROP TEMPORARY TABLE IF EXISTS tmp_factura_canonica_029;
CREATE TEMPORARY TABLE tmp_factura_canonica_029 AS
SELECT id_cuenta,
       id_pedido,
       tipo,
       COALESCE(
         MIN(CASE WHEN estado<>'ANULADA' THEN id_factura END),
         MIN(id_factura)
       ) AS id_factura_canonica
FROM factura
WHERE id_pedido IS NOT NULL
GROUP BY id_cuenta,id_pedido,tipo
HAVING COUNT(*)>1;

INSERT INTO factura_historial(id_factura,id_user,accion,valor_anterior,valor_nuevo,ip)
SELECT f.id_factura,f.id_user,'CONCILIAR_DUPLICADO',
       CONCAT('Pedido #',f.id_pedido,'; tipo ',f.tipo),
       CONCAT('Se preserva el documento; canonico #',c.id_factura_canonica,
              '. El vinculo id_pedido se separa para impedir doble materializacion.'),
       'migration-029'
FROM factura f
JOIN tmp_factura_canonica_029 c
  ON c.id_cuenta=f.id_cuenta AND c.id_pedido=f.id_pedido AND c.tipo=f.tipo
WHERE f.id_factura<>c.id_factura_canonica;

UPDATE factura f
JOIN tmp_factura_canonica_029 c
  ON c.id_cuenta=f.id_cuenta AND c.id_pedido=f.id_pedido AND c.tipo=f.tipo
SET f.id_pedido=NULL
WHERE f.id_factura<>c.id_factura_canonica;
DROP TEMPORARY TABLE tmp_factura_canonica_029;

SET @ddl=IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='factura' AND INDEX_NAME='uq_factura_cuenta_pedido_tipo')=0,
  'ALTER TABLE factura ADD UNIQUE KEY uq_factura_cuenta_pedido_tipo (id_cuenta,id_pedido,tipo)',
  'DO 0'
); PREPARE op_stmt FROM @ddl; EXECUTE op_stmt; DEALLOCATE PREPARE op_stmt;

-- Registro explicito de notas legacy. Ninguna nota ambigua se atribuye por
-- proximidad, nombre de cliente o coincidencias parciales.
CREATE TABLE IF NOT EXISTS factura_legacy_reconciliacion (
  id_cuenta INT NOT NULL,
  id_factura_nota INT NOT NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
  bloquea_nueva_nc TINYINT(1) NOT NULL DEFAULT 1,
  resuelta_manual TINYINT(1) NOT NULL DEFAULT 0,
  detalle VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_cuenta,id_factura_nota),
  KEY idx_factura_legacy_estado (id_cuenta,estado,bloquea_nueva_nc,resuelta_manual),
  CONSTRAINT fk_factura_legacy_nota
    FOREIGN KEY (id_cuenta,id_factura_nota)
    REFERENCES factura(id_cuenta,id_factura) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS factura_legacy_origen_candidato (
  id_cuenta INT NOT NULL,
  id_factura_nota INT NOT NULL,
  id_factura_origen INT NOT NULL,
  evidencia VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_cuenta,id_factura_nota,id_factura_origen),
  KEY idx_factura_legacy_candidato_origen (id_cuenta,id_factura_origen),
  CONSTRAINT fk_factura_legacy_candidato_nota
    FOREIGN KEY (id_cuenta,id_factura_nota)
    REFERENCES factura(id_cuenta,id_factura) ON DELETE CASCADE,
  CONSTRAINT fk_factura_legacy_candidato_origen
    FOREIGN KEY (id_cuenta,id_factura_origen)
    REFERENCES factura(id_cuenta,id_factura) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO factura_legacy_reconciliacion
  (id_cuenta,id_factura_nota,estado,bloquea_nueva_nc,resuelta_manual,detalle)
SELECT n.id_cuenta,n.id_factura,'PENDIENTE',
       CASE WHEN n.tipo='NOTA_CREDITO' THEN 1 ELSE 0 END,
       0,'Nota anterior a la relacion fiscal explicita'
FROM factura n
WHERE n.tipo IN ('NOTA_CREDITO','NOTA_DEBITO')
  AND n.id_factura_origen IS NULL;

-- Solo se acepta como evidencia automatica un historial que mencione de forma
-- inequivoca el ID completo del documento original.
INSERT IGNORE INTO factura_legacy_origen_candidato
  (id_cuenta,id_factura_nota,id_factura_origen,evidencia)
SELECT n.id_cuenta,n.id_factura,o.id_factura,
       CONCAT('factura_historial#',h.id_historial)
FROM factura n
JOIN factura_historial h ON h.id_factura=n.id_factura
JOIN factura o ON o.id_cuenta=n.id_cuenta AND o.tipo='FACTURA'
WHERE n.tipo IN ('NOTA_CREDITO','NOTA_DEBITO')
  AND n.id_factura_origen IS NULL
  AND LOWER(CONCAT_WS(' ',h.valor_anterior,h.valor_nuevo))
      REGEXP CONCAT('factura #[[:space:]]*',o.id_factura,'([^0-9]|$)');

-- El flujo legacy real guardaba "NC <numero> asociada" en el historial de la
-- factura original. Tambien se admite el caso inverso (la nota menciona el
-- numero completo de la factura). Los numeros se limitan a caracteres seguros
-- y se exigen limites de token para que NC-1 no coincida con NC-10.
INSERT IGNORE INTO factura_legacy_origen_candidato
  (id_cuenta,id_factura_nota,id_factura_origen,evidencia)
SELECT n.id_cuenta,n.id_factura,o.id_factura,
       CONCAT('factura_historial_numero_nota#',h.id_historial)
FROM factura n
JOIN factura o
  ON o.id_cuenta=n.id_cuenta AND o.tipo='FACTURA'
JOIN factura_historial h ON h.id_factura=o.id_factura
WHERE n.tipo IN ('NOTA_CREDITO','NOTA_DEBITO')
  AND n.id_factura_origen IS NULL
  AND n.numero REGEXP '^[A-Za-z0-9_-]{3,50}$'
  AND LOWER(CONCAT_WS(' ',h.valor_anterior,h.valor_nuevo))
      REGEXP CONCAT('(^|[^[:alnum:]_-])',LOWER(n.numero),'([^[:alnum:]_-]|$)');

INSERT IGNORE INTO factura_legacy_origen_candidato
  (id_cuenta,id_factura_nota,id_factura_origen,evidencia)
SELECT n.id_cuenta,n.id_factura,o.id_factura,
       CONCAT('factura_historial_numero_origen#',h.id_historial)
FROM factura n
JOIN factura_historial h ON h.id_factura=n.id_factura
JOIN factura o
  ON o.id_cuenta=n.id_cuenta AND o.tipo='FACTURA'
WHERE n.tipo IN ('NOTA_CREDITO','NOTA_DEBITO')
  AND n.id_factura_origen IS NULL
  AND o.numero REGEXP '^[A-Za-z0-9_-]{3,50}$'
  AND LOWER(CONCAT_WS(' ',h.valor_anterior,h.valor_nuevo))
      REGEXP CONCAT('(^|[^[:alnum:]_-])',LOWER(o.numero),'([^[:alnum:]_-]|$)');

DROP TEMPORARY TABLE IF EXISTS tmp_factura_origen_unico_029;
CREATE TEMPORARY TABLE tmp_factura_origen_unico_029 AS
SELECT id_cuenta,id_factura_nota,MIN(id_factura_origen) AS id_factura_origen
FROM factura_legacy_origen_candidato
GROUP BY id_cuenta,id_factura_nota
HAVING COUNT(*)=1;

UPDATE factura n
JOIN tmp_factura_origen_unico_029 u
  ON u.id_cuenta=n.id_cuenta AND u.id_factura_nota=n.id_factura
SET n.id_factura_origen=u.id_factura_origen
WHERE n.id_factura_origen IS NULL;
DROP TEMPORARY TABLE tmp_factura_origen_unico_029;

-- El detalle solo se vincula si existe exactamente una linea candidata en la
-- factura original: mismo producto interno, o mismo texto cuando no hay ID.
DROP TEMPORARY TABLE IF EXISTS tmp_factura_detalle_unico_029;
CREATE TEMPORARY TABLE tmp_factura_detalle_unico_029 AS
SELECT nd.id_factura_detalle,
       MIN(od.id_factura_detalle) AS id_detalle_origen,
       COUNT(*) AS candidatos
FROM factura_legacy_reconciliacion r
JOIN factura n
  ON n.id_cuenta=r.id_cuenta AND n.id_factura=r.id_factura_nota
JOIN factura_detalle nd ON nd.id_factura=n.id_factura
JOIN factura_detalle od ON od.id_factura=n.id_factura_origen
WHERE n.id_factura_origen IS NOT NULL
  AND nd.id_detalle_origen IS NULL
  AND (
    (nd.id_producto IS NOT NULL AND od.id_producto=nd.id_producto)
    OR
    (nd.id_producto IS NULL AND od.id_producto IS NULL
     AND TRIM(LOWER(COALESCE(od.producto,'')))=TRIM(LOWER(COALESCE(nd.producto,''))))
  )
GROUP BY nd.id_factura_detalle
HAVING COUNT(*)=1;

UPDATE factura_detalle nd
JOIN tmp_factura_detalle_unico_029 u
  ON u.id_factura_detalle=nd.id_factura_detalle
SET nd.id_detalle_origen=u.id_detalle_origen
WHERE nd.id_detalle_origen IS NULL;
DROP TEMPORARY TABLE tmp_factura_detalle_unico_029;

DROP TEMPORARY TABLE IF EXISTS tmp_factura_legacy_estado_029;
CREATE TEMPORARY TABLE tmp_factura_legacy_estado_029 AS
SELECT r.id_cuenta,r.id_factura_nota,n.tipo,n.id_factura_origen,
       (SELECT COUNT(*) FROM factura_legacy_origen_candidato c
        WHERE c.id_cuenta=r.id_cuenta AND c.id_factura_nota=r.id_factura_nota) AS candidatos,
       (SELECT COUNT(*) FROM factura_detalle d
        WHERE d.id_factura=r.id_factura_nota) AS lineas,
       (SELECT COUNT(*) FROM factura_detalle d
        WHERE d.id_factura=r.id_factura_nota AND d.id_detalle_origen IS NULL) AS lineas_sin_origen
FROM factura_legacy_reconciliacion r
JOIN factura n
  ON n.id_cuenta=r.id_cuenta AND n.id_factura=r.id_factura_nota
WHERE r.resuelta_manual=0;

UPDATE factura_legacy_reconciliacion r
JOIN tmp_factura_legacy_estado_029 e
  ON e.id_cuenta=r.id_cuenta AND e.id_factura_nota=r.id_factura_nota
SET r.estado=CASE
      WHEN e.id_factura_origen IS NULL AND e.candidatos=0 THEN 'SIN_CANDIDATO'
      WHEN e.id_factura_origen IS NULL THEN 'ORIGEN_AMBIGUO'
      WHEN e.lineas=0 OR e.lineas_sin_origen>0 THEN 'DETALLE_AMBIGUO'
      ELSE 'VINCULADA'
    END,
    r.bloquea_nueva_nc=CASE
      WHEN e.tipo='NOTA_CREDITO'
       AND (e.id_factura_origen IS NULL OR e.lineas=0 OR e.lineas_sin_origen>0)
      THEN 1 ELSE 0
    END,
    r.detalle=CASE
      WHEN e.id_factura_origen IS NULL AND e.candidatos=0
        THEN 'No existe evidencia inequivoca del documento original'
      WHEN e.id_factura_origen IS NULL
        THEN CONCAT('Existen ',e.candidatos,' documentos originales candidatos')
      WHEN e.lineas=0
        THEN 'La nota no contiene lineas conciliables'
      WHEN e.lineas_sin_origen>0
        THEN CONCAT(e.lineas_sin_origen,' linea(s) no tienen origen inequivoco')
      ELSE 'Cabecera y detalle vinculados automaticamente'
    END;
DROP TEMPORARY TABLE tmp_factura_legacy_estado_029;

INSERT INTO factura_historial(id_factura,id_user,accion,valor_anterior,valor_nuevo,ip)
SELECT r.id_factura_nota,n.id_user,'CONCILIACION_LEGACY',NULL,
       CONCAT(r.estado,': ',COALESCE(r.detalle,'')),'migration-029'
FROM factura_legacy_reconciliacion r
JOIN factura n
  ON n.id_cuenta=r.id_cuenta AND n.id_factura=r.id_factura_nota;

-- El pagado historico se deriva exclusivamente del ledger de pagos. El saldo
-- previo pudo haber sido alterado por notas legacy y no constituye evidencia.
INSERT INTO factura_historial(id_factura,id_user,accion,valor_anterior,valor_nuevo,ip)
SELECT f.id_factura,f.id_user,'CONCILIAR_SOBREPAGO',
       CONCAT('Ledger: ',ROUND(p.monto_pagado,2),'; total: ',ROUND(f.total,2)),
       'El encabezado se limita al total; el ledger requiere revision manual.',
       'migration-029'
FROM factura f
JOIN (
  SELECT id_factura,SUM(monto) AS monto_pagado
  FROM factura_pago
  GROUP BY id_factura
) p ON p.id_factura=f.id_factura
WHERE f.tipo<>'NOTA_CREDITO' AND p.monto_pagado>f.total;

UPDATE factura f
LEFT JOIN (
  SELECT id_factura,SUM(monto) AS monto_pagado
  FROM factura_pago
  GROUP BY id_factura
) p ON p.id_factura=f.id_factura
SET f.pagado=CASE
      WHEN f.tipo='NOTA_CREDITO' THEN 0
      ELSE LEAST(GREATEST(COALESCE(p.monto_pagado,0),0),GREATEST(f.total,0))
    END,
    f.saldo=CASE
      WHEN f.tipo='NOTA_CREDITO' THEN 0
      ELSE GREATEST(f.total-LEAST(GREATEST(COALESCE(p.monto_pagado,0),0),GREATEST(f.total,0)),0)
    END,
    f.estado=CASE
      WHEN f.estado='ANULADA' THEN 'ANULADA'
      WHEN f.tipo='NOTA_CREDITO' THEN f.estado
      WHEN f.total>0 AND COALESCE(p.monto_pagado,0)>=f.total THEN 'PAGADA'
      WHEN COALESCE(p.monto_pagado,0)>0 THEN 'PARCIAL'
      ELSE 'EMITIDA'
    END;
