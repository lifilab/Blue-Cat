-- ============================================================
-- Blue-Cat ERP v1.0 — Módulo Proveedores
-- ============================================================
SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS proveedor (
  id_proveedor INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(20) DEFAULT NULL,
  razon_social VARCHAR(250) NOT NULL,
  nombre_comercial VARCHAR(250) DEFAULT NULL,
  rut VARCHAR(20) DEFAULT NULL,
  giro VARCHAR(200) DEFAULT NULL,
  direccion VARCHAR(255) DEFAULT NULL,
  ciudad VARCHAR(100) DEFAULT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  correo VARCHAR(100) DEFAULT NULL,
  sitio_web VARCHAR(100) DEFAULT NULL,
  estado VARCHAR(20) DEFAULT 'ACTIVO',
  notas TEXT DEFAULT NULL,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_proveedor_user (id_user),
  INDEX idx_proveedor_codigo (codigo),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proveedor_contacto (
  id_contacto INT AUTO_INCREMENT PRIMARY KEY,
  id_proveedor INT NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  cargo VARCHAR(100) DEFAULT NULL,
  correo VARCHAR(100) DEFAULT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  principal TINYINT(1) DEFAULT 0,
  FOREIGN KEY (id_proveedor) REFERENCES proveedor(id_proveedor) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proveedor_banco (
  id_banco INT AUTO_INCREMENT PRIMARY KEY,
  id_proveedor INT NOT NULL,
  banco VARCHAR(100) NOT NULL,
  tipo_cuenta VARCHAR(30) DEFAULT NULL,
  numero_cuenta VARCHAR(50) DEFAULT NULL,
  titular VARCHAR(150) DEFAULT NULL,
  rut_titular VARCHAR(20) DEFAULT NULL,
  FOREIGN KEY (id_proveedor) REFERENCES proveedor(id_proveedor) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proveedor_producto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_proveedor INT NOT NULL,
  id_producto INT NOT NULL,
  sku_proveedor VARCHAR(50) DEFAULT NULL,
  sku_interno VARCHAR(50) DEFAULT NULL,
  producto VARCHAR(150) DEFAULT NULL,
  marca VARCHAR(100) DEFAULT NULL,
  categoria VARCHAR(100) DEFAULT NULL,
  precio_compra DECIMAL(10,2) DEFAULT 0,
  FOREIGN KEY (id_proveedor) REFERENCES proveedor(id_proveedor) ON DELETE CASCADE,
  FOREIGN KEY (id_producto) REFERENCES producto(id_producto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proveedor_historial (
  id_historial INT AUTO_INCREMENT PRIMARY KEY,
  id_proveedor INT NOT NULL,
  id_user INT DEFAULT NULL,
  accion VARCHAR(30) NOT NULL,
  valor_anterior TEXT DEFAULT NULL,
  valor_nuevo TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_proveedor) REFERENCES proveedor(id_proveedor) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
