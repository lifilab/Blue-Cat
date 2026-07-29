import { z } from "zod";

const strongPassword = z.string()
  .min(12, "La contraseña debe tener al menos 12 caracteres.")
  .max(128, "La contraseña es demasiado larga.")
  .regex(/[a-z]/, "Incluye una letra minúscula.")
  .regex(/[A-Z]/, "Incluye una letra mayúscula.")
  .regex(/[0-9]/, "Incluye un número.")
  .regex(/[^A-Za-z0-9]/, "Incluye un símbolo.");

export const registerInputSchema = z.object({
  email: z.email().max(190).transform((value) => value.trim()),
  displayName: z.string().trim().min(2).max(120),
  password: strongPassword,
  termsVersion: z.string().trim().min(3).max(40),
  privacyVersion: z.string().trim().min(3).max(40),
  marketingConsent: z.boolean().default(false),
});

export const loginInputSchema = z.object({
  email: z.email().max(190).transform((value) => value.trim()),
  password: z.string().min(1).max(128),
  totpCode: z.string().trim().regex(/^\d{6}$/).optional(),
});

export const tokenInputSchema = z.object({
  token: z.string().regex(/^[A-Za-z0-9_-]{43}$/),
});

export const resetPasswordInputSchema = tokenInputSchema.extend({
  password: strongPassword,
});

export const organizationInputSchema = z.object({
  legalName: z.string().trim().min(2).max(180),
  tradingName: z.string().trim().max(180).optional().default(""),
  taxId: z.string().trim().max(50).optional().default(""),
  country: z.string().trim().toUpperCase().regex(/^[A-Z]{2}$/),
  city: z.string().trim().min(2).max(100),
  billingEmail: z.email().max(190).transform((value) => value.trim()),
  addressLine: z.string().trim().min(4).max(220),
  region: z.string().trim().max(100).optional().default(""),
  postalCode: z.string().trim().max(20).optional().default(""),
  currency: z.string().trim().toUpperCase().regex(/^[A-Z]{3}$/).default("CLP"),
});

export const updateBillingInputSchema = organizationInputSchema.pick({
  legalName: true,
  taxId: true,
  country: true,
  city: true,
  billingEmail: true,
  addressLine: true,
  region: true,
  postalCode: true,
  currency: true,
});

export const mfaCodeSchema = z.object({
  code: z.string().trim().regex(/^\d{6}$/),
});

export type RegisterInput = z.infer<typeof registerInputSchema>;
export type LoginInput = z.infer<typeof loginInputSchema>;
export type OrganizationInput = z.infer<typeof organizationInputSchema>;
export type UpdateBillingInput = z.infer<typeof updateBillingInputSchema>;
