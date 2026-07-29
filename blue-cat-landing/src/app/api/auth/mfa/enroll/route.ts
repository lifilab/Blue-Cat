import { NextResponse } from "next/server";
import { beginMfaEnrollment } from "@/modules/identity/application/identity-service";
import { enforceIdentityOrigin, identityException, isIdentityContext, requireIdentitySession } from "@/modules/identity/infrastructure/identity-http";

export async function POST(request: Request) {
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  const context = await requireIdentitySession(request, { csrf: true, verified: true });
  if (!isIdentityContext(context)) return context;
  try {
    const enrollment = await beginMfaEnrollment(context.principal, context.requestId);
    return NextResponse.json({ data: enrollment, requestId: context.requestId }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return identityException(error, context.requestId, "mfa_enrollment_failed");
  }
}
