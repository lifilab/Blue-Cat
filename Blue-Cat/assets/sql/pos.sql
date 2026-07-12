-- POS - Tablas complementarias
SET NAMES utf8mb4;

DROP TABLE IF EXISTS pos_auditoria;
DROP TABLE IF EXISTS pos_cambio;
DROP TABLE IF EXISTS pos_devolucion_detalle;
DROP TABLE IF EXISTS pos_devolucion;
DROP TABLE IF EXISTS pos_cotizacion_detalle;
DROP TABLE IF EXISTS pos_cotizacion;
DROP TABLE IF EXISTS pos_reserva;
DROP TABLE IF EXISTS pos_descuento;
DROP TABLE IF EXISTS pos_promocion_producto;
DROP TABLE IF EXISTS pos_promocion;
DROP TABLE IF EXISTS pos_movimiento_caja;
DROP TABLE IF EXISTS pos_caja;

-- Cajas registradoras
CREATE TABLE pos_caja (
  id_caja INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(20) NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  sucursal VARCHAR(100) DEFAULT 'Principal',
  estado VARCHAR(20) DEFAULT 'CERRADA',
  monto_apertura INT DEFAULT 0,
  monto_actual INT DEFAULT 0,
  monto_cierre INT DEFAULT 0,
  fecha_apertura DATETIME,
  fecha_cierre DATETIME,
  id_sesion INT,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_sesion) REFERENCES sesion(id_sesion)
);

-- Movimientos de caja
CREATE TABLE pos_movimiento_caja (
  id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
  id_caja INT NOT NULL,
  id_user INT NOT NULL,
  tipo VARCHAR(30) NOT NULL, -- INGRESO, EGRESO, RETIRO, APERTURA, CIERRE
  concepto VARCHAR(200),
  monto INT NOT NULL,
  metodo VARCHAR(30) DEFAULT 'EFECTIVO',
  referencia VARCHAR(100),
  id_pedido INT,
  FOREIGN KEY (id_caja) REFERENCES pos_caja(id_caja),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
);

-- Promociones
CREATE TABLE pos_promocion (
  id_promocion INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(30) UNIQUE,
  nombre VARCHAR(150) NOT NULL,
  tipo VARCHAR(30) NOT NULL, -- 2X1, 3X2, DESCUENTO_PCT, DESCUENTO_MONTO, PACK, COMBO, HAPPY_HOUR
  valor INT NOT NULL, -- % o monto según tipo
  monto_minimo INT DEFAULT 0,
  cantidad_minima INT DEFAULT 0,
  fecha_inicio DATE,
  fecha_fin DATE,
  dias_semana VARCHAR(50), -- 1,2,3,4,5,6,7
  hora_inicio TIME,
  hora_fin TIME,
  aplica_categoria VARCHAR(100),
  aplica_marca VARCHAR(100),
  combinable TINYINT DEFAULT 0,
  activo TINYINT DEFAULT 1,
  usado INT DEFAULT 0,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
);

-- Productos incluidos en promociones
CREATE TABLE pos_promocion_producto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_promocion INT NOT NULL,
  id_producto INT NOT NULL,
  FOREIGN KEY (id_promocion) REFERENCES pos_promocion(id_promocion),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
);

-- Descuentos aplicados en ventas
CREATE TABLE pos_descuento (
  id_descuento INT AUTO_INCREMENT PRIMARY KEY,
  id_pedido INT,
  id_promocion INT,
  tipo VARCHAR(20) NOT NULL, -- PROMOCION, SUPERVISOR, CLIENTE, CUPON
  monto INT NOT NULL,
  motivo VARCHAR(200),
  autorizado_por INT,
  FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido),
  FOREIGN KEY (id_promocion) REFERENCES pos_promocion(id_promocion)
);

-- Devoluciones
CREATE TABLE pos_devolucion (
  id_devolucion INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  id_pedido INT NOT NULL,
  tipo VARCHAR(20) DEFAULT 'TOTAL', -- TOTAL, PARCIAL, CAMBIO, GARANTIA
  motivo VARCHAR(300),
  estado VARCHAR(20) DEFAULT 'COMPLETADA',
  monto_devuelto INT DEFAULT 0,
  fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_pedido) REFERENCES pedido(id_pedido)
);

CREATE TABLE pos_devolucion_detalle (
  id_detalle INT AUTO_INCREMENT PRIMARY KEY,
  id_devolucion INT NOT NULL,
  id_producto INT NOT NULL,
  cantidad INT NOT NULL,
  precio_unitario INT DEFAULT 0,
  subtotal INT DEFAULT 0,
  FOREIGN KEY (id_devolucion) REFERENCES pos_devolucion(id_devolucion),
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
);

-- Cambios
CREATE TABLE pos_cambio (
  id_cambio INT AUTO_INCREMENT PRIMARY KEY,
  id_devolucion INT,
  id_producto_nuevo INT NOT NULL,
  id_producto_viejo INT NOT NULL,
  cantidad INT DEFAULT 1,
  diferencia INT DEFAULT 0,
  FOREIGN KEY (id_devolucion) REFERENCES pos_devolucion(id_devolucion),
  FOREIGN KEY (id_producto_nuevo) REFERENCES producto(id_producto),
  FOREIGN KEY (id_producto_viejo) REFERENCES producto(id_producto)
);

-- Cotizaciones
CREATE TABLE pos_cotizacion (
  id_cotizacion INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(20) NOT NULL,
  id_cliente INT,
  cliente_nombre VARCHAR(150),
  cliente_rut VARCHAR(20),
  cliente_correo VARCHAR(100),
  cliente_telefono VARCHAR(30),
  subtotal INT DEFAULT 0,
  descuento INT DEFAULT 0,
  total INT DEFAULT 0,
  validez VARCHAR(30) DEFAULT '7 días',
  notas TEXT,
  estado VARCHAR(20) DEFAULT 'VIGENTE',
  convertida TINYINT DEFAULT 0,
  id_pedido_convertido INT,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente)
);

CREATE TABLE pos_cotizacion_detalle (
  id_detalle INT AUTO_INCREMENT PRIMARY KEY,
  id_cotizacion INT NOT NULL,
  id_producto INT,
  producto VARCHAR(150),
  sku VARCHAR(30),
  cantidad INT NOT NULL,
  precio_unitario INT DEFAULT 0,
  descuento INT DEFAULT 0,
  subtotal INT DEFAULT 0,
  FOREIGN KEY (id_cotizacion) REFERENCES pos_cotizacion(id_cotizacion)
);

-- Reservas
CREATE TABLE pos_reserva (
  id_reserva INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  id_cliente INT,
  cliente_nombre VARCHAR(150),
  cliente_telefono VARCHAR(30),
  fecha_reserva DATE NOT NULL,
  fecha_vencimiento DATE,
  estado VARCHAR(20) DEFAULT 'ACTIVA',
  total INT DEFAULT 0,
  abono INT DEFAULT 0,
  notas TEXT,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente)
);

-- Auditoría POS
CREATE TABLE pos_auditoria (
  id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT,
  accion VARCHAR(50) NOT NULL,
  detalle TEXT,
  id_referencia INT,
  tabla_referencia VARCHAR(50),
  ip VARCHAR(45),
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Alter existing tables to add POS integration
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS id_cliente INT AFTER id_sesion;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS id_caja INT AFTER id_cliente;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS id_bodega INT AFTER id_caja;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS tipo_documento VARCHAR(30) DEFAULT 'BOLETA' AFTER id_bodega;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS cliente_nombre VARCHAR(150) AFTER tipo_documento;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS cliente_rut VARCHAR(20) AFTER cliente_nombre;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS cliente_correo VARCHAR(100) AFTER cliente_rut;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS cliente_telefono VARCHAR(30) AFTER cliente_correo;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS anulado TINYINT DEFAULT 0 AFTER cliente_telefono;
ALTER TABLE pedido ADD COLUMN IF NOT EXISTS devuelto TINYINT DEFAULT 0 AFTER anulado;
