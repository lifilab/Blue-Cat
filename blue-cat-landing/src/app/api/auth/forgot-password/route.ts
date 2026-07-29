import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { z } from "zod";
import { clientRateLimitKey, enforceRateLimit } from "@/lib/http-security";
import { requestPasswordReset } from "@/modules/identity/application/identity-service";
import { enforceIdentityOrigin, identityError, identityException, readIdentityJson } from "@/modules/identity/infrastructure/identity-http";

const inputSchema = z.object({ email: z.email().max(190) });

export async function POST(request: Request) {
  const requestId = randomUUID();
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  try {
    if (!await enforceRateLimit("portal-password-reset-request", clientRateLimitKey(request), 5, 3600)) {
      return identityError(429, "RATE_LIMITED", "Espera antes de solicitar otro enlace.", requestId);
    }
    const parsed = inputSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) return identityError(422, "VALIDATION_ERROR", "Ingresa un correo válido.", requestId);
    await requestPasswordReset(parsed.data.email, requestId);
    return NextResponse.json(
      { data: { accepted: true, message: "Si existe una cuenta, enviaremos un enlace de recuperación." }, requestId },
      { status: 202, headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    return identityException(error, requestId, "portal_password_reset_request_failed");
  }
}
