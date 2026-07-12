-- ============================================================
-- Blue-Cat ERP v1.0 — Schema maestro
-- Ejecutar en orden: 01_core → 02_pos → ... → 08_migraciones
-- ============================================================

-- ─── CONFIG BOLETA ───
CREATE TABLE IF NOT EXISTS config_boleta (
  id_config INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  nombre_empresa VARCHAR(150) NOT NULL DEFAULT 'Mi Empresa',
  rut_empresa VARCHAR(20) DEFAULT NULL,
  direccion VARCHAR(255) DEFAULT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  email VARCHAR(100) DEFAULT NULL,
  logo MEDIUMTEXT DEFAULT NULL,
  mensaje_pie TEXT DEFAULT NULL,
  mensaje_agradecimiento TEXT DEFAULT NULL,
  mostrar_rut_cliente TINYINT(1) DEFAULT 0,
  mostrar_desglose_iva TINYINT(1) DEFAULT 1,
  mostrar_descuento TINYINT(1) DEFAULT 1,
  iva_porcentaje DECIMAL(5,2) DEFAULT 19.00,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_config_boleta_user (id_user, activo),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
