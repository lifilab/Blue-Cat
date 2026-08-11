import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { clientRateLimitKey, enforceRateLimit } from "@/lib/http-security";
import { registerPortalAccount } from "@/modules/identity/application/identity-service";
import { registerInputSchema } from "@/modules/identity/domain/identity-input";
import { dispatchEmailOutbox } from "@/modules/notifications/application/email-outbox-service";
import {
  enforceIdentityOrigin,
  identityError,
  identityException,
  readIdentityJson,
} from "@/modules/identity/infrastructure/identity-http";

export async function POST(request: Request) {
  const requestId = randomUUID();
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  try {
    if (!await enforceRateLimit("portal-register", clientRateLimitKey(request), 5, 3600)) {
      return identityError(429, "RATE_LIMITED", "Espera antes de intentar crear otra cuenta.", requestId);
    }
    const parsed = registerInputSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) {
      return NextResponse.json(
        { error: { code: "VALIDATION_ERROR", message: "Revisa los datos de la cuenta.", fields: parsed.error.flatten().fieldErrors }, requestId },
        { status: 422, headers: { "Cache-Control": "no-store" } },
      );
    }
    const registration = await registerPortalAccount(parsed.data, request, requestId);
    if (registration.outboxId) {
      const delivery = await dispatchEmailOutbox(1, registration.outboxId);
      if (delivery.sent !== 1) {
        console.error(JSON.stringify({ level: "error", event: "verification_email_delivery_deferred", requestId }));
      }
    }
    return NextResponse.json(
      {
        data: {
          accepted: true,
          verificationToken: registration.verificationToken,
          message: "Si el correo puede registrarse, recibirás un enlace de verificación.",
        },
        requestId,
      },
      { status: 202, headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    return identityException(error, requestId, "portal_registration_failed");
  }
}
