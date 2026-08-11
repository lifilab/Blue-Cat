"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";

export function RegisterForm({ termsVersion, privacyVersion }: { termsVersion: string; privacyVersion: string }) {
  const [status, setStatus] = useState<"idle" | "loading" | "sent">("idle");
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setStatus("loading");
    setError("");
    const form = new FormData(event.currentTarget);
    try {
      const response = await fetch("/api/auth/register", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: form.get("email"),
          displayName: form.get("displayName"),
          password: form.get("password"),
          termsVersion,
          privacyVersion,
          marketingConsent: form.get("marketingConsent") === "on",
        }),
      });
      const payload = await response.json() as { error?: { message?: string } };
      if (!response.ok) throw new Error(payload.error?.message || "No pudimos crear la cuenta.");
      setStatus("sent");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "No pudimos crear la cuenta.");
      setStatus("idle");
    }
  }

  if (status === "sent") {
    return <div className="form-card auth-card" role="status">
      <span className="status">Solicitud recibida</span>
      <h2>Revisa tu correo</h2>
      <p className="muted">Enviamos un enlace de un solo uso. Revisa también Spam o Promociones. Debes verificarlo antes de ingresar o crear tu organización.</p>
      <Link className="button button-primary" href="/ingresar">Ir a ingresar</Link>
    </div>;
  }

  return <form className="form-card auth-card" onSubmit={submit}>
    <div><span className="eyebrow">Portal de clientes</span><h2>Crea tu cuenta</h2><p className="muted">La contraseña nunca se envía por correo.</p></div>
    <div className="field"><label>Nombre completo<input name="displayName" autoComplete="name" minLength={2} maxLength={120} required /></label></div>
    <div className="field"><label>Correo<input name="email" type="email" autoComplete="email" maxLength={190} required /></label></div>
    <div className="field"><label>Contraseña<input name="password" type="password" autoComplete="new-password" minLength={12} maxLength={128} required /></label><small className="muted">12 caracteres o más, con mayúscula, minúscula, número y símbolo.</small></div>
    <label className="check-field"><input name="legalConsent" type="checkbox" required /><span>Acepto los <Link href="/terminos">términos</Link> y la <Link href="/privacidad">política de privacidad</Link> vigentes.</span></label>
    <label className="check-field"><input name="marketingConsent" type="checkbox" /><span>Quiero recibir novedades comerciales. Es opcional.</span></label>
    {error && <p className="form-status" role="alert">{error}</p>}
    <button className="button button-primary" disabled={status === "loading"}>{status === "loading" ? "Creando cuenta…" : "Crear cuenta segura"}</button>
    <p className="auth-switch">¿Ya tienes cuenta? <Link href="/ingresar">Ingresar</Link></p>
  </form>;
}
