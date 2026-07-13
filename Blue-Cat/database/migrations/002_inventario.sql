-- ============================================================
-- Blue-Cat ERP v1.0 — Módulo Inventario
-- ============================================================
SET NAMES utf8mb4;
START TRANSACTION;

-- ─── CATEGORÍA ───
CREATE TABLE IF NOT EXISTS categoria (
  id_categoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── MARCA ───
CREATE TABLE IF NOT EXISTS marca (
  id_marca INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── UNIDAD DE MEDIDA ───
CREATE TABLE IF NOT EXISTS unidad_medida (
  id_unidad INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  abreviatura VARCHAR(10) NOT NULL,
  tipo ENUM('UNIDAD','PESO','VOLUMEN','LONGITUD','TIEMPO','LOTE') DEFAULT 'UNIDAD',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── PRODUCTO ───
CREATE TABLE IF NOT EXISTS producto (
  id_producto INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  nombre_producto VARCHAR(100) NOT NULL,
  precio_venta DECIMAL(10,2) DEFAULT NULL,
  codigo_de_barras VARCHAR(30) DEFAULT NULL,
  cantidad DECIMAL(10,3) DEFAULT NULL,
  categoria VARCHAR(100) DEFAULT NULL,
  descripcion TEXT DEFAULT NULL,
  sku VARCHAR(50) DEFAULT NULL,
  id_categoria INT DEFAULT NULL,
  id_marca INT DEFAULT NULL,
  id_proveedor INT DEFAULT NULL,
  id_unidad INT DEFAULT NULL,
  tipo ENUM('PRODUCTO','SERVICIO','MATERIA_PRIMA','TERMINADO','CONSUMIBLE','KIT','COMBO') DEFAULT 'PRODUCTO',
  tipo_venta ENUM('UNIDAD','PESO','VOLUMEN') DEFAULT 'UNIDAD',
  precio_por_unidad VARCHAR(20) DEFAULT 'UNIDAD',
  precio_costo DECIMAL(10,2) DEFAULT 0,
  costo_promedio DECIMAL(10,2) DEFAULT 0,
  ultimo_costo DECIMAL(10,2) DEFAULT 0,
  imagen VARCHAR(255) DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  stock_minimo DECIMAL(10,3) DEFAULT 0,
  stock_maximo DECIMAL(10,3) DEFAULT 0,
  punto_reposicion DECIMAL(10,3) DEFAULT 0,
  stock_seguridad DECIMAL(10,3) DEFAULT 0,
  control_lote TINYINT(1) DEFAULT 0,
  control_serie TINYINT(1) DEFAULT 0,
  peso DECIMAL(10,2) DEFAULT 0,
  volumen DECIMAL(10,2) DEFAULT 0,
  lead_time INT DEFAULT 0,
  INDEX idx_producto_user (id_user),
  INDEX idx_producto_barcode (codigo_de_barras),
  INDEX idx_producto_categoria (id_categoria),
  INDEX idx_producto_marca (id_marca),
  INDEX idx_producto_activo (activo),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_categoria) REFERENCES categoria(id_categoria) ON DELETE SET NULL,
  FOREIGN KEY (id_marca) REFERENCES marca(id_marca) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── BODEGA ───
CREATE TABLE IF NOT EXISTS bodega (
  id_bodega INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  responsable VARCHAR(100) DEFAULT NULL,
  direccion TEXT DEFAULT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  estado ENUM('ACTIVA','INACTIVA','MANTENCION') DEFAULT 'ACTIVA',
  capacidad INT DEFAULT 0,
  observaciones TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── UBICACIÓN ───
CREATE TABLE IF NOT EXISTS ubicacion (
  id_ubicacion INT AUTO_INCREMENT PRIMARY KEY,
  id_bodega INT NOT NULL,
  codigo VARCHAR(30) DEFAULT NULL,
  pasillo VARCHAR(20) DEFAULT NULL,
  rack VARCHAR(20) DEFAULT NULL,
  nivel VARCHAR(10) DEFAULT NULL,
  columna_ VARCHAR(10) DEFAULT NULL,
  posicion VARCHAR(10) DEFAULT NULL,
  zona VARCHAR(30) DEFAULT NULL,
  sector VARCHAR(30) DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── STOCK (fuente única de verdad) ───
CREATE TABLE IF NOT EXISTS stock (
  id_stock INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  id_bodega INT NOT NULL,
  id_ubicacion INT DEFAULT NULL,
  disponible DECIMAL(10,3) DEFAULT 0,
  reservado DECIMAL(10,3) DEFAULT 0,
  comprometido DECIMAL(10,3) DEFAULT 0,
  en_transito DECIMAL(10,3) DEFAULT 0,
  danado DECIMAL(10,3) DEFAULT 0,
  bloqueado DECIMAL(10,3) DEFAULT 0,
  devuelto DECIMAL(10,3) DEFAULT 0,
  produccion DECIMAL(10,3) DEFAULT 0,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stock (id_producto, id_bodega, id_ubicacion),
  INDEX idx_stock_producto (id_producto),
  INDEX idx_stock_bodega (id_bodega),
  INDEX idx_stock_disponible (disponible),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto) ON DELETE CASCADE,
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── KARDEX ───
CREATE TABLE IF NOT EXISTS kardex (
  id_kardex INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  id_bodega INT DEFAULT NULL,
  tipo_movimiento VARCHAR(30) NOT NULL,
  id_documento INT DEFAULT NULL,
  documento_tipo VARCHAR(30) DEFAULT NULL,
  entrada DECIMAL(10,3) DEFAULT 0,
  salida DECIMAL(10,3) DEFAULT 0,
  saldo DECIMAL(10,3) DEFAULT 0,
  costo_unitario DECIMAL(10,2) DEFAULT 0,
  costo_total DECIMAL(10,2) DEFAULT 0,
  id_user INT DEFAULT NULL,
  observaciones TEXT DEFAULT NULL,
  fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kardex_producto (id_producto),
  INDEX idx_kardex_bodega (id_bodega),
  INDEX idx_kardex_fecha (fecha),
  INDEX idx_kardex_tipo (tipo_movimiento),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── MOVIMIENTO INVENTARIO ───
CREATE TABLE IF NOT EXISTS movimiento_inventario (
  id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) DEFAULT NULL,
  tipo VARCHAR(30) NOT NULL,
  id_producto INT NOT NULL,
  id_bodega_origen INT DEFAULT NULL,
  id_bodega_destino INT DEFAULT NULL,
  cantidad DECIMAL(10,3) NOT NULL,
  costo DECIMAL(10,2) DEFAULT 0,
  id_user INT DEFAULT NULL,
  observaciones TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_movto_producto (id_producto),
  INDEX idx_movto_tipo (tipo),
  INDEX idx_movto_created (created_at),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── TRANSFERENCIA ───
CREATE TABLE IF NOT EXISTS transferencia (
  id_transferencia INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) DEFAULT NULL,
  id_bodega_origen INT NOT NULL,
  id_bodega_destino INT NOT NULL,
  estado VARCHAR(20) DEFAULT 'PENDIENTE',
  id_user INT DEFAULT NULL,
  observaciones TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_transferencia_estado (estado),
  FOREIGN KEY (id_bodega_origen) REFERENCES bodega(id_bodega),
  FOREIGN KEY (id_bodega_destino) REFERENCES bodega(id_bodega)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── TRANSFERENCIA DETALLE ───
CREATE TABLE IF NOT EXISTS transferencia_detalle (
  id_transferencia_detalle INT AUTO_INCREMENT PRIMARY KEY,
  id_transferencia INT NOT NULL,
  id_producto INT NOT NULL,
  cantidad DECIMAL(10,3) NOT NULL,
  FOREIGN KEY (id_transferencia) REFERENCES transferencia(id_transferencia),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── AJUSTE INVENTARIO ───
CREATE TABLE IF NOT EXISTS ajuste_inventario (
  id_ajuste INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) DEFAULT NULL,
  tipo VARCHAR(30) NOT NULL,
  id_producto INT NOT NULL,
  id_bodega INT NOT NULL,
  cantidad_anterior DECIMAL(10,3) DEFAULT 0,
  cantidad_nueva DECIMAL(10,3) DEFAULT 0,
  diferencia DECIMAL(10,3) NOT NULL,
  motivo TEXT DEFAULT NULL,
  id_user INT DEFAULT NULL,
  autorizado_por INT DEFAULT NULL,
  documento_respaldo VARCHAR(255) DEFAULT NULL,
  observaciones TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ajuste_producto (id_producto),
  INDEX idx_ajuste_bodega (id_bodega),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto),
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── INVENTARIO FÍSICO ───
CREATE TABLE IF NOT EXISTS inventario_fisico (
  id_inventario INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(30) DEFAULT NULL,
  tipo VARCHAR(30) NOT NULL,
  id_bodega INT NOT NULL,
  id_user INT DEFAULT NULL,
  estado VARCHAR(20) DEFAULT 'EN_PROGRESO',
  observaciones TEXT DEFAULT NULL,
  fecha_inicio DATETIME DEFAULT NULL,
  fecha_fin DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inv_fisico_bodega (id_bodega),
  INDEX idx_inv_fisico_estado (estado),
  FOREIGN KEY (id_bodega) REFERENCES bodega(id_bodega)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CONTEO INVENTARIO ───
CREATE TABLE IF NOT EXISTS conteo_inventario (
  id_conteo INT AUTO_INCREMENT PRIMARY KEY,
  id_inventario INT NOT NULL,
  id_producto INT NOT NULL,
  id_ubicacion INT DEFAULT NULL,
  cantidad_contada DECIMAL(10,3) DEFAULT 0,
  conciliado TINYINT(1) DEFAULT 0,
  FOREIGN KEY (id_inventario) REFERENCES inventario_fisico(id_inventario),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── ALERTA STOCK ───
CREATE TABLE IF NOT EXISTS alerta_stock (
  id_alerta INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NOT NULL,
  id_bodega INT DEFAULT NULL,
  nivel_actual DECIMAL(10,3) DEFAULT 0,
  nivel_minimo DECIMAL(10,3) DEFAULT 0,
  leido TINYINT(1) DEFAULT 0,
  resuelto TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_alerta_leido (leido, resuelto),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── AUDITORÍA INVENTARIO ───
CREATE TABLE IF NOT EXISTS inventario_auditoria (
  id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT DEFAULT NULL,
  accion VARCHAR(50) NOT NULL,
  entidad VARCHAR(50) DEFAULT NULL,
  id_entidad INT DEFAULT NULL,
  detalle TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inv_aud_user (id_user),
  INDEX idx_inv_aud_accion (accion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
