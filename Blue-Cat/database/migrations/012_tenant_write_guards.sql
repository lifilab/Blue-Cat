-- Defensa en profundidad para endpoints legacy: la cuenta siempre deriva de su usuario.
DROP TRIGGER IF EXISTS bi_empleado_tenant;
CREATE TRIGGER bi_empleado_tenant BEFORE INSERT ON empleado FOR EACH ROW
SET NEW.id_cuenta=IF(NEW.id_user IS NULL,NEW.id_cuenta,(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user));

DROP TRIGGER IF EXISTS bu_empleado_tenant;
CREATE TRIGGER bu_empleado_tenant BEFORE UPDATE ON empleado FOR EACH ROW
SET NEW.id_cuenta=IF(NEW.id_user IS NULL,OLD.id_cuenta,(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user));

DROP TRIGGER IF EXISTS bi_categoria_tenant;
CREATE TRIGGER bi_categoria_tenant BEFORE INSERT ON categoria FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_categoria_tenant;
CREATE TRIGGER bu_categoria_tenant BEFORE UPDATE ON categoria FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_marca_tenant;
CREATE TRIGGER bi_marca_tenant BEFORE INSERT ON marca FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_marca_tenant;
CREATE TRIGGER bu_marca_tenant BEFORE UPDATE ON marca FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_producto_tenant;
CREATE TRIGGER bi_producto_tenant BEFORE INSERT ON producto FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_producto_tenant;
CREATE TRIGGER bu_producto_tenant BEFORE UPDATE ON producto FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_bodega_tenant;
CREATE TRIGGER bi_bodega_tenant BEFORE INSERT ON bodega FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_bodega_tenant;
CREATE TRIGGER bu_bodega_tenant BEFORE UPDATE ON bodega FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_cliente_tenant;
CREATE TRIGGER bi_cliente_tenant BEFORE INSERT ON cliente FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_cliente_tenant;
CREATE TRIGGER bu_cliente_tenant BEFORE UPDATE ON cliente FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_proveedor_tenant;
CREATE TRIGGER bi_proveedor_tenant BEFORE INSERT ON proveedor FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_proveedor_tenant;
CREATE TRIGGER bu_proveedor_tenant BEFORE UPDATE ON proveedor FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_sesion_tenant;
CREATE TRIGGER bi_sesion_tenant BEFORE INSERT ON sesion FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_sesion_tenant;
CREATE TRIGGER bu_sesion_tenant BEFORE UPDATE ON sesion FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_pedido_tenant;
CREATE TRIGGER bi_pedido_tenant BEFORE INSERT ON pedido FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM sesion WHERE id_sesion=NEW.id_sesion);
DROP TRIGGER IF EXISTS bu_pedido_tenant;
CREATE TRIGGER bu_pedido_tenant BEFORE UPDATE ON pedido FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM sesion WHERE id_sesion=NEW.id_sesion);

DROP TRIGGER IF EXISTS bi_pos_caja_tenant;
CREATE TRIGGER bi_pos_caja_tenant BEFORE INSERT ON pos_caja FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_pos_caja_tenant;
CREATE TRIGGER bu_pos_caja_tenant BEFORE UPDATE ON pos_caja FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_pos_promocion_tenant;
CREATE TRIGGER bi_pos_promocion_tenant BEFORE INSERT ON pos_promocion FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_pos_promocion_tenant;
CREATE TRIGGER bu_pos_promocion_tenant BEFORE UPDATE ON pos_promocion FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_pos_cotizacion_tenant;
CREATE TRIGGER bi_pos_cotizacion_tenant BEFORE INSERT ON pos_cotizacion FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_pos_cotizacion_tenant;
CREATE TRIGGER bu_pos_cotizacion_tenant BEFORE UPDATE ON pos_cotizacion FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_pos_reserva_tenant;
CREATE TRIGGER bi_pos_reserva_tenant BEFORE INSERT ON pos_reserva FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_pos_reserva_tenant;
CREATE TRIGGER bu_pos_reserva_tenant BEFORE UPDATE ON pos_reserva FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_factura_tenant;
CREATE TRIGGER bi_factura_tenant BEFORE INSERT ON factura FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_factura_tenant;
CREATE TRIGGER bu_factura_tenant BEFORE UPDATE ON factura FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);

DROP TRIGGER IF EXISTS bi_config_boleta_tenant;
CREATE TRIGGER bi_config_boleta_tenant BEFORE INSERT ON config_boleta FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
DROP TRIGGER IF EXISTS bu_config_boleta_tenant;
CREATE TRIGGER bu_config_boleta_tenant BEFORE UPDATE ON config_boleta FOR EACH ROW SET NEW.id_cuenta=(SELECT id_cuenta FROM usuario WHERE id_user=NEW.id_user);
