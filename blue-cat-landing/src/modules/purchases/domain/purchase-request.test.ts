import { describe, expect, it } from "vitest";
import { purchaseRequestSchema } from "./purchase-request";

const validRequest = {
  businessName: "Comercial Ejemplo SpA",
  contactName: "Pablo Ejemplo",
  email: "VENTAS@EJEMPLO.CL",
  phone: "+56 9 1234 5678",
  country: "Chile",
  city: "Santiago",
  planId: "pyme",
  estimatedBranches: "1",
  acceptsTerms: true,
  acceptsPrivacy: true,
};

describe("purchaseRequestSchema", () => {
  it("normaliza el correo y la cantidad", () => {
    const result = purchaseRequestSchema.parse(validRequest);
    expect(result.email).toBe("ventas@ejemplo.cl");
    expect(result.estimatedBranches).toBe(1);
  });

  it("rechaza una solicitud sin consentimientos", () => {
    const result = purchaseRequestSchema.safeParse({ ...validRequest, acceptsPrivacy: false });
    expect(result.success).toBe(false);
  });

  it("rechaza planes no publicados", () => {
    const result = purchaseRequestSchema.safeParse({ ...validRequest, planId: "unlimited" });
    expect(result.success).toBe(false);
  });
});
