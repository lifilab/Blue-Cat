-- Phase 4: durable marker for the Windows server bootstrap and repair flow.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS core_installation (
  id_installation TINYINT UNSIGNED NOT NULL DEFAULT 1,
  id_cuenta INT NOT NULL,
  id_user_admin INT NOT NULL,
  installation_id CHAR(36) NOT NULL,
  installed_version VARCHAR(40) NOT NULL,
  setup_completed TINYINT(1) NOT NULL DEFAULT 0,
  installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_installation),
  UNIQUE KEY uq_core_installation_uuid (installation_id),
  CONSTRAINT chk_core_installation_singleton CHECK (id_installation=1),
  CONSTRAINT fk_core_installation_cuenta FOREIGN KEY (id_cuenta) REFERENCES cuenta(id_cuenta) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_core_installation_admin FOREIGN KEY (id_user_admin) REFERENCES usuario(id_user) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
