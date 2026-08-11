import { randomUUID } from "node:crypto";
import { getPool } from "@/infrastructure/database/postgres";
import { purchaseAccessTokenHash } from "@/modules/purchases/domain/commercial-offer";
import type { PaymentDecision, PaymentReportStatus } from "../domain/payment-state";
import { canReviewPayment, purchaseStatusForDecision } from "../domain/payment-state";
import type { PaymentReportInput, PaymentReviewInput, ValidatedEvidence } from "../domain/payment-report";

interface PurchaseRow { id: string; status: string; }
interface ExistingPaymentRow { id: string; }
interface LockedPaymentRow { id: string; purchase_request_id: string; status: PaymentReportStatus; reported_amount_minor: string; reported_currency: string; expected_amount_minor: string | null; expected_currency: string | null; }
interface EvidenceRow { storage_key: string; mime_type: string; }

export interface PaymentReportSummary {
  id: string;
  trackingId: string;
  businessName: string;
  contactName: string;
  amountMinor: string;
  expectedAmountMinor: string | null;
  currency: string;
  transferDate: string;
  bankReference: string;
  status: PaymentReportStatus;
  mimeType: string;
  sizeBytes: number;
  createdAt: string;
}

export async function createPaymentReport(input: PaymentReportInput, evidence: ValidatedEvidence, storageKey: string, requestId: string): Promise<{ reportId: string; duplicate: boolean; keepStoredEvidence: boolean }> {
  const connection = await getPool().connect();
  try {
    await connection.query("BEGIN");
    const purchasesResult = await connection.query<PurchaseRow>(
      "SELECT pr.id, pr.status FROM purchase_requests pr WHERE pr.tracking_id = $1 AND pr.tracking_token_hash = $2 AND pr.tracking_token_expires_at > CURRENT_TIMESTAMP LIMIT 1 FOR UPDATE",
      [input.trackingId, purchaseAccessTokenHash(input.accessToken)],
    );
    const purchase = purchasesResult.rows[0];
    if (!purchase) throw new Error("PURCHASE_NOT_FOUND");
    const existingResult = await connection.query<ExistingPaymentRow>(
      "SELECT id FROM payment_reports WHERE purchase_request_id = $1 AND evidence_sha256 = $2 LIMIT 1",
      [purchase.id, evidence.sha256],
    );
    if (existingResult.rows[0]) {
      await connection.query("ROLLBACK");
      return { reportId: existingResult.rows[0].id, duplicate: true, keepStoredEvidence: false };
    }
    if (purchase.status !== "pending_payment") throw new Error("INVALID_PAYMENT_STATE");
    const reportId = randomUUID();
    await connection.query(
      "INSERT INTO payment_reports (id, purchase_request_id, amount_minor, currency, transfer_date, bank_reference, evidence_storage_key, evidence_original_name, evidence_mime_type, evidence_size_bytes, evidence_sha256, status) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, 'reported')",
      [reportId, purchase.id, input.amountMinor, input.currency, input.transferDate, input.bankReference, storageKey, evidence.originalName, evidence.mimeType, evidence.sizeBytes, evidence.sha256],
    );
    await connection.query("UPDATE purchase_requests SET status = 'payment_reported' WHERE id = $1", [purchase.id]);
    await connection.query(
      "INSERT INTO audit_events (request_id, aggregate_type, aggregate_id, event_type, metadata_json) VALUES ($1, 'payment_report', $2, 'payment_reported', $3)",
      [requestId, reportId, JSON.stringify({ trackingId: input.trackingId, amountMinor: input.amountMinor, currency: input.currency, evidenceSha256: evidence.sha256 })],
    );
    await connection.query("COMMIT");
    return { reportId, duplicate: false, keepStoredEvidence: true };
  } catch (error) {
    await connection.query("ROLLBACK");
    throw error;
  } finally {
    connection.release();
  }
}

export async function listPaymentReports(status?: PaymentReportStatus): Promise<PaymentReportSummary[]> {
  const filters = status ? "WHERE pay.status = $1" : "WHERE pay.status IN ('reported','under_review')";
  const params = status ? [status] : [];
  const result = await getPool().query<PaymentReportSummary>(
    `SELECT pay.id, pr.tracking_id AS "trackingId", c.business_name AS "businessName", c.contact_name AS "contactName",
      CAST(pay.amount_minor AS VARCHAR) AS "amountMinor", CAST(pr.expected_amount_minor AS VARCHAR) AS "expectedAmountMinor", pay.currency, TO_CHAR(pay.transfer_date, 'YYYY-MM-DD') AS "transferDate",
      pay.bank_reference AS "bankReference", pay.status, pay.evidence_mime_type AS "mimeType",
      pay.evidence_size_bytes AS "sizeBytes", TO_CHAR(pay.created_at, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS "createdAt"
     FROM payment_reports pay
     INNER JOIN purchase_requests pr ON pr.id = pay.purchase_request_id
     INNER JOIN customers c ON c.id = pr.customer_id
     ${filters}
     ORDER BY pay.created_at ASC LIMIT 100`,
    params,
  );
  return result.rows;
}

export async function getPaymentEvidence(reportId: string): Promise<{ storageKey: string; mimeType: string } | null> {
  const result = await getPool().query<EvidenceRow>(
    "SELECT evidence_storage_key AS storage_key, evidence_mime_type AS mime_type FROM payment_reports WHERE id = $1 LIMIT 1",
    [reportId],
  );
  return result.rows[0] ? { storageKey: result.rows[0].storage_key, mimeType: result.rows[0].mime_type } : null;
}

export async function reviewPaymentReport(input: PaymentReviewInput, actor: string, requestId: string): Promise<{ purchaseStatus: string }> {
  const connection = await getPool().connect();
  try {
    await connection.query("BEGIN");
    const result = await connection.query<LockedPaymentRow>(
      "SELECT pay.id, pay.purchase_request_id, pay.status, CAST(pay.amount_minor AS VARCHAR) AS reported_amount_minor, pay.currency AS reported_currency, CAST(pr.expected_amount_minor AS VARCHAR) AS expected_amount_minor, pr.currency AS expected_currency FROM payment_reports pay INNER JOIN purchase_requests pr ON pr.id = pay.purchase_request_id WHERE pay.id = $1 LIMIT 1 FOR UPDATE",
      [input.reportId],
    );
    const report = result.rows[0];
    if (!report) throw new Error("PAYMENT_REPORT_NOT_FOUND");
    const decision = input.decision as PaymentDecision;
    if (!canReviewPayment(report.status, decision)) throw new Error("INVALID_REVIEW_TRANSITION");
    const amountMismatch = report.expected_amount_minor !== null && (report.reported_amount_minor !== report.expected_amount_minor || report.reported_currency !== report.expected_currency);
    if (decision === "approved" && amountMismatch && input.note.length < 5) throw new Error("PAYMENT_MISMATCH_REQUIRES_NOTE");
    const purchaseStatus = purchaseStatusForDecision(decision);
    await connection.query(
      "UPDATE payment_reports SET status = $1, reviewed_by = $2, review_note = $3, reviewed_at = CURRENT_TIMESTAMP WHERE id = $4",
      [decision, actor, input.note || null, input.reportId],
    );
    await connection.query("UPDATE purchase_requests SET status = $1 WHERE id = $2", [purchaseStatus, report.purchase_request_id]);
    await connection.query(
      "INSERT INTO audit_events (request_id, aggregate_type, aggregate_id, event_type, metadata_json) VALUES ($1, 'payment_report', $2, $3, $4)",
      [requestId, input.reportId, `payment_${decision}`, JSON.stringify({ actor, decision, purchaseStatus, amountMismatch })],
    );
    await connection.query("COMMIT");
    return { purchaseStatus };
  } catch (error) {
    await connection.query("ROLLBACK");
    throw error;
  } finally {
    connection.release();
  }
}

export async function auditEvidenceAccess(reportId: string, actor: string, requestId: string): Promise<void> {
  await getPool().query(
    "INSERT INTO audit_events (request_id, aggregate_type, aggregate_id, event_type, metadata_json) VALUES ($1, 'payment_report', $2, 'payment_evidence_downloaded', $3)",
    [requestId, reportId, JSON.stringify({ actor })],
  );
}

