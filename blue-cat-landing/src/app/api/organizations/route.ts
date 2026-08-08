import { NextResponse } from "next/server";
import { organizationInputSchema } from "@/modules/identity/domain/identity-input";
import { createOrganization } from "@/modules/organizations/application/organization-service";
import { enforceIdentityOrigin, identityError, identityException, isIdentityContext, readIdentityJson, requireIdentitySession } from "@/modules/identity/infrastructure/identity-http";

export async function POST(request: Request) {
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  const context = await requireIdentitySession(request, { csrf: true, verified: true });
  if (!isIdentityContext(context)) return context;
  try {
    const parsed = organizationInputSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) return identityError(422, "VALIDATION_ERROR", "Revisa los datos de la organización.", context.requestId);
    const organization = await createOrganization(context.principal, parsed.data, context.requestId);
    return NextResponse.json({ data: organization, requestId: context.requestId }, { status: 201, headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return identityException(error, context.requestId, "organization_creation_failed");
  }
}
