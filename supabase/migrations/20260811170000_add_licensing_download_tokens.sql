CREATE TABLE IF NOT EXISTS licensing.download_tokens (
  id BIGSERIAL PRIMARY KEY,
  token_hash CHAR(64) UNIQUE NOT NULL,
  client_id INTEGER NOT NULL REFERENCES licensing.clients(id) ON DELETE CASCADE,
  license_id INTEGER NOT NULL REFERENCES licensing.licenses(id) ON DELETE CASCADE,
  portal_user_id UUID NULL,
  expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
  used_at TIMESTAMP WITH TIME ZONE NULL,
  ip_hash CHAR(64) NULL,
  user_agent_hash CHAR(64) NULL,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE licensing.download_tokens
  ADD COLUMN IF NOT EXISTS portal_user_id UUID NULL,
  ADD COLUMN IF NOT EXISTS ip_hash CHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS user_agent_hash CHAR(64) NULL;

CREATE INDEX IF NOT EXISTS idx_download_tokens_lookup
  ON licensing.download_tokens (token_hash, expires_at);

CREATE INDEX IF NOT EXISTS idx_download_tokens_license_created
  ON licensing.download_tokens (license_id, created_at DESC);
