import { timingSafeEqual } from "node:crypto";
import { isSameOrigin } from "@/lib/http-security";
import { adminSessionCookie, findAdminSession, opaqueTokenHash, type AdminSession } from "@/modules/admin/infrastructure/mysql-admin-session-repository";

export type AdminSessionAuthResult =
  | { ok: true; actor: string; email?: string; mode: "session" | "bearer"; session?: AdminSession; rawSessionToken?: string }
  | { ok: false; reason: "not_configured" | "unauthorized" | "csrf_rejected" };

function cookieValue(header: string, name: string): string {
  for (const part of header.split(";")) {
    const [key, ...value] = part.trim().split("=");
    if (key === name) return decodeURIComponent(value.join("="));
  }
  return "";
}

function validBearer(request: Request): boolean {
  const expected = process.env.ADMIN_API_TOKEN;
  if (!expected || expected.length < 32) return false;
  const authorization = request.headers.get("authorization") ?? "";
  const provided = authorization.startsWith("Bearer ") ? authorization.slice(7) : "";
  const expectedBytes = Buffer.from(expected);
  const providedBytes = Buffer.from(provided);
  return expectedBytes.length === providedBytes.length && timingSafeEqual(expectedBytes, providedBytes);
}

export async function authenticateAdminSession(request: Request): Promise<AdminSessionAuthResult> {
  if (validBearer(request)) {
    return { ok: true, actor: process.env.ADMIN_ACTOR_ID?.trim().slice(0, 120) || "commercial-admin", mode: "bearer" };
  }
  const rawSessionToken = cookieValue(request.headers.get("cookie") ?? "", adminSessionCookie);
  const session = rawSessionToken ? await findAdminSession(rawSessionToken) : null;
  if (session) return { ok: true, actor: session.actor, email: session.email, mode: "session", session, rawSessionToken };
  const configured = Boolean(process.env.ADMIN_PASSWORD_HASH && process.env.ADMIN_EMAIL);
  return { ok: false, reason: configured || process.env.ADMIN_API_TOKEN ? "unauthorized" : "not_configured" };
}

export async function authenticateAdminSessionMutation(request: Request): Promise<AdminSessionAuthResult> {
  const auth = await authenticateAdminSession(request);
  if (!auth.ok || auth.mode === "bearer") return auth;
  const csrfToken = request.headers.get("x-csrf-token") ?? "";
  if (!isSameOrigin(request) || !csrfToken || opaqueTokenHash(csrfToken) !== auth.session?.csrfTokenHash) {
    return { ok: false, reason: "csrf_rejected" };
  }
  return auth;
}

export function adminSessionTokenFromCookieHeader(header: string | null): string {
  return cookieValue(header ?? "", adminSessionCookie);
}
