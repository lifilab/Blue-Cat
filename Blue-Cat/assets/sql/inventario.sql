-- ============================================================
-- Módulo Inventario - Blue-Cat ERP
-- Tablas para gestión integral de inventario y bodegas
-- ============================================================

-- 1. CATEGORÍAS
CREATE TABLE IF NOT EXISTS categoria (
  id_categoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. SUBCATEGORÍAS
CREATE TABLE IF NOT EXISTS subcategoria (
  id_subcategoria INT AUTO_INCREMENT PRIMARY KEY,
  id_categoria INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_categoria) REFERENCES categoria(id_categoria) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. MARCAS
CREATE TABLE IF NOT EXISTS marca (
  id_marca INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. UNIDADES DE MEDIDA
CREATE TABLE IF NOT EXISTS unidad_medida (
  id_unidad INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  abreviatura VARCHAR(10) NOT NULL,
  tipo ENUM('UNIDAD','PESO','VOLUMEN','LONGITUD','TIEMPO','LOTE') DEFAULT 'UNIDAD',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. EXTENDER TABLA producto (usamos ALTER para agregar columnas si no existen)
-- Las columnas nuevas se agregan vía PHP en la API por seguridad

-- 6. BODEGAS
CREATE TABLE IF NOT EXISTS bodega (
  id_bodega INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  responsable VARCHAR(100),
  direccion TEXT,
  telefono VARCHAR(20),
  estado ENUM('ACTIVA','INACTIVA','MANTENCION') DEFAULT 'ACTIVA',
  capacidad INT DEFAULT 0,
  observaciones TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. UBICACIONES
CREATE TABLE IF NOT EXISTS ubicacion (
  id_ubicacion INT AUTO_INCREMENT PRIMARY KEY,
  id_bodega INT NOT NULL,
  codigo VARCHAR(30),
  pasillo VARCHAR(20),
  rack VARCHAR(20),
  nivel VARCHAR(10),
  columna_ VARCHAR(10),
  posicion VARCHAR(10),
  zona VARCHAR(30),
  sector VARCHAR(30),
  codigo_qr VARCHAR(100),
  codigo_barras VARCHAR(50),
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. STOCK (por producto + bodega + ubicación)
CREATE TABLE IF NOT EXISTS stock (
  id_stock INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  id_bodega INT NOT NULL,
  id_ubicacion INT,
  disponible INT DEFAULT 0,
  reservado INT DEFAULT 0,
  comprometido INT DEFAULT 0,
  en_transito INT DEFAULT 0,
  danado INT DEFAULT 0,
  bloqueado INT DEFAULT 0,
  devuelto INT DEFAULT 0,
  produccion INT DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto) ON DELETE CASCADE,
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega) ON DELETE CASCADE,
  FOREIGN KEY (id_ubicacion) REFERENCES ubicacion(id_ubicacion) ON DELETE SET NULL,
  UNIQUE KEY uq_stock (id_producto, id_bodega, id_ubicacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. MOVIMIENTOS DE INVENTARIO
CREATE TABLE IF NOT EXISTS movimiento_inventario (
  id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) NOT NULL UNIQUE,
  tipo ENUM('INGRESO','SALIDA','TRANSFERENCIA','AJUSTE','PRODUCCION','VENTA','COMPRA','DEVOLUCION','CONSUMO','MERMA','PERDIDA','REGULARIZACION') NOT NULL,
  id_documento_origen INT,
  tipo_documento_origen VARCHAR(30),
  id_producto INT NOT NULL,
  id_bodega_origen INT,
  id_bodega_destino INT,
  id_ubicacion INT,
  cantidad INT NOT NULL,
  costo INT DEFAULT 0,
  id_user INT NOT NULL,
  observaciones TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto),
  FOREIGN KEY (id_bodega_origen) REFERENCES bodega(id_bodega) ON DELETE SET NULL,
  FOREIGN KEY (id_bodega_destino) REFERENCES bodega(id_bodega) ON DELETE SET NULL,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. TRANSFERENCIAS
CREATE TABLE IF NOT EXISTS transferencia (
  id_transferencia INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) NOT NULL UNIQUE,
  id_bodega_origen INT NOT NULL,
  id_bodega_destino INT NOT NULL,
  estado ENUM('PENDIENTE','EN_TRANSITO','RECIBIDA','CANCELADA') DEFAULT 'PENDIENTE',
  id_user INT NOT NULL,
  id_user_recibe INT,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  fecha_recepcion DATETIME,
  observaciones TEXT,
  FOREIGN KEY (id_bodega_origen) REFERENCES bodega(id_bodega),
  FOREIGN KEY (id_bodega_destino) REFERENCES bodega(id_bodega),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_user_recibe) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. DETALLE TRANSFERENCIA
CREATE TABLE IF NOT EXISTS transferencia_detalle (
  id_detalle INT AUTO_INCREMENT PRIMARY KEY,
  id_transferencia INT NOT NULL,
  id_producto INT NOT NULL,
  cantidad INT NOT NULL,
  costo INT DEFAULT 0,
  FOREIGN KEY (id_transferencia) REFERENCES transferencia(id_transferencia) ON DELETE CASCADE,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. AJUSTES DE INVENTARIO
CREATE TABLE IF NOT EXISTS ajuste_inventario (
  id_ajuste INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) NOT NULL UNIQUE,
  tipo ENUM('AUMENTO','DISMINUCION','REGULARIZACION','CORRECCION','FISICO','ERROR') NOT NULL,
  id_producto INT NOT NULL,
  id_bodega INT NOT NULL,
  cantidad_anterior INT NOT NULL,
  cantidad_nueva INT NOT NULL,
  diferencia INT NOT NULL,
  motivo TEXT NOT NULL,
  id_user INT NOT NULL,
  autorizado_por VARCHAR(100),
  documento_respaldo VARCHAR(100),
  observaciones TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto),
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. INVENTARIOS FÍSICOS
CREATE TABLE IF NOT EXISTS inventario_fisico (
  id_inventario INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(30) NOT NULL UNIQUE,
  tipo ENUM('PROGRAMADO','CICLICO','GENERAL','CATEGORIA','BODEGA','UBICACION') DEFAULT 'GENERAL',
  id_bodega INT,
  id_categoria INT,
  fecha_inicio DATETIME,
  fecha_fin DATETIME,
  estado ENUM('PENDIENTE','EN_PROGRESO','CONCILIADO','CERRADO') DEFAULT 'PENDIENTE',
  id_user INT NOT NULL,
  observaciones TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega) ON DELETE SET NULL,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. CONTEOS DE INVENTARIO FÍSICO
CREATE TABLE IF NOT EXISTS conteo_inventario (
  id_conteo INT AUTO_INCREMENT PRIMARY KEY,
  id_inventario INT NOT NULL,
  id_producto INT NOT NULL,
  id_ubicacion INT,
  conteo1 INT,
  conteo2 INT,
  conteo3 INT,
  diferencia INT,
  conciliado TINYINT(1) DEFAULT 0,
  FOREIGN KEY (id_inventario) REFERENCES inventario_fisico(id_inventario) ON DELETE CASCADE,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto),
  FOREIGN KEY (id_ubicacion) REFERENCES ubicacion(id_ubicacion) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. KARDEX
CREATE TABLE IF NOT EXISTS kardex (
  id_kardex INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  id_bodega INT,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  tipo_movimiento VARCHAR(30) NOT NULL,
  id_documento INT,
  documento_tipo VARCHAR(30),
  entrada INT DEFAULT 0,
  salida INT DEFAULT 0,
  saldo INT NOT NULL,
  costo_unitario INT DEFAULT 0,
  costo_total INT DEFAULT 0,
  id_user INT,
  observaciones TEXT,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto),
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. LOTES
CREATE TABLE IF NOT EXISTS lote (
  id_lote INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  numero_lote VARCHAR(50) NOT NULL,
  id_proveedor INT,
  fecha_fabricacion DATE,
  fecha_ingreso DATE,
  fecha_vencimiento DATE,
  cantidad INT DEFAULT 0,
  cantidad_original INT DEFAULT 0,
  estado ENUM('DISPONIBLE','BLOQUEADO','VENCIDO','AGOTADO') DEFAULT 'DISPONIBLE',
  id_ubicacion INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto),
  FOREIGN KEY (id_proveedor) REFERENCES proveedor(id_proveedor) ON DELETE SET NULL,
  FOREIGN KEY (id_ubicacion) REFERENCES ubicacion(id_ubicacion) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. SERIES
CREATE TABLE IF NOT EXISTS serie (
  id_serie INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  numero_serie VARCHAR(100) NOT NULL,
  id_lote INT,
  estado ENUM('DISPONIBLE','VENDIDO','GARANTIA','BLOQUEADO','DEVUELTO') DEFAULT 'DISPONIBLE',
  id_ubicacion INT,
  id_cliente INT,
  fecha_venta DATE,
  garantia_dias INT DEFAULT 0,
  historial TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto),
  FOREIGN KEY (id_lote) REFERENCES lote(id_lote) ON DELETE SET NULL,
  FOREIGN KEY (id_ubicacion) REFERENCES ubicacion(id_ubicacion) ON DELETE SET NULL,
  FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 18. COSTOS DE PRODUCTO
CREATE TABLE IF NOT EXISTS costo_producto (
  id_costo INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  metodo ENUM('PROMEDIO','FIFO','ESTANDAR','ESPECIFICO') DEFAULT 'PROMEDIO',
  costo INT NOT NULL,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  id_user INT,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto) ON DELETE CASCADE,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 19. VALORIZACIÓN DE INVENTARIO
CREATE TABLE IF NOT EXISTS valorizacion_inventario (
  id_valorizacion INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  metodo ENUM('PROMEDIO','FIFO','HISTORICO','REPOSICION') DEFAULT 'PROMEDIO',
  costo_total BIGINT DEFAULT 0,
  cantidad_total INT DEFAULT 0,
  id_user INT,
  detalle JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 20. ALERTAS DE STOCK
CREATE TABLE IF NOT EXISTS alerta_stock (
  id_alerta INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  tipo ENUM('STOCK_MINIMO','STOCK_NEGATIVO','VENCIMIENTO','INMOVILIZADO','SIN_ROTACION','DIFERENCIA') NOT NULL,
  mensaje TEXT NOT NULL,
  leido TINYINT(1) DEFAULT 0,
  resuelto TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 21. AUDITORÍA DE INVENTARIO
CREATE TABLE IF NOT EXISTS inventario_auditoria (
  id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT,
  accion VARCHAR(50) NOT NULL,
  entidad VARCHAR(50) NOT NULL,
  id_entidad INT,
  detalle JSON,
  ip VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ALTER TABLE producto (extensión)
-- ============================================================
INSERT IGNORE INTO unidad_medida (nombre, abreviatura, tipo) VALUES
('Unidad','UND','UNIDAD'),
('Kilogramo','KG','PESO'),
('Gramo','GR','PESO'),
('Litro','LT','VOLUMEN'),
('Mililitro','ML','VOLUMEN'),
('Metro','M','LONGITUD'),
('Centímetro','CM','LONGITUD'),
('Caja','CAJ','UNIDAD'),
('Paquete','PAQ','UNIDAD'),
('Docena','DOC','UNIDAD');

-- Crear bodega por defecto
INSERT IGNORE INTO bodega (id_user, codigo, nombre, estado) 
SELECT id_user, 'BOD-001', 'Bodega Principal', 'ACTIVA'
FROM usuario LIMIT 1
WHERE NOT EXISTS (SELECT 1 FROM bodega WHERE codigo='BOD-001');
