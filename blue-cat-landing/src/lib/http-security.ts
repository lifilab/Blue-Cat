import { createHash } from "node:crypto";
import { Pool } from "pg";
import { getPool } from "@/infrastructure/database/postgres";

export function isSameOrigin(request: Request): boolean {
  const origin = request.headers.get("origin");
  if (!origin) return false;
  const requestOrigin = new URL(request.url).origin;
  const configuredOrigin = new URL(process.env.NEXT_PUBLIC_SITE_URL ?? requestOrigin).origin;
  return origin === requestOrigin || origin === configuredOrigin;
}

export function clientRateLimitKey(request: Request): string {
  const forwarded = request.headers.get("x-forwarded-for")?.split(",")[0]?.trim();
  const address = forwarded || request.headers.get("x-real-ip") || "unknown";
  const agent = request.headers.get("user-agent")?.slice(0, 160) || "unknown";
  return createHash("sha256").update(`${address}|${agent}`).digest("hex");
}

export async function enforceRateLimit(scope: string, identifier: string, maximum: number, windowSeconds: number, pool: Pool = getPool()): Promise<boolean> {
  const windowNumber = Math.floor(Date.now() / (windowSeconds * 1000));
  const keyHash = createHash("sha256").update(`${identifier}|${windowNumber}`).digest("hex");
  const expiresAt = new Date((windowNumber + 1) * windowSeconds * 1000);
  
  await pool.query(
    "INSERT INTO landing.api_rate_limits (scope, key_hash, request_count, expires_at) VALUES ($1, $2, 1, $3) ON CONFLICT (scope, key_hash) DO UPDATE SET request_count = api_rate_limits.request_count + 1",
    [scope, keyHash, expiresAt],
  );
  
  const result = await pool.query<{ request_count: number }>(
    "SELECT request_count FROM landing.api_rate_limits WHERE scope = $1 AND key_hash = $2 LIMIT 1",
    [scope, keyHash],
  );
  
  return (result.rows[0]?.request_count ?? maximum + 1) <= maximum;
}

