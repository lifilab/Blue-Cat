-- ============================================================
-- Blue-Cat ERP v1.0 — Módulo POS
-- ============================================================
SET NAMES utf8mb4;
START TRANSACTION;

-- ─── SESIÓN ───
CREATE TABLE IF NOT EXISTS sesion (
  id_sesion INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  fecha_ingreso VARCHAR(50) NOT NULL,
  fecha_cierre DATETIME DEFAULT NULL,
  monto_apertura INT DEFAULT NULL,
  empleado VARCHAR(30) DEFAULT NULL,
  nota VARCHAR(200) DEFAULT NULL,
  INDEX idx_sesion_user (id_user),
  INDEX idx_sesion_cierre (fecha_cierre),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CAJA ───
CREATE TABLE IF NOT EXISTS pos_caja (
  id_caja INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  sucursal VARCHAR(100) DEFAULT 'Principal',
  estado VARCHAR(20) DEFAULT 'CERRADA',
  monto_apertura INT DEFAULT 0,
  monto_actual INT DEFAULT 0,
  monto_cierre INT DEFAULT 0,
  fecha_apertura DATETIME DEFAULT NULL,
  fecha_cierre DATETIME DEFAULT NULL,
  id_sesion INT DEFAULT NULL,
  INDEX idx_caja_user_estado (id_user, estado),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_sesion) REFERENCES sesion(id_sesion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── MOVIMIENTO CAJA ───
CREATE TABLE IF NOT EXISTS pos_movimiento_caja (
  id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
  id_caja INT NOT NULL,
  id_user INT NOT NULL,
  tipo VARCHAR(30) NOT NULL,
  concepto VARCHAR(200) DEFAULT NULL,
  monto INT NOT NULL,
  metodo VARCHAR(30) DEFAULT 'EFECTIVO',
  referencia VARCHAR(100) DEFAULT NULL,
  id_pedido INT DEFAULT NULL,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mov_caja (id_caja),
  INDEX idx_mov_fecha (fecha),
  FOREIGN KEY (id_caja) REFERENCES pos_caja(id_caja),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── PEDIDO ───
CREATE TABLE IF NOT EXISTS pedido (
  id_pedido INT AUTO_INCREMENT PRIMARY KEY,
  id_sesion INT NOT NULL,
  id_cliente INT DEFAULT NULL,
  id_caja INT DEFAULT NULL,
  id_bodega INT DEFAULT NULL,
  tipo_documento VARCHAR(30) DEFAULT 'BOLETA',
  cliente_nombre VARCHAR(150) DEFAULT NULL,
  cliente_rut VARCHAR(20) DEFAULT NULL,
  cliente_correo VARCHAR(100) DEFAULT NULL,
  cliente_telefono VARCHAR(30) DEFAULT NULL,
  anulado TINYINT(1) DEFAULT 0,
  devuelto TINYINT(1) DEFAULT 0,
  precio_total INT NOT NULL DEFAULT 0,
  pago_total INT NOT NULL DEFAULT 0,
  diferencia INT NOT NULL DEFAULT 0,
  fecha DATETIME NOT NULL,
  INDEX idx_pedido_sesion (id_sesion),
  INDEX idx_pedido_fecha (fecha),
  INDEX idx_pedido_anulado (anulado),
  INDEX idx_pedido_cliente (id_cliente),
  INDEX idx_pedido_bodega (id_bodega),
  FOREIGN KEY (id_sesion) REFERENCES sesion(id_sesion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── DETALLE PEDIDO ───
CREATE TABLE IF NOT EXISTS detalle_pedido (
  id_detalle_pedido INT AUTO_INCREMENT PRIMARY KEY,
  id_pedido INT NOT NULL,
  id_producto INT NOT NULL,
  cantidad_pedida DECIMAL(10,3) NOT NULL,
  precio_total INT NOT NULL,
  INDEX idx_detalle_pedido (id_pedido),
  INDEX idx_detalle_producto (id_producto),
  FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── MÉTODO DE PAGO ───
CREATE TABLE IF NOT EXISTS metodo_de_pago (
  id_metodo_de_pago INT AUTO_INCREMENT PRIMARY KEY,
  id_pedido INT NOT NULL,
  nombre_metodo_pago VARCHAR(50) NOT NULL,
  monto INT NOT NULL,
  INDEX idx_metodo_pedido (id_pedido),
  FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── PROMOCIÓN ───
CREATE TABLE IF NOT EXISTS pos_promocion (
  id_promocion INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(30) UNIQUE,
  nombre VARCHAR(150) NOT NULL,
  tipo VARCHAR(30) NOT NULL,
  valor INT NOT NULL,
  monto_minimo INT DEFAULT 0,
  cantidad_minima INT DEFAULT 0,
  fecha_inicio DATE DEFAULT NULL,
  fecha_fin DATE DEFAULT NULL,
  dias_semana VARCHAR(50) DEFAULT NULL,
  hora_inicio TIME DEFAULT NULL,
  hora_fin TIME DEFAULT NULL,
  aplica_categoria VARCHAR(100) DEFAULT NULL,
  aplica_marca VARCHAR(100) DEFAULT NULL,
  combinable TINYINT DEFAULT 0,
  activo TINYINT DEFAULT 1,
  usado INT DEFAULT 0,
  INDEX idx_promo_user (id_user),
  INDEX idx_promo_activo (activo),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── PROMOCIÓN PRODUCTO ───
CREATE TABLE IF NOT EXISTS pos_promocion_producto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_promocion INT NOT NULL,
  id_producto INT NOT NULL,
  FOREIGN KEY (id_promocion) REFERENCES pos_promocion(id_promocion),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── DESCUENTO ───
CREATE TABLE IF NOT EXISTS pos_descuento (
  id_descuento INT AUTO_INCREMENT PRIMARY KEY,
  id_pedido INT DEFAULT NULL,
  id_promocion INT DEFAULT NULL,
  tipo VARCHAR(20) NOT NULL,
  monto INT NOT NULL,
  motivo VARCHAR(200) DEFAULT NULL,
  autorizado_por INT DEFAULT NULL,
  INDEX idx_desc_pedido (id_pedido),
  FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido),
  FOREIGN KEY (id_promocion) REFERENCES pos_promocion(id_promocion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── DEVOLUCIÓN ───
CREATE TABLE IF NOT EXISTS pos_devolucion (
  id_devolucion INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  id_pedido INT NOT NULL,
  tipo VARCHAR(20) DEFAULT 'TOTAL',
  motivo TEXT DEFAULT NULL,
  monto_devuelto INT NOT NULL DEFAULT 0,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dev_pedido (id_pedido),
  INDEX idx_dev_user (id_user),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── DEVOLUCIÓN DETALLE ───
CREATE TABLE IF NOT EXISTS pos_devolucion_detalle (
  id_devolucion_detalle INT AUTO_INCREMENT PRIMARY KEY,
  id_devolucion INT NOT NULL,
  id_producto INT NOT NULL,
  cantidad DECIMAL(10,3) NOT NULL DEFAULT 1,
  precio_unitario INT NOT NULL DEFAULT 0,
  subtotal INT NOT NULL DEFAULT 0,
  FOREIGN KEY (id_devolucion) REFERENCES pos_devolucion(id_devolucion),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── COTIZACIÓN ───
CREATE TABLE IF NOT EXISTS pos_cotizacion (
  id_cotizacion INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(30) DEFAULT NULL,
  id_cliente INT DEFAULT NULL,
  cliente_nombre VARCHAR(150) DEFAULT NULL,
  cliente_rut VARCHAR(20) DEFAULT NULL,
  cliente_correo VARCHAR(100) DEFAULT NULL,
  cliente_telefono VARCHAR(30) DEFAULT NULL,
  subtotal INT NOT NULL DEFAULT 0,
  descuento INT DEFAULT 0,
  total INT NOT NULL DEFAULT 0,
  validez VARCHAR(50) DEFAULT '7 días',
  notas TEXT DEFAULT NULL,
  convertida TINYINT(1) DEFAULT 0,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cotizacion_user (id_user),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── COTIZACIÓN DETALLE ───
CREATE TABLE IF NOT EXISTS pos_cotizacion_detalle (
  id_cotizacion_detalle INT AUTO_INCREMENT PRIMARY KEY,
  id_cotizacion INT NOT NULL,
  id_producto INT DEFAULT NULL,
  producto VARCHAR(150) DEFAULT NULL,
  sku VARCHAR(50) DEFAULT NULL,
  cantidad DECIMAL(10,3) NOT NULL DEFAULT 1,
  precio_unitario INT NOT NULL DEFAULT 0,
  descuento INT DEFAULT 0,
  subtotal INT NOT NULL DEFAULT 0,
  FOREIGN KEY (id_cotizacion) REFERENCES pos_cotizacion(id_cotizacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── RESERVA ───
CREATE TABLE IF NOT EXISTS pos_reserva (
  id_reserva INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  id_cliente INT DEFAULT NULL,
  id_referencia INT DEFAULT NULL,
  cliente_nombre VARCHAR(150) DEFAULT NULL,
  cliente_telefono VARCHAR(30) DEFAULT NULL,
  total INT NOT NULL DEFAULT 0,
  abono INT DEFAULT 0,
  estado VARCHAR(20) DEFAULT 'PENDIENTE',
  fecha_reserva DATE DEFAULT NULL,
  fecha_vencimiento DATE DEFAULT NULL,
  notas TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reserva_user (id_user),
  INDEX idx_reserva_estado (estado),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CAMBIO ───
CREATE TABLE IF NOT EXISTS pos_cambio (
  id_cambio INT AUTO_INCREMENT PRIMARY KEY,
  id_pedido_original INT NOT NULL,
  id_pedido_nuevo INT DEFAULT NULL,
  motivo TEXT DEFAULT NULL,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_pedido_original) REFERENCES pedido(id_pedido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── AUDITORÍA POS ───
CREATE TABLE IF NOT EXISTS pos_auditoria (
  id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT DEFAULT NULL,
  accion VARCHAR(50) NOT NULL,
  detalle TEXT DEFAULT NULL,
  id_referencia INT DEFAULT NULL,
  tabla_referencia VARCHAR(50) DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pos_aud_user (id_user),
  INDEX idx_pos_aud_accion (accion),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
