-- ============================================================
-- Blue-Cat ERP v1.0 — Módulo Facturas
-- ============================================================
SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS factura (
  id_factura INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  id_cliente INT DEFAULT NULL,
  id_pedido INT DEFAULT NULL,
  folio VARCHAR(20) DEFAULT NULL,
  numero VARCHAR(50) DEFAULT NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'FACTURA',
  estado VARCHAR(20) DEFAULT 'EMITIDA',
  fecha_emision DATE NOT NULL,
  fecha_vencimiento DATE DEFAULT NULL,
  observaciones TEXT DEFAULT NULL,
  subtotal DECIMAL(10,2) DEFAULT 0,
  descuento DECIMAL(10,2) DEFAULT 0,
  neto DECIMAL(10,2) DEFAULT 0,
  iva DECIMAL(10,2) DEFAULT 0,
  total DECIMAL(10,2) DEFAULT 0,
  saldo DECIMAL(10,2) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_factura_user (id_user),
  INDEX idx_factura_cliente (id_cliente),
  INDEX idx_factura_fecha (fecha_emision),
  INDEX idx_factura_estado (estado),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user),
  FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS factura_detalle (
  id_factura_detalle INT AUTO_INCREMENT PRIMARY KEY,
  id_factura INT NOT NULL,
  id_producto INT DEFAULT NULL,
  producto VARCHAR(150) DEFAULT NULL,
  cantidad DECIMAL(10,3) NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(10,2) DEFAULT 0,
  total DECIMAL(10,2) DEFAULT 0,
  FOREIGN KEY (id_factura) REFERENCES factura(id_factura) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS factura_pago (
  id_factura_pago INT AUTO_INCREMENT PRIMARY KEY,
  id_factura INT NOT NULL,
  metodo VARCHAR(30) NOT NULL,
  monto DECIMAL(10,2) NOT NULL,
  fecha DATE DEFAULT NULL,
  referencia VARCHAR(100) DEFAULT NULL,
  INDEX idx_factura_pago_factura (id_factura),
  FOREIGN KEY (id_factura) REFERENCES factura(id_factura) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS factura_historial (
  id_historial INT AUTO_INCREMENT PRIMARY KEY,
  id_factura INT NOT NULL,
  id_user INT DEFAULT NULL,
  accion VARCHAR(30) NOT NULL,
  valor_anterior TEXT DEFAULT NULL,
  valor_nuevo TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_factura) REFERENCES factura(id_factura) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
