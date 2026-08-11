"use client";

import Link from "next/link";
import { Building2, Download, KeyRound, LogOut, Server, ShieldCheck, UserRound } from "lucide-react";
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
  licenses: Array<{
    id: number;
    licenseKey: string;
    status: "active" | "suspended" | "revoked";
    serverBound: boolean;
    downloadAllowed: boolean;
    expiresAt: string | null;
    issuedAt: string;
  }>;
}

interface InstallerGrant {
  downloadUrl: string;
  expiresIn: number;
  version: string;
  sha256: string;
}

export function PortalDashboard() {
  const [overview, setOverview] = useState<PortalOverview | null>(null);
  const [error, setError] = useState("");
  const [downloadError, setDownloadError] = useState("");
  const [downloadNotice, setDownloadNotice] = useState("");
  const [downloadingLicenseId, setDownloadingLicenseId] = useState<number | null>(null);

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

  async function downloadInstaller(licenseId: number) {
    setDownloadError("");
    setDownloadNotice("");
    setDownloadingLicenseId(licenseId);
    try {
      const grant = await portalMutation<InstallerGrant>(`/api/licenses/${licenseId}/download`, "POST");
      const link = document.createElement("a");
      link.href = grant.downloadUrl;
      link.rel = "noopener noreferrer";
      document.body.appendChild(link);
      link.click();
      link.remove();
      setDownloadNotice(`Descarga autorizada para Blue-Cat ${grant.version}. SHA-256: ${grant.sha256}`);
    } catch (reason) {
      setDownloadError(reason instanceof Error ? reason.message : "No pudimos autorizar la descarga.");
    } finally {
      setDownloadingLicenseId(null);
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
      <article className="card"><span className="card-icon"><KeyRound size={20}/></span><h3>Licencias del servidor</h3><p>{overview.licenses.length ? `${overview.licenses.length} licencia(s) asociada(s) a tu correo.` : "Aún no hay licencias asociadas."}</p><span className="status">Una licencia por servidor</span></article>
    </div>

    <section className="portal-section">
      <div className="portal-section-heading"><div><span className="eyebrow">Software y activación</span><h2>Mis licencias Blue-Cat</h2></div></div>
      {downloadError && <p className="form-status" role="alert">{downloadError}</p>}
      {downloadNotice && <p className="notice installer-notice" role="status">{downloadNotice}</p>}
      {overview.licenses.length === 0
        ? <div className="empty-portal"><KeyRound size={30}/><h3>No encontramos una licencia para este correo</h3><p>Si ya compraste Blue-Cat, ingresa con el mismo correo registrado en la compra o contacta a soporte.</p></div>
        : <div className="license-list">{overview.licenses.map((license) => {
          const active = license.downloadAllowed;
          return <article className="card license-card" key={license.id}>
            <div className="license-card-heading"><span className="card-icon"><Server size={20}/></span><div><span className={`status ${active ? "status-active" : "status-inactive"}`}>{active ? "Activa" : license.status}</span><h3>Servidor Blue-Cat</h3></div></div>
            <dl>
              <div><dt>Clave de activación</dt><dd><code>{license.licenseKey}</code></dd></div>
              <div><dt>Servidor</dt><dd>{license.serverBound ? "Servidor vinculado" : "Pendiente de activación"}</dd></div>
              <div><dt>Vencimiento</dt><dd>{license.expiresAt ? new Intl.DateTimeFormat("es-CL", { dateStyle: "long" }).format(new Date(license.expiresAt)) : "Sin vencimiento"}</dd></div>
            </dl>
            <button className="button button-primary" type="button" disabled={!active || downloadingLicenseId === license.id} onClick={() => downloadInstaller(license.id)}>
              <Download size={17}/> {downloadingLicenseId === license.id ? "Autorizando…" : "Descargar instalador firmado"}
            </button>
            <p className="muted license-help">Puedes volver a descargarlo cuando lo necesites. Cada enlace funciona una sola vez durante cinco minutos, con un máximo de cinco enlaces por hora.</p>
          </article>;
        })}</div>}
      <div className="notice network-license-note"><Server size={22}/><div><strong>Una licencia, varios equipos de trabajo</strong><p>La licencia activa el servidor del negocio. Los PC de POS, bodega y oficina se conectan a ese servidor con usuarios y permisos propios, sin consumir otra licencia.</p></div></div>
    </section>

    <section className="portal-section">
      <div className="portal-section-heading"><div><span className="eyebrow">Perfil comercial</span><h2>Mis organizaciones</h2></div><Link className="button button-primary" href="/portal/organizacion/nueva">Crear organización</Link></div>
      {overview.organizations.length === 0 ? <div className="empty-portal"><Building2 size={30}/><h3>Comienza por tu organización</h3><p>Define la razón social y el perfil de facturación que usaremos en futuros pedidos.</p></div> :
        <div className="grid-2">{overview.organizations.map((organization) => <article className="card organization-card" key={organization.id}><span className="status">{organization.status}</span><h3>{organization.tradingName || organization.legalName}</h3><p>{organization.legalName}</p><dl><div><dt>Rol</dt><dd>{organization.role}</dd></div><div><dt>Ubicación</dt><dd>{organization.city}, {organization.country}</dd></div><div><dt>Facturación</dt><dd>{organization.billingEmail || "Pendiente"}</dd></div><div><dt>Moneda</dt><dd>{organization.currency || "CLP"}</dd></div></dl></article>)}</div>}
    </section>
  </div>;
}