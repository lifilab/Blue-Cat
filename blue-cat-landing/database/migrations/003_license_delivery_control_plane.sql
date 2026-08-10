USE blue_cat_commercial;

ALTER TABLE customers
  ADD COLUMN status ENUM('active','blocked') NOT NULL DEFAULT 'active' AFTER tax_id,
  ADD COLUMN email_verified_at TIMESTAMP(6) NULL AFTER status,
  ADD COLUMN notes VARCHAR(2000) NULL AFTER email_verified_at,
  ADD INDEX idx_customers_status_created (status, created_at);

CREATE TABLE admin_sessions (
  id CHAR(36) PRIMARY KEY,
  actor_id VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  csrf_token_hash CHAR(64) NOT NULL,
  client_key_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP(6) NOT NULL,
  last_seen_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX idx_admin_session_active (token_hash, expires_at, revoked_at),
  INDEX idx_admin_session_expiry (expires_at)
) ENGINE=InnoDB;

CREATE TABLE customer_email_verifications (
  id CHAR(36) PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at TIMESTAMP(6) NOT NULL,
  consumed_at TIMESTAMP(6) NULL,
  created_by VARCHAR(120) NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_email_verification_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_email_verification_customer (customer_id, consumed_at, expires_at)
) ENGINE=InnoDB;

CREATE TABLE product_artifacts (
  id CHAR(36) PRIMARY KEY,
  product_code VARCHAR(60) NOT NULL DEFAULT 'blue-cat-erp',
  version VARCHAR(40) NOT NULL,
  channel ENUM('stable','preview','hotfix') NOT NULL DEFAULT 'stable',
  original_name VARCHAR(255) NOT NULL,
  storage_key VARCHAR(180) NOT NULL UNIQUE,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  release_notes VARCHAR(4000) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_by VARCHAR(120) NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_product_artifact_version (product_code, version, channel, sha256),
  INDEX idx_product_artifact_active (is_active, created_at)
) ENGINE=InnoDB;

CREATE TABLE licenses (
  id CHAR(36) PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  purchase_request_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  artifact_id CHAR(36) NULL,
  plan_id ENUM('pyme','enterprise') NOT NULL,
  status ENUM('issued','active','suspended','revoked') NOT NULL DEFAULT 'issued',
  activation_code_hash CHAR(64) NOT NULL UNIQUE,
  activation_code_last4 CHAR(4) NOT NULL,
  activation_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_activations SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  installation_id CHAR(36) NULL,
  device_public_key TEXT NULL,
  device_key_fingerprint CHAR(64) NULL,
  lease_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  offline_grace_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
  activated_at TIMESTAMP(6) NULL,
  last_lease_at TIMESTAMP(6) NULL,
  revoked_at TIMESTAMP(6) NULL,
  revoke_reason VARCHAR(500) NULL,
  issued_by VARCHAR(120) NOT NULL,
  issued_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  version INT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_license_purchase FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id),
  CONSTRAINT fk_license_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_license_artifact FOREIGN KEY (artifact_id) REFERENCES product_artifacts(id),
  CONSTRAINT chk_license_activation_limit CHECK (max_activations > 0),
  CONSTRAINT chk_license_lease_minutes CHECK (lease_minutes BETWEEN 5 AND 1440),
  UNIQUE KEY uq_license_purchase (purchase_request_id),
  INDEX idx_license_customer_status (customer_id, status, issued_at)
) ENGINE=InnoDB;

CREATE TABLE license_challenges (
  id CHAR(36) PRIMARY KEY,
  license_id CHAR(36) NOT NULL,
  nonce_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP(6) NOT NULL,
  consumed_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_license_challenge_license FOREIGN KEY (license_id) REFERENCES licenses(id),
  INDEX idx_license_challenge_active (license_id, consumed_at, expires_at)
) ENGINE=InnoDB;

CREATE TABLE license_leases (
  id CHAR(36) PRIMARY KEY,
  license_id CHAR(36) NOT NULL,
  installation_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  key_id VARCHAR(60) NOT NULL,
  issued_at TIMESTAMP(6) NOT NULL,
  expires_at TIMESTAMP(6) NOT NULL,
  grace_until TIMESTAMP(6) NOT NULL,
  revoked_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_license_lease_license FOREIGN KEY (license_id) REFERENCES licenses(id),
  INDEX idx_license_lease_active (license_id, installation_id, expires_at, revoked_at)
) ENGINE=InnoDB;

CREATE TABLE download_grants (
  id CHAR(36) PRIMARY KEY,
  license_id CHAR(36) NOT NULL,
  artifact_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at TIMESTAMP(6) NOT NULL,
  max_downloads SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  download_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_downloaded_at TIMESTAMP(6) NULL,
  revoked_at TIMESTAMP(6) NULL,
  created_by VARCHAR(120) NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_download_grant_license FOREIGN KEY (license_id) REFERENCES licenses(id),
  CONSTRAINT fk_download_grant_artifact FOREIGN KEY (artifact_id) REFERENCES product_artifacts(id),
  CONSTRAINT chk_download_grant_limit CHECK (max_downloads > 0),
  INDEX idx_download_grant_active (license_id, expires_at, revoked_at)
) ENGINE=InnoDB;
