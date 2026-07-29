import { randomUUID } from "node:crypto";
import type { PoolConnection } from "mysql2/promise";
import type { RowDataPacket } from "mysql2";
import { getPool } from "@/infrastructure/database/mysql";
import type { LoginInput, RegisterInput } from "../domain/identity-input";
import { createMfaEnrollment, verifyMfaCode } from "../domain/mfa";
import { hashPassword, verifyPassword } from "../domain/password";
import {
  encryptPrivatePayload,
  hashToken,
  normalizeEmail,
  privacyHash,
  randomToken,
} from "../domain/secure-values";
import {
  issuePortalSession,
  revokeAllUserSessions,
  type IssuedPortalSession,
  type PortalPrincipal,
} from "../infrastructure/portal-session";

const dummyPasswordHash = "$argon2id$v=19$m=19456,t=3,p=1$nDqGZ6jt1eYtS16YD3adVQ$jpbiBdzL3GlcAd7/z4uZ0gpj+GBM8L9h+r9B8TdWhFg";

interface UserRow extends RowDataPacket {
  id: string;
  email: string;
  normalized_email: string;
  display_name: string;
  password_hash: string;
  user_type: "customer" | "operator";
  status: "pending_verification" | "active" | "locked" | "disabled";
  email_verified_at: Date | null;
  failed_login_count: number;
  locked_until: Date | null;
  session_version: number;
  mfa_required: number;
  mfa_enabled: number;
  mfa_secret_ciphertext: string | null;
}

interface TokenRow extends RowDataPacket {
  id: string;
  user_id: string;
  expires_at: Date;
}

export type LoginResult =
  | { status: "authenticated"; session: IssuedPortalSession; requiresMfaEnrollment: boolean; userType: "customer" | "operator" }
  | { status: "mfa_required" }
  | { status: "email_not_verified" }
  | { status: "locked" }
  | { status: "invalid_credentials" };

export async function registerPortalAccount(
  input: RegisterInput,
  request: Request,
  requestId: string,
): Promise<{ accepted: true; verificationToken?: string; userId?: string }> {
  assertCurrentLegalVersions(input.termsVersion, input.privacyVersion);
  const email = normalizeEmail(input.email);
  const passwordHash = await hashPassword(input.password);
  const userId = randomUUID();
  const verificationToken = randomToken();
  const tokenHash = hashToken(verificationToken);
  const expiresAt = new Date(Date.now() + 24 * 60 * 60 * 1000);
  const connection = await getPool().getConnection();
  try {
    await connection.beginTransaction();
    const [existing] = await connection.query<UserRow[]>(
      "SELECT id,status FROM portal_users WHERE normalized_email=? LIMIT 1 FOR UPDATE",
      [email],
    );
    if (existing[0]) {
      await connection.rollback();
      return { accepted: true };
    }
    await connection.execute(
      `INSERT INTO portal_users
        (id,email,normalized_email,display_name,password_hash,user_type,status)
       VALUES (?,?,?,?,?,'customer','pending_verification')`,
      [userId, input.email.trim(), email, input.displayName, passwordHash],
    );
    await recordConsent(connection, userId, "terms", input.termsVersion, true, request);
    await recordConsent(connection, userId, "privacy", input.privacyVersion, true, request);
    await recordConsent(connection, userId, "marketing", marketingVersion(), input.marketingConsent, request);
    await connection.execute(
      `INSERT INTO portal_email_tokens (id,user_id,token_type,token_hash,expires_at)
       VALUES (?,?,'verify_email',?,?)`,
      [randomUUID(), userId, tokenHash, expiresAt],
    );
    await queueEmail(connection, {
      userId,
      recipient: email,
      template: "verify_email",
      tokenHash,
      payload: {
        displayName: input.displayName,
        actionUrl: `${siteUrl()}/verificar-correo#token=${verificationToken}`,
        expiresHours: 24,
      },
    });
    await audit(connection, requestId, "portal_user", userId, "portal_user_registered", {
      userType: "customer",
      termsVersion: input.termsVersion,
      privacyVersion: input.privacyVersion,
    });
    await connection.commit();
    return { accepted: true, verificationToken, userId };
  } catch (error) {
    await connection.rollback();
    if (isDuplicateEntry(error)) return { accepted: true };
    throw error;
  } finally {
    connection.release();
  }
}

export async function resendVerificationEmail(
  emailInput: string,
  password: string,
  requestId: string,
): Promise<{ accepted: true; verificationToken?: string }> {
  const email = normalizeEmail(emailInput);
  const [rows] = await getPool().query<UserRow[]>(
    "SELECT * FROM portal_users WHERE normalized_email=? LIMIT 1",
    [email],
  );
  const user = rows[0];
  const valid = await verifyPassword(user?.password_hash ?? dummyPasswordHash, password);
  if (!user || !valid || user.status !== "pending_verification") return { accepted: true };
  const verificationToken = randomToken();
  const tokenHash = hashToken(verificationToken);
  const connection = await getPool().getConnection();
  try {
    await connection.beginTransaction();
    await connection.execute(
      "UPDATE portal_email_tokens SET used_at=CURRENT_TIMESTAMP(6) WHERE user_id=? AND token_type='verify_email' AND used_at IS NULL",
      [user.id],
    );
    await connection.execute(
      "INSERT INTO portal_email_tokens (id,user_id,token_type,token_hash,expires_at) VALUES (?,?,'verify_email',?,DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL 24 HOUR))",
      [randomUUID(), user.id, tokenHash],
    );
    await queueEmail(connection, {
      userId: user.id,
      recipient: user.email,
      template: "verify_email",
      tokenHash,
      payload: {
        displayName: user.display_name,
        actionUrl: `${siteUrl()}/verificar-correo#token=${verificationToken}`,
        expiresHours: 24,
      },
    });
    await audit(connection, requestId, "portal_user", user.id, "verification_email_reissued", {});
    await connection.commit();
    return { accepted: true, verificationToken };
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

export async function verifyPortalEmail(token: string, requestId: string): Promise<boolean> {
  const connection = await getPool().getConnection();
  try {
    await connection.beginTransaction();
    const [rows] = await connection.query<TokenRow[]>(
      `SELECT id,user_id,expires_at
       FROM portal_email_tokens
       WHERE token_hash=? AND token_type='verify_email' AND used_at IS NULL
       LIMIT 1 FOR UPDATE`,
      [hashToken(token)],
    );
    const row = rows[0];
    if (!row || row.expires_at.getTime() <= Date.now()) {
      await connection.rollback();
      return false;
    }
    await connection.execute("UPDATE portal_email_tokens SET used_at=CURRENT_TIMESTAMP(6) WHERE id=?", [row.id]);
    await connection.execute(
      `UPDATE portal_users
       SET status='active',email_verified_at=COALESCE(email_verified_at,CURRENT_TIMESTAMP(6)),
           failed_login_count=0,locked_until=NULL
       WHERE id=? AND status<>'disabled'`,
      [row.user_id],
    );
    await audit(connection, requestId, "portal_user", row.user_id, "email_verified", {});
    await connection.commit();
    return true;
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

export async function loginPortal(
  input: LoginInput,
  request: Request,
  requestId: string,
): Promise<LoginResult> {
  const email = normalizeEmail(input.email);
  const [rows] = await getPool().query<UserRow[]>(
    "SELECT * FROM portal_users WHERE normalized_email=? LIMIT 1",
    [email],
  );
  const user = rows[0];
  const passwordValid = await verifyPassword(user?.password_hash ?? dummyPasswordHash, input.password);
  if (!user || !passwordValid || user.status === "disabled") {
    if (user) await registerFailedLogin(user, requestId);
    return { status: "invalid_credentials" };
  }
  if (user.locked_until && user.locked_until.getTime() > Date.now()) return { status: "locked" };
  if (user.status === "pending_verification" || !user.email_verified_at) return { status: "email_not_verified" };

  let authLevel: "password" | "mfa" = "password";
  if (user.mfa_enabled) {
    if (!input.totpCode) return { status: "mfa_required" };
    if (!user.mfa_secret_ciphertext || !await verifyMfaCode(user.mfa_secret_ciphertext, input.totpCode)) {
      await registerFailedLogin(user, requestId);
      return { status: "invalid_credentials" };
    }
    authLevel = "mfa";
  }
  await getPool().execute(
    `UPDATE portal_users
     SET failed_login_count=0,locked_until=NULL,status='active',last_login_at=CURRENT_TIMESTAMP(6)
     WHERE id=?`,
    [user.id],
  );
  const session = await issuePortalSession({
    id: user.id,
    sessionVersion: user.session_version,
    userType: user.user_type,
  }, request, authLevel);
  await audit(getPool(), requestId, "portal_user", user.id, "portal_login_succeeded", {
    authLevel,
    userType: user.user_type,
  });
  return {
    status: "authenticated",
    session,
    requiresMfaEnrollment: Boolean(user.mfa_required) && !Boolean(user.mfa_enabled),
    userType: user.user_type,
  };
}

export async function requestPasswordReset(emailInput: string, requestId: string): Promise<{ accepted: true; resetToken?: string }> {
  const email = normalizeEmail(emailInput);
  const [rows] = await getPool().query<UserRow[]>(
    "SELECT * FROM portal_users WHERE normalized_email=? AND status<>'disabled' LIMIT 1",
    [email],
  );
  const user = rows[0];
  if (!user) return { accepted: true };
  const resetToken = randomToken();
  const tokenHash = hashToken(resetToken);
  const connection = await getPool().getConnection();
  try {
    await connection.beginTransaction();
    await connection.execute(
      "UPDATE portal_email_tokens SET used_at=CURRENT_TIMESTAMP(6) WHERE user_id=? AND token_type='reset_password' AND used_at IS NULL",
      [user.id],
    );
    await connection.execute(
      "INSERT INTO portal_email_tokens (id,user_id,token_type,token_hash,expires_at) VALUES (?,?,'reset_password',?,DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL 30 MINUTE))",
      [randomUUID(), user.id, tokenHash],
    );
    await queueEmail(connection, {
      userId: user.id,
      recipient: user.email,
      template: "reset_password",
      tokenHash,
      payload: {
        displayName: user.display_name,
        actionUrl: `${siteUrl()}/restablecer-clave#token=${resetToken}`,
        expiresMinutes: 30,
      },
    });
    await audit(connection, requestId, "portal_user", user.id, "password_reset_requested", {});
    await connection.commit();
    return { accepted: true, resetToken };
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

export async function resetPortalPassword(token: string, password: string, requestId: string): Promise<boolean> {
  const passwordHash = await hashPassword(password);
  const connection = await getPool().getConnection();
  let userId = "";
  try {
    await connection.beginTransaction();
    const [rows] = await connection.query<TokenRow[]>(
      `SELECT id,user_id,expires_at FROM portal_email_tokens
       WHERE token_hash=? AND token_type='reset_password' AND used_at IS NULL
       LIMIT 1 FOR UPDATE`,
      [hashToken(token)],
    );
    const row = rows[0];
    if (!row || row.expires_at.getTime() <= Date.now()) {
      await connection.rollback();
      return false;
    }
    userId = row.user_id;
    await connection.execute("UPDATE portal_email_tokens SET used_at=CURRENT_TIMESTAMP(6) WHERE id=?", [row.id]);
    await connection.execute(
      `UPDATE portal_users
       SET password_hash=?,password_changed_at=CURRENT_TIMESTAMP(6),session_version=session_version+1,
           failed_login_count=0,locked_until=NULL,status=IF(status='disabled','disabled','active')
       WHERE id=?`,
      [passwordHash, userId],
    );
    await connection.execute(
      "UPDATE portal_sessions SET revoked_at=CURRENT_TIMESTAMP(6),revoke_reason='PASSWORD_RESET' WHERE user_id=? AND revoked_at IS NULL",
      [userId],
    );
    await audit(connection, requestId, "portal_user", userId, "password_reset_completed", {});
    await connection.commit();
    return true;
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

export async function beginMfaEnrollment(principal: PortalPrincipal, requestId: string) {
  if (!principal.emailVerified) throw new Error("EMAIL_NOT_VERIFIED");
  const enrollment = createMfaEnrollment(principal.email);
  await getPool().execute(
    "UPDATE portal_users SET mfa_secret_ciphertext=?,mfa_recovery_hashes=?,mfa_enabled=0 WHERE id=?",
    [enrollment.encryptedSecret, JSON.stringify(enrollment.recoveryHashes), principal.userId],
  );
  await audit(getPool(), requestId, "portal_user", principal.userId, "mfa_enrollment_started", {});
  return {
    secret: enrollment.secret,
    uri: enrollment.uri,
    recoveryCodes: enrollment.recoveryCodes,
  };
}

export async function confirmMfaEnrollment(principal: PortalPrincipal, code: string, requestId: string): Promise<boolean> {
  const [rows] = await getPool().query<UserRow[]>("SELECT * FROM portal_users WHERE id=? LIMIT 1", [principal.userId]);
  const user = rows[0];
  if (!user?.mfa_secret_ciphertext || !await verifyMfaCode(user.mfa_secret_ciphertext, code)) return false;
  await getPool().execute("UPDATE portal_users SET mfa_enabled=1 WHERE id=?", [principal.userId]);
  await revokeAllUserSessions(principal.userId, "MFA_ENABLED");
  await audit(getPool(), requestId, "portal_user", principal.userId, "mfa_enabled", {});
  return true;
}

async function registerFailedLogin(user: UserRow, requestId: string): Promise<void> {
  const failures = Math.min(20, Number(user.failed_login_count || 0) + 1);
  const lockMinutes = failures >= 5 ? Math.min(60, 15 * Math.max(1, failures - 4)) : 0;
  await getPool().execute(
    `UPDATE portal_users
     SET failed_login_count=?,locked_until=IF(?=0,NULL,DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL ? MINUTE)),
         status=IF(?=0,status,'locked')
     WHERE id=?`,
    [failures, lockMinutes, lockMinutes, lockMinutes, user.id],
  );
  await audit(getPool(), requestId, "portal_user", user.id, "portal_login_failed", { failures, locked: lockMinutes > 0 });
}

async function recordConsent(
  connection: PoolConnection,
  userId: string,
  type: "terms" | "privacy" | "marketing",
  version: string,
  granted: boolean,
  request: Request,
): Promise<void> {
  const address = request.headers.get("x-forwarded-for")?.split(",")[0]?.trim()
    || request.headers.get("x-real-ip")
    || "unknown";
  const agent = request.headers.get("user-agent")?.slice(0, 512) || "unknown";
  await connection.execute(
    `INSERT INTO portal_consents
      (id,user_id,consent_type,document_version,granted,ip_hash,user_agent_hash)
     VALUES (?,?,?,?,?,?,?)`,
    [randomUUID(), userId, type, version, granted, privacyHash(address), privacyHash(agent)],
  );
}

async function queueEmail(
  connection: PoolConnection,
  input: {
    userId: string;
    recipient: string;
    template: string;
    tokenHash: string;
    payload: Record<string, unknown>;
  },
): Promise<void> {
  const deduplicationKey = hashToken(`${input.template}|${input.userId}|${input.tokenHash}`);
  await connection.execute(
    `INSERT INTO email_outbox
      (id,user_id,recipient,template_key,encrypted_payload,deduplication_key)
     VALUES (?,?,?,?,?,?)`,
    [
      randomUUID(),
      input.userId,
      input.recipient,
      input.template,
      encryptPrivatePayload(input.payload),
      deduplicationKey,
    ],
  );
}

type SqlExecutor = Pick<PoolConnection, "execute"> | ReturnType<typeof getPool>;

async function audit(
  executor: SqlExecutor,
  requestId: string,
  aggregateType: string,
  aggregateId: string,
  eventType: string,
  metadata: Record<string, unknown>,
): Promise<void> {
  await executor.execute(
    `INSERT INTO audit_events
      (request_id,aggregate_type,aggregate_id,event_type,metadata_json)
     VALUES (?,?,?,?,?)`,
    [requestId, aggregateType, aggregateId, eventType, JSON.stringify(metadata)],
  );
}

function assertCurrentLegalVersions(termsVersion: string, privacyVersion: string): void {
  const expectedTerms = process.env.CURRENT_TERMS_VERSION?.trim() || "terms-2026-07";
  const expectedPrivacy = process.env.CURRENT_PRIVACY_VERSION?.trim() || "privacy-2026-07";
  if (termsVersion !== expectedTerms || privacyVersion !== expectedPrivacy) throw new Error("LEGAL_VERSION_OUTDATED");
}

function marketingVersion(): string {
  return process.env.CURRENT_MARKETING_CONSENT_VERSION?.trim() || "marketing-2026-07";
}

function siteUrl(): string {
  return new URL(process.env.NEXT_PUBLIC_SITE_URL || "http://localhost:3000").origin;
}

function isDuplicateEntry(error: unknown): boolean {
  return Boolean(error && typeof error === "object" && "code" in error && error.code === "ER_DUP_ENTRY");
}
