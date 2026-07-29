import { z } from "zod";
import { licensePlans, type PurchaseStatus } from "@/config/commercial";

const planIds = licensePlans.map((plan) => plan.id) as ["pyme", "enterprise"];

export const purchaseRequestSchema = z.object({
  businessName: z.string().trim().min(2, "Ingresa el nombre o razón social.").max(160),
  contactName: z.string().trim().min(2, "Ingresa el nombre del contacto.").max(120),
  email: z.string().trim().toLowerCase().email("Ingresa un correo válido.").max(190),
  phone: z.string().trim().min(6, "Ingresa un teléfono válido.").max(40),
  country: z.string().trim().min(2, "Ingresa el país.").max(80),
  city: z.string().trim().min(2, "Ingresa la ciudad.").max(100),
  taxId: z.string().trim().max(50).optional().default(""),
  planId: z.enum(planIds),
  estimatedBranches: z.coerce.number().int().min(1).max(999),
  wantsCloudSync: z.boolean().default(false),
  message: z.string().trim().max(1500).optional().default(""),
  acceptsTerms: z.boolean().refine(Boolean, "Debes aceptar los términos."),
  acceptsPrivacy: z.boolean().refine(Boolean, "Debes aceptar la política de privacidad."),
  website: z.string().max(0).optional().default(""),
});

export type PurchaseRequestInput = z.infer<typeof purchaseRequestSchema>;
export type PurchaseRequestFormInput = z.input<typeof purchaseRequestSchema>;

export interface CreatedPurchaseRequest {
  trackingId: string;
  status: PurchaseStatus;
  accessToken: string;
  expectedAmountMinor?: number;
  currency?: string;
  offerExpiresAt?: string;
  duplicate: boolean;
}
