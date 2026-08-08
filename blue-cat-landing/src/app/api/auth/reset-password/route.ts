import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { clientRateLimitKey, enforceRateLimit } from "@/lib/http-security";
import { resetPortalPassword } from "@/modules/identity/application/identity-service";
import { resetPasswordInputSchema } from "@/modules/identity/domain/identity-input";
import { enforceIdentityOrigin, identityError, identityException, readIdentityJson } from "@/modules/identity/infrastructure/identity-http";

export async function POST(request: Request) {
  const requestId = randomUUID();
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  try {
    if (!await enforceRateLimit("portal-password-reset", clientRateLimitKey(request), 10, 3600)) {
      return identityError(429, "RATE_LIMITED", "Espera antes de volver a intentarlo.", requestId);
    }
    const parsed = resetPasswordInputSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) return identityError(422, "VALIDATION_ERROR", "El enlace o la nueva contraseña no son válidos.", requestId);
    const completed = await resetPortalPassword(parsed.data.token, parsed.data.password, requestId);
    if (!completed) return identityError(410, "TOKEN_EXPIRED", "El enlace venció o ya fue utilizado.", requestId);
    return NextResponse.json({ data: { reset: true }, requestId }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return identityException(error, requestId, "portal_password_reset_failed");
  }
}
