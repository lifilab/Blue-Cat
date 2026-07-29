import { createHash, createHmac } from "node:crypto";

export interface DirectOffer {
  amountMinor: number;
  currency: string;
  version: string;
  expiresAt: Date;
}

function positiveInteger(value: string | undefined): number | null {
  if (!value || !/^\d+$/.test(value)) return null;
  const number = Number(value);
  return Number.isSafeInteger(number) && number > 0 ? number : null;
}

export function getDirectOffer(planId: "pyme" | "enterprise"): DirectOffer | null {
  if (!process.env.BANK_TRANSFER_INSTRUCTIONS?.trim()) return null;
  const amountMinor = positiveInteger(planId === "pyme" ? process.env.PYME_PRICE_MINOR : process.env.ENTERPRISE_PRICE_MINOR);
  const currency = (process.env.COMMERCIAL_CURRENCY ?? "CLP").trim().toUpperCase();
  const version = (process.env.OFFER_VERSION ?? "").trim();
  const validDays = positiveInteger(process.env.OFFER_VALID_DAYS) ?? 7;
  if (!amountMinor || !/^[A-Z]{3}$/.test(currency) || !version) return null;
  return { amountMinor, currency, version, expiresAt: new Date(Date.now() + validDays * 86_400_000) };
}

function purchaseSecret(): string {
  const secret = process.env.PURCHASE_TOKEN_SECRET ?? "";
  if (secret.length < 32) throw new Error("PURCHASE_TOKEN_NOT_CONFIGURED");
  return secret;
}

export function derivePurchaseAccessToken(idempotencyKey: string): string {
  return createHmac("sha256", purchaseSecret()).update(`purchase-access|${idempotencyKey}`).digest("base64url");
}

export function purchaseAccessTokenHash(token: string): string {
  return createHash("sha256").update(token).digest("hex");
}

export function secureRequestHash(canonical: string): string {
  return createHmac("sha256", purchaseSecret()).update(`purchase-request|${canonical}`).digest("hex");
}
