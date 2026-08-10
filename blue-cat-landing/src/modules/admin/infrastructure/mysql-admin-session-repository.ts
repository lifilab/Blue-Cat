import { createHash, randomBytes, randomUUID } from "node:crypto";
import { getPool } from "@/infrastructure/database/postgres";

export const adminSessionCookie = "bluecat_admin_session";
export const adminCsrfCookie = "bluecat_admin_csrf";

export interface AdminSession {
  id: string;
  actor: string;
  email: string;
  csrfTokenHash: string;
  expiresAt: Date;
}

interface AdminSessionRow {
  id: string;
  actor_id: string;
  email: string;
  csrf_token_hash: string;
  expires_at: Date;
}

export function opaqueTokenHash(value: string): string {
  return createHash("sha256").update(value).digest("hex");
}

export async function createAdminSession(input: { actor: string; email: string; clientKey: string }): Promise<{ token: string; csrfToken: string; expiresAt: Date }> {
  const token = randomBytes(32).toString("base64url");
  const csrfToken = randomBytes(32).toString("base64url");
  const configuredHours = Number.parseInt(process.env.ADMIN_SESSION_HOURS ?? "12", 10);
  const hours = Number.isFinite(configuredHours) ? Math.min(24, Math.max(1, configuredHours)) : 12;
  const expiresAt = new Date(Date.now() + hours * 3_600_000);
  await getPool().query(
    "INSERT INTO admin_sessions (id, actor_id, email, token_hash, csrf_token_hash, client_key_hash, expires_at) VALUES ($1, $2, $3, $4, $5, $6, $7)",
    [randomUUID(), input.actor, input.email, opaqueTokenHash(token), opaqueTokenHash(csrfToken), opaqueTokenHash(input.clientKey), expiresAt],
  );
  return { token, csrfToken, expiresAt };
}

export async function findAdminSession(rawToken: string): Promise<AdminSession | null> {
  if (!/^[A-Za-z0-9_-]{40,60}$/.test(rawToken)) return null;
  const result = await getPool().query<AdminSessionRow>(
    "SELECT id, actor_id, email, csrf_token_hash, expires_at FROM admin_sessions WHERE token_hash = $1 AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP LIMIT 1",
    [opaqueTokenHash(rawToken)],
  );
  const row = result.rows[0];
  if (!row) return null;
  void getPool().query("UPDATE admin_sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = $1", [row.id]).catch(() => undefined);
  return { id: row.id, actor: row.actor_id, email: row.email, csrfTokenHash: row.csrf_token_hash, expiresAt: row.expires_at };
}

export async function revokeAdminSession(rawToken: string): Promise<void> {
  if (!rawToken) return;
  await getPool().query("UPDATE admin_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE token_hash = $1 AND revoked_at IS NULL", [opaqueTokenHash(rawToken)]);
}

