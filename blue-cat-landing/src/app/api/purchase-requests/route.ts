import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { purchaseRequestSchema } from "@/modules/purchases/domain/purchase-request";
import { createPurchaseRequest } from "@/modules/purchases/infrastructure/mysql-purchase-repository";
import { clientRateLimitKey, enforceRateLimit, isSameOrigin } from "@/lib/http-security";
import { safeErrorCode } from "@/lib/safe-error";

export async function POST(request: Request) {
  const requestId = randomUUID();
  try {
    if (!isSameOrigin(request)) return NextResponse.json({ error: { code: "INVALID_ORIGIN", message: "Origen de solicitud no permitido." }, requestId }, { status: 403 });
    const contentType = request.headers.get("content-type") ?? "";
    if (!contentType.includes("application/json")) return NextResponse.json({ error: { code: "UNSUPPORTED_MEDIA_TYPE", message: "Formato de solicitud no permitido." }, requestId }, { status: 415 });
    const contentLength = Number(request.headers.get("content-length") ?? 0);
    if (contentLength > 64 * 1024) return NextResponse.json({ error: { code: "PAYLOAD_TOO_LARGE", message: "La solicitud supera el tamaño permitido." }, requestId }, { status: 413 });
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    if (!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(idempotencyKey)) return NextResponse.json({ error: { code: "INVALID_IDEMPOTENCY_KEY", message: "No fue posible identificar el envío." }, requestId }, { status: 400 });
    const allowed = await enforceRateLimit("purchase-request", clientRateLimitKey(request), 5, 3600);
    if (!allowed) return NextResponse.json({ error: { code: "RATE_LIMITED", message: "Demasiados intentos. Espera antes de volver a enviar." }, requestId }, { status: 429, headers: { "Retry-After": "3600" } });
    const body: unknown = await request.json();
    const parsed = purchaseRequestSchema.safeParse(body);
    if (!parsed.success) return NextResponse.json({ error: { code: "VALIDATION_ERROR", message: "Revisa los datos ingresados.", fields: parsed.error.flatten().fieldErrors }, requestId }, { status: 422 });
    if (parsed.data.website) return NextResponse.json({ error: { code: "REQUEST_REJECTED", message: "No fue posible procesar la solicitud." }, requestId }, { status: 400 });
    const result = await createPurchaseRequest(parsed.data, requestId, idempotencyKey);
    return NextResponse.json({ data: result, requestId }, { status: result.duplicate ? 200 : 201, headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    const code = safeErrorCode(error);
    if (code === "DATABASE_NOT_CONFIGURED") return NextResponse.json({ error: { code: "SERVICE_NOT_CONFIGURED", message: "El canal de solicitudes aún no está configurado." }, requestId }, { status: 503 });
    if (code === "PURCHASE_TOKEN_NOT_CONFIGURED") return NextResponse.json({ error: { code: "SERVICE_NOT_CONFIGURED", message: "El seguimiento seguro aún no está configurado." }, requestId }, { status: 503 });
    if (code === "IDEMPOTENCY_CONFLICT") return NextResponse.json({ error: { code, message: "El identificador de envío ya fue utilizado con otros datos. Recarga el formulario." }, requestId }, { status: 409 });
    console.error(JSON.stringify({ level: "error", event: "purchase_request_failed", requestId, code }));
    return NextResponse.json({ error: { code: "INTERNAL_ERROR", message: "No pudimos registrar la solicitud. Intenta nuevamente." }, requestId }, { status: 500 });
  }
}
