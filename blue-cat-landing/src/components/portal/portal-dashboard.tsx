"use client";

import Link from "next/link";
import { Building2, LogOut, ShieldCheck, UserRound } from "lucide-react";
import { useEffect, useState } from "react";
import { portalMutation } from "@/lib/portal-client";

interface PortalOverview {
  account: {
    email: string;
    displayName: string;
    userType: "customer" | "operator";
    emailVerified: boolean;
    mfaRequired: boolean;
    mfaEnabled: boolean;
    authLevel: "password" | "mfa";
  };
  organizations: Array<{
    id: string;
    slug: string;
    legalName: string;
    tradingName: string | null;
    country: string;
    city: string;
    status: string;
    role: string;
    billingEmail: string | null;
    currency: string | null;
  }>;
}

export function PortalDashboard() {
  const [overview, setOverview] = useState<PortalOverview | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    const controller = new AbortController();
    fetch("/api/auth/session", { cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        const payload = await response.json() as { data?: PortalOverview; error?: { message?: string } };
        if (!response.ok || !payload.data) throw new Error(payload.error?.message || "No pudimos cargar el portal.");
        setOverview(payload.data);
      })
      .catch((reason) => { if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : "No pudimos cargar el portal."); });
    return () => controller.abort();
  }, []);

  async function logout() {
    try {
      await portalMutation<{ loggedOut: boolean }>("/api/auth/logout", "POST");
      window.location.assign("/ingresar");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "No pudimos cerrar la sesión.");
    }
  }

  if (error) return <div className="form-card auth-card"><h2>Acceso no disponible</h2><p className="form-status" role="alert">{error}</p><Link className="button button-primary" href="/ingresar">Ingresar</Link></div>;
  if (!overview) return <div className="form-card auth-card" aria-live="polite"><h2>Cargando portal…</h2><p className="muted">Consultando el estado de tu cuenta.</p></div>;

  const enrollmentRequired = overview.account.mfaRequired && !overview.account.mfaEnabled;
  return <div className="portal-stack">
    <section className="portal-welcome">
      <div><span className="eyebrow">Cuenta verificada</span><h1>Hola, {overview.account.displayName}</h1><p>{overview.account.email}</p></div>
      <button className="button button-secondary" type="button" onClick={logout}><LogOut size={17}/> Cerrar sesión</button>
    </section>
    {enrollmentRequired && <div className="notice portal-notice"><ShieldCheck size={22}/><div><strong>Activa el segundo factor</strong><p>Es obligatorio para operadores internos antes de usar funciones administrativas.</p><Link className="button button-primary" href="/portal/seguridad">Configurar MFA</Link></div></div>}
    <div className="portal-grid">
      <article className="card"><span className="card-icon"><UserRound size={20}/></span><h3>Estado de cuenta</h3><p>Correo verificado · {overview.account.userType === "operator" ? "Operador interno" : "Cliente"}</p><span className="status">{overview.account.mfaEnabled ? "MFA activo" : "Contraseña protegida"}</span></article>
      <article className="card"><span className="card-icon"><Building2 size={20}/></span><h3>Organizaciones</h3><p>{overview.organizations.length ? `${overview.organizations.length} organización(es) vinculada(s).` : "Aún no has creado tu organización."}</p><Link className="button button-secondary" href="/portal/organizacion/nueva">Nueva organización</Link></article>
    </div>
    <section className="portal-section">
      <div className="portal-section-heading"><div><span className="eyebrow">Perfil comercial</span><h2>Mis organizaciones</h2></div><Link className="button button-primary" href="/portal/organizacion/nueva">Crear organización</Link></div>
      {overview.organizations.length === 0 ? <div className="empty-portal"><Building2 size={30}/><h3>Comienza por tu organización</h3><p>Define la razón social y el perfil de facturación que usaremos en futuros pedidos.</p></div> :
        <div className="grid-2">{overview.organizations.map((organization) => <article className="card organization-card" key={organization.id}><span className="status">{organization.status}</span><h3>{organization.tradingName || organization.legalName}</h3><p>{organization.legalName}</p><dl><div><dt>Rol</dt><dd>{organization.role}</dd></div><div><dt>Ubicación</dt><dd>{organization.city}, {organization.country}</dd></div><div><dt>Facturación</dt><dd>{organization.billingEmail || "Pendiente"}</dd></div><div><dt>Moneda</dt><dd>{organization.currency || "CLP"}</dd></div></dl></article>)}</div>}
    </section>
  </div>;
}
