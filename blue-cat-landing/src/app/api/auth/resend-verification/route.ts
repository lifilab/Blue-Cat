import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { z } from "zod";
import { clientRateLimitKey, enforceRateLimit } from "@/lib/http-security";
import { resendVerificationEmail } from "@/modules/identity/application/identity-service";
import { enforceIdentityOrigin, identityError, identityException, readIdentityJson } from "@/modules/identity/infrastructure/identity-http";
import { dispatchEmailOutbox } from "@/modules/notifications/application/email-outbox-service";

const inputSchema = z.object({
  email: z.email().max(190),
  password: z.string().min(1).max(128),
});

export async function POST(request: Request) {
  const requestId = randomUUID();
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  try {
    if (!await enforceRateLimit("portal-resend-verification", clientRateLimitKey(request), 3, 3600)) {
      return identityError(429, "RATE_LIMITED", "Espera antes de solicitar otro mensaje.", requestId);
    }
    const parsed = inputSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) return identityError(422, "VALIDATION_ERROR", "Revisa el correo y la contraseña.", requestId);
    const resend = await resendVerificationEmail(parsed.data.email, parsed.data.password, requestId);
    if (resend.outboxId) {
      const delivery = await dispatchEmailOutbox(1, resend.outboxId);
      if (delivery.sent !== 1) {
        console.error(JSON.stringify({ level: "error", event: "verification_email_redelivery_deferred", requestId }));
      }
    }
    return NextResponse.json(
      { data: { accepted: true, message: "Si la cuenta está pendiente, enviaremos un nuevo enlace." }, requestId },
      { status: 202, headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    return identityException(error, requestId, "portal_verification_resend_failed");
  }
}
