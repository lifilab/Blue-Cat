import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { isSameOrigin } from "@/lib/http-security";
import {
  authenticatePortalRequest,
  requireRequestCsrf,
  type PortalPrincipal,
} from "./portal-session";

const maxIdentityBodyBytes = 32 * 1024;

export interface IdentityRequestContext {
  requestId: string;
  principal: PortalPrincipal;
}

export function identityError(status: number, code: string, message: string, requestId: string = randomUUID()) {
  return NextResponse.json(
    { error: { code, message }, requestId },
    { status, headers: { "Cache-Control": "no-store" } },
  );
}

export async function readIdentityJson(request: Request): Promise<Record<string, unknown>> {
  const contentType = request.headers.get("content-type")?.split(";")[0]?.trim().toLowerCase();
  if (contentType !== "application/json") throw new Error("CONTENT_TYPE_REQUIRED");
  const declared = request.headers.get("content-length");
  if (declared && (!/^\d+$/.test(declared) || Number(declared) > maxIdentityBodyBytes)) throw new Error("PAYLOAD_TOO_LARGE");
  const raw = await request.text();
  if (Buffer.byteLength(raw, "utf8") > maxIdentityBodyBytes) throw new Error("PAYLOAD_TOO_LARGE");
  const value = JSON.parse(raw) as unknown;
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("INVALID_JSON");
  return value as Record<string, unknown>;
}

export function enforceIdentityOrigin(request: Request): NextResponse | null {
  return isSameOrigin(request)
    ? null
    : identityError(403, "ORIGIN_FORBIDDEN", "La solicitud no proviene del portal autorizado.");
}

export async function requireIdentitySession(
  request: Request,
  options: { csrf?: boolean; verified?: boolean; operator?: boolean; mfa?: boolean } = {},
): Promise<IdentityRequestContext | NextResponse> {
  const requestId = randomUUID();
  const principal = await authenticatePortalRequest(request);
  if (!principal) return identityError(401, "AUTH_REQUIRED", "Inicia sesión para continuar.", requestId);
  if (options.verified && !principal.emailVerified) return identityError(403, "EMAIL_NOT_VERIFIED", "Verifica tu correo para continuar.", requestId);
  if (options.operator && principal.userType !== "operator") return identityError(403, "OPERATOR_REQUIRED", "Esta acción requiere una cuenta operadora.", requestId);
  if (options.mfa && (!principal.mfaEnabled || principal.authLevel !== "mfa")) return identityError(403, "MFA_REQUIRED", "Confirma el segundo factor para continuar.", requestId);
  if (options.csrf && !requireRequestCsrf(request, principal)) return identityError(403, "CSRF_INVALID", "La protección de la sesión no es válida.", requestId);
  return { requestId, principal };
}

export function isIdentityContext(value: IdentityRequestContext | NextResponse): value is IdentityRequestContext {
  return !(value instanceof NextResponse);
}

export function identityException(error: unknown, requestId: string, event: string): NextResponse {
  const code = error instanceof Error ? error.message : "INTERNAL_ERROR";
  console.error(JSON.stringify({ level: "error", event, requestId, code: safeCode(code) }));
  if (code === "CONTENT_TYPE_REQUIRED") return identityError(415, code, "Envía los datos como JSON.", requestId);
  if (code === "PAYLOAD_TOO_LARGE") return identityError(413, code, "La solicitud supera el tamaño permitido.", requestId);
  if (code === "INVALID_JSON" || error instanceof SyntaxError) return identityError(400, "INVALID_JSON", "El cuerpo JSON no es válido.", requestId);
  if (code === "LEGAL_VERSION_OUTDATED") return identityError(409, code, "Los documentos legales cambiaron. Recarga la página.", requestId);
  if (code === "VERIFIED_ACCOUNT_REQUIRED") return identityError(403, code, "Debes verificar tu cuenta antes de crear una organización.", requestId);
  if (code === "ORGANIZATION_LIMIT_REACHED") return identityError(409, code, "La cuenta alcanzó el límite de organizaciones.", requestId);
  if (code === "ORGANIZATION_ALREADY_EXISTS") return identityError(409, code, "Ya existe una organización con esos datos.", requestId);
  if (code === "ORGANIZATION_FORBIDDEN") return identityError(403, code, "No tienes permiso para modificar esta organización.", requestId);
  return identityError(500, "INTERNAL_ERROR", "No pudimos completar la operación.", requestId);
}

function safeCode(code: string): string {
  return code.replace(/[^A-Z0-9_-]/gi, "_").slice(0, 100);
}
