"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { portalMutation } from "@/lib/portal-client";

interface Enrollment {
  secret: string;
  uri: string;
  recoveryCodes: string[];
}

export function MfaEnrollment() {
  const [enrollment, setEnrollment] = useState<Enrollment | null>(null);
  const [error, setError] = useState("");
  const [confirmed, setConfirmed] = useState(false);

  async function begin() {
    setError("");
    try { setEnrollment(await portalMutation<Enrollment>("/api/auth/mfa/enroll", "POST")); }
    catch (reason) { setError(reason instanceof Error ? reason.message : "No pudimos iniciar la configuración."); }
  }

  async function confirm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    const code = new FormData(event.currentTarget).get("code");
    try {
      await portalMutation<{ enabled: boolean }>("/api/auth/mfa/confirm", "POST", { code });
      setConfirmed(true);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "El código no es válido."); }
  }

  if (confirmed) return <div className="form-card auth-card"><span className="status">MFA activado</span><h2>Segundo factor listo</h2><p className="muted">Cerramos las sesiones anteriores. Ingresa nuevamente y usa el código de tu autenticador.</p><Link className="button button-primary" href="/ingresar">Volver a ingresar</Link></div>;

  return <div className="form-card auth-card">
    <span className="eyebrow">Seguridad de la cuenta</span><h2>Configura tu autenticador</h2>
    {!enrollment ? <><p className="muted">Usa una aplicación compatible con TOTP. Los operadores internos deben completar este paso antes de revisar pagos o cotizaciones.</p><button className="button button-primary" type="button" onClick={begin}>Generar configuración segura</button></> : <>
      <div className="notice"><strong>1. Agrega la cuenta</strong><p>Copia esta clave en tu autenticador:</p><code className="secret-code">{enrollment.secret}</code><details><summary>URI avanzada</summary><code className="uri-code">{enrollment.uri}</code></details></div>
      <div><strong>2. Guarda los códigos de recuperación</strong><p className="muted">Se muestran una sola vez.</p><div className="recovery-grid">{enrollment.recoveryCodes.map((code) => <code key={code}>{code}</code>)}</div></div>
      <form onSubmit={confirm} className="field"><label>3. Código de 6 dígitos<input name="code" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required /></label><button className="button button-primary">Confirmar y cerrar sesiones</button></form>
    </>}
    {error && <p className="form-status" role="alert">{error}</p>}
  </div>;
}
