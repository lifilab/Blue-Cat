import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { clientRateLimitKey, enforceRateLimit, isSameOrigin } from "@/lib/http-security";
import { safeErrorCode } from "@/lib/safe-error";
import { MAX_EVIDENCE_BYTES, validateEvidence } from "@/modules/payments/domain/evidence-validation";
import { paymentReportSchema } from "@/modules/payments/domain/payment-report";
import { deleteEvidence, storeEvidence } from "@/modules/payments/infrastructure/local-evidence-storage";
import { createPaymentReport } from "@/modules/payments/infrastructure/mysql-payment-repository";

export const runtime = "nodejs";

function field(form: FormData, name: string): string {
  const value = form.get(name);
  return typeof value === "string" ? value : "";
}

export async function POST(request: Request) {
  const requestId = randomUUID();
  let storedKey: string | null = null;
  try {
    if (!isSameOrigin(request)) return NextResponse.json({ error: { code: "INVALID_ORIGIN", message: "Origen de solicitud no permitido." }, requestId }, { status: 403 });
    const contentType = request.headers.get("content-type") ?? "";
    if (!contentType.includes("multipart/form-data")) return NextResponse.json({ error: { code: "UNSUPPORTED_MEDIA_TYPE", message: "El reporte debe incluir un formulario y comprobante." }, requestId }, { status: 415 });
    const contentLength = Number(request.headers.get("content-length") ?? 0);
    if (contentLength > MAX_EVIDENCE_BYTES + 512 * 1024) return NextResponse.json({ error: { code: "PAYLOAD_TOO_LARGE", message: "El comprobante supera el límite de 5 MB." }, requestId }, { status: 413 });
    const allowed = await enforceRateLimit("payment-report", clientRateLimitKey(request), 10, 3600);
    if (!allowed) return NextResponse.json({ error: { code: "RATE_LIMITED", message: "Demasiados intentos. Espera antes de volver a enviar." }, requestId }, { status: 429, headers: { "Retry-After": "3600" } });
    const form = await request.formData();
    const parsed = paymentReportSchema.safeParse({
      trackingId: field(form, "trackingId"),
      accessToken: field(form, "accessToken"),
      amountMinor: field(form, "amountMinor"),
      currency: field(form, "currency"),
      transferDate: field(form, "transferDate"),
      bankReference: field(form, "bankReference"),
      acceptsPrivacy: field(form, "acceptsPrivacy") === "true",
      website: field(form, "website"),
    });
    if (!parsed.success) return NextResponse.json({ error: { code: "VALIDATION_ERROR", message: "Revisa los datos del pago.", fields: parsed.error.flatten().fieldErrors }, requestId }, { status: 422 });
    if (parsed.data.website) return NextResponse.json({ error: { code: "REQUEST_REJECTED", message: "No fue posible procesar el reporte." }, requestId }, { status: 400 });
    const uploaded = form.get("evidence");
    if (!(uploaded instanceof File)) return NextResponse.json({ error: { code: "EVIDENCE_REQUIRED", message: "Adjunta el comprobante de transferencia." }, requestId }, { status: 422 });
    const evidence = validateEvidence(Buffer.from(await uploaded.arrayBuffer()), uploaded.name, uploaded.type);
    const stored = await storeEvidence(evidence);
    storedKey = stored.storageKey;
    const result = await createPaymentReport(parsed.data, evidence, stored.storageKey, requestId);
    if (!result.keepStoredEvidence) {
      await deleteEvidence(stored.storageKey);
      storedKey = null;
    }
    return NextResponse.json({ data: { reportId: result.reportId, trackingId: parsed.data.trackingId, status: "payment_reported", duplicate: result.duplicate }, requestId }, { status: result.duplicate ? 200 : 201 });
  } catch (error) {
    if (storedKey) await deleteEvidence(storedKey).catch(() => undefined);
    const code = safeErrorCode(error);
    const clientErrors: Record<string, { status: number; message: string }> = {
      EVIDENCE_EMPTY: { status: 422, message: "El comprobante está vacío." },
      EVIDENCE_TOO_LARGE: { status: 413, message: "El comprobante supera el límite de 5 MB." },
      EVIDENCE_TYPE_NOT_ALLOWED: { status: 422, message: "Solo se aceptan comprobantes PDF, PNG o JPEG válidos." },
      EVIDENCE_MIME_MISMATCH: { status: 422, message: "El contenido del archivo no coincide con su tipo declarado." },
      PURCHASE_NOT_FOUND: { status: 404, message: "No encontramos una solicitud pendiente con esos datos." },
      INVALID_PAYMENT_STATE: { status: 409, message: "Esta solicitud no admite un nuevo comprobante en su estado actual." },
      DATABASE_NOT_CONFIGURED: { status: 503, message: "El canal de pagos aún no está configurado." },
    };
    const known = clientErrors[code];
    if (known) return NextResponse.json({ error: { code, message: known.message }, requestId }, { status: known.status });
    console.error(JSON.stringify({ level: "error", event: "payment_report_failed", requestId, code }));
    return NextResponse.json({ error: { code: "INTERNAL_ERROR", message: "No pudimos registrar el comprobante. Intenta nuevamente." }, requestId }, { status: 500 });
  }
}
