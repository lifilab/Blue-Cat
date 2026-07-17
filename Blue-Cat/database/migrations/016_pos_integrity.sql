-- Phase 2: POS integrity baseline (idempotency and normalized payments).
SET NAMES utf8mb4;

ALTER TABLE pedido
  ADD COLUMN monto_recibido INT NOT NULL DEFAULT 0 AFTER pago_total,
  ADD COLUMN vuelto INT NOT NULL DEFAULT 0 AFTER monto_recibido,
  ADD COLUMN folio BIGINT NULL AFTER tipo_documento,
  ADD COLUMN numero_documento VARCHAR(40) NULL AFTER folio,
  ADD UNIQUE KEY uq_pedido_folio (id_cuenta,tipo_documento,folio);

ALTER TABLE pos_devolucion_detalle
  ADD COLUMN id_detalle_pedido INT NULL AFTER id_devolucion,
  ADD KEY idx_devolucion_detalle_venta (id_detalle_pedido),
  ADD CONSTRAINT fk_devolucion_detalle_venta FOREIGN KEY (id_detalle_pedido)
    REFERENCES detalle_pedido(id_detalle_pedido) ON DELETE RESTRICT ON UPDATE CASCADE;

UPDATE pedido
SET monto_recibido = pago_total,
    vuelto = GREATEST(diferencia, 0)
WHERE monto_recibido = 0 AND pago_total > 0;

-- Legacy POS stored cash tendered as applied revenue. Remove historical change
-- from one cash payment/movement so reports and physical cash agree.
UPDATE metodo_de_pago mp
JOIN (
  SELECT p.id_pedido, MAX(m.id_metodo_de_pago) id_pago, p.diferencia vuelto
  FROM pedido p
  JOIN metodo_de_pago m ON m.id_pedido=p.id_pedido
    AND UPPER(TRIM(m.nombre_metodo_pago))='EFECTIVO'
  WHERE p.diferencia>0
  GROUP BY p.id_pedido,p.diferencia
) x ON x.id_pago=mp.id_metodo_de_pago
SET mp.monto=GREATEST(mp.monto-x.vuelto,0);

UPDATE pos_movimiento_caja mov
JOIN (
  SELECT p.id_pedido, MAX(m.id_movimiento) id_movimiento, p.diferencia vuelto
  FROM pedido p
  JOIN pos_movimiento_caja m ON m.id_pedido=p.id_pedido
    AND m.tipo='INGRESO' AND UPPER(TRIM(m.metodo))='EFECTIVO'
  WHERE p.diferencia>0
  GROUP BY p.id_pedido,p.diferencia
) x ON x.id_movimiento=mov.id_movimiento
SET mov.monto=GREATEST(mov.monto-x.vuelto,0);

UPDATE pedido
SET pago_total=precio_total,
    diferencia=0
WHERE diferencia>0;

UPDATE metodo_de_pago
SET nombre_metodo_pago = CASE
  WHEN UPPER(TRIM(nombre_metodo_pago)) = 'EFECTIVO' THEN 'EFECTIVO'
  WHEN UPPER(TRIM(nombre_metodo_pago)) IN ('TARJETA', 'CRÉDITO', 'CREDITO') THEN 'TARJETA_CREDITO'
  WHEN UPPER(TRIM(nombre_metodo_pago)) IN ('DÉBITO', 'DEBITO') THEN 'TARJETA_DEBITO'
  WHEN UPPER(TRIM(nombre_metodo_pago)) = 'TRANSFERENCIA' THEN 'TRANSFERENCIA'
  ELSE 'OTRO'
END;

UPDATE pos_movimiento_caja
SET metodo = CASE
  WHEN UPPER(TRIM(metodo)) = 'EFECTIVO' THEN 'EFECTIVO'
  WHEN UPPER(TRIM(metodo)) IN ('TARJETA', 'CRÉDITO', 'CREDITO') THEN 'TARJETA_CREDITO'
  WHEN UPPER(TRIM(metodo)) IN ('DÉBITO', 'DEBITO') THEN 'TARJETA_DEBITO'
  WHEN UPPER(TRIM(metodo)) = 'TRANSFERENCIA' THEN 'TRANSFERENCIA'
  ELSE 'OTRO'
END;

UPDATE pos_caja c
SET c.monto_actual=(
  SELECT COALESCE(SUM(CASE
    WHEN m.tipo IN ('APERTURA','INGRESO') THEN m.monto
    WHEN m.tipo='EGRESO' THEN -m.monto
    ELSE 0 END),0)
  FROM pos_movimiento_caja m
  WHERE m.id_caja=c.id_caja AND m.metodo='EFECTIVO' AND m.tipo<>'CIERRE'
)
WHERE c.estado='ABIERTA';

CREATE TABLE IF NOT EXISTS pos_venta_idempotencia (
  id_idempotencia BIGINT NOT NULL AUTO_INCREMENT,
  id_cuenta INT NOT NULL,
  id_user INT NOT NULL,
  clave VARCHAR(64) NOT NULL,
  solicitud_hash CHAR(64) NOT NULL,
  estado ENUM('PROCESANDO','COMPLETADA') NOT NULL DEFAULT 'PROCESANDO',
  id_pedido INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  PRIMARY KEY (id_idempotencia),
  UNIQUE KEY uq_pos_venta_idempotencia (id_cuenta, clave),
  KEY idx_pos_venta_idempotencia_pedido (id_pedido),
  CONSTRAINT fk_pos_venta_idempotencia_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pos_venta_idempotencia_user FOREIGN KEY (id_user)
    REFERENCES usuario(id_user) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pos_venta_idempotencia_pedido FOREIGN KEY (id_pedido)
    REFERENCES pedido(id_pedido) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_caja_fisica (
  id_caja_fisica INT NOT NULL AUTO_INCREMENT,
  id_cuenta INT NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  sucursal VARCHAR(100) NOT NULL DEFAULT 'Principal',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_caja_fisica),
  UNIQUE KEY uq_pos_caja_fisica_codigo (id_cuenta,codigo),
  CONSTRAINT fk_pos_caja_fisica_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pos_caja_fisica(id_cuenta,codigo,nombre,sucursal)
SELECT id_cuenta,TRIM(codigo),
       COALESCE(NULLIF(MAX(nombre),''),'Caja'),
       COALESCE(NULLIF(MAX(sucursal),''),'Principal')
FROM pos_caja
WHERE NULLIF(TRIM(codigo),'') IS NOT NULL
GROUP BY id_cuenta,TRIM(codigo);

INSERT INTO pos_caja_fisica(id_cuenta,codigo,nombre,sucursal)
SELECT id_cuenta,CONCAT('LEGACY-',id_caja),
       COALESCE(NULLIF(nombre,''),'Caja'),COALESCE(NULLIF(sucursal,''),'Principal')
FROM pos_caja WHERE NULLIF(TRIM(codigo),'') IS NULL;

ALTER TABLE pos_caja ADD COLUMN id_caja_fisica INT NULL AFTER id_cuenta;
UPDATE pos_caja c JOIN pos_caja_fisica f
  ON f.id_cuenta=c.id_cuenta
 AND f.codigo=COALESCE(NULLIF(TRIM(c.codigo),''),CONCAT('LEGACY-',c.id_caja))
SET c.id_caja_fisica=f.id_caja_fisica;
ALTER TABLE pos_caja
  MODIFY id_caja_fisica INT NOT NULL,
  ADD KEY idx_pos_caja_fisica_estado (id_caja_fisica,estado),
  ADD CONSTRAINT fk_pos_caja_fisica FOREIGN KEY (id_caja_fisica)
    REFERENCES pos_caja_fisica(id_caja_fisica) ON DELETE RESTRICT ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS pos_folio_contador (
  id_cuenta INT NOT NULL,
  tipo_documento VARCHAR(30) NOT NULL,
  ultimo_folio BIGINT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_cuenta,tipo_documento),
  CONSTRAINT fk_pos_folio_contador_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO pos_folio_contador(id_cuenta,tipo_documento,ultimo_folio)
SELECT id_cuenta,tipo_documento,COALESCE(MAX(folio),0)
FROM pedido GROUP BY id_cuenta,tipo_documento;

INSERT INTO pos_folio_contador(id_cuenta,tipo_documento,ultimo_folio)
SELECT id_cuenta,
       CASE WHEN tipo IN ('NOTA_CREDITO','NOTA_DEBITO') THEN tipo ELSE 'FACTURA' END,
       COALESCE(MAX(CAST(folio AS UNSIGNED)),0)
FROM factura WHERE folio IS NOT NULL
GROUP BY id_cuenta,CASE WHEN tipo IN ('NOTA_CREDITO','NOTA_DEBITO') THEN tipo ELSE 'FACTURA' END
ON DUPLICATE KEY UPDATE ultimo_folio=GREATEST(ultimo_folio,VALUES(ultimo_folio));

CREATE TABLE IF NOT EXISTS pos_documento_snapshot (
  id_snapshot BIGINT NOT NULL AUTO_INCREMENT,
  id_cuenta INT NOT NULL,
  id_pedido INT NOT NULL,
  contenido_json LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_snapshot),
  UNIQUE KEY uq_pos_documento_snapshot_pedido (id_pedido),
  KEY idx_pos_documento_snapshot_cuenta (id_cuenta,created_at),
  CONSTRAINT fk_pos_documento_snapshot_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pos_documento_snapshot_pedido FOREIGN KEY (id_pedido)
    REFERENCES pedido(id_pedido) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
