import { NextResponse } from "next/server";
import { enforceIdentityOrigin, isIdentityContext, requireIdentitySession } from "@/modules/identity/infrastructure/identity-http";
import { clearPortalCookies, revokePortalSession } from "@/modules/identity/infrastructure/portal-session";

export async function POST(request: Request) {
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  const context = await requireIdentitySession(request, { csrf: true });
  if (!isIdentityContext(context)) return context;
  await revokePortalSession(context.principal.sessionId);
  const response = NextResponse.json({ data: { loggedOut: true }, requestId: context.requestId }, { headers: { "Cache-Control": "no-store" } });
  clearPortalCookies(response);
  return response;
}
