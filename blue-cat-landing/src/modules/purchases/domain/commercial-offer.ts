import { createHash, createHmac } from "node:crypto";
import { commerceEnabled } from "@/config/commercial-runtime";
import { requiredSecret } from "@/modules/identity/domain/secure-values";

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

export function getBankInstructions(): string {
  return process.env.BANK_TRANSFER_INSTRUCTIONS?.trim() || `Titular: Pablo Hugo Millones Guerra
RUT: 19.130.038-6
Banco: Mercado Pago
Tipo de cuenta: Cuenta Vista
N° de cuenta: 1096731087`;
}

export function getDirectOffer(planId: "pyme" | "enterprise"): DirectOffer | null {
  if (!commerceEnabled()) return null;
  if (!getBankInstructions()) return null;
  const amountMinor = positiveInteger(planId === "pyme" ? process.env.PYME_PRICE_MINOR : process.env.ENTERPRISE_PRICE_MINOR);
  const currency = (process.env.COMMERCIAL_CURRENCY ?? "CLP").trim().toUpperCase();
  const version = (process.env.OFFER_VERSION ?? "").trim();
  const validDays = positiveInteger(process.env.OFFER_VALID_DAYS) ?? 7;
  if (!amountMinor || !/^[A-Z]{3}$/.test(currency) || !version) return null;
  return { amountMinor, currency, version, expiresAt: new Date(Date.now() + validDays * 86_400_000) };
}

function purchaseSecret(): string {
  return requiredSecret("PURCHASE_TOKEN_SECRET", 32);
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
