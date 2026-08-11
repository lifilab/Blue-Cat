-- ==============================================================================
-- UNIFIED SUPABASE INITIALIZATION SCRIPT (POSTGRESQL)
-- For Blue Cat Landing and License Validation Admin Panel
-- ==============================================================================

-- ------------------------------------------------------------------------------
-- 0. CLEANUP SCHEMAS
-- ------------------------------------------------------------------------------
DROP SCHEMA IF EXISTS landing CASCADE;
DROP SCHEMA IF EXISTS licensing CASCADE;

-- ------------------------------------------------------------------------------
-- 1. SCHEMA: landing (For next.js Landing page and Portal)
-- ------------------------------------------------------------------------------
CREATE SCHEMA IF NOT EXISTS landing;
SET search_path TO landing;


-- Trigger function for ON UPDATE CURRENT_TIMESTAMP
CREATE OR REPLACE FUNCTION update_modified_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE 'plpgsql';

CREATE TABLE customers (
  id BIGSERIAL PRIMARY KEY,
  business_name VARCHAR(160) NOT NULL,
  contact_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  country VARCHAR(80) NOT NULL,
  city VARCHAR(100) NOT NULL,
  tax_id VARCHAR(50) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'blocked')),
  email_verified_at TIMESTAMP WITH TIME ZONE NULL,
  notes VARCHAR(2000) NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_customers_email ON customers (email);
CREATE INDEX idx_customers_status_created ON customers (status, created_at);

CREATE TABLE purchase_requests (
  id BIGSERIAL PRIMARY KEY,
  tracking_id VARCHAR(40) NOT NULL UNIQUE,
  customer_id BIGINT NOT NULL REFERENCES customers(id),
  plan_id VARCHAR(20) NOT NULL CHECK (plan_id IN ('pyme', 'enterprise')),
  estimated_branches SMALLINT NOT NULL,
  wants_cloud_sync BOOLEAN NOT NULL DEFAULT FALSE,
  message TEXT NULL,
  status VARCHAR(30) NOT NULL CHECK (status IN ('draft', 'pending_quote', 'pending_payment', 'payment_reported', 'under_review', 'approved', 'rejected', 'license_generated', 'download_available', 'completed', 'cancelled')),
  request_hash CHAR(64) NOT NULL UNIQUE,
  idempotency_key_hash CHAR(64) NULL UNIQUE,
  tracking_token_hash CHAR(64) NULL UNIQUE,
  tracking_token_expires_at TIMESTAMP WITH TIME ZONE NULL,
  expected_amount_minor BIGINT NULL,
  currency CHAR(3) NULL,
  offer_version VARCHAR(40) NULL,
  offer_expires_at TIMESTAMP WITH TIME ZONE NULL,
  terms_version VARCHAR(40) NOT NULL DEFAULT 'draft-2026-01',
  privacy_version VARCHAR(40) NOT NULL DEFAULT 'draft-2026-01',
  consented_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_changed_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_purchase_status_created ON purchase_requests (status, created_at);
CREATE TRIGGER update_purchase_requests_modtime BEFORE UPDATE ON purchase_requests FOR EACH ROW EXECUTE FUNCTION update_modified_column();

CREATE TABLE audit_events (
  id BIGSERIAL PRIMARY KEY,
  request_id CHAR(36) NOT NULL,
  aggregate_type VARCHAR(50) NOT NULL,
  aggregate_id VARCHAR(64) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  metadata_json JSONB NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_audit_aggregate ON audit_events (aggregate_type, aggregate_id, created_at);

CREATE TABLE payment_reports (
  id UUID PRIMARY KEY,
  purchase_request_id BIGINT NOT NULL REFERENCES purchase_requests(id),
  amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
  currency CHAR(3) NOT NULL,
  transfer_date DATE NOT NULL,
  bank_reference VARCHAR(120) NOT NULL,
  evidence_storage_key VARCHAR(180) NOT NULL UNIQUE,
  evidence_original_name VARCHAR(255) NOT NULL,
  evidence_mime_type VARCHAR(80) NOT NULL,
  evidence_size_bytes INT NOT NULL,
  evidence_sha256 CHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'reported' CHECK (status IN ('reported', 'under_review', 'approved', 'rejected')),
  reviewed_by VARCHAR(120) NULL,
  review_note VARCHAR(1000) NULL,
  reviewed_at TIMESTAMP WITH TIME ZONE NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (purchase_request_id, evidence_sha256)
);
CREATE INDEX idx_payment_status_created ON payment_reports (status, created_at);
CREATE TRIGGER update_payment_reports_modtime BEFORE UPDATE ON payment_reports FOR EACH ROW EXECUTE FUNCTION update_modified_column();

CREATE TABLE api_rate_limits (
  scope VARCHAR(60) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  request_count INT NOT NULL DEFAULT 1,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  PRIMARY KEY (scope, key_hash)
);
CREATE INDEX idx_rate_limit_expiry ON api_rate_limits (expires_at);

CREATE TABLE portal_users (
  id UUID PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  normalized_email VARCHAR(190) NOT NULL UNIQUE,
  display_name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  user_type VARCHAR(20) NOT NULL DEFAULT 'customer' CHECK (user_type IN ('customer', 'operator')),
  status VARCHAR(25) NOT NULL DEFAULT 'pending_verification' CHECK (status IN ('pending_verification', 'active', 'locked', 'disabled')),
  email_verified_at TIMESTAMP WITH TIME ZONE NULL,
  password_changed_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP WITH TIME ZONE NULL,
  failed_login_count SMALLINT NOT NULL DEFAULT 0,
  locked_until TIMESTAMP WITH TIME ZONE NULL,
  session_version INT NOT NULL DEFAULT 1,
  mfa_required BOOLEAN NOT NULL DEFAULT FALSE,
  mfa_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  mfa_secret_ciphertext TEXT NULL,
  mfa_recovery_hashes JSONB NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_portal_user_status ON portal_users (status, created_at);
CREATE TRIGGER update_portal_users_modtime BEFORE UPDATE ON portal_users FOR EACH ROW EXECUTE FUNCTION update_modified_column();

CREATE TABLE portal_email_tokens (
  id UUID PRIMARY KEY,
  user_id UUID NOT NULL REFERENCES portal_users(id) ON DELETE CASCADE,
  token_type VARCHAR(30) NOT NULL CHECK (token_type IN ('verify_email', 'reset_password')),
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  used_at TIMESTAMP WITH TIME ZONE NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_email_token_user_type ON portal_email_tokens (user_id, token_type, expires_at);

CREATE TABLE portal_sessions (
  id UUID PRIMARY KEY,
  user_id UUID NOT NULL REFERENCES portal_users(id) ON DELETE CASCADE,
  session_token_hash CHAR(64) NOT NULL UNIQUE,
  csrf_token_hash CHAR(64) NOT NULL,
  session_version INT NOT NULL,
  auth_level VARCHAR(20) NOT NULL DEFAULT 'password' CHECK (auth_level IN ('password', 'mfa')),
  ip_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  idle_expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  last_seen_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at TIMESTAMP WITH TIME ZONE NULL,
  revoke_reason VARCHAR(80) NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_portal_session_user ON portal_sessions (user_id, revoked_at, expires_at);
CREATE INDEX idx_portal_session_expiry ON portal_sessions (idle_expires_at);

CREATE TABLE organizations (
  id UUID PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  legal_name VARCHAR(180) NOT NULL,
  trading_name VARCHAR(180) NULL,
  tax_id VARCHAR(50) NULL,
  country CHAR(2) NOT NULL,
  city VARCHAR(100) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'closed')),
  created_by UUID NOT NULL REFERENCES portal_users(id),
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (country, tax_id)
);
CREATE INDEX idx_organization_status ON organizations (status, created_at);
CREATE TRIGGER update_organizations_modtime BEFORE UPDATE ON organizations FOR EACH ROW EXECUTE FUNCTION update_modified_column();

CREATE TABLE organization_memberships (
  organization_id UUID NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
  user_id UUID NOT NULL REFERENCES portal_users(id) ON DELETE CASCADE,
  role VARCHAR(20) NOT NULL CHECK (role IN ('owner', 'admin', 'billing', 'member')),
  status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'invited', 'revoked')),
  joined_at TIMESTAMP WITH TIME ZONE NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (organization_id, user_id)
);
CREATE INDEX idx_membership_user_status ON organization_memberships (user_id, status);

CREATE TABLE organization_billing_profiles (
  organization_id UUID PRIMARY KEY REFERENCES organizations(id) ON DELETE CASCADE,
  billing_email VARCHAR(190) NOT NULL,
  legal_name VARCHAR(180) NOT NULL,
  tax_id VARCHAR(50) NULL,
  address_line VARCHAR(220) NOT NULL,
  city VARCHAR(100) NOT NULL,
  region VARCHAR(100) NULL,
  postal_code VARCHAR(20) NULL,
  country CHAR(2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CLP',
  updated_by UUID NOT NULL REFERENCES portal_users(id),
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TRIGGER update_organization_billing_profiles_modtime BEFORE UPDATE ON organization_billing_profiles FOR EACH ROW EXECUTE FUNCTION update_modified_column();

CREATE TABLE portal_consents (
  id UUID PRIMARY KEY,
  user_id UUID NOT NULL REFERENCES portal_users(id) ON DELETE CASCADE,
  consent_type VARCHAR(20) NOT NULL CHECK (consent_type IN ('terms', 'privacy', 'marketing')),
  document_version VARCHAR(40) NOT NULL,
  granted BOOLEAN NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_consent_user_type ON portal_consents (user_id, consent_type, created_at);

CREATE TABLE email_outbox (
  id UUID PRIMARY KEY,
  user_id UUID NULL REFERENCES portal_users(id) ON DELETE SET NULL,
  recipient VARCHAR(190) NOT NULL,
  template_key VARCHAR(60) NOT NULL,
  encrypted_payload TEXT NOT NULL,
  deduplication_key CHAR(64) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'sent', 'failed', 'dead')),
  attempts SMALLINT NOT NULL DEFAULT 0,
  available_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at TIMESTAMP WITH TIME ZONE NULL,
  locked_by VARCHAR(80) NULL,
  provider_message_id VARCHAR(190) NULL,
  last_error_code VARCHAR(80) NULL,
  sent_at TIMESTAMP WITH TIME ZONE NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_outbox_dispatch ON email_outbox (status, available_at, created_at);
CREATE TRIGGER update_email_outbox_modtime BEFORE UPDATE ON email_outbox FOR EACH ROW EXECUTE FUNCTION update_modified_column();

CREATE TABLE admin_sessions (
  id UUID PRIMARY KEY,
  actor_id VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  csrf_token_hash CHAR(64) NOT NULL,
  client_key_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  last_seen_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at TIMESTAMP WITH TIME ZONE NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_admin_session_active ON admin_sessions (token_hash, expires_at, revoked_at);
CREATE INDEX idx_admin_session_expiry ON admin_sessions (expires_at);

CREATE TABLE customer_email_verifications (
  id UUID PRIMARY KEY,
  customer_id BIGINT NOT NULL REFERENCES customers(id),
  email VARCHAR(190) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  consumed_at TIMESTAMP WITH TIME ZONE NULL,
  created_by VARCHAR(120) NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_email_verification_customer ON customer_email_verifications (customer_id, consumed_at, expires_at);

CREATE TABLE product_artifacts (
  id UUID PRIMARY KEY,
  product_code VARCHAR(60) NOT NULL DEFAULT 'blue-cat-erp',
  version VARCHAR(40) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'stable' CHECK (channel IN ('stable', 'preview', 'hotfix')),
  original_name VARCHAR(255) NOT NULL,
  storage_key VARCHAR(180) NOT NULL UNIQUE,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes BIGINT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  release_notes VARCHAR(4000) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_by VARCHAR(120) NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_product_artifact_active ON product_artifacts (is_active, created_at);

CREATE TABLE licenses (
  id UUID PRIMARY KEY,
  public_id VARCHAR(40) NOT NULL UNIQUE,
  purchase_request_id BIGINT NOT NULL REFERENCES purchase_requests(id),
  customer_id BIGINT NOT NULL REFERENCES customers(id),
  artifact_id UUID NULL REFERENCES product_artifacts(id),
  plan_id VARCHAR(20) NOT NULL CHECK (plan_id IN ('pyme', 'enterprise')),
  status VARCHAR(20) NOT NULL DEFAULT 'issued' CHECK (status IN ('issued', 'active', 'suspended', 'revoked')),
  activation_code_hash CHAR(64) NOT NULL UNIQUE,
  activation_code_last4 CHAR(4) NOT NULL,
  activation_count SMALLINT NOT NULL DEFAULT 0,
  max_activations SMALLINT NOT NULL DEFAULT 1,
  installation_id UUID NULL,
  device_public_key TEXT NULL,
  device_key_fingerprint CHAR(64) NULL,
  lease_minutes SMALLINT NOT NULL DEFAULT 30,
  offline_grace_minutes INT NOT NULL DEFAULT 1440,
  activated_at TIMESTAMP WITH TIME ZONE NULL,
  last_lease_at TIMESTAMP WITH TIME ZONE NULL,
  revoked_at TIMESTAMP WITH TIME ZONE NULL,
  revoke_reason VARCHAR(500) NULL,
  issued_by VARCHAR(120) NOT NULL,
  issued_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  version INT NOT NULL DEFAULT 1,
  UNIQUE (purchase_request_id),
  CONSTRAINT chk_license_activation_limit CHECK (max_activations > 0),
  CONSTRAINT chk_license_lease_minutes CHECK (lease_minutes BETWEEN 5 AND 1440)
);
CREATE INDEX idx_license_customer_status ON licenses (customer_id, status, issued_at);
CREATE TRIGGER update_licenses_modtime BEFORE UPDATE ON licenses FOR EACH ROW EXECUTE FUNCTION update_modified_column();

CREATE TABLE license_challenges (
  id UUID PRIMARY KEY,
  license_id UUID NOT NULL REFERENCES licenses(id),
  nonce_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  consumed_at TIMESTAMP WITH TIME ZONE NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_license_challenge_active ON license_challenges (license_id, consumed_at, expires_at);

CREATE TABLE license_leases (
  id UUID PRIMARY KEY,
  license_id UUID NOT NULL REFERENCES licenses(id),
  installation_id UUID NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  key_id VARCHAR(60) NOT NULL,
  issued_at TIMESTAMP WITH TIME ZONE NOT NULL,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  grace_until TIMESTAMP WITH TIME ZONE NOT NULL,
  revoked_at TIMESTAMP WITH TIME ZONE NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_license_lease_active ON license_leases (license_id, installation_id, expires_at, revoked_at);

CREATE TABLE download_grants (
  id UUID PRIMARY KEY,
  license_id UUID NOT NULL REFERENCES licenses(id),
  artifact_id UUID NOT NULL REFERENCES product_artifacts(id),
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  max_downloads SMALLINT NOT NULL DEFAULT 1,
  download_count SMALLINT NOT NULL DEFAULT 0,
  last_downloaded_at TIMESTAMP WITH TIME ZONE NULL,
  revoked_at TIMESTAMP WITH TIME ZONE NULL,
  created_by VARCHAR(120) NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_download_grant_limit CHECK (max_downloads > 0)
);
CREATE INDEX idx_download_grant_active ON download_grants (license_id, expires_at, revoked_at);


-- ------------------------------------------------------------------------------
-- 2. SCHEMA: licensing (For license validation admin panel server)
-- ------------------------------------------------------------------------------
CREATE SCHEMA IF NOT EXISTS licensing;
SET search_path TO licensing;

CREATE TABLE admins (
  id SERIAL PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE clients (
  id SERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(190) UNIQUE NOT NULL,
  phone VARCHAR(50),
  payment_reference VARCHAR(150),
  notes TEXT,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE licenses (
  id SERIAL PRIMARY KEY,
  client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
  email VARCHAR(190) NOT NULL,
  license_key VARCHAR(100) UNIQUE NOT NULL,
  status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'revoked')),
  hwid VARCHAR(255) DEFAULT NULL,
  allow_hwid_change INTEGER DEFAULT 0,
  max_sessions INTEGER DEFAULT 1,
  expires_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
  last_token VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sessions (
  id SERIAL PRIMARY KEY,
  license_id INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
  session_token VARCHAR(255) UNIQUE NOT NULL,
  ip_address VARCHAR(100),
  hwid VARCHAR(255),
  last_heartbeat TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE settings (
  key VARCHAR(100) PRIMARY KEY,
  value TEXT
);
