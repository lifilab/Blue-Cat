import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { clientRateLimitKey, enforceRateLimit } from "@/lib/http-security";
import { verifyPortalEmail } from "@/modules/identity/application/identity-service";
import { tokenInputSchema } from "@/modules/identity/domain/identity-input";
import { enforceIdentityOrigin, identityError, identityException, readIdentityJson } from "@/modules/identity/infrastructure/identity-http";

export async function POST(request: Request) {
  const requestId = randomUUID();
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  try {
    if (!await enforceRateLimit("portal-verify-email", clientRateLimitKey(request), 20, 3600)) {
      return identityError(429, "RATE_LIMITED", "Espera antes de volver a verificar.", requestId);
    }
    const parsed = tokenInputSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) return identityError(422, "INVALID_TOKEN", "El enlace de verificación no es válido.", requestId);
    const verified = await verifyPortalEmail(parsed.data.token, requestId);
    if (!verified) return identityError(410, "TOKEN_EXPIRED", "El enlace venció o ya fue utilizado.", requestId);
    return NextResponse.json({ data: { verified: true }, requestId }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return identityException(error, requestId, "portal_email_verification_failed");
  }
}
