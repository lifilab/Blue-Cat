USE blue_cat_commercial;

ALTER TABLE purchase_requests
  DROP INDEX request_hash,
  ADD COLUMN idempotency_key_hash CHAR(64) NULL AFTER request_hash,
  ADD COLUMN tracking_token_hash CHAR(64) NULL AFTER idempotency_key_hash,
  ADD COLUMN tracking_token_expires_at TIMESTAMP(6) NULL AFTER tracking_token_hash,
  ADD COLUMN expected_amount_minor BIGINT UNSIGNED NULL AFTER tracking_token_expires_at,
  ADD COLUMN currency CHAR(3) NULL AFTER expected_amount_minor,
  ADD COLUMN offer_version VARCHAR(40) NULL AFTER currency,
  ADD COLUMN offer_expires_at TIMESTAMP(6) NULL AFTER offer_version,
  ADD COLUMN terms_version VARCHAR(40) NOT NULL DEFAULT 'draft-2026-01' AFTER offer_expires_at,
  ADD COLUMN privacy_version VARCHAR(40) NOT NULL DEFAULT 'draft-2026-01' AFTER terms_version,
  ADD COLUMN consented_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER privacy_version,
  ADD COLUMN status_changed_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER consented_at,
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status_changed_at,
  MODIFY COLUMN status ENUM('draft','pending_quote','pending_payment','payment_reported','under_review','approved','rejected','license_generated','download_available','completed','cancelled') NOT NULL,
  ADD UNIQUE KEY uq_purchase_idempotency (idempotency_key_hash),
  ADD UNIQUE KEY uq_purchase_tracking_token (tracking_token_hash);

CREATE TABLE payment_reports (
  id CHAR(36) PRIMARY KEY,
  purchase_request_id BIGINT UNSIGNED NOT NULL,
  amount_minor BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  transfer_date DATE NOT NULL,
  bank_reference VARCHAR(120) NOT NULL,
  evidence_storage_key VARCHAR(180) NOT NULL UNIQUE,
  evidence_original_name VARCHAR(255) NOT NULL,
  evidence_mime_type VARCHAR(80) NOT NULL,
  evidence_size_bytes INT UNSIGNED NOT NULL,
  evidence_sha256 CHAR(64) NOT NULL,
  status ENUM('reported','under_review','approved','rejected') NOT NULL DEFAULT 'reported',
  reviewed_by VARCHAR(120) NULL,
  review_note VARCHAR(1000) NULL,
  reviewed_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_payment_purchase FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id),
  CONSTRAINT chk_payment_amount CHECK (amount_minor > 0),
  UNIQUE KEY uq_payment_purchase_hash (purchase_request_id, evidence_sha256),
  INDEX idx_payment_status_created (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE api_rate_limits (
  scope VARCHAR(60) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 1,
  expires_at TIMESTAMP(6) NOT NULL,
  PRIMARY KEY (scope, key_hash),
  INDEX idx_rate_limit_expiry (expires_at)
) ENGINE=InnoDB;
