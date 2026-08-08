import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";
import type { RowDataPacket } from "mysql2";
import { generate } from "otplib";
import { getPool } from "../src/infrastructure/database/mysql";
import { authenticateAdmin } from "../src/lib/admin-auth";
import {
  beginMfaEnrollment,
  confirmMfaEnrollment,
  loginPortal,
  registerPortalAccount,
  issueAccountRecoveryChallenge,
  resetPortalPassword,
  verifyPortalEmail,
} from "../src/modules/identity/application/identity-service";
import { isArgon2idHash } from "../src/modules/identity/domain/password";
import { authenticatePortalRequest, requireRequestCsrf } from "../src/modules/identity/infrastructure/portal-session";
import { createOrganization, getPortalOverview } from "../src/modules/organizations/application/organization-service";

const origin = process.env.NEXT_PUBLIC_SITE_URL || "http://localhost:3000";
const termsVersion = process.env.CURRENT_TERMS_VERSION || "terms-2026-07";
const privacyVersion = process.env.CURRENT_PRIVACY_VERSION || "privacy-2026-07";

async function main() {
  const [databaseRows] = await getPool().query<Array<RowDataPacket & { database_name: string }>>("SELECT DATABASE() database_name");
  assert.match(databaseRows[0]?.database_name || "", /_test$/, "Identity integration tests require a database ending in _test.");
  await resetData();

  const email = `owner-${Date.now()}@example.test`;
  const request = identityRequest();
  const registered = await registerPortalAccount({
    email,
    displayName: "Propietaria de Prueba",
    password: "BlueCat-Test!2026",
    termsVersion,
    privacyVersion,
    marketingConsent: false,
  }, request, randomUUID());
  assert.ok(registered.userId && registered.verificationToken);

  const [users] = await getPool().query<Array<RowDataPacket & { password_hash: string; status: string }>>(
    "SELECT password_hash,status FROM portal_users WHERE id=?",
    [registered.userId],
  );
  assert.equal(users[0]?.status, "pending_verification");
  assert.equal(isArgon2idHash(users[0]?.password_hash || ""), true);
  const [outbox] = await getPool().query<Array<RowDataPacket & { encrypted_payload: string }>>("SELECT encrypted_payload FROM email_outbox LIMIT 1");
  assert.equal(outbox[0]?.encrypted_payload.includes(registered.verificationToken!), false, "Raw tokens must not be stored in outbox payloads.");

  assert.equal(await verifyPortalEmail(registered.verificationToken!, randomUUID()), true);
  assert.equal(await verifyPortalEmail(registered.verificationToken!, randomUUID()), false, "Verification tokens are single use.");

  const login = await loginPortal({ email, password: "BlueCat-Test!2026" }, request, randomUUID());
  assert.equal(login.status, "authenticated");
  if (login.status !== "authenticated") throw new Error("LOGIN_FAILED");
  const authenticatedRequest = identityRequest(`${login.session.sessionToken}`, login.session.csrfToken);
  const principal = await authenticatePortalRequest(authenticatedRequest);
  assert.ok(principal?.emailVerified);
  assert.equal(requireRequestCsrf(authenticatedRequest, principal!), true);

  const organization = await createOrganization(principal!, {
    legalName: "Comercial Integración SpA",
    tradingName: "Mercado Prueba",
    taxId: "76.123.456-7",
    country: "CL",
    city: "Santiago",
    billingEmail: email,
    addressLine: "Avenida Prueba 123",
    region: "Metropolitana",
    postalCode: "8320000",
    currency: "CLP",
  }, randomUUID());
  assert.ok(organization.id);
  const overview = await getPortalOverview(principal!);
  assert.equal(overview.organizations.length, 1);
  assert.equal(overview.organizations[0]?.role, "owner");

  const reset = await issueAccountRecoveryChallenge(email, randomUUID());
  assert.ok(reset.resetToken);
  assert.equal(await resetPortalPassword(reset.resetToken!, "BlueCat-New!2026", randomUUID()), true);
  assert.equal(await authenticatePortalRequest(authenticatedRequest), null, "Password reset revokes prior sessions.");

  await getPool().execute("UPDATE portal_users SET user_type='operator',mfa_required=1 WHERE id=?", [registered.userId]);
  const operatorLogin = await loginPortal({ email, password: "BlueCat-New!2026" }, request, randomUUID());
  assert.equal(operatorLogin.status, "authenticated");
  if (operatorLogin.status !== "authenticated") throw new Error("OPERATOR_LOGIN_FAILED");
  const operatorRequest = identityRequest(operatorLogin.session.sessionToken, operatorLogin.session.csrfToken);
  const operatorPrincipal = await authenticatePortalRequest(operatorRequest);
  assert.ok(operatorPrincipal);
  assert.equal((await authenticateAdmin(operatorRequest)).ok, false, "Operator admin access requires MFA.");
  const enrollment = await beginMfaEnrollment(operatorPrincipal!, randomUUID());
  const code = await generate({ secret: enrollment.secret });
  assert.equal(await confirmMfaEnrollment(operatorPrincipal!, code, randomUUID()), true);
  const mfaLogin = await loginPortal({ email, password: "BlueCat-New!2026", totpCode: await generate({ secret: enrollment.secret }) }, request, randomUUID());
  assert.equal(mfaLogin.status, "authenticated");
  if (mfaLogin.status !== "authenticated") throw new Error("MFA_LOGIN_FAILED");
  const adminRequest = identityRequest(mfaLogin.session.sessionToken, mfaLogin.session.csrfToken);
  assert.equal((await authenticateAdmin(adminRequest)).ok, true);

  console.info("Portal identity integration: OK");
  await getPool().end();
}

function identityRequest(sessionToken?: string, csrfToken?: string): Request {
  const headers = new Headers({
    Origin: origin,
    "User-Agent": "blue-cat-identity-test",
    "X-Real-IP": "127.0.0.1",
  });
  if (sessionToken && csrfToken) {
    headers.set("Cookie", `bc_portal_session=${sessionToken}; bc_portal_csrf=${csrfToken}`);
    headers.set("X-CSRF-Token", csrfToken);
  }
  return new Request(`${origin}/api/test`, { method: "POST", headers });
}

async function resetData() {
  await getPool().query("SET FOREIGN_KEY_CHECKS=0");
  for (const table of [
    "email_outbox",
    "portal_consents",
    "organization_billing_profiles",
    "organization_memberships",
    "organizations",
    "portal_sessions",
    "portal_email_tokens",
    "portal_users",
    "audit_events",
    "api_rate_limits",
  ]) {
    await getPool().query(`TRUNCATE TABLE \`${table}\``);
  }
  await getPool().query("SET FOREIGN_KEY_CHECKS=1");
}

main().catch(async (error) => {
  console.error(error);
  await getPool().end();
  process.exitCode = 1;
});
