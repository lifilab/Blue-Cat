import { describe, expect, it } from "vitest";
import { canReviewPayment, purchaseStatusForDecision } from "./payment-state";

describe("payment state machine", () => {
  it("permite tomar, aprobar o rechazar un reporte nuevo", () => {
    expect(canReviewPayment("reported", "under_review")).toBe(true);
    expect(canReviewPayment("reported", "approved")).toBe(true);
    expect(canReviewPayment("reported", "rejected")).toBe(true);
  });

  it("impide modificar una decisión final", () => {
    expect(canReviewPayment("approved", "rejected")).toBe(false);
    expect(canReviewPayment("rejected", "approved")).toBe(false);
  });

  it("devuelve el pago rechazado a espera de reemplazo", () => {
    expect(purchaseStatusForDecision("rejected")).toBe("pending_payment");
    expect(purchaseStatusForDecision("approved")).toBe("approved");
  });
});
