import { randomBytes, randomUUID } from "node:crypto";
import { getPool } from "@/infrastructure/database/postgres";
import type { OrganizationInput, UpdateBillingInput } from "@/modules/identity/domain/identity-input";
import type { PortalPrincipal } from "@/modules/identity/infrastructure/portal-session";
import { getCustomerLicenses } from "@/modules/licenses/application/customer-license-service";

interface UserStateRow {
  status: string;
  email_verified_at: Date | null;
}

interface MembershipRow {
  organization_id: string;
  role: "owner" | "admin" | "billing" | "member";
}

interface PortalOrganizationRow {
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
  const connection = await getPool().connect();
  const organizationId = randomUUID();
  const slug = organizationSlug(input.tradingName || input.legalName);
  try {
    await connection.query("BEGIN");
    const usersResult = await connection.query<UserStateRow>(
      "SELECT status,email_verified_at FROM portal_users WHERE id=$1 LIMIT 1 FOR UPDATE",
      [principal.userId],
    );
    const users = usersResult.rows;
    if (!users[0] || users[0].status !== "active" || !users[0].email_verified_at) throw new Error("VERIFIED_ACCOUNT_REQUIRED");
    
    const countsResult = await connection.query<{ total: string }>(
      "SELECT COUNT(*)::integer as total FROM organization_memberships WHERE user_id=$1 AND status='active'",
      [principal.userId],
    );
    const counts = countsResult.rows;
    if (Number(counts[0]?.total ?? 0) >= 10) throw new Error("ORGANIZATION_LIMIT_REACHED");
    
    await connection.query(
      `INSERT INTO organizations
        (id,slug,legal_name,trading_name,tax_id,country,city,status,created_by)
       VALUES ($1,$2,$3,$4,$5,$6,$7,'active',$8)`,
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
    await connection.query(
      `INSERT INTO organization_memberships
        (organization_id,user_id,role,status,joined_at)
       VALUES ($1,$2,'owner','active',CURRENT_TIMESTAMP)`,
      [organizationId, principal.userId],
    );
    await connection.query(
      `INSERT INTO organization_billing_profiles
        (organization_id,billing_email,legal_name,tax_id,address_line,city,region,postal_code,country,currency,updated_by)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)`,
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
    await connection.query(
      `INSERT INTO audit_events
        (request_id,aggregate_type,aggregate_id,event_type,metadata_json)
       VALUES ($1,'organization',$2,'organization_created',$3)`,
      [requestId, organizationId, JSON.stringify({ actor: principal.userId, slug, country: input.country })],
    );
    await connection.query("COMMIT");
    return { id: organizationId, slug };
  } catch (error) {
    await connection.query("ROLLBACK");
    if (error && typeof error === "object" && "code" in error && error.code === "23505") throw new Error("ORGANIZATION_ALREADY_EXISTS");
    throw error;
  } finally {
    connection.release();
  }
}

export async function getPortalOverview(principal: PortalPrincipal) {
  const result = await getPool().query<PortalOrganizationRow>(
    `SELECT o.id,o.slug,o.legal_name as "legalName",o.trading_name as "tradingName",o.tax_id as "taxId",
      o.country,o.city,o.status,m.role,b.billing_email as "billingEmail",b.address_line as "addressLine",
      b.region,b.postal_code as "postalCode",b.currency
     FROM organization_memberships m
     INNER JOIN organizations o ON o.id=m.organization_id
     LEFT JOIN organization_billing_profiles b ON b.organization_id=o.id
     WHERE m.user_id=$1 AND m.status='active'
     ORDER BY o.created_at ASC`,
    [principal.userId],
  );
  const licenses = await getCustomerLicenses(principal);
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
    organizations: result.rows,
    licenses,
  };
}

export async function updateBillingProfile(
  principal: PortalPrincipal,
  organizationId: string,
  input: UpdateBillingInput,
  requestId: string,
): Promise<void> {
  const membershipsResult = await getPool().query<MembershipRow>(
    `SELECT organization_id,role FROM organization_memberships
     WHERE organization_id=$1 AND user_id=$2 AND status='active' LIMIT 1`,
    [organizationId, principal.userId],
  );
  const membership = membershipsResult.rows[0];
  if (!membership || !["owner", "admin", "billing"].includes(membership.role)) throw new Error("ORGANIZATION_FORBIDDEN");
  const connection = await getPool().connect();
  try {
    await connection.query("BEGIN");
    await connection.query(
      `UPDATE organization_billing_profiles
       SET billing_email=$1,legal_name=$2,tax_id=$3,address_line=$4,city=$5,region=$6,postal_code=$7,
           country=$8,currency=$9,updated_by=$10
       WHERE organization_id=$11`,
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
    await connection.query(
      `INSERT INTO audit_events
        (request_id,aggregate_type,aggregate_id,event_type,metadata_json)
       VALUES ($1,'organization',$2,'billing_profile_updated',$3)`,
      [requestId, organizationId, JSON.stringify({ actor: principal.userId, currency: input.currency })],
    );
    await connection.query("COMMIT");
  } catch (error) {
    await connection.query("ROLLBACK");
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

