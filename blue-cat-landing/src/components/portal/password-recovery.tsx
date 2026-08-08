"use client";

import Link from "next/link";
import { FormEvent, useState, useSyncExternalStore } from "react";

export function ForgotPasswordForm() {
  const [sent, setSent] = useState(false);
  const [error, setError] = useState("");
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    const email = new FormData(event.currentTarget).get("email");
    try {
      const response = await fetch("/api/auth/forgot-password", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ email }) });
      const payload = await response.json() as { error?: { message?: string } };
      if (!response.ok) throw new Error(payload.error?.message || "No pudimos procesar la solicitud.");
      setSent(true);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "No pudimos procesar la solicitud."); }
  }
  return <form className="form-card auth-card" onSubmit={submit}>
    <span className="eyebrow">Recuperar acceso</span><h2>{sent ? "Revisa tu correo" : "Restablece tu contraseña"}</h2>
    {sent ? <><p className="muted">Si existe una cuenta, enviaremos un enlace válido por 30 minutos.</p><Link className="button button-secondary" href="/ingresar">Volver a ingresar</Link></> : <>
      <p className="muted">La respuesta es la misma exista o no la cuenta para proteger tu privacidad.</p>
      <div className="field"><label>Correo<input name="email" type="email" autoComplete="email" required /></label></div>
      {error && <p className="form-status" role="alert">{error}</p>}
      <button className="button button-primary">Enviar enlace seguro</button>
    </>}
  </form>;
}

const subscribeHash = (callback: () => void) => { window.addEventListener("hashchange", callback); return () => window.removeEventListener("hashchange", callback); };
const hashSnapshot = () => window.location.hash.startsWith("#token=") ? window.location.hash.slice(7) : "";
const serverHashSnapshot = () => "";

export function ResetPasswordForm() {
  const token = useSyncExternalStore(subscribeHash, hashSnapshot, serverHashSnapshot);
  const [completed, setCompleted] = useState(false);
  const [error, setError] = useState("");
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    const password = new FormData(event.currentTarget).get("password");
    try {
      const response = await fetch("/api/auth/reset-password", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ token, password }) });
      const payload = await response.json() as { error?: { message?: string } };
      if (!response.ok) throw new Error(payload.error?.message || "No pudimos cambiar la contraseña.");
      window.history.replaceState(null, "", window.location.pathname);
      setCompleted(true);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "No pudimos cambiar la contraseña."); }
  }
  return <form className="form-card auth-card" onSubmit={submit}>
    <span className="eyebrow">Nueva contraseña</span><h2>{completed ? "Acceso actualizado" : "Crea una contraseña nueva"}</h2>
    {completed ? <><p className="muted">Cerramos las sesiones anteriores. Ya puedes volver a ingresar.</p><Link className="button button-primary" href="/ingresar">Ingresar</Link></> : <>
      <div className="field"><label>Contraseña<input name="password" type="password" autoComplete="new-password" minLength={12} maxLength={128} required disabled={!token} /></label><small className="muted">Usa mayúscula, minúscula, número y símbolo.</small></div>
      {(error || !token) && <p className="form-status" role="alert">{error || "Abre el enlace completo recibido por correo."}</p>}
      <button className="button button-primary" disabled={!token}>Guardar y cerrar sesiones</button>
    </>}
  </form>;
}
