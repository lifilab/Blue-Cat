"use client";

import Link from "next/link";
import { useEffect, useRef, useState, useSyncExternalStore } from "react";

const subscribeHash = (callback: () => void) => { window.addEventListener("hashchange", callback); return () => window.removeEventListener("hashchange", callback); };
const hashSnapshot = () => window.location.hash.startsWith("#token=") ? window.location.hash.slice(7) : "";
const serverHashSnapshot = () => "";

export function EmailVerification() {
  const token = useSyncExternalStore(subscribeHash, hashSnapshot, serverHashSnapshot);
  const [state, setState] = useState<"loading" | "verified" | "error">("loading");
  const [message, setMessage] = useState("");
  const started = useRef(false);

  useEffect(() => {
    if (!token || started.current) return;
    started.current = true;
    fetch("/api/auth/verify-email", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token }),
    }).then(async (response) => {
      const payload = await response.json() as { error?: { message?: string } };
      if (!response.ok) throw new Error(payload.error?.message || "No pudimos verificar el enlace.");
      window.history.replaceState(null, "", window.location.pathname);
      setState("verified");
    }).catch((reason) => {
      setMessage(reason instanceof Error ? reason.message : "No pudimos verificar el enlace.");
      setState("error");
    });
  }, [token]);

  return <div className="form-card auth-card" aria-live="polite">
    <span className="eyebrow">Verificación de correo</span>
    {!token && <><h2>Falta el token</h2><p className="muted">Abre el enlace completo recibido por correo.</p></>}
    {token && state === "loading" && <><h2>Verificando…</h2><p className="muted">Estamos validando el enlace de un solo uso.</p></>}
    {state === "verified" && <><h2>Cuenta verificada</h2><p className="muted">Tu correo quedó confirmado. Ya puedes ingresar y crear tu organización.</p><Link className="button button-primary" href="/ingresar">Ingresar</Link></>}
    {state === "error" && <><h2>Enlace no disponible</h2><p className="form-status" role="alert">{message}</p><Link className="button button-secondary" href="/ingresar">Volver al acceso</Link></>}
  </div>;
}
