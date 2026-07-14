-- Autorizaciones puntuales de supervisor para POS e inventario.
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO permiso (modulo, accion, descripcion) VALUES
('supervisor','aprobar_pos','Autorizar excepciones sensibles del POS'),
('supervisor','aprobar_inventario','Autorizar excepciones sensibles de inventario'),
('supervisor','configurar_credencial','Configurar PIN o tarjeta de supervisor')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

CREATE TABLE IF NOT EXISTS supervisor_credencial (
  id_credencial INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cuenta INT NOT NULL,
  id_user INT NOT NULL,
  pin_hash VARCHAR(255) NULL,
  tarjeta_hash VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_credencial),
  UNIQUE KEY uq_supervisor_credencial_user (id_cuenta,id_user),
  KEY idx_supervisor_credencial_cuenta (id_cuenta,activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supervisor_autorizacion (
  id_autorizacion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cuenta INT NOT NULL,
  id_solicitante INT NOT NULL,
  id_supervisor INT NULL,
  modulo VARCHAR(30) NOT NULL,
  accion VARCHAR(60) NOT NULL,
  entidad_tipo VARCHAR(40) NULL,
  entidad_id VARCHAR(80) NULL,
  contexto_hash CHAR(64) NOT NULL,
  motivo VARCHAR(500) NULL,
  token_hash CHAR(64) NULL,
  estado ENUM('EMITIDA','CONSUMIDA','EXPIRADA','RECHAZADA') NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  expires_at DATETIME NULL,
  consumed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_autorizacion),
  UNIQUE KEY uq_supervisor_token (token_hash),
  KEY idx_supervisor_solicitud (id_cuenta,id_solicitante,accion,estado,expires_at),
  KEY idx_supervisor_intentos (id_cuenta,id_solicitante,estado,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El supervisor local o plantilla recibe las capacidades de aprobación.
INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p
  ON p.modulo='supervisor' AND p.accion IN ('aprobar_pos','aprobar_inventario')
WHERE r.nombre='Supervisor' AND r.activo=1;

-- Administradores pueden configurar y también resolver excepciones.
INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p
  ON p.modulo='supervisor' AND p.accion IN ('configurar_credencial','aprobar_pos','aprobar_inventario')
WHERE r.nombre='Administrador' AND r.activo=1;

-- Las instalaciones antiguas podían dar estas excepciones directamente.
DELETE rp FROM rol_permiso rp
JOIN rol r ON r.id_rol=rp.id_rol
JOIN permiso p ON p.id_permiso=rp.id_permiso
WHERE r.nombre='Cajero' AND (
  (p.modulo='pos' AND p.accion IN ('cancelar_venta','devoluciones','cambiar_precios'))
);

DELETE rp FROM rol_permiso rp
JOIN rol r ON r.id_rol=rp.id_rol
JOIN permiso p ON p.id_permiso=rp.id_permiso
WHERE r.nombre='Bodeguero' AND p.modulo='inventario'
  AND p.accion IN ('ajustes');

-- Un Supervisor debe poder ejecutar la excepción que aprueba.
INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p ON
 (p.modulo='pos' AND p.accion IN ('cancelar_venta','devoluciones','cambiar_precios','cerrar_caja','caja')) OR
 (p.modulo='inventario' AND p.accion IN ('ajustes','transferencias','conteo_fisico'))
WHERE r.nombre='Supervisor' AND r.activo=1;

COMMIT;
