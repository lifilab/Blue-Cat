-- ============================================================
-- Módulo CRM: Clientes — Blue-Cat ERP
-- ============================================================

-- ------------------------------------------------------------
-- Cliente (definición base, compartida con facturacion.sql)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cliente` (
  `id_cliente` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL,
  `rut` varchar(20) DEFAULT NULL,
  `razon_social` varchar(200) NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `comuna` varchar(100) DEFAULT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `giro` varchar(200) DEFAULT NULL,
  `fecha_creacion` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_cliente`),
  KEY `id_user` (`id_user`),
  CONSTRAINT `cliente_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `usuario` (`id_user`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Extender tabla cliente con nuevas columnas
-- (PHP maneja los errores si las columnas/índices ya existen)
-- ------------------------------------------------------------
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `codigo` varchar(20) DEFAULT NULL AFTER `giro`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `tipo` enum('persona','empresa') DEFAULT NULL AFTER `codigo`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `categoria` varchar(50) DEFAULT NULL AFTER `tipo`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `clasificacion` varchar(50) DEFAULT NULL AFTER `categoria`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `origen` varchar(50) DEFAULT NULL AFTER `clasificacion`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `id_vendedor` int DEFAULT NULL AFTER `origen`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `lista_precios` varchar(30) DEFAULT NULL AFTER `id_vendedor`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `moneda` varchar(10) DEFAULT 'CLP' AFTER `lista_precios`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `canal` varchar(30) DEFAULT NULL AFTER `moneda`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `estado` enum('activo','inactivo','bloqueado','moroso','vip') DEFAULT 'activo' AFTER `canal`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `activo` tinyint(1) DEFAULT 1 AFTER `estado`;
ALTER TABLE `cliente` ADD COLUMN IF NOT EXISTS `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `activo`;

-- Índices adicionales
ALTER TABLE `cliente` ADD UNIQUE INDEX `uq_cliente_codigo` (`codigo`);
ALTER TABLE `cliente` ADD INDEX `idx_cliente_estado` (`estado`);
ALTER TABLE `cliente` ADD INDEX `idx_cliente_tipo` (`tipo`);
ALTER TABLE `cliente` ADD INDEX `idx_cliente_vendedor` (`id_vendedor`);

-- FK id_vendedor -> usuario (se maneja via PHP si la constraint ya existe)
ALTER TABLE `cliente` ADD CONSTRAINT `cliente_ibfk_2` FOREIGN KEY (`id_vendedor`) REFERENCES `usuario` (`id_user`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 1. CLIENTE_CONTACTO
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cliente_contacto` (
  `id_contacto` int NOT NULL AUTO_INCREMENT,
  `id_cliente` int NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) DEFAULT NULL,
  `cargo` varchar(100) DEFAULT NULL,
  `departamento` varchar(100) DEFAULT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `whatsapp` varchar(30) DEFAULT NULL,
  `cumpleanos` date DEFAULT NULL,
  `idioma` varchar(10) DEFAULT 'es',
  `principal` tinyint(1) DEFAULT 0,
  `observaciones` text,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_contacto`),
  KEY `id_cliente` (`id_cliente`),
  KEY `idx_contacto_activo` (`activo`),
  KEY `idx_contacto_principal` (`principal`),
  CONSTRAINT `cliente_contacto_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `cliente` (`id_cliente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. CLIENTE_DIRECCION
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cliente_direccion` (
  `id_direccion` int NOT NULL AUTO_INCREMENT,
  `id_cliente` int NOT NULL,
  `tipo` enum('facturacion','despacho','casa_matriz','sucursal') DEFAULT 'facturacion',
  `direccion` varchar(255) DEFAULT NULL,
  `ciudad` varchar(100) DEFAULT NULL,
  `comuna` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `pais` varchar(50) DEFAULT 'Chile',
  `codigo_postal` varchar(20) DEFAULT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `principal` tinyint(1) DEFAULT 0,
  `observaciones` text,
  `activo` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_direccion`),
  KEY `id_cliente` (`id_cliente`),
  KEY `idx_direccion_tipo` (`tipo`),
  KEY `idx_direccion_activo` (`activo`),
  KEY `idx_direccion_principal` (`principal`),
  CONSTRAINT `cliente_direccion_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `cliente` (`id_cliente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. CLIENTE_CREDITO
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cliente_credito` (
  `id_credito` int NOT NULL AUTO_INCREMENT,
  `id_cliente` int NOT NULL,
  `limite_credito` int DEFAULT 0,
  `credito_utilizado` int DEFAULT 0,
  `dias_credito` int DEFAULT 0,
  `condiciones_pago` varchar(100) DEFAULT NULL,
  `bloqueado` tinyint(1) DEFAULT 0,
  `estado` enum('activo','bloqueado','suspendido') DEFAULT 'activo',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_credito`),
  UNIQUE KEY `uq_cliente_credito` (`id_cliente`),
  KEY `idx_credito_bloqueado` (`bloqueado`),
  KEY `idx_credito_estado` (`estado`),
  CONSTRAINT `cliente_credito_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `cliente` (`id_cliente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. CLIENTE_ACTIVIDAD
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cliente_actividad` (
  `id_actividad` int NOT NULL AUTO_INCREMENT,
  `id_cliente` int NOT NULL,
  `tipo` enum('llamada','correo','reunion','visita','tarea','nota','seguimiento') NOT NULL,
  `asunto` varchar(200) DEFAULT NULL,
  `descripcion` text,
  `fecha_planificada` datetime DEFAULT NULL,
  `fecha_realizada` datetime DEFAULT NULL,
  `estado` enum('pendiente','completada','cancelada') DEFAULT 'pendiente',
  `id_user` int DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_actividad`),
  KEY `id_cliente` (`id_cliente`),
  KEY `id_user` (`id_user`),
  KEY `idx_actividad_tipo` (`tipo`),
  KEY `idx_actividad_estado` (`estado`),
  KEY `idx_actividad_planificada` (`fecha_planificada`),
  CONSTRAINT `cliente_actividad_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `cliente` (`id_cliente`) ON DELETE CASCADE,
  CONSTRAINT `cliente_actividad_ibfk_2` FOREIGN KEY (`id_user`) REFERENCES `usuario` (`id_user`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. CLIENTE_ETIQUETA (tags globales)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cliente_etiqueta` (
  `id_etiqueta` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `color` varchar(7) DEFAULT '#6b7280',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_etiqueta`),
  UNIQUE KEY `uq_etiqueta_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. CLIENTE_ETIQUETA_REL (many-to-many cliente <-> etiqueta)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cliente_etiqueta_rel` (
  `id_rel` int NOT NULL AUTO_INCREMENT,
  `id_cliente` int NOT NULL,
  `id_etiqueta` int NOT NULL,
  PRIMARY KEY (`id_rel`),
  UNIQUE KEY `uq_cliente_etiqueta` (`id_cliente`, `id_etiqueta`),
  KEY `id_cliente` (`id_cliente`),
  KEY `id_etiqueta` (`id_etiqueta`),
  CONSTRAINT `cliente_etiqueta_rel_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `cliente` (`id_cliente`) ON DELETE CASCADE,
  CONSTRAINT `cliente_etiqueta_rel_ibfk_2` FOREIGN KEY (`id_etiqueta`) REFERENCES `cliente_etiqueta` (`id_etiqueta`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 7. CLIENTE_AUDITORIA (audit log)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cliente_auditoria` (
  `id_auditoria` bigint NOT NULL AUTO_INCREMENT,
  `id_cliente` int DEFAULT NULL,
  `id_user` int DEFAULT NULL,
  `accion` varchar(50) NOT NULL,
  `entidad` varchar(50) NOT NULL,
  `id_entidad` int DEFAULT NULL,
  `valor_anterior` json DEFAULT NULL,
  `valor_nuevo` json DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `nivel` enum('INFO','WARNING','ERROR','CRITICAL','DEBUG') DEFAULT 'INFO',
  `created_at` timestamp(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id_auditoria`),
  KEY `idx_audit_cliente` (`id_cliente`),
  KEY `idx_audit_user` (`id_user`),
  KEY `idx_audit_entidad` (`entidad`, `id_entidad`),
  KEY `idx_audit_accion` (`accion`),
  KEY `idx_audit_nivel` (`nivel`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DATA SEEDS
-- ============================================================

-- Etiquetas de ejemplo
INSERT IGNORE INTO `cliente_etiqueta` (`nombre`, `color`) VALUES
('VIP', '#f59e0b'),
('Mayorista', '#3b82f6'),
('Moroso', '#ef4444');

-- Cliente de prueba
INSERT IGNORE INTO `cliente` (`id_user`, `rut`, `razon_social`, `nombre`, `correo`, `telefono`, `giro`, `tipo`, `estado`, `moneda`)
VALUES (1, '99.999.999-9', 'Cliente de Prueba SpA', 'Cliente Test', 'test@bluecat.cl', '56912345678', 'Tecnología', 'empresa', 'activo', 'CLP');
