-- ============================================================
-- Blue-Cat ERP v1.0 — Módulo CRM / Clientes
-- ============================================================
SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS cliente (
  id_cliente INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(20) DEFAULT NULL,
  rut VARCHAR(20) DEFAULT NULL,
  razon_social VARCHAR(200) DEFAULT NULL,
  nombre VARCHAR(150) NOT NULL,
  correo VARCHAR(100) DEFAULT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  direccion VARCHAR(255) DEFAULT NULL,
  ciudad VARCHAR(100) DEFAULT NULL,
  giro VARCHAR(200) DEFAULT NULL,
  categoria VARCHAR(100) DEFAULT NULL,
  estado VARCHAR(20) DEFAULT 'ACTIVO',
  notas TEXT DEFAULT NULL,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cliente_user (id_user),
  INDEX idx_cliente_rut (rut),
  INDEX idx_cliente_nombre (nombre),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cliente_contacto (
  id_contacto INT AUTO_INCREMENT PRIMARY KEY,
  id_cliente INT NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  cargo VARCHAR(100) DEFAULT NULL,
  correo VARCHAR(100) DEFAULT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  principal TINYINT(1) DEFAULT 0,
  FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cliente_credito (
  id_credito INT AUTO_INCREMENT PRIMARY KEY,
  id_cliente INT NOT NULL,
  id_user INT NOT NULL,
  monto DECIMAL(12,2) NOT NULL,
  saldo DECIMAL(12,2) NOT NULL DEFAULT 0,
  fecha_otorgamiento DATE NOT NULL,
  fecha_vencimiento DATE DEFAULT NULL,
  estado VARCHAR(20) DEFAULT 'ACTIVO',
  observaciones TEXT DEFAULT NULL,
  FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cliente_actividad (
  id_actividad INT AUTO_INCREMENT PRIMARY KEY,
  id_cliente INT NOT NULL,
  id_user INT NOT NULL,
  tipo VARCHAR(50) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cliente_etiqueta (
  id_etiqueta INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL UNIQUE,
  color VARCHAR(7) DEFAULT '#4f46e5'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cliente_etiqueta_rel (
  id_cliente INT NOT NULL,
  id_etiqueta INT NOT NULL,
  UNIQUE KEY uq_cliente_etiqueta (id_cliente, id_etiqueta),
  FOREIGN KEY (id_cliente) REFERENCES cliente(id_cliente) ON DELETE CASCADE,
  FOREIGN KEY (id_etiqueta) REFERENCES cliente_etiqueta(id_etiqueta) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cliente_auditoria (
  id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT DEFAULT NULL,
  accion VARCHAR(50) NOT NULL,
  id_cliente INT DEFAULT NULL,
  detalle TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cli_aud_user (id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
