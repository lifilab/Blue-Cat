-- ============================================================
-- Blue-Cat ERP v1.0 — Módulo Empleados
-- ============================================================
SET NAMES utf8mb4;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS empleado (
  id_empleado INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT DEFAULT NULL,
  id_cuenta INT DEFAULT NULL,
  id_sucursal INT DEFAULT NULL,
  nombres VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  rut VARCHAR(20) DEFAULT NULL,
  correo VARCHAR(100) DEFAULT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  direccion VARCHAR(255) DEFAULT NULL,
  cargo VARCHAR(100) DEFAULT NULL,
  departamento VARCHAR(100) DEFAULT NULL,
  estado VARCHAR(20) DEFAULT 'ACTIVO',
  fecha_ingreso DATE DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_empleado_user (id_user),
  INDEX idx_empleado_estado (estado),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS empleado_contrato (
  id_contrato INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  tipo_contrato VARCHAR(50) NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE DEFAULT NULL,
  salario INT DEFAULT 0,
  observaciones TEXT DEFAULT NULL,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS empleado_auditoria (
  id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT DEFAULT NULL,
  accion VARCHAR(50) NOT NULL,
  id_empleado INT DEFAULT NULL,
  detalle TEXT DEFAULT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
