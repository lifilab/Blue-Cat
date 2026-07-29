import { NextResponse } from "next/server";
import { confirmMfaEnrollment } from "@/modules/identity/application/identity-service";
import { mfaCodeSchema } from "@/modules/identity/domain/identity-input";
import { enforceIdentityOrigin, identityError, identityException, isIdentityContext, readIdentityJson, requireIdentitySession } from "@/modules/identity/infrastructure/identity-http";
import { clearPortalCookies } from "@/modules/identity/infrastructure/portal-session";

export async function POST(request: Request) {
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  const context = await requireIdentitySession(request, { csrf: true, verified: true });
  if (!isIdentityContext(context)) return context;
  try {
    const parsed = mfaCodeSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) return identityError(422, "INVALID_MFA_CODE", "Ingresa un código válido.", context.requestId);
    const confirmed = await confirmMfaEnrollment(context.principal, parsed.data.code, context.requestId);
    if (!confirmed) return identityError(401, "INVALID_MFA_CODE", "El código no es correcto.", context.requestId);
    const response = NextResponse.json(
      { data: { enabled: true, loginRequired: true }, requestId: context.requestId },
      { headers: { "Cache-Control": "no-store" } },
    );
    clearPortalCookies(response);
    return response;
  } catch (error) {
    return identityException(error, context.requestId, "mfa_confirmation_failed");
  }
}
