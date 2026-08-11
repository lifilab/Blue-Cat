import { randomUUID } from "node:crypto";
import { getPool } from "@/infrastructure/database/postgres";
import { decryptPrivatePayload } from "@/modules/identity/domain/secure-values";

interface OutboxRow {
  id: string;
  recipient: string;
  template_key: "verify_email" | "reset_password";
  encrypted_payload: string;
  attempts: number;
}

interface EmailPayload {
  displayName: string;
  actionUrl: string;
  expiresHours?: number;
  expiresMinutes?: number;
}

export interface OutboxDispatchResult {
  claimed: number;
  sent: number;
  failed: number;
  dead: number;
}

export async function dispatchEmailOutbox(batchSize = 10, jobId?: string): Promise<OutboxDispatchResult> {
  const jobs = await claimJobs(Math.max(1, Math.min(25, batchSize)), jobId);
  const result: OutboxDispatchResult = { claimed: jobs.length, sent: 0, failed: 0, dead: 0 };
  for (const job of jobs) {
    try {
      const payload = decryptPrivatePayload<EmailPayload>(job.encrypted_payload);
      const message = renderEmail(job.template_key, payload);
      const providerMessageId = await sendEmail(job.id, job.recipient, message.subject, message.html, message.text);
      await getPool().query(
        `UPDATE landing.email_outbox
         SET status='sent',provider_message_id=$1,sent_at=CURRENT_TIMESTAMP,
             encrypted_payload='purged',locked_at=NULL,locked_by=NULL,last_error_code=NULL
         WHERE id=$2 AND status='processing'`,
        [providerMessageId, job.id],
      );
      result.sent += 1;
    } catch (error) {
      const dead = job.attempts >= 5;
      const delayMinutes = Math.min(60, 2 ** Math.max(0, job.attempts - 1));
      await getPool().query(
        `UPDATE landing.email_outbox
         SET status=$1,available_at=CURRENT_TIMESTAMP + ($2 || ' minutes')::interval,
             locked_at=NULL,locked_by=NULL,last_error_code=$3
         WHERE id=$4`,
        [dead ? "dead" : "failed", delayMinutes, safeErrorCode(error), job.id],
      );
      if (dead) result.dead += 1;
      else result.failed += 1;
    }
  }
  return result;
}

async function claimJobs(limit: number, jobId?: string): Promise<OutboxRow[]> {
  const connection = await getPool().connect();
  const workerId = `worker-${randomUUID()}`;
  try {
    await connection.query("BEGIN");
    const result = await connection.query<OutboxRow>(
      `SELECT id,recipient,template_key,encrypted_payload,attempts
       FROM landing.email_outbox
       WHERE ($2::uuid IS NULL OR id=$2::uuid)
         AND ((
           status IN ('pending','failed') AND available_at<=CURRENT_TIMESTAMP
         ) OR (
           status='processing' AND locked_at<CURRENT_TIMESTAMP - INTERVAL '15 minutes'
         ))
       ORDER BY created_at ASC
       LIMIT $1 FOR UPDATE SKIP LOCKED`,
      [limit, jobId ?? null],
    );
    const rows = result.rows;
    if (rows.length) {
      await connection.query(
        `UPDATE landing.email_outbox
         SET status='processing',attempts=attempts+1,locked_at=CURRENT_TIMESTAMP,locked_by=$1
         WHERE id = ANY($2::uuid[])`,
        [workerId, rows.map((row) => row.id)],
      );
    }
    await connection.query("COMMIT");
    return rows.map((row) => ({ ...row, attempts: Number(row.attempts) + 1 }));
  } catch (error) {
    await connection.query("ROLLBACK");
    throw error;
  } finally {
    connection.release();
  }
}

async function sendEmail(jobId: string, recipient: string, subject: string, html: string, text: string): Promise<string> {
  const provider = process.env.EMAIL_PROVIDER?.trim().toLowerCase() || (process.env.NODE_ENV === "production" ? "" : "console");
  if (provider === "console") {
    if (process.env.NODE_ENV === "production") throw new Error("CONSOLE_EMAIL_FORBIDDEN");
    console.info(JSON.stringify({ level: "info", event: "email_dispatched_console", recipient, subject }));
    return `console-${randomUUID()}`;
  }
  if (provider !== "resend") throw new Error("EMAIL_PROVIDER_NOT_CONFIGURED");
  const apiKey = process.env.RESEND_API_KEY?.trim() || "";
  let from = process.env.EMAIL_FROM?.trim() || "";
  if (!apiKey.startsWith("re_")) throw new Error("EMAIL_PROVIDER_NOT_CONFIGURED");
  const extractedFromAddressMatch = from.match(/<([^<>@\s]+@[^<>@\s]+)>$/) ?? from.match(/^([^<>\s@]+@[^<>\s@]+)$/);
  const extractedFromAddress = extractedFromAddressMatch?.[1] ?? extractedFromAddressMatch?.[0] ?? "";
  const fromParts = extractedFromAddress.toLowerCase().split("@");
  const fromDomain = fromParts.length === 2 ? fromParts[1] : "";
  const blockedDomains = new Set(["tu-dominio.cl", "example.com", "example.invalid", "localhost"]);
  if (!fromDomain || blockedDomains.has(fromDomain) || fromDomain.includes("tu-dominio")) {
    from = "Blue Cat <onboarding@resend.dev>";
  }
  const response = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey}`,
      "Content-Type": "application/json",
      "Idempotency-Key": `bluecat-outbox-${jobId}`,
    },
    body: JSON.stringify({ from, to: [recipient], subject, html, text }),
    signal: AbortSignal.timeout(10_000),
  });
  if (!response.ok) {
    const errorBody = await response.text().catch(() => "");
    console.error(JSON.stringify({ level: "error", event: "resend_api_failed", status: response.status, body: errorBody }));
    throw new Error(`EMAIL_PROVIDER_${response.status}`);
  }
  const payload = await response.json() as { id?: string };
  if (!payload.id) throw new Error("EMAIL_PROVIDER_INVALID_RESPONSE");
  return payload.id;
}

function renderEmail(template: OutboxRow["template_key"], payload: EmailPayload) {
  const displayName = escapeHtml(payload.displayName);
  const actionUrl = escapeHtml(payload.actionUrl);
  if (template === "verify_email") {
    const subject = "Verifica tu cuenta Blue Cat";
    return {
      subject,
      html: `<p>Hola ${displayName},</p><p>Confirma tu correo para activar el portal de Blue Cat.</p><p><a href="${actionUrl}">Verificar mi cuenta</a></p><p>El enlace vence en ${Number(payload.expiresHours || 24)} horas.</p>`,
      text: `Hola ${payload.displayName}. Verifica tu cuenta Blue Cat: ${payload.actionUrl}`,
    };
  }
  const subject = "Restablece tu acceso a Blue Cat";
  return {
    subject,
    html: `<p>Hola ${displayName},</p><p>Recibimos una solicitud para restablecer tu contraseña.</p><p><a href="${actionUrl}">Crear una nueva contraseña</a></p><p>El enlace vence en ${Number(payload.expiresMinutes || 30)} minutos. Si no fuiste tú, ignora este mensaje.</p>`,
    text: `Hola ${payload.displayName}. Restablece tu contraseña Blue Cat: ${payload.actionUrl}`,
  };
}

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#039;",
  })[character] || character);
}

function safeErrorCode(error: unknown): string {
  if (error instanceof Error) return error.message.replace(/[^A-Z0-9_-]/gi, "_").slice(0, 80) || "EMAIL_FAILED";
  return "EMAIL_FAILED";
}

