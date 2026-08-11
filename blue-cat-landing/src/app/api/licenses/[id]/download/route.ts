import { NextResponse } from "next/server";
import { enforceIdentityOrigin, identityError, isIdentityContext, requireIdentitySession } from "@/modules/identity/infrastructure/identity-http";
import { enforceRateLimit } from "@/lib/http-security";
import { issueInstallerGrant } from "@/modules/licenses/application/customer-license-service";

export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
) {
  const originError = enforceIdentityOrigin(request);
  if (originError) return originError;
  const identity = await requireIdentitySession(request, { csrf: true, verified: true });
  if (!isIdentityContext(identity)) return identity;

  const { id } = await context.params;
  if (!/^\d+$/.test(id) || Number(id) <= 0) {
    return identityError(422, "LICENSE_ID_INVALID", "La licencia solicitada no es válida.", identity.requestId);
  }

  if (!await enforceRateLimit("installer-grant", identity.principal.userId, 10, 3600)) {
    return identityError(429, "DOWNLOAD_RATE_LIMITED", "Demasiadas solicitudes de descarga.", identity.requestId);
  }

  try {
    const grant = await issueInstallerGrant(identity.principal, Number(id), request);
    return NextResponse.json(
      { data: grant, requestId: identity.requestId },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    const code = error instanceof Error ? error.message : "INTERNAL_ERROR";
    console.error(JSON.stringify({ level: "error", event: "installer_grant_failed", requestId: identity.requestId, code }));
    if (code === "LICENSE_NOT_FOUND") return identityError(404, code, "La licencia no está asociada a tu cuenta.", identity.requestId);
    if (code === "LICENSE_NOT_ACTIVE") return identityError(403, code, "La licencia no está activa.", identity.requestId);
    if (code === "LICENSE_EXPIRED") return identityError(403, code, "La licencia está expirada.", identity.requestId);
    if (code === "DOWNLOAD_RATE_LIMITED") return identityError(429, code, "Puedes generar hasta cinco enlaces por hora.", identity.requestId);
    if (code.startsWith("INSTALLER_")) return identityError(503, "INSTALLER_UNAVAILABLE", "El instalador firmado todavía no está disponible.", identity.requestId);
    return identityError(500, "INTERNAL_ERROR", "No pudimos autorizar la descarga.", identity.requestId);
  }
}
