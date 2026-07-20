import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { authenticateAdmin } from "@/lib/admin-auth";
import { enforceRateLimit } from "@/lib/http-security";
import { safeErrorCode } from "@/lib/safe-error";
import { purchaseQuoteSchema } from "@/modules/payments/domain/payment-report";
import { issuePurchaseQuote } from "@/modules/purchases/infrastructure/mysql-purchase-access-repository";

const noStore = { "Cache-Control": "no-store" };

export async function POST(request: Request, { params }: { params: Promise<{ trackingId: string }> }) {
  const requestId = randomUUID();
  try {
    const auth = authenticateAdmin(request);
    if (!auth.ok) return NextResponse.json({ error: { code: auth.reason === "not_configured" ? "ADMIN_NOT_CONFIGURED" : "UNAUTHORIZED", message: "Acceso no autorizado." }, requestId }, { status: auth.reason === "not_configured" ? 503 : 401, headers: noStore });
    if (!await enforceRateLimit("admin-purchase-quote", auth.actor, 60, 60)) return NextResponse.json({ error: { code: "RATE_LIMITED", message: "Demasiadas solicitudes." }, requestId }, { status: 429, headers: noStore });
    if (!(request.headers.get("content-type") ?? "").includes("application/json")) return NextResponse.json({ error: { code: "UNSUPPORTED_MEDIA_TYPE", message: "Formato no permitido." }, requestId }, { status: 415, headers: noStore });
    const parsed = purchaseQuoteSchema.safeParse(await request.json() as unknown);
    if (!parsed.success) return NextResponse.json({ error: { code: "VALIDATION_ERROR", message: "Revisa la cotización.", fields: parsed.error.flatten().fieldErrors }, requestId }, { status: 422, headers: noStore });
    const { trackingId: rawTrackingId } = await params;
    const trackingId = rawTrackingId.toUpperCase();
    if (!/^BC-\d{4}-[A-F0-9]{10}$/.test(trackingId)) return NextResponse.json({ error: { code: "INVALID_TRACKING_ID", message: "Código de seguimiento inválido." }, requestId }, { status: 422, headers: noStore });
    const result = await issuePurchaseQuote(trackingId, parsed.data, auth.actor, requestId);
    return NextResponse.json({ data: { trackingId, ...result }, requestId }, { headers: noStore });
  } catch (error) {
    const code = safeErrorCode(error);
    if (code === "PURCHASE_NOT_FOUND") return NextResponse.json({ error: { code, message: "Solicitud no encontrada." }, requestId }, { status: 404, headers: noStore });
    if (code === "INVALID_QUOTE_STATE") return NextResponse.json({ error: { code, message: "La solicitud no admite una nueva cotización en su estado actual." }, requestId }, { status: 409, headers: noStore });
    console.error(JSON.stringify({ level: "error", event: "quote_issue_failed", requestId, code }));
    return NextResponse.json({ error: { code: "INTERNAL_ERROR", message: "No fue posible emitir la cotización." }, requestId }, { status: 500, headers: noStore });
  }
}
