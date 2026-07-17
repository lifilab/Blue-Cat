-- Phase 2 extension: configurable and auditable promotion rule engine.

ALTER TABLE pos_promocion
  MODIFY valor DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN cantidad_pagada DECIMAL(12,3) NULL AFTER cantidad_minima,
  ADD COLUMN cantidad_beneficiada DECIMAL(12,3) NULL AFTER cantidad_pagada,
  ADD COLUMN prioridad INT NOT NULL DEFAULT 100 AFTER combinable,
  ADD COLUMN requiere_codigo TINYINT(1) NOT NULL DEFAULT 0 AFTER prioridad,
  ADD COLUMN max_aplicaciones_transaccion INT NULL AFTER requiere_codigo,
  ADD COLUMN max_usos_cliente INT NULL AFTER max_aplicaciones_transaccion,
  ADD COLUMN segmento_cliente VARCHAR(50) NULL AFTER max_usos_cliente,
  ADD COLUMN lista_precios VARCHAR(50) NULL AFTER segmento_cliente,
  ADD COLUMN id_sucursal INT NULL AFTER lista_precios,
  ADD COLUMN canal VARCHAR(30) NULL AFTER id_sucursal,
  ADD COLUMN condiciones_json JSON NULL AFTER canal,
  ADD COLUMN beneficio_json JSON NULL AFTER condiciones_json,
  ADD COLUMN motivo VARCHAR(255) NULL AFTER beneficio_json,
  ADD COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVA' AFTER activo,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER usado,
  ADD KEY idx_pos_promocion_evaluacion (id_cuenta,activo,estado,prioridad),
  ADD CONSTRAINT fk_pos_promocion_sucursal FOREIGN KEY (id_sucursal) REFERENCES sucursal(id_sucursal) ON DELETE SET NULL;

ALTER TABLE pos_promocion_producto
  ADD COLUMN rol VARCHAR(20) NOT NULL DEFAULT 'ELEGIBLE' AFTER id_producto,
  ADD COLUMN codigo_producto VARCHAR(50) NULL AFTER rol,
  ADD COLUMN sku VARCHAR(50) NULL AFTER codigo_producto,
  ADD COLUMN cantidad_minima DECIMAL(12,3) NOT NULL DEFAULT 1 AFTER sku,
  ADD KEY idx_promo_producto_rol (id_promocion,rol),
  ADD KEY idx_promo_producto_codigo (codigo_producto),
  ADD KEY idx_promo_producto_sku (sku);

UPDATE pos_promocion_producto pp
JOIN producto p ON p.id_producto=pp.id_producto
SET pp.codigo_producto=p.codigo_de_barras,pp.sku=p.sku
WHERE pp.codigo_producto IS NULL AND pp.sku IS NULL;

ALTER TABLE detalle_pedido
  ADD COLUMN precio_unitario_original INT NULL AFTER cantidad_pedida,
  ADD COLUMN descuento INT NOT NULL DEFAULT 0 AFTER precio_unitario_original,
  ADD COLUMN precio_unitario_final INT NULL AFTER descuento;

ALTER TABLE pos_cotizacion
  ADD COLUMN cupones_json JSON NULL AFTER notas,
  ADD COLUMN promociones_json JSON NULL AFTER cupones_json;

CREATE TABLE IF NOT EXISTS pos_promocion_aplicacion (
  id_aplicacion BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_cuenta INT NOT NULL,
  id_pedido INT NOT NULL,
  id_promocion INT NOT NULL,
  id_cliente INT NULL,
  id_user INT NOT NULL,
  codigo VARCHAR(30) NULL,
  aplicaciones DECIMAL(12,3) NOT NULL DEFAULT 1,
  descuento INT NOT NULL,
  detalle_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_promocion_pedido (id_pedido,id_promocion),
  KEY idx_promocion_cliente (id_cuenta,id_cliente,id_promocion),
  CONSTRAINT fk_promo_aplicacion_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT,
  CONSTRAINT fk_promo_aplicacion_pedido FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido) ON DELETE RESTRICT,
  CONSTRAINT fk_promo_aplicacion_promocion FOREIGN KEY (id_promocion) REFERENCES pos_promocion(id_promocion) ON DELETE RESTRICT,
  CONSTRAINT fk_promo_aplicacion_cliente FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente) ON DELETE SET NULL,
  CONSTRAINT fk_promo_aplicacion_user FOREIGN KEY (id_user) REFERENCES usuario(id_user) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_promocion_auditoria (
  id_auditoria BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_cuenta INT NOT NULL,
  id_promocion INT NULL,
  id_pedido INT NULL,
  id_cliente INT NULL,
  id_user INT NOT NULL,
  evento VARCHAR(30) NOT NULL,
  motivo VARCHAR(255) NOT NULL,
  descuento INT NOT NULL DEFAULT 0,
  contexto_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_promo_audit_cuenta_fecha (id_cuenta,created_at),
  KEY idx_promo_audit_promocion (id_promocion),
  CONSTRAINT fk_promo_audit_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT,
  CONSTRAINT fk_promo_audit_promocion FOREIGN KEY (id_promocion) REFERENCES pos_promocion(id_promocion) ON DELETE SET NULL,
  CONSTRAINT fk_promo_audit_pedido FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido) ON DELETE SET NULL,
  CONSTRAINT fk_promo_audit_cliente FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente) ON DELETE SET NULL,
  CONSTRAINT fk_promo_audit_user FOREIGN KEY (id_user) REFERENCES usuario(id_user) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
