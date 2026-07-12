-- Migración para soporte de productos por peso/volumen
-- Ejecutar en orden

-- 1. Cambiar campos de INT a DECIMAL para permitir decimales
ALTER TABLE stock 
  MODIFY COLUMN disponible DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN reservado DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN comprometido DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN en_transito DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN danado DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN bloqueado DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN devuelto DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN produccion DECIMAL(10,3) DEFAULT 0;

ALTER TABLE detalle_pedido
  MODIFY COLUMN cantidad_pedida DECIMAL(10,3) NOT NULL;

ALTER TABLE kardex
  MODIFY COLUMN entrada DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN salida DECIMAL(10,3) DEFAULT 0,
  MODIFY COLUMN saldo DECIMAL(10,3) DEFAULT 0;

-- 2. Agregar campo de unidad de medida a producto
ALTER TABLE producto 
  ADD COLUMN IF NOT EXISTS id_unidad INT DEFAULT NULL AFTER id_proveedor,
  ADD COLUMN IF NOT EXISTS tipo_venta ENUM('UNIDAD','PESO','VOLUMEN') DEFAULT 'UNIDAD' AFTER id_unidad,
  ADD COLUMN IF NOT EXISTS precio_por_unidad VARCHAR(20) DEFAULT 'UNIDAD' AFTER tipo_venta;

-- 3. Agregar foreign key si no existe
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
  WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'producto' 
  AND CONSTRAINT_NAME = 'fk_producto_unidad');

SET @sql = IF(@fk_exists = 0, 
  'ALTER TABLE producto ADD CONSTRAINT fk_producto_unidad FOREIGN KEY (id_unidad) REFERENCES unidad_medida(id_unidad)', 
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Insertar unidades de medida comunes si no existen
INSERT IGNORE INTO unidad_medida (nombre, abreviatura, tipo) VALUES
('Kilogramo', 'kg', 'PESO'),
('Gramo', 'g', 'PESO'),
('Libra', 'lb', 'PESO'),
('Onza', 'oz', 'PESO'),
('Litro', 'L', 'VOLUMEN'),
('Mililitro', 'mL', 'VOLUMEN'),
('Unidad', 'u', 'UNIDAD'),
('Metro', 'm', 'LONGITUD'),
('Centímetro', 'cm', 'LONGITUD');

-- 5. Actualizar productos existentes (por defecto son unidades)
UPDATE producto SET tipo_venta = 'UNIDAD' WHERE tipo_venta IS NULL;

-- 6. Crear vista para ver productos con sus unidades
CREATE OR REPLACE VIEW v_productos_con_unidad AS
SELECT 
  p.*,
  COALESCE(u.nombre, 'Unidad') as unidad_nombre,
  COALESCE(u.abreviatura, 'u') as unidad_abrev,
  COALESCE(p.tipo_venta, 'UNIDAD') as tipo_venta,
  CASE 
    WHEN p.tipo_venta = 'PESO' THEN CONCAT('$', p.precio_venta, '/', u.abreviatura)
    WHEN p.tipo_venta = 'VOLUMEN' THEN CONCAT('$', p.precio_venta, '/', u.abreviatura)
    ELSE CONCAT('$', p.precio_venta, '/u')
  END as precio_formateado
FROM producto p
LEFT JOIN unidad_medida u ON p.id_unidad = u.id_unidad;
