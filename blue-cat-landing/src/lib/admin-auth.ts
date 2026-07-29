import { timingSafeEqual } from "node:crypto";

export type AdminAuthResult = { ok: true; actor: string } | { ok: false; reason: "not_configured" | "unauthorized" };

export function authenticateAdmin(request: Request): AdminAuthResult {
  const expected = process.env.ADMIN_API_TOKEN;
  if (!expected || expected.length < 32) return { ok: false, reason: "not_configured" };
  const authorization = request.headers.get("authorization") ?? "";
  const provided = authorization.startsWith("Bearer ") ? authorization.slice(7) : "";
  const expectedBytes = Buffer.from(expected);
  const providedBytes = Buffer.from(provided);
  if (expectedBytes.length !== providedBytes.length || !timingSafeEqual(expectedBytes, providedBytes)) return { ok: false, reason: "unauthorized" };
  return { ok: true, actor: process.env.ADMIN_ACTOR_ID?.trim().slice(0, 120) || "commercial-admin" };
}
