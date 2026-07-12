-- ============================================================
-- Modulo LICENSING: Planes, Suscripciones y Modulos — Blue-Cat ERP
-- ============================================================

-- 1. PLANES
CREATE TABLE IF NOT EXISTS plan (
  id_plan INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  precio INT DEFAULT 0,
  max_empresas INT DEFAULT 1,
  max_sucursales INT DEFAULT 1,
  max_usuarios INT DEFAULT 5,
  max_almacenamiento INT DEFAULT 100,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. MODULOS
CREATE TABLE IF NOT EXISTS modulo (
  id_modulo INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(30) NOT NULL UNIQUE,
  nombre VARCHAR(50) NOT NULL,
  descripcion VARCHAR(200),
  icono VARCHAR(30),
  ruta VARCHAR(100),
  orden INT DEFAULT 0,
  activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. PLAN-MODULO (modules included in a plan)
CREATE TABLE IF NOT EXISTS plan_modulo (
  id_plan_modulo INT AUTO_INCREMENT PRIMARY KEY,
  id_plan INT NOT NULL,
  id_modulo INT NOT NULL,
  UNIQUE KEY uq_plan_modulo (id_plan, id_modulo),
  FOREIGN KEY (id_plan) REFERENCES plan(id_plan) ON DELETE CASCADE,
  FOREIGN KEY (id_modulo) REFERENCES modulo(id_modulo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. SUSCRIPCIONES
CREATE TABLE IF NOT EXISTS suscripcion (
  id_suscripcion INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NOT NULL,
  id_plan INT NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE,
  usuarios_extra INT DEFAULT 0,
  sucursales_extra INT DEFAULT 0,
  estado ENUM('activa','suspendida','cancelada','expirada') DEFAULT 'activa',
  renovacion_auto TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_suscripcion_empresa (id_empresa),
  FOREIGN KEY (id_empresa) REFERENCES empresa(id_empresa),
  FOREIGN KEY (id_plan) REFERENCES plan(id_plan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DATA SEEDS
-- ============================================================

-- Modulos del sistema
INSERT IGNORE INTO modulo (codigo, nombre, descripcion, icono, ruta, orden) VALUES
('dashboard','Dashboard','Panel de control','fa-tachometer-alt','Inicio.html',1),
('pos','POS','Punto de Venta','fa-cash-register','pos.html',2),
('inventario','Inventario','Gestión de inventario','fa-box','inventario.html',3),
('crm','Clientes CRM','Gestión de clientes','fa-address-book','crm.html',4),
('proveedores','Proveedores','Gestión de proveedores','fa-truck','proveedores.html',5),
('facturas','Facturación','Facturación electrónica','fa-file-invoice','facturas.html',6),
('empleados','Empleados','Recursos humanos','fa-users','empleados.html',7),
('cuadre','Cuadre de Ventas','Cierre de caja','fa-chart-line','cuadre_de_ventas.html',8),
('configuracion','Configuración','Configuración del sistema','fa-cogs','configuracion.html',9);

-- Plan default
INSERT IGNORE INTO plan (nombre, descripcion, precio, max_empresas, max_sucursales, max_usuarios) VALUES
('Plan Básico','Plan inicial para pequeños negocios',0,1,1,5);

-- Asignar todos los modulos al plan default
INSERT IGNORE INTO plan_modulo (id_plan, id_modulo) SELECT 1, id_modulo FROM modulo;

-- Suscripcion default para la empresa existente
INSERT IGNORE INTO suscripcion (id_empresa, id_plan, fecha_inicio, estado) SELECT id_empresa, 1, CURDATE(), 'activa' FROM empresa LIMIT 1;
