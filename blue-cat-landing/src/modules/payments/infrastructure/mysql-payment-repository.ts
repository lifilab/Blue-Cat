import { randomUUID } from "node:crypto";
import type { RowDataPacket } from "mysql2";
import { getPool } from "@/infrastructure/database/mysql";
import { purchaseAccessTokenHash } from "@/modules/purchases/domain/commercial-offer";
import type { PaymentDecision, PaymentReportStatus } from "../domain/payment-state";
import { canReviewPayment, purchaseStatusForDecision } from "../domain/payment-state";
import type { PaymentReportInput, PaymentReviewInput, ValidatedEvidence } from "../domain/payment-report";

interface PurchaseRow extends RowDataPacket { id: number; status: string; }
interface ExistingPaymentRow extends RowDataPacket { id: string; }
interface LockedPaymentRow extends RowDataPacket { id: string; purchase_request_id: number; status: PaymentReportStatus; reported_amount_minor: string; reported_currency: string; expected_amount_minor: string | null; expected_currency: string | null; }
interface EvidenceRow extends RowDataPacket { storage_key: string; mime_type: string; }

export interface PaymentReportSummary extends RowDataPacket {
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
  const connection = await getPool().getConnection();
  try {
    await connection.beginTransaction();
    const [purchases] = await connection.query<PurchaseRow[]>(
      "SELECT pr.id, pr.status FROM purchase_requests pr WHERE pr.tracking_id = ? AND pr.tracking_token_hash = ? AND pr.tracking_token_expires_at > CURRENT_TIMESTAMP(6) LIMIT 1 FOR UPDATE",
      [input.trackingId, purchaseAccessTokenHash(input.accessToken)],
    );
    const purchase = purchases[0];
    if (!purchase) throw new Error("PURCHASE_NOT_FOUND");
    const [existing] = await connection.query<ExistingPaymentRow[]>(
      "SELECT id FROM payment_reports WHERE purchase_request_id = ? AND evidence_sha256 = ? LIMIT 1",
      [purchase.id, evidence.sha256],
    );
    if (existing[0]) {
      await connection.rollback();
      return { reportId: existing[0].id, duplicate: true, keepStoredEvidence: false };
    }
    if (purchase.status !== "pending_payment") throw new Error("INVALID_PAYMENT_STATE");
    const reportId = randomUUID();
    await connection.execute(
      "INSERT INTO payment_reports (id, purchase_request_id, amount_minor, currency, transfer_date, bank_reference, evidence_storage_key, evidence_original_name, evidence_mime_type, evidence_size_bytes, evidence_sha256, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported')",
      [reportId, purchase.id, input.amountMinor, input.currency, input.transferDate, input.bankReference, storageKey, evidence.originalName, evidence.mimeType, evidence.sizeBytes, evidence.sha256],
    );
    await connection.execute("UPDATE purchase_requests SET status = 'payment_reported' WHERE id = ?", [purchase.id]);
    await connection.execute(
      "INSERT INTO audit_events (request_id, aggregate_type, aggregate_id, event_type, metadata_json) VALUES (?, 'payment_report', ?, 'payment_reported', ?)",
      [requestId, reportId, JSON.stringify({ trackingId: input.trackingId, amountMinor: input.amountMinor, currency: input.currency, evidenceSha256: evidence.sha256 })],
    );
    await connection.commit();
    return { reportId, duplicate: false, keepStoredEvidence: true };
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

export async function listPaymentReports(status?: PaymentReportStatus): Promise<PaymentReportSummary[]> {
  const filters = status ? "WHERE pay.status = ?" : "WHERE pay.status IN ('reported','under_review')";
  const params = status ? [status] : [];
  const [rows] = await getPool().query<PaymentReportSummary[]>(
    `SELECT pay.id, pr.tracking_id AS trackingId, c.business_name AS businessName, c.contact_name AS contactName,
      CAST(pay.amount_minor AS CHAR) AS amountMinor, CAST(pr.expected_amount_minor AS CHAR) AS expectedAmountMinor, pay.currency, DATE_FORMAT(pay.transfer_date, '%Y-%m-%d') AS transferDate,
      pay.bank_reference AS bankReference, pay.status, pay.evidence_mime_type AS mimeType,
      pay.evidence_size_bytes AS sizeBytes, DATE_FORMAT(pay.created_at, '%Y-%m-%dT%H:%i:%sZ') AS createdAt
     FROM payment_reports pay
     INNER JOIN purchase_requests pr ON pr.id = pay.purchase_request_id
     INNER JOIN customers c ON c.id = pr.customer_id
     ${filters}
     ORDER BY pay.created_at ASC LIMIT 100`,
    params,
  );
  return rows;
}

export async function getPaymentEvidence(reportId: string): Promise<{ storageKey: string; mimeType: string } | null> {
  const [rows] = await getPool().query<EvidenceRow[]>(
    "SELECT evidence_storage_key AS storage_key, evidence_mime_type AS mime_type FROM payment_reports WHERE id = ? LIMIT 1",
    [reportId],
  );
  return rows[0] ? { storageKey: rows[0].storage_key, mimeType: rows[0].mime_type } : null;
}

export async function reviewPaymentReport(input: PaymentReviewInput, actor: string, requestId: string): Promise<{ purchaseStatus: string }> {
  const connection = await getPool().getConnection();
  try {
    await connection.beginTransaction();
    const [rows] = await connection.query<LockedPaymentRow[]>(
      "SELECT pay.id, pay.purchase_request_id, pay.status, CAST(pay.amount_minor AS CHAR) AS reported_amount_minor, pay.currency AS reported_currency, CAST(pr.expected_amount_minor AS CHAR) AS expected_amount_minor, pr.currency AS expected_currency FROM payment_reports pay INNER JOIN purchase_requests pr ON pr.id = pay.purchase_request_id WHERE pay.id = ? LIMIT 1 FOR UPDATE",
      [input.reportId],
    );
    const report = rows[0];
    if (!report) throw new Error("PAYMENT_REPORT_NOT_FOUND");
    const decision = input.decision as PaymentDecision;
    if (!canReviewPayment(report.status, decision)) throw new Error("INVALID_REVIEW_TRANSITION");
    const amountMismatch = report.expected_amount_minor !== null && (report.reported_amount_minor !== report.expected_amount_minor || report.reported_currency !== report.expected_currency);
    if (decision === "approved" && amountMismatch && input.note.length < 5) throw new Error("PAYMENT_MISMATCH_REQUIRES_NOTE");
    const purchaseStatus = purchaseStatusForDecision(decision);
    await connection.execute(
      "UPDATE payment_reports SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = CURRENT_TIMESTAMP(6) WHERE id = ?",
      [decision, actor, input.note || null, input.reportId],
    );
    await connection.execute("UPDATE purchase_requests SET status = ? WHERE id = ?", [purchaseStatus, report.purchase_request_id]);
    await connection.execute(
      "INSERT INTO audit_events (request_id, aggregate_type, aggregate_id, event_type, metadata_json) VALUES (?, 'payment_report', ?, ?, ?)",
      [requestId, input.reportId, `payment_${decision}`, JSON.stringify({ actor, decision, purchaseStatus, amountMismatch })],
    );
    await connection.commit();
    return { purchaseStatus };
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

export async function auditEvidenceAccess(reportId: string, actor: string, requestId: string): Promise<void> {
  await getPool().execute(
    "INSERT INTO audit_events (request_id, aggregate_type, aggregate_id, event_type, metadata_json) VALUES (?, 'payment_report', ?, 'payment_evidence_downloaded', ?)",
    [requestId, reportId, JSON.stringify({ actor })],
  );
}
