import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { authenticateAdmin } from "@/lib/admin-auth";
import { enforceRateLimit } from "@/lib/http-security";
import { safeErrorCode } from "@/lib/safe-error";
import type { PaymentReportStatus } from "@/modules/payments/domain/payment-state";
import { paymentReviewSchema } from "@/modules/payments/domain/payment-report";
import { listPaymentReports, reviewPaymentReport } from "@/modules/payments/infrastructure/mysql-payment-repository";

const validStatuses = new Set<PaymentReportStatus>(["reported", "under_review", "approved", "rejected"]);
const noStore = { "Cache-Control": "no-store" };

function denied(reason: "not_configured" | "unauthorized", requestId: string) {
  const status = reason === "not_configured" ? 503 : 401;
  const code = reason === "not_configured" ? "ADMIN_NOT_CONFIGURED" : "UNAUTHORIZED";
  return NextResponse.json({ error: { code, message: reason === "not_configured" ? "La revisión administrativa aún no está configurada." : "Credenciales administrativas inválidas." }, requestId }, { status, headers: noStore });
}

export async function GET(request: Request) {
  const requestId = randomUUID();
  try {
    const auth = authenticateAdmin(request);
    if (!auth.ok) return denied(auth.reason, requestId);
    if (!await enforceRateLimit("admin-payment-list", auth.actor, 120, 60)) return NextResponse.json({ error: { code: "RATE_LIMITED", message: "Demasiadas solicitudes." }, requestId }, { status: 429, headers: noStore });
    const rawStatus = new URL(request.url).searchParams.get("status");
    const status = rawStatus && validStatuses.has(rawStatus as PaymentReportStatus) ? rawStatus as PaymentReportStatus : undefined;
    if (rawStatus && !status) return NextResponse.json({ error: { code: "INVALID_STATUS", message: "Estado de reporte inválido." }, requestId }, { status: 422, headers: noStore });
    const reports = await listPaymentReports(status);
    return NextResponse.json({ data: reports, requestId }, { headers: noStore });
  } catch (error) {
    const code = safeErrorCode(error);
    console.error(JSON.stringify({ level: "error", event: "admin_payment_list_failed", requestId, code }));
    return NextResponse.json({ error: { code: "INTERNAL_ERROR", message: "No fue posible consultar los comprobantes." }, requestId }, { status: 500, headers: noStore });
  }
}

export async function POST(request: Request) {
  const requestId = randomUUID();
  try {
    const auth = authenticateAdmin(request);
    if (!auth.ok) return denied(auth.reason, requestId);
    if (!await enforceRateLimit("admin-payment-review", auth.actor, 60, 60)) return NextResponse.json({ error: { code: "RATE_LIMITED", message: "Demasiadas solicitudes." }, requestId }, { status: 429, headers: noStore });
    if (!(request.headers.get("content-type") ?? "").includes("application/json")) return NextResponse.json({ error: { code: "UNSUPPORTED_MEDIA_TYPE", message: "Formato no permitido." }, requestId }, { status: 415, headers: noStore });
    const body: unknown = await request.json();
    const parsed = paymentReviewSchema.safeParse(body);
    if (!parsed.success) return NextResponse.json({ error: { code: "VALIDATION_ERROR", message: "Revisa la decisión y su motivo.", fields: parsed.error.flatten().fieldErrors }, requestId }, { status: 422, headers: noStore });
    const result = await reviewPaymentReport(parsed.data, auth.actor, requestId);
    return NextResponse.json({ data: { reportId: parsed.data.reportId, decision: parsed.data.decision, purchaseStatus: result.purchaseStatus }, requestId }, { headers: noStore });
  } catch (error) {
    const code = safeErrorCode(error);
    if (code === "PAYMENT_REPORT_NOT_FOUND") return NextResponse.json({ error: { code, message: "Comprobante no encontrado." }, requestId }, { status: 404, headers: noStore });
    if (code === "INVALID_REVIEW_TRANSITION") return NextResponse.json({ error: { code, message: "La decisión no es válida para el estado actual." }, requestId }, { status: 409, headers: noStore });
    if (code === "PAYMENT_MISMATCH_REQUIRES_NOTE") return NextResponse.json({ error: { code, message: "El monto o moneda no coincide con la cotización; documenta el motivo antes de aprobar." }, requestId }, { status: 409, headers: noStore });
    console.error(JSON.stringify({ level: "error", event: "admin_payment_review_failed", requestId, code }));
    return NextResponse.json({ error: { code: "INTERNAL_ERROR", message: "No fue posible revisar el comprobante." }, requestId }, { status: 500, headers: noStore });
  }
}
