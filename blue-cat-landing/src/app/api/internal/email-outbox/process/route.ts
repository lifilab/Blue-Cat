import { timingSafeEqual } from "node:crypto";
import { NextResponse } from "next/server";
import { requiredSecret } from "@/modules/identity/domain/secure-values";
import { dispatchEmailOutbox } from "@/modules/notifications/application/email-outbox-service";

export async function POST(request: Request) {
  let expected: string;
  try {
    expected = requiredSecret("OUTBOX_WORKER_TOKEN", 32);
  } catch {
    return NextResponse.json(
      { error: { code: "SERVICE_NOT_CONFIGURED", message: "Servicio no configurado." } },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
  const authorization = request.headers.get("authorization") ?? "";
  const provided = authorization.startsWith("Bearer ") ? authorization.slice(7) : "";
  if (!safeEqual(expected, provided)) {
    return NextResponse.json({ error: { code: "UNAUTHORIZED", message: "No autorizado." } }, { status: 401, headers: { "Cache-Control": "no-store" } });
  }
  const result = await dispatchEmailOutbox();
  return NextResponse.json({ data: result }, { headers: { "Cache-Control": "no-store" } });
}

function safeEqual(left: string, right: string): boolean {
  const leftBytes = Buffer.from(left);
  const rightBytes = Buffer.from(right);
  return leftBytes.length === rightBytes.length && timingSafeEqual(leftBytes, rightBytes);
}
