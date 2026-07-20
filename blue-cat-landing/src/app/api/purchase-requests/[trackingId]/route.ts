import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { clientRateLimitKey, enforceRateLimit } from "@/lib/http-security";
import { safeErrorCode } from "@/lib/safe-error";
import { getPurchaseStatus } from "@/modules/purchases/infrastructure/mysql-purchase-access-repository";

const trackingPattern = /^BC-\d{4}-[A-F0-9]{10}$/;

export async function GET(request: Request, { params }: { params: Promise<{ trackingId: string }> }) {
  const requestId = randomUUID();
  try {
    const { trackingId: rawTrackingId } = await params;
    const trackingId = rawTrackingId.toUpperCase();
    const accessToken = request.headers.get("purchase-token") ?? "";
    if (!trackingPattern.test(trackingId) || !/^[A-Za-z0-9_-]{43}$/.test(accessToken)) return NextResponse.json({ error: { code: "INVALID_ACCESS", message: "El enlace de seguimiento no es válido." }, requestId }, { status: 401, headers: { "Cache-Control": "no-store" } });
    if (!await enforceRateLimit("purchase-status", `${clientRateLimitKey(request)}|${trackingId}`, 60, 3600)) return NextResponse.json({ error: { code: "RATE_LIMITED", message: "Demasiadas consultas. Espera antes de volver a intentar." }, requestId }, { status: 429, headers: { "Cache-Control": "no-store", "Retry-After": "3600" } });
    const status = await getPurchaseStatus(trackingId, accessToken);
    if (!status) return NextResponse.json({ error: { code: "INVALID_ACCESS", message: "El enlace de seguimiento no es válido o expiró." }, requestId }, { status: 401, headers: { "Cache-Control": "no-store" } });
    const bankInstructions = status.status === "pending_payment" && !status.offerExpired ? process.env.BANK_TRANSFER_INSTRUCTIONS : undefined;
    return NextResponse.json({ data: { ...status, bankInstructions }, requestId }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    const code = safeErrorCode(error);
    console.error(JSON.stringify({ level: "error", event: "purchase_status_failed", requestId, code }));
    return NextResponse.json({ error: { code: "INTERNAL_ERROR", message: "No fue posible consultar la solicitud." }, requestId }, { status: 500, headers: { "Cache-Control": "no-store" } });
  }
}
