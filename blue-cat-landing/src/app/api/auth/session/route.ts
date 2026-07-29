import { NextResponse } from "next/server";
import { getPortalOverview } from "@/modules/organizations/application/organization-service";
import { isIdentityContext, requireIdentitySession } from "@/modules/identity/infrastructure/identity-http";

export async function GET(request: Request) {
  const context = await requireIdentitySession(request);
  if (!isIdentityContext(context)) return context;
  const overview = await getPortalOverview(context.principal);
  return NextResponse.json({ data: overview, requestId: context.requestId }, { headers: { "Cache-Control": "no-store" } });
}
