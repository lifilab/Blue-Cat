"use client";

import Link from "next/link";
import { FormEvent, useRef, useState } from "react";

export function LoginForm() {
  const [mfaRequired, setMfaRequired] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [needsVerification, setNeedsVerification] = useState(false);
  const [resending, setResending] = useState(false);
  const [resendMessage, setResendMessage] = useState("");
  const [resendToken, setResendToken] = useState<string | null>(null);
  const formRef = useRef<HTMLFormElement>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError("");
    setNeedsVerification(false);
    setResendMessage("");
    setResendToken(null);
    const form = new FormData(event.currentTarget);
    try {
      const response = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: form.get("email"),
          password: form.get("password"),
          totpCode: form.get("totpCode") || undefined,
        }),
      });
      const payload = await response.json() as { data?: { authenticated?: boolean; mfaRequired?: boolean }; error?: { code?: string; message?: string } };
      if (response.status === 202 && payload.data?.mfaRequired) {
        setMfaRequired(true);
        setLoading(false);
        return;
      }
      if (payload.error?.code === "EMAIL_NOT_VERIFIED") setNeedsVerification(true);
      if (!response.ok || !payload.data?.authenticated) throw new Error(payload.error?.message || "No pudimos iniciar la sesión.");
      window.location.assign("/portal");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "No pudimos iniciar la sesión.");
      setLoading(false);
    }
  }

  async function resendVerification() {
    if (!formRef.current) return;
    const form = new FormData(formRef.current);
    setResending(true);
    setError("");
    setResendMessage("");
    setResendToken(null);
    try {
      const response = await fetch("/api/auth/resend-verification", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: form.get("email"), password: form.get("password") }),
      });
      const payload = await response.json() as { data?: { message?: string; verificationToken?: string }; error?: { message?: string } };
      if (!response.ok) throw new Error(payload.error?.message || "No pudimos reenviar el correo.");
      setResendMessage(payload.data?.message || "Si la cuenta está pendiente, enviaremos un nuevo enlace.");
      if (payload.data?.verificationToken) {
        setResendToken(payload.data.verificationToken);
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "No pudimos reenviar el correo.");
    } finally {
      setResending(false);
    }
  }

  return (
    <form ref={formRef} className="form-card auth-card" onSubmit={submit}>
      <div>
        <span className="eyebrow">Acceso protegido</span>
        <h2>Ingresa al portal</h2>
        <p className="muted">Administra tu cuenta y organizaciones.</p>
      </div>
      <div className="field">
        <label>
          Correo
          <input name="email" type="email" autoComplete="email" required />
        </label>
      </div>
      <div className="field">
        <label>
          Contraseña
          <input name="password" type="password" autoComplete="current-password" required />
        </label>
      </div>
      {mfaRequired && (
        <div className="field">
          <label>
            Código del autenticador
            <input name="totpCode" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required autoFocus />
          </label>
        </div>
      )}
      {error && <p className="form-status" role="alert">{error}</p>}
      {needsVerification && (
        <button className="button button-secondary" type="button" onClick={resendVerification} disabled={resending}>
          {resending ? "Reenviando…" : "Reenviar correo de confirmación"}
        </button>
      )}
      {resendMessage && (
        <div style={{ display: "flex", flexDirection: "column", gap: "0.6rem", width: "100%" }}>
          <p className="status" role="status">{resendMessage}</p>
          {resendToken && (
            <Link className="button button-primary" href={`/verificar-correo#token=${resendToken}`} style={{ width: "100%", textAlign: "center" }}>
              Activar y verificar mi cuenta ahora
            </Link>
          )}
        </div>
      )}
      <button className="button button-primary" disabled={loading}>
        {loading ? "Verificando…" : mfaRequired ? "Confirmar segundo factor" : "Ingresar"}
      </button>
      <div className="auth-links">
        <Link href="/recuperar-acceso">Olvidé mi contraseña</Link>
        <Link href="/crear-cuenta">Crear cuenta</Link>
      </div>
    </form>
  );
}
