-- ============================================================
-- Blue-Cat ERP v1.0 — Schema completo
-- Ejecutar en MySQL 8.0+
-- ============================================================
SET NAMES utf8mb4;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

-- ─── CORE: EMPRESA ───
CREATE TABLE IF NOT EXISTS empresa (
  id_empresa INT AUTO_INCREMENT PRIMARY KEY,
  razon_social VARCHAR(200) NOT NULL,
  nombre_comercial VARCHAR(200),
  rut VARCHAR(20) NOT NULL UNIQUE,
  giro VARCHAR(200),
  direccion TEXT,
  ciudad VARCHAR(100),
  pais VARCHAR(50) DEFAULT 'Chile',
  telefono VARCHAR(30),
  correo VARCHAR(100),
  moneda_base VARCHAR(10) DEFAULT 'CLP',
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: SUCURSAL ───
CREATE TABLE IF NOT EXISTS sucursal (
  id_sucursal INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NOT NULL,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  direccion TEXT,
  responsable VARCHAR(100),
  telefono VARCHAR(30),
  correo VARCHAR(100),
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_empresa) REFERENCES empresa(id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: USUARIO ───
CREATE TABLE IF NOT EXISTS usuario (
  id_user INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT DEFAULT NULL,
  id_sucursal INT DEFAULT NULL,
  id_cuenta INT DEFAULT NULL,
  id_empleado INT DEFAULT NULL,
  nombre VARCHAR(100) NOT NULL,
  nombre_completo VARCHAR(200) DEFAULT NULL,
  correo VARCHAR(100) NOT NULL,
  password VARCHAR(512) NOT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  cargo VARCHAR(100) DEFAULT NULL,
  validar_sesion TINYINT(1) NOT NULL DEFAULT 0,
  intentos_fallidos INT DEFAULT 0,
  ultimo_acceso DATETIME DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_empresa) REFERENCES empresa(id_empresa),
  FOREIGN KEY (id_sucursal) REFERENCES sucursal(id_sucursal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: ROL ───
CREATE TABLE IF NOT EXISTS rol (
  id_rol INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL UNIQUE,
  descripcion TEXT,
  es_sistema TINYINT(1) DEFAULT 0,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: PERMISO ───
CREATE TABLE IF NOT EXISTS permiso (
  id_permiso INT AUTO_INCREMENT PRIMARY KEY,
  modulo VARCHAR(50) NOT NULL,
  accion VARCHAR(50) NOT NULL,
  descripcion VARCHAR(200),
  UNIQUE KEY uq_modulo_accion (modulo, accion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: ROL-PERMISO ───
CREATE TABLE IF NOT EXISTS rol_permiso (
  id_rol_permiso INT AUTO_INCREMENT PRIMARY KEY,
  id_rol INT NOT NULL,
  id_permiso INT NOT NULL,
  UNIQUE KEY uq_rol_permiso (id_rol, id_permiso),
  FOREIGN KEY (id_rol) REFERENCES rol(id_rol) ON DELETE CASCADE,
  FOREIGN KEY (id_permiso) REFERENCES permiso(id_permiso) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: USUARIO-ROL ───
CREATE TABLE IF NOT EXISTS usuario_rol (
  id_usuario_rol INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  id_rol INT NOT NULL,
  UNIQUE KEY uq_usuario_rol (id_user, id_rol),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user) ON DELETE CASCADE,
  FOREIGN KEY (id_rol) REFERENCES rol(id_rol) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: MÓDULO ───
CREATE TABLE IF NOT EXISTS modulo (
  id_modulo INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(30) NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  icono VARCHAR(30) DEFAULT 'fa-cube',
  ruta VARCHAR(100) DEFAULT NULL,
  orden INT DEFAULT 0,
  activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: PLAN ───
CREATE TABLE IF NOT EXISTS plan (
  id_plan INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  precio INT DEFAULT 0,
  max_empresas INT DEFAULT 1,
  max_sucursales INT DEFAULT 1,
  max_usuarios INT DEFAULT 5,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: PLAN-MÓDULO ───
CREATE TABLE IF NOT EXISTS plan_modulo (
  id_plan_modulo INT AUTO_INCREMENT PRIMARY KEY,
  id_plan INT NOT NULL,
  id_modulo INT NOT NULL,
  UNIQUE KEY uq_plan_modulo (id_plan, id_modulo),
  FOREIGN KEY (id_plan) REFERENCES plan(id_plan) ON DELETE CASCADE,
  FOREIGN KEY (id_modulo) REFERENCES modulo(id_modulo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: SUSCRIPCIÓN ───
CREATE TABLE IF NOT EXISTS suscripcion (
  id_suscripcion INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NOT NULL,
  id_plan INT NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE DEFAULT NULL,
  usuarios_extra INT DEFAULT 0,
  estado VARCHAR(20) DEFAULT 'activa',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_empresa) REFERENCES empresa(id_empresa),
  FOREIGN KEY (id_plan) REFERENCES plan(id_plan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: MONEDA ───
CREATE TABLE IF NOT EXISTS moneda (
  id_moneda INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(10) NOT NULL UNIQUE,
  nombre VARCHAR(50) NOT NULL,
  simbolo VARCHAR(10) DEFAULT NULL,
  decimales INT DEFAULT 0,
  activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: IMPUESTO ───
CREATE TABLE IF NOT EXISTS impuesto (
  id_impuesto INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  tasa DECIMAL(5,2) NOT NULL DEFAULT 0,
  tipo VARCHAR(20) DEFAULT 'IVA',
  activo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: NUMERACIÓN ───
CREATE TABLE IF NOT EXISTS numeracion (
  id_numeracion INT AUTO_INCREMENT PRIMARY KEY,
  tipo_documento VARCHAR(50) NOT NULL,
  prefijo VARCHAR(10) DEFAULT NULL,
  siguiente_numero INT NOT NULL DEFAULT 1,
  formato VARCHAR(50) DEFAULT NULL,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: PARÁMETRO ───
CREATE TABLE IF NOT EXISTS parametro (
  id_parametro INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(50) NOT NULL UNIQUE,
  valor TEXT,
  tipo VARCHAR(20) DEFAULT 'string',
  descripcion VARCHAR(200),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: AUDITORÍA ───
CREATE TABLE IF NOT EXISTS core_auditoria (
  id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT DEFAULT NULL,
  id_entidad INT DEFAULT NULL,
  accion VARCHAR(50) NOT NULL,
  entidad VARCHAR(50) DEFAULT NULL,
  valor_anterior TEXT,
  valor_nuevo TEXT,
  resultado VARCHAR(20) DEFAULT 'OK',
  ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  nivel VARCHAR(20) DEFAULT 'INFO',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auditoria_created (created_at),
  INDEX idx_auditoria_user (id_user),
  INDEX idx_auditoria_nivel (nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: SESIÓN LOG ───
CREATE TABLE IF NOT EXISTS sesion_log (
  id_sesion_log INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT DEFAULT NULL,
  accion VARCHAR(30) NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sesion_log_created (created_at),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: DEPARTAMENTO ───
CREATE TABLE IF NOT EXISTS departamento (
  id_departamento INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORE: TIPO DE CAMBIO ───
CREATE TABLE IF NOT EXISTS tipo_cambio (
  id_tipo_cambio INT AUTO_INCREMENT PRIMARY KEY,
  id_moneda INT NOT NULL,
  fecha DATE NOT NULL,
  tasa DECIMAL(10,4) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_moneda_fecha (id_moneda, fecha),
  FOREIGN KEY (id_moneda) REFERENCES moneda(id_moneda)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
