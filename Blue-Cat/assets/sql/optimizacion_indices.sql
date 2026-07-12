-- ============================================================
-- Migración: Optimización de Índices — Blue-Cat ERP
-- Generado automáticamente vía análisis de esquemas y código
-- ============================================================
-- TOTAL: ~83 índices
--   ALTA: 59 (Foreign Keys sin índice y columnas de JOIN)
--   MEDIA: 18 (Columnas en WHERE de listados frecuentes)
--   BAJA: 6 (Columnas en ORDER BY/GROUP BY y FULLTEXT)
-- ============================================================

DELIMITER $$

-- Procedimiento helper para crear índices idempotentemente
DROP PROCEDURE IF EXISTS crear_indice_si_no_existe $$
CREATE PROCEDURE crear_indice_si_no_existe(IN p_tabla VARCHAR(64), IN p_indice VARCHAR(64), IN p_columnas VARCHAR(255))
BEGIN
    SET @db = DATABASE();
    SET @idx_exists = 0;
    SET @sql_exists = CONCAT(
        'SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS ',
        'WHERE TABLE_SCHEMA = @db AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    PREPARE stmt_exists FROM @sql_exists;
    EXECUTE stmt_exists USING p_tabla, p_indice;
    DEALLOCATE PREPARE stmt_exists;

    SET @ddl = IF(@idx_exists = 0,
        CONCAT('CREATE INDEX ', p_indice, ' ON ', p_tabla, '(', p_columnas, ')'),
        'SELECT 1 AS ya_existe'
    );
    PREPARE stmt FROM @ddl;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END $$

DELIMITER ;

-- ============================================================
-- PRIORIDAD ALTA: Foreign Keys sin índice
-- ============================================================

-- CORE
CALL crear_indice_si_no_existe('sucursal', 'idx_sucursal_id_empresa', 'id_empresa');

-- INVENTARIO
CALL crear_indice_si_no_existe('categoria', 'idx_categoria_id_user', 'id_user');
CALL crear_indice_si_no_existe('subcategoria', 'idx_subcategoria_id_categoria', 'id_categoria');
CALL crear_indice_si_no_existe('marca', 'idx_marca_id_user', 'id_user');
CALL crear_indice_si_no_existe('bodega', 'idx_bodega_id_user', 'id_user');
CALL crear_indice_si_no_existe('ubicacion', 'idx_ubicacion_id_bodega', 'id_bodega');
CALL crear_indice_si_no_existe('movimiento_inventario', 'idx_movto_inv_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('movimiento_inventario', 'idx_movto_inv_bodega_origen', 'id_bodega_origen');
CALL crear_indice_si_no_existe('movimiento_inventario', 'idx_movto_inv_bodega_destino', 'id_bodega_destino');
CALL crear_indice_si_no_existe('movimiento_inventario', 'idx_movto_inv_id_user', 'id_user');
CALL crear_indice_si_no_existe('transferencia', 'idx_transferencia_bodega_origen', 'id_bodega_origen');
CALL crear_indice_si_no_existe('transferencia', 'idx_transferencia_bodega_destino', 'id_bodega_destino');
CALL crear_indice_si_no_existe('transferencia', 'idx_transferencia_id_user', 'id_user');
CALL crear_indice_si_no_existe('transferencia', 'idx_transferencia_id_user_recibe', 'id_user_recibe');
CALL crear_indice_si_no_existe('transferencia_detalle', 'idx_trf_detalle_id_transferencia', 'id_transferencia');
CALL crear_indice_si_no_existe('transferencia_detalle', 'idx_trf_detalle_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('ajuste_inventario', 'idx_ajuste_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('ajuste_inventario', 'idx_ajuste_id_bodega', 'id_bodega');
CALL crear_indice_si_no_existe('ajuste_inventario', 'idx_ajuste_id_user', 'id_user');
CALL crear_indice_si_no_existe('inventario_fisico', 'idx_inv_fisico_id_bodega', 'id_bodega');
CALL crear_indice_si_no_existe('inventario_fisico', 'idx_inv_fisico_id_user', 'id_user');
CALL crear_indice_si_no_existe('conteo_inventario', 'idx_conteo_id_inventario', 'id_inventario');
CALL crear_indice_si_no_existe('conteo_inventario', 'idx_conteo_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('kardex', 'idx_kardex_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('kardex', 'idx_kardex_id_bodega', 'id_bodega');
CALL crear_indice_si_no_existe('lote', 'idx_lote_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('lote', 'idx_lote_id_proveedor', 'id_proveedor');
CALL crear_indice_si_no_existe('serie', 'idx_serie_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('serie', 'idx_serie_id_lote', 'id_lote');
CALL crear_indice_si_no_existe('serie', 'idx_serie_id_cliente', 'id_cliente');
CALL crear_indice_si_no_existe('costo_producto', 'idx_costo_prod_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('costo_producto', 'idx_costo_prod_id_user', 'id_user');
CALL crear_indice_si_no_existe('valorizacion_inventario', 'idx_valorizacion_id_user', 'id_user');
CALL crear_indice_si_no_existe('alerta_stock', 'idx_alerta_stock_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('inventario_auditoria', 'idx_inv_auditoria_id_user', 'id_user');

-- POS
CALL crear_indice_si_no_existe('pos_caja', 'idx_pos_caja_id_user', 'id_user');
CALL crear_indice_si_no_existe('pos_caja', 'idx_pos_caja_id_sesion', 'id_sesion');
CALL crear_indice_si_no_existe('pos_movimiento_caja', 'idx_pos_mov_caja_id_caja', 'id_caja');
CALL crear_indice_si_no_existe('pos_movimiento_caja', 'idx_pos_mov_caja_id_user', 'id_user');
CALL crear_indice_si_no_existe('pos_promocion', 'idx_pos_promo_id_user', 'id_user');
CALL crear_indice_si_no_existe('pos_promocion_producto', 'idx_pos_promo_prod_id_promo', 'id_promocion');
CALL crear_indice_si_no_existe('pos_promocion_producto', 'idx_pos_promo_prod_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('pos_descuento', 'idx_pos_desc_id_pedido', 'id_pedido');
CALL crear_indice_si_no_existe('pos_descuento', 'idx_pos_desc_id_promocion', 'id_promocion');
CALL crear_indice_si_no_existe('pos_devolucion', 'idx_pos_dev_id_user', 'id_user');
CALL crear_indice_si_no_existe('pos_devolucion', 'idx_pos_dev_id_pedido', 'id_pedido');
CALL crear_indice_si_no_existe('pos_devolucion_detalle', 'idx_pos_dev_det_id_devolucion', 'id_devolucion');
CALL crear_indice_si_no_existe('pos_devolucion_detalle', 'idx_pos_dev_det_id_producto', 'id_producto');
CALL crear_indice_si_no_existe('pos_cambio', 'idx_pos_cambio_id_devolucion', 'id_devolucion');
CALL crear_indice_si_no_existe('pos_cambio', 'idx_pos_cambio_id_prod_nuevo', 'id_producto_nuevo');
CALL crear_indice_si_no_existe('pos_cambio', 'idx_pos_cambio_id_prod_viejo', 'id_producto_viejo');
CALL crear_indice_si_no_existe('pos_cotizacion', 'idx_pos_cot_id_user', 'id_user');
CALL crear_indice_si_no_existe('pos_cotizacion', 'idx_pos_cot_id_cliente', 'id_cliente');
CALL crear_indice_si_no_existe('pos_cotizacion_detalle', 'idx_pos_cot_det_id_cotizacion', 'id_cotizacion');
CALL crear_indice_si_no_existe('pos_reserva', 'idx_pos_reserva_id_user', 'id_user');
CALL crear_indice_si_no_existe('pos_reserva', 'idx_pos_reserva_id_cliente', 'id_cliente');

-- EMPLEADOS
CALL crear_indice_si_no_existe('empleado', 'idx_empleado_id_user', 'id_user');
CALL crear_indice_si_no_existe('empleado_contrato', 'idx_emp_contrato_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_documento', 'idx_emp_doc_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_turno', 'idx_emp_turno_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_vacacion', 'idx_emp_vacacion_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_permiso', 'idx_emp_permiso_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_licencia', 'idx_emp_licencia_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_hora_extra', 'idx_emp_hora_extra_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_remuneracion', 'idx_emp_remu_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_beneficio', 'idx_emp_beneficio_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_capacitacion', 'idx_emp_capacitacion_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_evaluacion', 'idx_emp_eval_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_activo', 'idx_emp_activo_id_empleado', 'id_empleado');
CALL crear_indice_si_no_existe('empleado_historial', 'idx_emp_historial_id_empleado', 'id_empleado');

-- ============================================================
-- PRIORIDAD MEDIA: Columnas en WHERE / JOIN / LIKE
-- ============================================================

CALL crear_indice_si_no_existe('producto', 'idx_producto_activo', 'activo');
CALL crear_indice_si_no_existe('producto', 'idx_producto_id_categoria', 'id_categoria');
CALL crear_indice_si_no_existe('producto', 'idx_producto_id_marca', 'id_marca');
CALL crear_indice_si_no_existe('producto', 'idx_producto_sku', 'sku');
CALL crear_indice_si_no_existe('producto', 'idx_producto_codigo_barras', 'codigo_de_barras');
CALL crear_indice_si_no_existe('stock', 'idx_stock_id_bodega', 'id_bodega');
CALL crear_indice_si_no_existe('kardex', 'idx_kardex_fecha', 'fecha');
CALL crear_indice_si_no_existe('kardex', 'idx_kardex_tipo_movimiento', 'tipo_movimiento(30)');
CALL crear_indice_si_no_existe('movimiento_inventario', 'idx_movto_inv_tipo', 'tipo(20)');
CALL crear_indice_si_no_existe('movimiento_inventario', 'idx_movto_inv_created', 'created_at');
CALL crear_indice_si_no_existe('transferencia', 'idx_transferencia_estado', 'estado(15)');
CALL crear_indice_si_no_existe('lote', 'idx_lote_fecha_vencimiento', 'fecha_vencimiento');
CALL crear_indice_si_no_existe('lote', 'idx_lote_estado', 'estado(15)');
CALL crear_indice_si_no_existe('lote', 'idx_lote_numero_lote', 'numero_lote(30)');
CALL crear_indice_si_no_existe('serie', 'idx_serie_numero_serie', 'numero_serie(50)');
CALL crear_indice_si_no_existe('serie', 'idx_serie_estado', 'estado(15)');
CALL crear_indice_si_no_existe('pedido', 'idx_pedido_fecha', 'fecha');
CALL crear_indice_si_no_existe('pedido', 'idx_pedido_anulado', 'anulado');
CALL crear_indice_si_no_existe('pedido', 'idx_pedido_id_cliente', 'id_cliente');
CALL crear_indice_si_no_existe('pedido', 'idx_pedido_id_bodega', 'id_bodega');
CALL crear_indice_si_no_existe('pedido', 'idx_pedido_id_caja', 'id_caja');
CALL crear_indice_si_no_existe('sesion', 'idx_sesion_id_user', 'id_user');
CALL crear_indice_si_no_existe('sesion', 'idx_sesion_fecha_cierre', 'fecha_cierre');
CALL crear_indice_si_no_existe('empleado', 'idx_empleado_estado', 'estado(15)');
CALL crear_indice_si_no_existe('empleado', 'idx_empleado_cargo', 'cargo(30)');
CALL crear_indice_si_no_existe('empleado', 'idx_empleado_departamento', 'departamento(30)');
CALL crear_indice_si_no_existe('empleado', 'idx_empleado_codigo', 'codigo');
CALL crear_indice_si_no_existe('factura', 'idx_factura_fecha_emision', 'fecha_emision');
CALL crear_indice_si_no_existe('factura', 'idx_factura_numero', 'numero(30)');
CALL crear_indice_si_no_existe('core_auditoria', 'idx_core_audit_nivel', 'nivel(10)');
CALL crear_indice_si_no_existe('pos_auditoria', 'idx_pos_aud_accion', 'accion(20)');
CALL crear_indice_si_no_existe('pos_auditoria', 'idx_pos_aud_id_referencia', 'id_referencia');
CALL crear_indice_si_no_existe('pos_promocion', 'idx_pos_promo_activo', 'activo');
CALL crear_indice_si_no_existe('pos_promocion', 'idx_pos_promo_codigo', 'codigo');
CALL crear_indice_si_no_existe('pos_cotizacion', 'idx_pos_cot_estado', 'estado(15)');
CALL crear_indice_si_no_existe('pos_reserva', 'idx_pos_reserva_estado', 'estado(15)');
CALL crear_indice_si_no_existe('pos_reserva', 'idx_pos_reserva_fecha', 'fecha_reserva');
CALL crear_indice_si_no_existe('proveedor', 'idx_proveedor_codigo', 'codigo');
CALL crear_indice_si_no_existe('proveedor', 'idx_proveedor_razon_social', 'razon_social(100)');
CALL crear_indice_si_no_existe('factura_pago', 'idx_fact_pago_metodo', 'metodo(20)');
CALL crear_indice_si_no_existe('factura_historial', 'idx_fact_hist_accion', 'accion(30)');

-- ============================================================
-- PRIORIDAD BAJA: ORDER BY / GROUP BY / filtros secundarios
-- ============================================================

CALL crear_indice_si_no_existe('alerta_stock', 'idx_alerta_leido', 'leido');
CALL crear_indice_si_no_existe('alerta_stock', 'idx_alerta_resuelto', 'resuelto');
CALL crear_indice_si_no_existe('empleado_asistencia', 'idx_asistencia_fecha', 'fecha');
CALL crear_indice_si_no_existe('empleado_vacacion', 'idx_emp_vacacion_estado', 'estado(15)');
CALL crear_indice_si_no_existe('empleado_permiso', 'idx_emp_permiso_estado', 'estado(15)');
CALL crear_indice_si_no_existe('empleado_permiso', 'idx_emp_permiso_tipo', 'tipo(30)');
CALL crear_indice_si_no_existe('empleado_remuneracion', 'idx_emp_remu_periodo', 'periodo');
CALL crear_indice_si_no_existe('inventario_fisico', 'idx_inv_fisico_estado', 'estado(15)');

-- ============================================================
-- FULLTEXT INDEXES: Búsquedas LIKE en texto
-- ============================================================

SET @ft_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producto' AND INDEX_NAME = 'idx_producto_fulltext' AND INDEX_TYPE = 'FULLTEXT');
SET @ft_ddl = IF(@ft_exists = 0, 
    'ALTER TABLE producto ADD FULLTEXT INDEX idx_producto_fulltext (nombre_producto, descripcion, sku, codigo_de_barras)',
    'SELECT 1');
PREPARE ft_stmt FROM @ft_ddl;
EXECUTE ft_stmt;
DEALLOCATE PREPARE ft_stmt;

SET @ft_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente' AND INDEX_NAME = 'idx_cliente_fulltext' AND INDEX_TYPE = 'FULLTEXT');
SET @ft_ddl = IF(@ft_exists = 0, 
    'ALTER TABLE cliente ADD FULLTEXT INDEX idx_cliente_fulltext (razon_social, nombre, correo)',
    'SELECT 1');
PREPARE ft_stmt FROM @ft_ddl;
EXECUTE ft_stmt;
DEALLOCATE PREPARE ft_stmt;

-- ============================================================
-- Limpieza
-- ============================================================
DROP PROCEDURE IF EXISTS crear_indice_si_no_existe;
