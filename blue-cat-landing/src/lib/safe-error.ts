const internalCodes = new Set([
  "DATABASE_NOT_CONFIGURED", "PURCHASE_TOKEN_NOT_CONFIGURED", "IDEMPOTENCY_CONFLICT",
  "EVIDENCE_EMPTY", "EVIDENCE_TOO_LARGE", "EVIDENCE_TYPE_NOT_ALLOWED", "EVIDENCE_MIME_MISMATCH",
  "PURCHASE_NOT_FOUND", "INVALID_PAYMENT_STATE", "PAYMENT_REPORT_NOT_FOUND",
  "INVALID_REVIEW_TRANSITION", "PAYMENT_MISMATCH_REQUIRES_NOTE", "INVALID_QUOTE_STATE",
  "INVALID_STORAGE_KEY",
  "EVIDENCE_DIR_INVALID",
]);

export function safeErrorCode(error: unknown): string {
  if (error instanceof Error && internalCodes.has(error.message)) return error.message;
  return error instanceof Error ? error.name : "UNKNOWN";
}
