"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";

export function LoginForm() {
  const [mfaRequired, setMfaRequired] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError("");
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
      const payload = await response.json() as { data?: { authenticated?: boolean; mfaRequired?: boolean }; error?: { message?: string } };
      if (response.status === 202 && payload.data?.mfaRequired) {
        setMfaRequired(true);
        setLoading(false);
        return;
      }
      if (!response.ok || !payload.data?.authenticated) throw new Error(payload.error?.message || "No pudimos iniciar la sesión.");
      window.location.assign("/portal");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "No pudimos iniciar la sesión.");
      setLoading(false);
    }
  }

  return <form className="form-card auth-card" onSubmit={submit}>
    <div><span className="eyebrow">Acceso protegido</span><h2>Ingresa al portal</h2><p className="muted">Administra tu cuenta y organizaciones.</p></div>
    <div className="field"><label>Correo<input name="email" type="email" autoComplete="email" required /></label></div>
    <div className="field"><label>Contraseña<input name="password" type="password" autoComplete="current-password" required /></label></div>
    {mfaRequired && <div className="field"><label>Código del autenticador<input name="totpCode" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required autoFocus /></label></div>}
    {error && <p className="form-status" role="alert">{error}</p>}
    <button className="button button-primary" disabled={loading}>{loading ? "Verificando…" : mfaRequired ? "Confirmar segundo factor" : "Ingresar"}</button>
    <div className="auth-links"><Link href="/recuperar-acceso">Olvidé mi contraseña</Link><Link href="/crear-cuenta">Crear cuenta</Link></div>
  </form>;
}
