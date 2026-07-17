-- Phase 3: authentication, sessions and request security foundation.
SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE usuario
  ADD COLUMN bloqueado_hasta DATETIME NULL AFTER intentos_fallidos,
  ADD COLUMN ultimo_fallo_login DATETIME NULL AFTER bloqueado_hasta,
  ADD COLUMN password_changed_at DATETIME NULL AFTER ultimo_acceso,
  ADD COLUMN requiere_cambio_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at,
  ADD COLUMN session_version INT NOT NULL DEFAULT 1 AFTER requiere_cambio_password,
  ADD KEY idx_usuario_bloqueo (bloqueado_hasta),
  ADD KEY idx_usuario_cuenta_activo_sesion (id_cuenta, activo, session_version);

CREATE TABLE auth_intento (
  id_intento BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identity_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  resultado ENUM('FALLO','EXITO','BLOQUEADO') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_intento),
  KEY idx_auth_intento_identity_fecha (identity_hash, created_at),
  KEY idx_auth_intento_ip_fecha (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE core_sesion (
  id_sesion BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_cuenta INT NOT NULL,
  id_user INT NOT NULL,
  session_hash CHAR(64) NOT NULL,
  session_version INT NOT NULL,
  ip_hash CHAR(64) NULL,
  user_agent_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  revoked_by INT NULL,
  revoke_reason VARCHAR(180) NULL,
  PRIMARY KEY (id_sesion),
  UNIQUE KEY uq_core_sesion_hash (session_hash),
  KEY idx_core_sesion_user_active (id_user, revoked_at, expires_at),
  KEY idx_core_sesion_account_active (id_cuenta, revoked_at, expires_at),
  CONSTRAINT fk_core_sesion_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_core_sesion_user FOREIGN KEY (id_user) REFERENCES usuario(id_user) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_core_sesion_revoker FOREIGN KEY (revoked_by) REFERENCES usuario(id_user) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO permiso (modulo,accion,descripcion) VALUES
('seguridad','ver_sesiones','Ver sesiones activas de la cuenta'),
('seguridad','revocar_sesiones','Revocar sesiones de usuarios de la cuenta'),
('seguridad','ver_auditoria','Ver la auditoria de seguridad'),
('usuarios','restablecer_password','Restablecer credenciales de empleados')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);

INSERT IGNORE INTO rol_permiso (id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso
FROM rol r
JOIN permiso p ON (p.modulo='seguridad' AND p.accion IN ('ver_sesiones','revocar_sesiones','ver_auditoria'))
                 OR (p.modulo='usuarios' AND p.accion='restablecer_password')
WHERE r.nombre='Administrador' AND r.activo=1;

COMMIT;
