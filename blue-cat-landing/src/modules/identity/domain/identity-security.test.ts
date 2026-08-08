import { beforeAll, describe, expect, it } from "vitest";
import { loginInputSchema, organizationInputSchema, registerInputSchema } from "./identity-input";
import { createMfaEnrollment, verifyMfaCode } from "./mfa";
import { hashPassword, isArgon2idHash, verifyPassword } from "./password";
import { decryptPrivatePayload, encryptPrivatePayload, hashToken, normalizeEmail, randomToken } from "./secure-values";
import { generate } from "otplib";

beforeAll(() => {
  process.env.IDENTITY_HASH_PEPPER = "test-only-pepper-32-characters-minimum-value";
  process.env.IDENTITY_DATA_KEY = Buffer.from("01234567890123456789012345678901").toString("base64");
});

describe("portal identity security", () => {
  it("hashes passwords with Argon2id and rejects a different password", async () => {
    const passwordHash = await hashPassword("BlueCat-Test!2026");
    expect(isArgon2idHash(passwordHash)).toBe(true);
    await expect(verifyPassword(passwordHash, "BlueCat-Test!2026")).resolves.toBe(true);
    await expect(verifyPassword(passwordHash, "BlueCat-Wrong!2026")).resolves.toBe(false);
  });

  it("encrypts private outbox payloads and detects altered envelopes", () => {
    const payload = { actionUrl: `https://example.test/#token=${randomToken()}` };
    const encrypted = encryptPrivatePayload(payload);
    expect(encrypted).not.toContain(payload.actionUrl);
    expect(decryptPrivatePayload<typeof payload>(encrypted)).toEqual(payload);
    expect(() => decryptPrivatePayload(`${encrypted.slice(0, -2)}aa`)).toThrow();
  });

  it("normalizes emails and hashes opaque values deterministically", () => {
    expect(normalizeEmail("  Owner@Example.COM ")).toBe("owner@example.com");
    expect(hashToken("same")).toBe(hashToken("same"));
    expect(hashToken("same")).not.toBe(hashToken("different"));
  });

  it("keys opaque token hashes with the server-side pepper", () => {
    const originalPepper = process.env.IDENTITY_HASH_PEPPER;
    const firstHash = hashToken("opaque-token");
    process.env.IDENTITY_HASH_PEPPER = "different-test-pepper-at-least-32-characters";
    const rotatedHash = hashToken("opaque-token");
    process.env.IDENTITY_HASH_PEPPER = originalPepper;
    expect(rotatedHash).not.toBe(firstHash);
  });

  it("creates and verifies TOTP enrollments", async () => {
    const enrollment = createMfaEnrollment("owner@example.test");
    expect(enrollment.recoveryCodes).toHaveLength(8);
    const token = await generate({ secret: enrollment.secret });
    await expect(verifyMfaCode(enrollment.encryptedSecret, token)).resolves.toBe(true);
    await expect(verifyMfaCode(enrollment.encryptedSecret, "000000")).resolves.toBe(false);
  });
});

describe("portal identity contracts", () => {
  it("requires strong passwords and current account fields", () => {
    expect(registerInputSchema.safeParse({
      email: "owner@example.test",
      displayName: "Owner",
      password: "short",
      termsVersion: "terms-2026-07",
      privacyVersion: "privacy-2026-07",
      marketingConsent: false,
    }).success).toBe(false);
    expect(loginInputSchema.safeParse({ email: "owner@example.test", password: "x", totpCode: "123456" }).success).toBe(true);
  });

  it("validates billing identity using ISO country and currency codes", () => {
    expect(organizationInputSchema.safeParse({
      legalName: "Comercial Blue Cat SpA",
      tradingName: "Blue Cat Store",
      taxId: "76.123.456-7",
      country: "cl",
      city: "Santiago",
      billingEmail: "billing@example.test",
      addressLine: "Avenida Siempre Viva 123",
      region: "Metropolitana",
      postalCode: "8320000",
      currency: "clp",
    }).success).toBe(true);
  });
});
