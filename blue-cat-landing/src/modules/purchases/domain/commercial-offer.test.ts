import { afterEach, describe, expect, it } from "vitest";
import { derivePurchaseAccessToken, getDirectOffer, purchaseAccessTokenHash } from "./commercial-offer";

const originalEnvironment = { ...process.env };
afterEach(() => { process.env = { ...originalEnvironment }; });

describe("commercial offer", () => {
  it("genera un token estable de alta entropía para la idempotencia", () => {
    process.env.PURCHASE_TOKEN_SECRET = "a".repeat(32);
    const first = derivePurchaseAccessToken("c0a80127-1111-4111-8111-111111111111");
    const second = derivePurchaseAccessToken("c0a80127-1111-4111-8111-111111111111");
    expect(first).toBe(second);
    expect(first).toHaveLength(43);
    expect(purchaseAccessTokenHash(first)).toHaveLength(64);
  });

  it("no habilita transferencia sin precio e instrucciones", () => {
    delete process.env.PYME_PRICE_MINOR;
    delete process.env.BANK_TRANSFER_INSTRUCTIONS;
    expect(getDirectOffer("pyme")).toBeNull();
  });

  it("crea una oferta solamente con configuración completa", () => {
    process.env.PYME_PRICE_MINOR = "199000";
    process.env.BANK_TRANSFER_INSTRUCTIONS = "Datos configurados";
    process.env.COMMERCIAL_CURRENCY = "CLP";
    process.env.OFFER_VERSION = "beta-1";
    const offer = getDirectOffer("pyme");
    expect(offer?.amountMinor).toBe(199000);
    expect(offer?.currency).toBe("CLP");
  });
});
