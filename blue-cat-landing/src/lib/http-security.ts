import { createHash } from "node:crypto";
import type { Pool } from "mysql2/promise";
import { getPool } from "@/infrastructure/database/mysql";

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
  await pool.execute(
    "INSERT INTO api_rate_limits (scope, key_hash, request_count, expires_at) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE request_count = request_count + 1",
    [scope, keyHash, expiresAt],
  );
  const [rows] = await pool.query<Array<{ request_count: number } & import("mysql2").RowDataPacket>>(
    "SELECT request_count FROM api_rate_limits WHERE scope = ? AND key_hash = ? LIMIT 1",
    [scope, keyHash],
  );
  return (rows[0]?.request_count ?? maximum + 1) <= maximum;
}
