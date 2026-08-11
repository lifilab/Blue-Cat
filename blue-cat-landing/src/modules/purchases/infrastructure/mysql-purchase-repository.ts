import { createHash, randomBytes } from "node:crypto";
import type { PurchaseStatus } from "@/config/commercial";
import { getPool } from "@/infrastructure/database/postgres";
import { derivePurchaseAccessToken, getDirectOffer, purchaseAccessTokenHash, secureRequestHash } from "../domain/commercial-offer";
import type { CreatedPurchaseRequest, PurchaseRequestInput } from "../domain/purchase-request";

interface ExistingRequestRow {
  tracking_id: string;
  request_hash: string;
  status: PurchaseStatus;
  expected_amount_minor: string | null;
  currency: string | null;
  offer_expires_at: Date | null;
}

function requestHash(input: PurchaseRequestInput): string {
  const canonical = JSON.stringify({
    businessName: input.businessName,
    contactName: input.contactName,
    email: input.email,
    phone: input.phone,
    country: input.country,
    city: input.city,
    taxId: input.taxId,
    planId: input.planId,
    estimatedBranches: input.estimatedBranches,
    wantsCloudSync: input.wantsCloudSync,
    message: input.message,
  });
  return secureRequestHash(canonical);
}

function trackingId(): string {
  return `BC-${new Date().getUTCFullYear()}-${randomBytes(5).toString("hex").toUpperCase()}`;
}

export async function createPurchaseRequest(input: PurchaseRequestInput, requestId: string, idempotencyKey: string): Promise<CreatedPurchaseRequest> {
  const pool = getPool();
  const hash = requestHash(input);
  const idempotencyHash = createHash("sha256").update(idempotencyKey).digest("hex");
  const accessToken = derivePurchaseAccessToken(idempotencyKey);
  const tokenHash = purchaseAccessTokenHash(accessToken);
  const tokenExpiresAt = new Date(Date.now() + 30 * 86_400_000);
  const offer = getDirectOffer(input.planId);
  const status = offer ? "pending_payment" : "pending_quote";
  const connection = await pool.connect();
  try {
    await connection.query("BEGIN");
    const existingResult = await connection.query<ExistingRequestRow>(
      "SELECT tracking_id, request_hash, status, expected_amount_minor, currency, offer_expires_at FROM landing.purchase_requests WHERE idempotency_key_hash = $1 LIMIT 1",
      [idempotencyHash]
    );
    const existing = existingResult.rows;
    if (existing[0]) {
      await connection.query("ROLLBACK");
      if (existing[0].request_hash !== hash) throw new Error("IDEMPOTENCY_CONFLICT");
      return existingResultMapping(existing[0], accessToken);
    }
    const customerResult = await connection.query<{ id: string }>(
      "INSERT INTO landing.customers (business_name, contact_name, email, phone, country, city, tax_id) VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id",
      [input.businessName, input.contactName, input.email, input.phone, input.country, input.city, input.taxId || null],
    );
    const customerId = customerResult.rows[0].id;
    const id = trackingId();
    await connection.query(
      "INSERT INTO landing.purchase_requests (tracking_id, customer_id, plan_id, estimated_branches, wants_cloud_sync, message, status, request_hash, idempotency_key_hash, tracking_token_hash, tracking_token_expires_at, expected_amount_minor, currency, offer_version, offer_expires_at) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)",
      [id, customerId, input.planId, input.estimatedBranches, input.wantsCloudSync, input.message || null, status, hash, idempotencyHash, tokenHash, tokenExpiresAt, offer?.amountMinor ?? null, offer?.currency ?? null, offer?.version ?? null, offer?.expiresAt ?? null],
    );
    await connection.query(
      "INSERT INTO landing.audit_events (request_id, aggregate_type, aggregate_id, event_type, metadata_json) VALUES ($1, 'purchase_request', $2, 'purchase_submitted', $3)",
      [requestId, id, JSON.stringify({ planId: input.planId, wantsCloudSync: input.wantsCloudSync, status, offerVersion: offer?.version ?? null })],
    );
    await connection.query("COMMIT");
    return { trackingId: id, status, accessToken, expectedAmountMinor: offer?.amountMinor, currency: offer?.currency, offerExpiresAt: offer?.expiresAt.toISOString(), duplicate: false };
  } catch (error) {
    await connection.query("ROLLBACK");
    // Código de violación de clave única en Postgres: '23505'
    if (error && typeof error === "object" && "code" in error && error.code === "23505") {
      const existingResult = await connection.query<ExistingRequestRow>(
        "SELECT tracking_id, request_hash, status, expected_amount_minor, currency, offer_expires_at FROM landing.purchase_requests WHERE idempotency_key_hash = $1 LIMIT 1",
        [idempotencyHash]
      );
      const existing = existingResult.rows;
      if (existing[0]) {
        if (existing[0].request_hash !== hash) throw new Error("IDEMPOTENCY_CONFLICT");
        return existingResultMapping(existing[0], accessToken);
      }
    }
    throw error;
  } finally {
    connection.release();
  }
}

function existingResultMapping(row: ExistingRequestRow, accessToken: string) {
  return {
    trackingId: row.tracking_id,
    status: row.status,
    accessToken,
    expectedAmountMinor: row.expected_amount_minor ? Number.parseInt(row.expected_amount_minor, 10) : undefined,
    currency: row.currency ?? undefined,
    offerExpiresAt: row.offer_expires_at?.toISOString(),
    duplicate: true,
  };
}

