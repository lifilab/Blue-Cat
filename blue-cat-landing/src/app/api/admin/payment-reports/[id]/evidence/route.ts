import { randomUUID } from "node:crypto";
import { NextResponse } from "next/server";
import { z } from "zod";
import { authenticateAdmin } from "@/lib/admin-auth";
import { enforceRateLimit } from "@/lib/http-security";
import { safeErrorCode } from "@/lib/safe-error";
import { readEvidence } from "@/modules/payments/infrastructure/local-evidence-storage";
import { auditEvidenceAccess, getPaymentEvidence } from "@/modules/payments/infrastructure/mysql-payment-repository";

export const runtime = "nodejs";
const noStore = { "Cache-Control": "no-store" };

export async function GET(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const requestId = randomUUID();
  try {
    const auth = authenticateAdmin(request);
    if (!auth.ok) return NextResponse.json({ error: { code: auth.reason === "not_configured" ? "ADMIN_NOT_CONFIGURED" : "UNAUTHORIZED", message: "Acceso no autorizado." }, requestId }, { status: auth.reason === "not_configured" ? 503 : 401, headers: noStore });
    if (!await enforceRateLimit("admin-payment-evidence", auth.actor, 60, 60)) return NextResponse.json({ error: { code: "RATE_LIMITED", message: "Demasiadas solicitudes." }, requestId }, { status: 429, headers: noStore });
    const { id } = await params;
    if (!z.string().uuid().safeParse(id).success) return NextResponse.json({ error: { code: "INVALID_REPORT_ID", message: "Identificador inválido." }, requestId }, { status: 422, headers: noStore });
    const evidence = await getPaymentEvidence(id);
    if (!evidence) return NextResponse.json({ error: { code: "PAYMENT_REPORT_NOT_FOUND", message: "Comprobante no encontrado." }, requestId }, { status: 404, headers: noStore });
    const bytes = await readEvidence(evidence.storageKey);
    await auditEvidenceAccess(id, auth.actor, requestId);
    const extension = evidence.storageKey.split(".").pop() ?? "bin";
    return new NextResponse(new Uint8Array(bytes), { headers: { ...noStore, "Content-Type": evidence.mimeType, "Content-Disposition": `attachment; filename="comprobante-${id}.${extension}"`, "Content-Length": String(bytes.length) } });
  } catch (error) {
    const code = safeErrorCode(error);
    console.error(JSON.stringify({ level: "error", event: "admin_evidence_download_failed", requestId, code }));
    return NextResponse.json({ error: { code: "INTERNAL_ERROR", message: "No fue posible obtener el comprobante." }, requestId }, { status: 500, headers: noStore });
  }
}
