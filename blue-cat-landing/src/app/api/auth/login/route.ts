import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { clientRateLimitKey, enforceRateLimit } from "@/lib/http-security";
import { loginPortal } from "@/modules/identity/application/identity-service";
import { loginInputSchema } from "@/modules/identity/domain/identity-input";
import { enforceIdentityOrigin, identityError, identityException, readIdentityJson } from "@/modules/identity/infrastructure/identity-http";
import { applyPortalCookies } from "@/modules/identity/infrastructure/portal-session";

export async function POST(request: Request) {
  const requestId = randomUUID();
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  try {
    if (!await enforceRateLimit("portal-login", clientRateLimitKey(request), 15, 900)) {
      return identityError(429, "RATE_LIMITED", "Demasiados intentos. Espera antes de volver a ingresar.", requestId);
    }
    const parsed = loginInputSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) return identityError(422, "VALIDATION_ERROR", "Revisa tus credenciales.", requestId);
    const result = await loginPortal(parsed.data, request, requestId);
    if (result.status === "mfa_required") {
      return NextResponse.json(
        { data: { authenticated: false, mfaRequired: true }, requestId },
        { status: 202, headers: { "Cache-Control": "no-store" } },
      );
    }
    if (result.status === "email_not_verified") return identityError(403, "EMAIL_NOT_VERIFIED", "Verifica tu correo antes de ingresar.", requestId);
    if (result.status === "locked") return identityError(423, "ACCOUNT_LOCKED", "La cuenta está bloqueada temporalmente.", requestId);
    if (result.status === "invalid_credentials") return identityError(401, "INVALID_CREDENTIALS", "Correo o contraseña incorrectos.", requestId);
    const response = NextResponse.json(
      {
        data: {
          authenticated: true,
          requiresMfaEnrollment: result.requiresMfaEnrollment,
          userType: result.userType,
        },
        requestId,
      },
      { headers: { "Cache-Control": "no-store" } },
    );
    applyPortalCookies(response, result.session);
    return response;
  } catch (error) {
    return identityException(error, requestId, "portal_login_failed");
  }
}
