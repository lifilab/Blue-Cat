import { randomUUID } from "node:crypto";
import { getPool } from "../src/infrastructure/database/postgres";
import { loginInputSchema } from "../src/modules/identity/domain/identity-input";
import { hashPassword } from "../src/modules/identity/domain/password";
import { normalizeEmail } from "../src/modules/identity/domain/secure-values";

async function main() {
  const emailInput = process.env.OPERATOR_EMAIL?.trim() || "";
  const name = process.env.OPERATOR_NAME?.trim() || "";
  const password = process.env.OPERATOR_PASSWORD || "";
  const parsedCredentials = loginInputSchema.pick({ email: true }).safeParse({ email: emailInput });
  if (!parsedCredentials.success || name.length < 2 || name.length > 120 || password.length < 12) {
    throw new Error("Set OPERATOR_EMAIL, OPERATOR_NAME and a strong OPERATOR_PASSWORD.");
  }
  const normalizedEmail = normalizeEmail(emailInput);
  const passwordHash = await hashPassword(password);
  const connection = await getPool().connect();
  try {
    await connection.query("BEGIN");
    const result = await connection.query<{ id: string; user_type: string }>(
      "SELECT id,user_type FROM portal_users WHERE normalized_email=$1 LIMIT 1 FOR UPDATE",
      [normalizedEmail],
    );
    const existing = result.rows[0];
    if (existing && existing.user_type !== "operator") throw new Error("EMAIL_ALREADY_BELONGS_TO_CUSTOMER");
    const userId = existing?.id || randomUUID();
    if (existing) {
      await connection.query(
        `UPDATE portal_users
         SET email=$1,display_name=$2,password_hash=$3,status='active',email_verified_at=COALESCE(email_verified_at,CURRENT_TIMESTAMP),
             password_changed_at=CURRENT_TIMESTAMP,session_version=session_version+1,mfa_required=true
         WHERE id=$4`,
        [emailInput, name, passwordHash, userId],
      );
      await connection.query(
        "UPDATE portal_sessions SET revoked_at=CURRENT_TIMESTAMP,revoke_reason='OPERATOR_BOOTSTRAP' WHERE user_id=$1 AND revoked_at IS NULL",
        [userId],
      );
    } else {
      await connection.query(
        `INSERT INTO portal_users
          (id,email,normalized_email,display_name,password_hash,user_type,status,email_verified_at,mfa_required)
         VALUES ($1,$2,$3,$4,$5,'operator','active',CURRENT_TIMESTAMP,true)`,
        [userId, emailInput, normalizedEmail, name, passwordHash],
      );
    }
    await connection.query(
      `INSERT INTO audit_events
        (request_id,aggregate_type,aggregate_id,event_type,metadata_json)
       VALUES ($1,'portal_user',$2,'operator_bootstrapped',$3)`,
      [randomUUID(), userId, JSON.stringify({ email: normalizedEmail, existing: Boolean(existing) })],
    );
    await connection.query("COMMIT");
    console.info(`Operator ready: ${normalizedEmail}. MFA enrollment is required on first login.`);
  } catch (error) {
    await connection.query("ROLLBACK");
    throw error;
  } finally {
    connection.release();
    await getPool().end();
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : "OPERATOR_BOOTSTRAP_FAILED");
  process.exitCode = 1;
});

