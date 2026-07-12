-- ============================================================
-- Módulo CORE: Configuración General — Blue-Cat ERP
-- Single Source of Truth para todo el ERP
-- ============================================================

-- 1. EMPRESAS
CREATE TABLE IF NOT EXISTS empresa (
  id_empresa INT AUTO_INCREMENT PRIMARY KEY,
  razon_social VARCHAR(200) NOT NULL,
  nombre_comercial VARCHAR(200),
  rut VARCHAR(20) NOT NULL UNIQUE,
  giro VARCHAR(200),
  representante_legal VARCHAR(100),
  direccion TEXT,
  region VARCHAR(100),
  ciudad VARCHAR(100),
  pais VARCHAR(50) DEFAULT 'Chile',
  telefono VARCHAR(30),
  correo VARCHAR(100),
  sitio_web VARCHAR(100),
  regimen_tributario VARCHAR(50),
  actividad_economica VARCHAR(100),
  moneda_base VARCHAR(10) DEFAULT 'CLP',
  logo VARCHAR(255),
  color_primario VARCHAR(7) DEFAULT '#4f46e5',
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. SUCURSALES
CREATE TABLE IF NOT EXISTS sucursal (
  id_sucursal INT AUTO_INCREMENT PRIMARY KEY,
  id_empresa INT NOT NULL,
  codigo VARCHAR(20) NOT NULL UNIQUE,
  nombre VARCHAR(100) NOT NULL,
  direccion TEXT,
  responsable VARCHAR(100),
  telefono VARCHAR(30),
  correo VARCHAR(100),
  horario_apertura TIME,
  horario_cierre TIME,
  zona_horaria VARCHAR(50) DEFAULT 'America/Santiago',
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_empresa) REFERENCES empresa(id_empresa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. DEPARTAMENTOS
CREATE TABLE IF NOT EXISTS departamento (
  id_departamento INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion TEXT,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. EXTENDER bodega con sucursal
-- (se ejecuta via PHP si la columna no existe)

-- 5. EXTENDER usuario con campos enterprise
-- (se ejecuta via PHP si las columnas no existen)

-- 6. ROLES
CREATE TABLE IF NOT EXISTS rol (
  id_rol INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL UNIQUE,
  descripcion TEXT,
  es_sistema TINYINT(1) DEFAULT 0,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. PERMISOS
CREATE TABLE IF NOT EXISTS permiso (
  id_permiso INT AUTO_INCREMENT PRIMARY KEY,
  modulo VARCHAR(50) NOT NULL,
  accion VARCHAR(50) NOT NULL,
  descripcion VARCHAR(200),
  UNIQUE KEY uq_modulo_accion (modulo, accion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. ROL-PERMISO
CREATE TABLE IF NOT EXISTS rol_permiso (
  id_rol_permiso INT AUTO_INCREMENT PRIMARY KEY,
  id_rol INT NOT NULL,
  id_permiso INT NOT NULL,
  UNIQUE KEY uq_rol_permiso (id_rol, id_permiso),
  FOREIGN KEY (id_rol) REFERENCES rol(id_rol) ON DELETE CASCADE,
  FOREIGN KEY (id_permiso) REFERENCES permiso(id_permiso) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. USUARIO-ROL
CREATE TABLE IF NOT EXISTS usuario_rol (
  id_usuario_rol INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  id_rol INT NOT NULL,
  UNIQUE KEY uq_usuario_rol (id_user, id_rol),
  FOREIGN KEY (id_user) REFERENCES usuario(id_user) ON DELETE CASCADE,
  FOREIGN KEY (id_rol) REFERENCES rol(id_rol) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. MONEDAS
CREATE TABLE IF NOT EXISTS moneda (
  id_moneda INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(5) NOT NULL UNIQUE,
  nombre VARCHAR(50) NOT NULL,
  simbolo VARCHAR(5),
  decimales TINYINT DEFAULT 0,
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. TIPOS DE CAMBIO
CREATE TABLE IF NOT EXISTS tipo_cambio (
  id_tipo_cambio INT AUTO_INCREMENT PRIMARY KEY,
  id_moneda INT NOT NULL,
  fecha DATE NOT NULL,
  valor DECIMAL(12,4) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_moneda_fecha (id_moneda, fecha),
  FOREIGN KEY (id_moneda) REFERENCES moneda(id_moneda)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. IMPUESTOS
CREATE TABLE IF NOT EXISTS impuesto (
  id_impuesto INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(50) NOT NULL,
  codigo VARCHAR(10) NOT NULL UNIQUE,
  tasa DECIMAL(5,2) NOT NULL DEFAULT 0,
  tipo ENUM('IVA','EXENTO','RETENCION','OTRO') DEFAULT 'IVA',
  activo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. NUMERACIONES
CREATE TABLE IF NOT EXISTS numeracion (
  id_numeracion INT AUTO_INCREMENT PRIMARY KEY,
  tipo_documento VARCHAR(30) NOT NULL,
  prefijo VARCHAR(10),
  ultimo_numero INT DEFAULT 0,
  siguiente_numero INT DEFAULT 1,
  formato VARCHAR(30),
  activo TINYINT(1) DEFAULT 1,
  UNIQUE KEY uq_tipo_doc (tipo_documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. PARÁMETROS GLOBALES
CREATE TABLE IF NOT EXISTS parametro (
  id_parametro INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(50) NOT NULL UNIQUE,
  valor TEXT,
  tipo VARCHAR(20) DEFAULT 'texto',
  descripcion VARCHAR(200),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. AUDITORÍA UNIFICADA
CREATE TABLE IF NOT EXISTS core_auditoria (
  id_auditoria BIGINT AUTO_INCREMENT PRIMARY KEY,
  id_user INT,
  id_empresa INT,
  id_sucursal INT,
  accion VARCHAR(50) NOT NULL,
  entidad VARCHAR(50) NOT NULL,
  id_entidad INT,
  valor_anterior JSON,
  valor_nuevo JSON,
  resultado VARCHAR(20) DEFAULT 'OK',
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  duracion_ms INT,
  nivel ENUM('INFO','WARNING','ERROR','CRITICAL','DEBUG') DEFAULT 'INFO',
  created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_entidad (entidad, id_entidad),
  INDEX idx_user (id_user),
  INDEX idx_accion (accion),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DATA SEEDS
-- ============================================================

-- Empresa default
INSERT IGNORE INTO empresa (razon_social, nombre_comercial, rut, giro, ciudad, pais) 
VALUES ('MiniMarket San Fernando SpA', 'MiniMarket San Fernando', '76.086.428-5', 'Venta al por menor de alimentos', 'San Fernando', 'Chile');

-- Sucursal default
INSERT IGNORE INTO sucursal (id_empresa, codigo, nombre)
SELECT id_empresa, 'SUC-001', 'Casa Matriz' FROM empresa WHERE rut='76.086.428-5';

-- Departamentos básicos
INSERT IGNORE INTO departamento (nombre) VALUES ('Administración'),('Ventas'),('Bodega'),('Finanzas');

-- Roles del sistema
INSERT IGNORE INTO rol (nombre, descripcion, es_sistema) VALUES
('Administrador', 'Control total del sistema', 1),
('Supervisor', 'Supervisión de operaciones', 1),
('Vendedor', 'Operador de ventas y POS', 1),
('Bodeguero', 'Gestión de inventario y bodegas', 1),
('Consulta', 'Solo lectura', 1);

-- Monedas
INSERT IGNORE INTO moneda (codigo, nombre, simbolo, decimales) VALUES
('CLP', 'Peso Chileno', '$', 0),
('USD', 'Dólar Estadounidense', 'US$', 2),
('EUR', 'Euro', '€', 2);

-- Impuesto default (IVA Chile 19%)
INSERT IGNORE INTO impuesto (nombre, codigo, tasa, tipo) VALUES
('IVA 19%', 'IVA', 19.00, 'IVA'),
('Exento', 'EXE', 0.00, 'EXENTO');

-- Numeraciones default
INSERT IGNORE INTO numeracion (tipo_documento, prefijo, siguiente_numero) VALUES
('BOLETA', 'B', 1),
('FACTURA', 'F', 1),
('COTIZACION', 'COT', 1),
('GUIA', 'G', 1);

-- Parámetros globales
INSERT IGNORE INTO parametro (clave, valor, tipo, descripcion) VALUES
('formato_fecha', 'd/m/Y', 'texto', 'Formato de fecha para visualización'),
('formato_moneda', '$', 'texto', 'Símbolo de moneda'),
('separador_decimal', '.', 'texto', 'Separador decimal'),
('zona_horaria', 'America/Santiago', 'texto', 'Zona horaria del sistema'),
('idioma', 'es-CL', 'texto', 'Idioma por defecto'),
('tiempo_sesion', '480', 'numero', 'Tiempo máximo de sesión en minutos'),
('paginacion', '50', 'numero', 'Registros por página');

-- Asignar rol Admin al usuario admin (id_user=4)
INSERT IGNORE INTO usuario_rol (id_user, id_rol)
SELECT 4, id_rol FROM rol WHERE nombre='Administrador';

-- Insertar permisos básicos
INSERT IGNORE INTO permiso (modulo, accion, descripcion) VALUES
('productos','ver','Ver productos'),
('productos','crear','Crear productos'),
('productos','editar','Editar productos'),
('productos','eliminar','Eliminar productos'),
('inventario','ver','Ver inventario'),
('inventario','movimientos','Realizar movimientos'),
('inventario','transferencias','Realizar transferencias'),
('inventario','ajustes','Realizar ajustes'),
('pos','ventas','Realizar ventas'),
('pos','caja','Abrir/Cerrar caja'),
('pos','anular','Anular ventas'),
('proveedores','ver','Ver proveedores'),
('proveedores','crear','Crear proveedores'),
('empleados','ver','Ver empleados'),
('empleados','crear','Crear empleados'),
('facturas','ver','Ver facturas'),
('facturas','crear','Crear facturas'),
('configuracion','ver','Ver configuración'),
('configuracion','editar','Editar configuración'),
('reportes','ver','Ver reportes');
