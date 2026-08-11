import "server-only";
import { getInstallerDeliveryConfig } from "@/config/installer-delivery";
import { getPool } from "@/infrastructure/database/postgres";
import { hashToken, privacyHash, randomToken } from "@/modules/identity/domain/secure-values";
import type { PortalPrincipal } from "@/modules/identity/infrastructure/portal-session";
import { createSignedInstallerUrl } from "@/modules/licenses/infrastructure/private-installer-storage";

export interface CustomerLicense {
  id: number;
  licenseKey: string;
  status: "active" | "suspended" | "revoked";
  serverBound: boolean;
  downloadAllowed: boolean;
  expiresAt: Date | null;
  issuedAt: Date;
}

interface CustomerLicenseRow {
  id: number;
  licenseKey: string;
  status: CustomerLicense["status"];
  serverBound: boolean;
  downloadAllowed: boolean;
  expiresAt: Date | null;
  issuedAt: Date;
  clientId: number;
}

export interface InstallerGrant {
  downloadUrl: string;
  expiresIn: number;
  version: string;
  sha256: string;
}

const grantLifetimeSeconds = 5 * 60;
const hourlyGrantLimit = 5;

export async function getCustomerLicenses(principal: PortalPrincipal): Promise<CustomerLicense[]> {
  if (!principal.emailVerified) return [];
  const result = await getPool().query<CustomerLicenseRow>(
    `SELECT l.id,
            l.license_key AS "licenseKey",
            l.status,
            (l.hwid IS NOT NULL) AS "serverBound",
            (l.status = 'active' AND (l.expires_at IS NULL OR l.expires_at > CURRENT_TIMESTAMP)) AS "downloadAllowed",
            l.expires_at AS "expiresAt",
            l.created_at AS "issuedAt",
            c.id AS "clientId"
       FROM licensing.clients c
       JOIN licensing.licenses l ON l.client_id = c.id
      WHERE LOWER(c.email) = LOWER($1)
      ORDER BY l.created_at DESC`,
    [principal.email],
  );
  return result.rows.map((row) => ({
    id: row.id,
    licenseKey: row.licenseKey,
    status: row.status,
    serverBound: row.serverBound,
    downloadAllowed: row.downloadAllowed,
    expiresAt: row.expiresAt,
    issuedAt: row.issuedAt,
  }));
}

export async function issueInstallerGrant(
  principal: PortalPrincipal,
  licenseId: number,
  request: Request,
): Promise<InstallerGrant> {
  if (!principal.emailVerified) throw new Error("LICENSE_NOT_FOUND");
  const installer = getInstallerDeliveryConfig();
  const connection = await getPool().connect();
  try {
    await connection.query("BEGIN");
    const licenseResult = await connection.query<CustomerLicenseRow>(
      `SELECT l.id,
              l.license_key AS "licenseKey",
              l.status,
              (l.hwid IS NOT NULL) AS "serverBound",
            (l.status = 'active' AND (l.expires_at IS NULL OR l.expires_at > CURRENT_TIMESTAMP)) AS "downloadAllowed",
              l.expires_at AS "expiresAt",
              l.created_at AS "issuedAt",
              c.id AS "clientId"
         FROM licensing.clients c
         JOIN licensing.licenses l ON l.client_id = c.id
        WHERE l.id = $1 AND LOWER(c.email) = LOWER($2)
        LIMIT 1
        FOR UPDATE OF l`,
      [licenseId, principal.email],
    );
    const license = licenseResult.rows[0];
    if (!license) throw new Error("LICENSE_NOT_FOUND");
    if (license.status !== "active") throw new Error("LICENSE_NOT_ACTIVE");
    if (license.expiresAt && license.expiresAt.getTime() <= Date.now()) {
      throw new Error("LICENSE_EXPIRED");
    }

    const usageResult = await connection.query<{ total: number }>(
      `SELECT COUNT(*)::integer AS total
         FROM licensing.download_tokens
        WHERE license_id = $1
          AND created_at > CURRENT_TIMESTAMP - INTERVAL '1 hour'`,
      [licenseId],
    );
    if ((usageResult.rows[0]?.total ?? hourlyGrantLimit) >= hourlyGrantLimit) {
      throw new Error("DOWNLOAD_RATE_LIMITED");
    }

    const rawToken = randomToken();
    const expiresAt = new Date(Date.now() + grantLifetimeSeconds * 1000);
    const address = request.headers.get("x-forwarded-for")?.split(",")[0]?.trim()
      || request.headers.get("x-real-ip")
      || "unknown";
    const userAgent = request.headers.get("user-agent")?.slice(0, 512) || "unknown";
    await connection.query(
      `INSERT INTO licensing.download_tokens
        (token_hash,client_id,license_id,portal_user_id,expires_at,ip_hash,user_agent_hash)
       VALUES ($1,$2,$3,$4,$5,$6,$7)`,
      [
        hashToken(rawToken),
        license.clientId,
        license.id,
        principal.userId,
        expiresAt,
        privacyHash(address),
        privacyHash(userAgent),
      ],
    );
    await connection.query("COMMIT");
    return {
      downloadUrl: `/api/licenses/download?token=${encodeURIComponent(rawToken)}`,
      expiresIn: grantLifetimeSeconds,
      version: installer.version,
      sha256: installer.sha256,
    };
  } catch (error) {
    await connection.query("ROLLBACK");
    throw error;
  } finally {
    connection.release();
  }
}

export async function consumeInstallerGrant(rawToken: string): Promise<URL> {
  const installer = getInstallerDeliveryConfig();
  const connection = await getPool().connect();
  try {
    await connection.query("BEGIN");
    const grantResult = await connection.query<{ id: string; status: string; expiresAt: Date | null }>(
      `SELECT dt.id,l.status,l.expires_at AS "expiresAt"
         FROM licensing.download_tokens dt
         JOIN licensing.licenses l ON l.id = dt.license_id
        WHERE dt.token_hash = $1
          AND dt.used_at IS NULL
          AND dt.expires_at > CURRENT_TIMESTAMP
        LIMIT 1
        FOR UPDATE OF dt`,
      [hashToken(rawToken)],
    );
    const grant = grantResult.rows[0];
    if (!grant) throw new Error("DOWNLOAD_GRANT_INVALID");
    if (grant.status !== "active") throw new Error("LICENSE_NOT_ACTIVE");
    if (grant.expiresAt && grant.expiresAt.getTime() <= Date.now()) {
      throw new Error("LICENSE_EXPIRED");
    }

    const signedUrl = await createSignedInstallerUrl(installer);
    await connection.query(
      "UPDATE licensing.download_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=$1 AND used_at IS NULL",
      [grant.id],
    );
    await connection.query("COMMIT");
    return signedUrl;
  } catch (error) {
    await connection.query("ROLLBACK");
    throw error;
  } finally {
    connection.release();
  }
}
