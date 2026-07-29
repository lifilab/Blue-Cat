import { NextResponse } from "next/server";
import { updateBillingInputSchema } from "@/modules/identity/domain/identity-input";
import { updateBillingProfile } from "@/modules/organizations/application/organization-service";
import { enforceIdentityOrigin, identityError, identityException, isIdentityContext, readIdentityJson, requireIdentitySession } from "@/modules/identity/infrastructure/identity-http";

const organizationIdPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export async function PATCH(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  const context = await requireIdentitySession(request, { csrf: true, verified: true });
  if (!isIdentityContext(context)) return context;
  try {
    const { id } = await params;
    if (!organizationIdPattern.test(id)) return identityError(404, "ORGANIZATION_NOT_FOUND", "Organización no encontrada.", context.requestId);
    const parsed = updateBillingInputSchema.safeParse(await readIdentityJson(request));
    if (!parsed.success) return identityError(422, "VALIDATION_ERROR", "Revisa los datos de facturación.", context.requestId);
    await updateBillingProfile(context.principal, id, parsed.data, context.requestId);
    return NextResponse.json({ data: { updated: true }, requestId: context.requestId }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return identityException(error, context.requestId, "billing_profile_update_failed");
  }
}
