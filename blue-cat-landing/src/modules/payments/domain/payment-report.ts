import { z } from "zod";

const today = () => new Date().toISOString().slice(0, 10);

export const paymentReportSchema = z.object({
  trackingId: z.string().trim().toUpperCase().regex(/^BC-\d{4}-[A-F0-9]{10}$/, "Código de seguimiento inválido."),
  accessToken: z.string().trim().regex(/^[A-Za-z0-9_-]{43}$/, "El enlace seguro de seguimiento no es válido."),
  amountMinor: z.coerce.number().int().positive("El monto debe ser mayor que cero.").max(Number.MAX_SAFE_INTEGER),
  currency: z.string().trim().toUpperCase().regex(/^[A-Z]{3}$/, "Utiliza un código de moneda de tres letras."),
  transferDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Fecha inválida.").refine((value) => value <= today(), "La fecha no puede estar en el futuro."),
  bankReference: z.string().trim().min(3, "Ingresa la referencia de la transferencia.").max(120),
  acceptsPrivacy: z.boolean().refine(Boolean, "Debes aceptar el tratamiento del comprobante."),
  website: z.string().max(0).optional().default(""),
});

export type PaymentReportInput = z.infer<typeof paymentReportSchema>;
export type PaymentReportFormInput = z.input<typeof paymentReportSchema>;

export const paymentReviewSchema = z.object({
  reportId: z.string().uuid("Identificador de reporte inválido."),
  decision: z.enum(["under_review", "approved", "rejected"]),
  note: z.string().trim().max(1000).optional().default(""),
}).superRefine((value, context) => {
  if (value.decision === "rejected" && value.note.length < 5) context.addIssue({ code: "custom", path: ["note"], message: "El rechazo requiere un motivo." });
});

export type PaymentReviewInput = z.infer<typeof paymentReviewSchema>;

export const purchaseQuoteSchema = z.object({
  amountMinor: z.coerce.number().int().positive().max(Number.MAX_SAFE_INTEGER),
  currency: z.string().trim().toUpperCase().regex(/^[A-Z]{3}$/),
  offerVersion: z.string().trim().min(1).max(40),
  expiresAt: z.string().datetime().refine((value) => new Date(value).getTime() > Date.now(), "La cotización debe vencer en el futuro."),
});

export type PurchaseQuoteInput = z.infer<typeof purchaseQuoteSchema>;

export interface ValidatedEvidence {
  bytes: Buffer;
  originalName: string;
  mimeType: "application/pdf" | "image/png" | "image/jpeg";
  extension: "pdf" | "png" | "jpg";
  sizeBytes: number;
  sha256: string;
}
