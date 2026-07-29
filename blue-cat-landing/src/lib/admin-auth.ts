import {
  authenticatePortalRequest,
  requireRequestCsrf,
} from "@/modules/identity/infrastructure/portal-session";

export type AdminAuthResult =
  | { ok: true; actor: string }
  | { ok: false; reason: "unauthorized" | "mfa_required" | "csrf_invalid" };

export async function authenticateAdmin(request: Request, options: { csrf?: boolean } = {}): Promise<AdminAuthResult> {
  const principal = await authenticatePortalRequest(request);
  if (!principal || principal.userType !== "operator") return { ok: false, reason: "unauthorized" };
  if (!principal.mfaEnabled || principal.authLevel !== "mfa") return { ok: false, reason: "mfa_required" };
  if (options.csrf && !requireRequestCsrf(request, principal)) return { ok: false, reason: "csrf_invalid" };
  return { ok: true, actor: principal.userId };
}
