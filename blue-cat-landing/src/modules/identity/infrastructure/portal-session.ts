import { randomUUID, timingSafeEqual } from "node:crypto";
import { NextResponse } from "next/server";
import { getPool } from "@/infrastructure/database/postgres";
import { hashToken, privacyHash, randomToken } from "../domain/secure-values";

export const sessionCookieName = "bc_portal_session";
export const csrfCookieName = "bc_portal_csrf";

interface SessionRow {
  session_id: string;
  user_id: string;
  email: string;
  display_name: string;
  user_type: "customer" | "operator";
  user_status: "active" | "pending_verification" | "locked" | "disabled";
  email_verified_at: Date | null;
  mfa_required: boolean;
  mfa_enabled: boolean;
  auth_level: "password" | "mfa";
  csrf_token_hash: string;
}

export interface PortalPrincipal {
  sessionId: string;
  userId: string;
  email: string;
  displayName: string;
  userType: "customer" | "operator";
  emailVerified: boolean;
  mfaRequired: boolean;
  mfaEnabled: boolean;
  authLevel: "password" | "mfa";
  csrfTokenHash: string;
}

export interface IssuedPortalSession {
  sessionToken: string;
  csrfToken: string;
  expiresAt: Date;
}

export async function issuePortalSession(
  user: { id: string; sessionVersion: number; userType: "customer" | "operator" },
  request: Request,
  authLevel: "password" | "mfa",
): Promise<IssuedPortalSession> {
  const sessionToken = randomToken();
  const csrfToken = randomToken();
  const expiresAt = new Date(Date.now() + (user.userType === "operator" ? 8 : 12) * 60 * 60 * 1000);
  const idleExpiresAt = new Date(Math.min(expiresAt.getTime(), Date.now() + 30 * 60 * 1000));
  const ip = clientAddress(request);
  const agent = request.headers.get("user-agent")?.slice(0, 512) ?? "unknown";
  
  await getPool().query(
    `INSERT INTO landing.portal_sessions
      (id,user_id,session_token_hash,csrf_token_hash,session_version,auth_level,ip_hash,user_agent_hash,expires_at,idle_expires_at)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)`,
    [
      randomUUID(),
      user.id,
      hashToken(sessionToken),
      hashToken(csrfToken),
      user.sessionVersion,
      authLevel,
      privacyHash(ip),
      privacyHash(agent),
      expiresAt,
      idleExpiresAt,
    ],
  );
  return { sessionToken, csrfToken, expiresAt };
}

export async function authenticatePortalRequest(request: Request): Promise<PortalPrincipal | null> {
  const token = readCookie(request, sessionCookieName);
  if (!token) return null;
  
  const result = await getPool().query<SessionRow>(
    `SELECT s.id session_id,s.user_id,u.email,u.display_name,u.user_type,u.status user_status,
      u.email_verified_at,u.mfa_required,u.mfa_enabled,s.auth_level,s.csrf_token_hash
     FROM landing.portal_sessions s
     INNER JOIN landing.portal_users u ON u.id=s.user_id
     WHERE s.session_token_hash=$1
       AND s.revoked_at IS NULL
       AND s.expires_at>CURRENT_TIMESTAMP
       AND s.idle_expires_at>CURRENT_TIMESTAMP
       AND s.session_version=u.session_version
       AND u.status IN ('active','pending_verification')
     LIMIT 1`,
    [hashToken(token)],
  );
  const row = result.rows[0];
  if (!row) return null;
  
  await getPool().query(
    `UPDATE landing.portal_sessions
     SET last_seen_at=CURRENT_TIMESTAMP,
         idle_expires_at=LEAST(expires_at, CURRENT_TIMESTAMP + INTERVAL '30 minutes')
     WHERE id=$1 AND last_seen_at<CURRENT_TIMESTAMP - INTERVAL '2 minutes'`,
    [row.session_id],
  );
  return {
    sessionId: row.session_id,
    userId: row.user_id,
    email: row.email,
    displayName: row.display_name,
    userType: row.user_type,
    emailVerified: Boolean(row.email_verified_at),
    mfaRequired: Boolean(row.mfa_required),
    mfaEnabled: Boolean(row.mfa_enabled),
    authLevel: row.auth_level,
    csrfTokenHash: row.csrf_token_hash,
  };
}

export function requireRequestCsrf(request: Request, principal: PortalPrincipal): boolean {
  const headerToken = request.headers.get("x-csrf-token") ?? "";
  const cookieToken = readCookie(request, csrfCookieName) ?? "";
  if (!headerToken || !cookieToken || !safeEqual(headerToken, cookieToken)) return false;
  return safeEqual(hashToken(headerToken), principal.csrfTokenHash);
}

export async function revokePortalSession(sessionId: string, reason = "LOGOUT"): Promise<void> {
  await getPool().query(
    "UPDATE landing.portal_sessions SET revoked_at=CURRENT_TIMESTAMP,revoke_reason=$1 WHERE id=$2 AND revoked_at IS NULL",
    [reason, sessionId],
  );
}

export async function revokeAllUserSessions(userId: string, reason: string): Promise<void> {
  await getPool().query(
    "UPDATE landing.portal_sessions SET revoked_at=CURRENT_TIMESTAMP,revoke_reason=$1 WHERE user_id=$2 AND revoked_at IS NULL",
    [reason, userId],
  );
}

export function applyPortalCookies(response: NextResponse, session: IssuedPortalSession): void {
  const secure = cookieSecure();
  const maxAge = Math.max(0, Math.floor((session.expiresAt.getTime() - Date.now()) / 1000));
  response.cookies.set(sessionCookieName, session.sessionToken, {
    httpOnly: true,
    secure,
    sameSite: "strict",
    path: "/",
    maxAge,
  });
  response.cookies.set(csrfCookieName, session.csrfToken, {
    httpOnly: false,
    secure,
    sameSite: "strict",
    path: "/",
    maxAge,
  });
}

export function clearPortalCookies(response: NextResponse): void {
  response.cookies.set(sessionCookieName, "", { httpOnly: true, secure: cookieSecure(), sameSite: "strict", path: "/", maxAge: 0 });
  response.cookies.set(csrfCookieName, "", { httpOnly: false, secure: cookieSecure(), sameSite: "strict", path: "/", maxAge: 0 });
}

export function readCookie(request: Request, name: string): string | null {
  const cookieHeader = request.headers.get("cookie") ?? "";
  for (const part of cookieHeader.split(";")) {
    const separator = part.indexOf("=");
    if (separator < 0) continue;
    const key = part.slice(0, separator).trim();
    if (key === name) return decodeURIComponent(part.slice(separator + 1).trim());
  }
  return null;
}

function safeEqual(left: string, right: string): boolean {
  const leftBytes = Buffer.from(left);
  const rightBytes = Buffer.from(right);
  return leftBytes.length === rightBytes.length && timingSafeEqual(leftBytes, rightBytes);
}

function cookieSecure(): boolean {
  if (process.env.NODE_ENV === "production") return true;
  return (process.env.NEXT_PUBLIC_SITE_URL ?? "").startsWith("https://");
}

function clientAddress(request: Request): string {
  return request.headers.get("x-forwarded-for")?.split(",")[0]?.trim()
    || request.headers.get("x-real-ip")
    || "unknown";
}

