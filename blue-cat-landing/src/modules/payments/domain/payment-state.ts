import type { PurchaseStatus } from "@/config/commercial";

export type PaymentReportStatus = "reported" | "under_review" | "approved" | "rejected";
export type PaymentDecision = Exclude<PaymentReportStatus, "reported">;

const transitions: Record<PaymentReportStatus, readonly PaymentDecision[]> = {
  reported: ["under_review", "approved", "rejected"],
  under_review: ["approved", "rejected"],
  approved: [],
  rejected: [],
};

export function canReviewPayment(current: PaymentReportStatus, decision: PaymentDecision): boolean {
  return transitions[current].includes(decision);
}

export function purchaseStatusForDecision(decision: PaymentDecision): PurchaseStatus {
  if (decision === "approved") return "approved";
  if (decision === "under_review") return "under_review";
  return "pending_payment";
}
