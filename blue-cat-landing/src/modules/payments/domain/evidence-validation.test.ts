import { describe, expect, it } from "vitest";
import { MAX_EVIDENCE_BYTES, validateEvidence } from "./evidence-validation";

describe("validateEvidence", () => {
  it("detecta un PDF por firma binaria", () => {
    const result = validateEvidence(Buffer.from("%PDF-1.7\ncontenido\n%%EOF"), "pago.pdf", "application/pdf");
    expect(result.mimeType).toBe("application/pdf");
    expect(result.extension).toBe("pdf");
    expect(result.sha256).toHaveLength(64);
  });

  it("rechaza un ejecutable renombrado como imagen", () => {
    expect(() => validateEvidence(Buffer.from("MZ-program"), "pago.png", "image/png")).toThrow("EVIDENCE_TYPE_NOT_ALLOWED");
  });

  it("rechaza MIME declarado que no coincide", () => {
    const jpeg = Buffer.from([0xff, 0xd8, 0xff, 0x00, 0xff, 0xd9]);
    expect(() => validateEvidence(jpeg, "pago.png", "image/png")).toThrow("EVIDENCE_MIME_MISMATCH");
  });

  it("limita el comprobante a 5 MB", () => {
    const oversized = Buffer.alloc(MAX_EVIDENCE_BYTES + 1);
    expect(() => validateEvidence(oversized, "pago.pdf", "application/pdf")).toThrow("EVIDENCE_TOO_LARGE");
  });
});
