CREATE TABLE portal_users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  normalized_email VARCHAR(190) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  user_type ENUM('customer','operator') NOT NULL DEFAULT 'customer',
  status ENUM('pending_verification','active','locked','disabled') NOT NULL DEFAULT 'pending_verification',
  email_verified_at TIMESTAMP(6) NULL,
  password_changed_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_login_at TIMESTAMP(6) NULL,
  failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until TIMESTAMP(6) NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  mfa_required BOOLEAN NOT NULL DEFAULT FALSE,
  mfa_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  mfa_secret_ciphertext TEXT NULL,
  mfa_recovery_hashes JSON NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_portal_user_email (normalized_email),
  INDEX idx_portal_user_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE portal_email_tokens (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  token_type ENUM('verify_email','reset_password') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP(6) NOT NULL,
  used_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_email_token_user FOREIGN KEY (user_id) REFERENCES portal_users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_email_token_hash (token_hash),
  INDEX idx_email_token_user_type (user_id, token_type, expires_at)
) ENGINE=InnoDB;

CREATE TABLE portal_sessions (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  session_token_hash CHAR(64) NOT NULL,
  csrf_token_hash CHAR(64) NOT NULL,
  session_version INT UNSIGNED NOT NULL,
  auth_level ENUM('password','mfa') NOT NULL DEFAULT 'password',
  ip_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP(6) NOT NULL,
  idle_expires_at TIMESTAMP(6) NOT NULL,
  last_seen_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at TIMESTAMP(6) NULL,
  revoke_reason VARCHAR(80) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_portal_session_user FOREIGN KEY (user_id) REFERENCES portal_users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_portal_session_token (session_token_hash),
  INDEX idx_portal_session_user (user_id, revoked_at, expires_at),
  INDEX idx_portal_session_expiry (idle_expires_at)
) ENGINE=InnoDB;

CREATE TABLE organizations (
  id CHAR(36) PRIMARY KEY,
  slug VARCHAR(80) NOT NULL,
  legal_name VARCHAR(180) NOT NULL,
  trading_name VARCHAR(180) NULL,
  tax_id VARCHAR(50) NULL,
  country CHAR(2) NOT NULL,
  city VARCHAR(100) NOT NULL,
  status ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
  created_by CHAR(36) NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_organization_creator FOREIGN KEY (created_by) REFERENCES portal_users(id),
  UNIQUE KEY uq_organization_slug (slug),
  UNIQUE KEY uq_organization_tax_country (country, tax_id),
  INDEX idx_organization_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE organization_memberships (
  organization_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  role ENUM('owner','admin','billing','member') NOT NULL,
  status ENUM('active','invited','revoked') NOT NULL DEFAULT 'active',
  joined_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (organization_id, user_id),
  CONSTRAINT fk_membership_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_membership_user FOREIGN KEY (user_id) REFERENCES portal_users(id) ON DELETE CASCADE,
  INDEX idx_membership_user_status (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE organization_billing_profiles (
  organization_id CHAR(36) PRIMARY KEY,
  billing_email VARCHAR(190) NOT NULL,
  legal_name VARCHAR(180) NOT NULL,
  tax_id VARCHAR(50) NULL,
  address_line VARCHAR(220) NOT NULL,
  city VARCHAR(100) NOT NULL,
  region VARCHAR(100) NULL,
  postal_code VARCHAR(20) NULL,
  country CHAR(2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CLP',
  updated_by CHAR(36) NOT NULL,
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_billing_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_updater FOREIGN KEY (updated_by) REFERENCES portal_users(id)
) ENGINE=InnoDB;

CREATE TABLE portal_consents (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  consent_type ENUM('terms','privacy','marketing') NOT NULL,
  document_version VARCHAR(40) NOT NULL,
  granted BOOLEAN NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_consent_user FOREIGN KEY (user_id) REFERENCES portal_users(id) ON DELETE CASCADE,
  INDEX idx_consent_user_type (user_id, consent_type, created_at)
) ENGINE=InnoDB;

CREATE TABLE email_outbox (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NULL,
  recipient VARCHAR(190) NOT NULL,
  template_key VARCHAR(60) NOT NULL,
  encrypted_payload MEDIUMTEXT NOT NULL,
  deduplication_key CHAR(64) NOT NULL,
  status ENUM('pending','processing','sent','failed','dead') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  locked_at TIMESTAMP(6) NULL,
  locked_by VARCHAR(80) NULL,
  provider_message_id VARCHAR(190) NULL,
  last_error_code VARCHAR(80) NULL,
  sent_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_outbox_user FOREIGN KEY (user_id) REFERENCES portal_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_outbox_deduplication (deduplication_key),
  INDEX idx_outbox_dispatch (status, available_at, created_at)
) ENGINE=InnoDB;
