import { randomBytes, randomUUID } from "node:crypto";
import type { RowDataPacket } from "mysql2";
import { getPool } from "@/infrastructure/database/mysql";
import type { OrganizationInput, UpdateBillingInput } from "@/modules/identity/domain/identity-input";
import type { PortalPrincipal } from "@/modules/identity/infrastructure/portal-session";

interface UserStateRow extends RowDataPacket {
  status: string;
  email_verified_at: Date | null;
}

interface MembershipRow extends RowDataPacket {
  organization_id: string;
  role: "owner" | "admin" | "billing" | "member";
}

interface PortalOrganizationRow extends RowDataPacket {
  id: string;
  slug: string;
  legalName: string;
  tradingName: string | null;
  taxId: string | null;
  country: string;
  city: string;
  status: string;
  role: string;
  billingEmail: string | null;
  addressLine: string | null;
  region: string | null;
  postalCode: string | null;
  currency: string | null;
}

export async function createOrganization(
  principal: PortalPrincipal,
  input: OrganizationInput,
  requestId: string,
): Promise<{ id: string; slug: string }> {
  const connection = await getPool().getConnection();
  const organizationId = randomUUID();
  const slug = organizationSlug(input.tradingName || input.legalName);
  try {
    await connection.beginTransaction();
    const [users] = await connection.query<UserStateRow[]>(
      "SELECT status,email_verified_at FROM portal_users WHERE id=? LIMIT 1 FOR UPDATE",
      [principal.userId],
    );
    if (!users[0] || users[0].status !== "active" || !users[0].email_verified_at) throw new Error("VERIFIED_ACCOUNT_REQUIRED");
    const [counts] = await connection.query<Array<RowDataPacket & { total: number }>>(
      "SELECT COUNT(*) total FROM organization_memberships WHERE user_id=? AND status='active'",
      [principal.userId],
    );
    if (Number(counts[0]?.total ?? 0) >= 10) throw new Error("ORGANIZATION_LIMIT_REACHED");
    await connection.execute(
      `INSERT INTO organizations
        (id,slug,legal_name,trading_name,tax_id,country,city,status,created_by)
       VALUES (?,?,?,?,?,?,?,'active',?)`,
      [
        organizationId,
        slug,
        input.legalName,
        input.tradingName || null,
        input.taxId || null,
        input.country,
        input.city,
        principal.userId,
      ],
    );
    await connection.execute(
      `INSERT INTO organization_memberships
        (organization_id,user_id,role,status,joined_at)
       VALUES (?,?,'owner','active',CURRENT_TIMESTAMP(6))`,
      [organizationId, principal.userId],
    );
    await connection.execute(
      `INSERT INTO organization_billing_profiles
        (organization_id,billing_email,legal_name,tax_id,address_line,city,region,postal_code,country,currency,updated_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
      [
        organizationId,
        input.billingEmail,
        input.legalName,
        input.taxId || null,
        input.addressLine,
        input.city,
        input.region || null,
        input.postalCode || null,
        input.country,
        input.currency,
        principal.userId,
      ],
    );
    await connection.execute(
      `INSERT INTO audit_events
        (request_id,aggregate_type,aggregate_id,event_type,metadata_json)
       VALUES (?,'organization',?,'organization_created',?)`,
      [requestId, organizationId, JSON.stringify({ actor: principal.userId, slug, country: input.country })],
    );
    await connection.commit();
    return { id: organizationId, slug };
  } catch (error) {
    await connection.rollback();
    if (error && typeof error === "object" && "code" in error && error.code === "ER_DUP_ENTRY") throw new Error("ORGANIZATION_ALREADY_EXISTS");
    throw error;
  } finally {
    connection.release();
  }
}

export async function getPortalOverview(principal: PortalPrincipal) {
  const [organizations] = await getPool().query<PortalOrganizationRow[]>(
    `SELECT o.id,o.slug,o.legal_name legalName,o.trading_name tradingName,o.tax_id taxId,
      o.country,o.city,o.status,m.role,b.billing_email billingEmail,b.address_line addressLine,
      b.region,b.postal_code postalCode,b.currency
     FROM organization_memberships m
     INNER JOIN organizations o ON o.id=m.organization_id
     LEFT JOIN organization_billing_profiles b ON b.organization_id=o.id
     WHERE m.user_id=? AND m.status='active'
     ORDER BY o.created_at ASC`,
    [principal.userId],
  );
  return {
    account: {
      id: principal.userId,
      email: principal.email,
      displayName: principal.displayName,
      userType: principal.userType,
      emailVerified: principal.emailVerified,
      mfaRequired: principal.mfaRequired,
      mfaEnabled: principal.mfaEnabled,
      authLevel: principal.authLevel,
    },
    organizations,
  };
}

export async function updateBillingProfile(
  principal: PortalPrincipal,
  organizationId: string,
  input: UpdateBillingInput,
  requestId: string,
): Promise<void> {
  const [memberships] = await getPool().query<MembershipRow[]>(
    `SELECT organization_id,role FROM organization_memberships
     WHERE organization_id=? AND user_id=? AND status='active' LIMIT 1`,
    [organizationId, principal.userId],
  );
  const membership = memberships[0];
  if (!membership || !["owner", "admin", "billing"].includes(membership.role)) throw new Error("ORGANIZATION_FORBIDDEN");
  const connection = await getPool().getConnection();
  try {
    await connection.beginTransaction();
    await connection.execute(
      `UPDATE organization_billing_profiles
       SET billing_email=?,legal_name=?,tax_id=?,address_line=?,city=?,region=?,postal_code=?,
           country=?,currency=?,updated_by=?
       WHERE organization_id=?`,
      [
        input.billingEmail,
        input.legalName,
        input.taxId || null,
        input.addressLine,
        input.city,
        input.region || null,
        input.postalCode || null,
        input.country,
        input.currency,
        principal.userId,
        organizationId,
      ],
    );
    await connection.execute(
      `INSERT INTO audit_events
        (request_id,aggregate_type,aggregate_id,event_type,metadata_json)
       VALUES (?,'organization',?,'billing_profile_updated',?)`,
      [requestId, organizationId, JSON.stringify({ actor: principal.userId, currency: input.currency })],
    );
    await connection.commit();
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

function organizationSlug(name: string): string {
  const base = name.normalize("NFKD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 60) || "organizacion";
  return `${base}-${randomBytes(4).toString("hex")}`;
}
