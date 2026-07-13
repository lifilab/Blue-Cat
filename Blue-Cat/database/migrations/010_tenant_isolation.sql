-- Phase 1: formal tenant/account boundary.
SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS cuenta (
  id_cuenta INT NOT NULL AUTO_INCREMENT,
  id_usuario_propietario INT NULL,
  nombre VARCHAR(120) NOT NULL,
  estado ENUM('ACTIVA','SUSPENDIDA','CERRADA') NOT NULL DEFAULT 'ACTIVA',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_cuenta),
  UNIQUE KEY uq_cuenta_propietario (id_usuario_propietario),
  CONSTRAINT fk_cuenta_propietario FOREIGN KEY (id_usuario_propietario)
    REFERENCES usuario(id_user) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Preserve the legacy convention where the owner user id represented the account.
INSERT INTO cuenta (id_cuenta, id_usuario_propietario, nombre)
SELECT account_id,
       COALESCE(MAX(CASE WHEN id_user=account_id THEN id_user END), MIN(id_user)),
       COALESCE(MAX(CASE WHEN id_user=account_id THEN NULLIF(nombre_completo,'') END),
                MAX(CASE WHEN id_user=account_id THEN nombre END),
                CONCAT('Cuenta ', account_id))
FROM (
  SELECT u.*, COALESCE(NULLIF(u.id_cuenta,0),u.id_user) account_id
  FROM usuario u
) legacy_accounts
GROUP BY account_id
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

UPDATE usuario SET id_cuenta=id_user WHERE id_cuenta IS NULL OR id_cuenta=0;
ALTER TABLE usuario
  MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_usuario_cuenta_activo (id_cuenta, activo),
  ADD UNIQUE KEY uq_usuario_nombre (nombre),
  ADD UNIQUE KEY uq_usuario_correo (correo),
  ADD CONSTRAINT fk_usuario_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

UPDATE empleado e
JOIN usuario u ON u.id_user=e.id_user
SET e.id_cuenta=u.id_cuenta
WHERE e.id_user IS NOT NULL;
ALTER TABLE empleado
  MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_empleado_cuenta_estado (id_cuenta, estado),
  ADD CONSTRAINT fk_empleado_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

SET @account_count=(SELECT COUNT(*) FROM cuenta);
SET @single_account_id=(SELECT IF(@account_count=1,MIN(id_cuenta),NULL) FROM cuenta);

ALTER TABLE empresa ADD COLUMN id_cuenta INT NULL AFTER id_empresa;
UPDATE empresa e
LEFT JOIN sucursal s ON s.id_empresa=e.id_empresa
LEFT JOIN usuario u ON u.id_sucursal=s.id_sucursal
SET e.id_cuenta=COALESCE(e.id_cuenta,u.id_cuenta,@single_account_id);
ALTER TABLE empresa
  MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_empresa_cuenta_activo (id_cuenta,activo),
  ADD CONSTRAINT fk_empresa_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE sucursal ADD COLUMN id_cuenta INT NULL AFTER id_sucursal;
UPDATE sucursal s JOIN empresa e ON e.id_empresa=s.id_empresa SET s.id_cuenta=e.id_cuenta;
ALTER TABLE sucursal
  MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_sucursal_cuenta_activo (id_cuenta,activo),
  ADD CONSTRAINT fk_sucursal_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE rol
  ADD COLUMN id_cuenta INT NULL AFTER id_rol,
  ADD COLUMN es_plantilla TINYINT(1) NOT NULL DEFAULT 1 AFTER es_sistema,
  DROP INDEX nombre,
  ADD UNIQUE KEY uq_rol_cuenta_nombre (id_cuenta,nombre),
  ADD KEY idx_rol_cuenta_activo (id_cuenta,activo),
  ADD CONSTRAINT fk_rol_cuenta FOREIGN KEY (id_cuenta)
    REFERENCES cuenta(id_cuenta) ON DELETE CASCADE ON UPDATE CASCADE;

INSERT INTO rol (id_cuenta,nombre,descripcion,activo,es_sistema,es_plantilla)
SELECT c.id_cuenta,r.nombre,r.descripcion,r.activo,0,0
FROM cuenta c CROSS JOIN rol r
WHERE r.id_cuenta IS NULL AND r.es_plantilla=1;

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT local_role.id_rol,rp.id_permiso
FROM rol local_role
JOIN rol template_role ON template_role.id_cuenta IS NULL AND template_role.nombre=local_role.nombre
JOIN rol_permiso rp ON rp.id_rol=template_role.id_rol
WHERE local_role.id_cuenta IS NOT NULL;

UPDATE usuario_rol ur
JOIN usuario u ON u.id_user=ur.id_user
JOIN rol template_role ON template_role.id_rol=ur.id_rol AND template_role.id_cuenta IS NULL
JOIN rol local_role ON local_role.id_cuenta=u.id_cuenta AND local_role.nombre=template_role.nombre
SET ur.id_rol=local_role.id_rol;

ALTER TABLE categoria ADD COLUMN id_cuenta INT NULL AFTER id_categoria;
UPDATE categoria x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE categoria MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_categoria_cuenta (id_cuenta),
  ADD CONSTRAINT fk_categoria_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE marca ADD COLUMN id_cuenta INT NULL AFTER id_marca;
UPDATE marca x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE marca MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_marca_cuenta (id_cuenta),
  ADD CONSTRAINT fk_marca_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE producto ADD COLUMN id_cuenta INT NULL AFTER id_producto;
UPDATE producto x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE producto MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_producto_cuenta_activo (id_cuenta,activo),
  ADD KEY idx_producto_cuenta_barcode (id_cuenta,codigo_de_barras),
  ADD CONSTRAINT fk_producto_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE bodega ADD COLUMN id_cuenta INT NULL AFTER id_bodega;
UPDATE bodega x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE bodega MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_bodega_cuenta_estado (id_cuenta,estado),
  ADD CONSTRAINT fk_bodega_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE cliente ADD COLUMN id_cuenta INT NULL AFTER id_cliente;
UPDATE cliente x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
SET @has_uq_codigo=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cliente' AND INDEX_NAME='uq_codigo');
SET @drop_uq_codigo=IF(@has_uq_codigo>0,'ALTER TABLE cliente DROP INDEX uq_codigo','DO 0');
PREPARE phase1_stmt FROM @drop_uq_codigo;
EXECUTE phase1_stmt;
DEALLOCATE PREPARE phase1_stmt;
ALTER TABLE cliente
  MODIFY id_cuenta INT NOT NULL,
  ADD UNIQUE KEY uq_cliente_cuenta_codigo (id_cuenta,codigo),
  ADD KEY idx_cliente_cuenta_estado (id_cuenta,estado),
  ADD CONSTRAINT fk_cliente_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE cliente_etiqueta ADD COLUMN id_cuenta INT NULL AFTER id_etiqueta;
UPDATE cliente_etiqueta e
JOIN cliente_etiqueta_rel er ON er.id_etiqueta=e.id_etiqueta
JOIN cliente c ON c.id_cliente=er.id_cliente
SET e.id_cuenta=c.id_cuenta;
UPDATE cliente_etiqueta SET id_cuenta=@single_account_id WHERE id_cuenta IS NULL;
ALTER TABLE cliente_etiqueta MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_cliente_etiqueta_cuenta (id_cuenta),
  ADD CONSTRAINT fk_cliente_etiqueta_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE proveedor ADD COLUMN id_cuenta INT NULL AFTER id_proveedor;
UPDATE proveedor x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE proveedor MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_proveedor_cuenta_estado (id_cuenta,estado),
  ADD CONSTRAINT fk_proveedor_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE sesion ADD COLUMN id_cuenta INT NULL AFTER id_sesion;
UPDATE sesion x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE sesion MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_sesion_cuenta_fecha (id_cuenta,fecha_ingreso),
  ADD CONSTRAINT fk_sesion_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE pedido ADD COLUMN id_cuenta INT NULL AFTER id_pedido;
UPDATE pedido x JOIN sesion s ON s.id_sesion=x.id_sesion SET x.id_cuenta=s.id_cuenta;
ALTER TABLE pedido MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_pedido_cuenta_fecha (id_cuenta,fecha),
  ADD CONSTRAINT fk_pedido_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE pos_caja ADD COLUMN id_cuenta INT NULL AFTER id_caja;
UPDATE pos_caja x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE pos_caja MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_pos_caja_cuenta_estado (id_cuenta,estado),
  ADD CONSTRAINT fk_pos_caja_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE pos_promocion ADD COLUMN id_cuenta INT NULL AFTER id_promocion;
UPDATE pos_promocion x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE pos_promocion MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_pos_promocion_cuenta (id_cuenta),
  ADD CONSTRAINT fk_pos_promocion_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE pos_cotizacion ADD COLUMN id_cuenta INT NULL AFTER id_cotizacion;
UPDATE pos_cotizacion x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE pos_cotizacion MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_pos_cotizacion_cuenta (id_cuenta),
  ADD CONSTRAINT fk_pos_cotizacion_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE pos_reserva ADD COLUMN id_cuenta INT NULL AFTER id_reserva;
UPDATE pos_reserva x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE pos_reserva MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_pos_reserva_cuenta (id_cuenta),
  ADD CONSTRAINT fk_pos_reserva_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE factura ADD COLUMN id_cuenta INT NULL AFTER id_factura;
UPDATE factura x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE factura MODIFY id_cuenta INT NOT NULL,
  ADD KEY idx_factura_cuenta_fecha (id_cuenta,fecha_emision),
  ADD CONSTRAINT fk_factura_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE config_boleta ADD COLUMN id_cuenta INT NULL AFTER id_config;
UPDATE config_boleta x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE config_boleta MODIFY id_cuenta INT NOT NULL,
  ADD UNIQUE KEY uq_config_boleta_cuenta (id_cuenta),
  ADD CONSTRAINT fk_config_boleta_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE core_auditoria ADD COLUMN id_cuenta INT NULL AFTER id_auditoria;
UPDATE core_auditoria x JOIN usuario u ON u.id_user=x.id_user SET x.id_cuenta=u.id_cuenta;
ALTER TABLE core_auditoria
  ADD KEY idx_core_auditoria_cuenta_fecha (id_cuenta,created_at),
  ADD CONSTRAINT fk_core_auditoria_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE numeracion ADD COLUMN id_cuenta INT NULL AFTER id_numeracion,
  ADD KEY idx_numeracion_cuenta (id_cuenta),
  ADD CONSTRAINT fk_numeracion_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE parametro ADD COLUMN id_cuenta INT NULL AFTER id_parametro,
  ADD KEY idx_parametro_cuenta (id_cuenta),
  ADD CONSTRAINT fk_parametro_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE CASCADE ON UPDATE CASCADE;

COMMIT;
