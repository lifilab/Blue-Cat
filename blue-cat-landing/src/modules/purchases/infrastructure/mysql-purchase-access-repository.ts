import type { RowDataPacket } from "mysql2";
import { getPool } from "@/infrastructure/database/mysql";
import type { PurchaseStatus } from "@/config/commercial";
import { purchaseAccessTokenHash } from "../domain/commercial-offer";
import type { PurchaseQuoteInput } from "@/modules/payments/domain/payment-report";

interface AccessRow extends RowDataPacket {
  status: PurchaseStatus;
  expected_amount_minor: number | null;
  currency: string | null;
  offer_version: string | null;
  offer_expires_at: Date | null;
  updated_at: Date;
}

interface QuotePurchaseRow extends RowDataPacket { id: number; status: PurchaseStatus; version: number; }

export async function getPurchaseStatus(trackingId: string, accessToken: string) {
  const [rows] = await getPool().query<AccessRow[]>(
    "SELECT status, expected_amount_minor, currency, offer_version, offer_expires_at, updated_at FROM purchase_requests WHERE tracking_id = ? AND tracking_token_hash = ? AND tracking_token_expires_at > CURRENT_TIMESTAMP(6) LIMIT 1",
    [trackingId, purchaseAccessTokenHash(accessToken)],
  );
  const row = rows[0];
  if (!row) return null;
  const offerExpired = Boolean(row.offer_expires_at && row.offer_expires_at.getTime() <= Date.now());
  return {
    trackingId,
    status: row.status,
    expectedAmountMinor: row.expected_amount_minor ?? undefined,
    currency: row.currency ?? undefined,
    offerVersion: row.offer_version ?? undefined,
    offerExpiresAt: row.offer_expires_at?.toISOString(),
    offerExpired,
    updatedAt: row.updated_at.toISOString(),
  };
}

export async function issuePurchaseQuote(trackingId: string, input: PurchaseQuoteInput, actor: string, requestId: string) {
  const connection = await getPool().getConnection();
  try {
    await connection.beginTransaction();
    const [rows] = await connection.query<QuotePurchaseRow[]>("SELECT id, status, version FROM purchase_requests WHERE tracking_id = ? LIMIT 1 FOR UPDATE", [trackingId]);
    const purchase = rows[0];
    if (!purchase) throw new Error("PURCHASE_NOT_FOUND");
    if (purchase.status !== "pending_quote") throw new Error("INVALID_QUOTE_STATE");
    await connection.execute(
      "UPDATE purchase_requests SET expected_amount_minor = ?, currency = ?, offer_version = ?, offer_expires_at = ?, status = 'pending_payment', status_changed_at = CURRENT_TIMESTAMP(6), version = version + 1 WHERE id = ? AND version = ?",
      [input.amountMinor, input.currency, input.offerVersion, new Date(input.expiresAt), purchase.id, purchase.version],
    );
    await connection.execute(
      "INSERT INTO audit_events (request_id, aggregate_type, aggregate_id, event_type, metadata_json) VALUES (?, 'purchase_request', ?, 'quote_issued', ?)",
      [requestId, trackingId, JSON.stringify({ actor, amountMinor: input.amountMinor, currency: input.currency, offerVersion: input.offerVersion, fromStatus: "pending_quote", toStatus: "pending_payment" })],
    );
    await connection.commit();
    return { status: "pending_payment" as const };
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}
