import type { Metadata } from "next";
import Link from "next/link";
import { cookies, headers } from "next/headers";
import { PurchaseRequestForm } from "@/components/forms/purchase-request-form";
import { PageHero } from "@/components/marketing/page-hero";
import { authenticatePortalRequest } from "@/modules/identity/infrastructure/portal-session";

export const metadata: Metadata = {
  title: "Solicitar licencia",
  description: "Inicia una solicitud de compra de Blue Cat mediante transferencia bancaria.",
};

async function getSessionPrincipal() {
  try {
    const cookieStore = await cookies();
    const headerStore = await headers();
    const cookieString = cookieStore.toString();
    const userAgent = headerStore.get("user-agent") || "";
    const xForwardedFor = headerStore.get("x-forwarded-for") || "";

    const req = new Request("http://localhost", {
      headers: {
        cookie: cookieString,
        "user-agent": userAgent,
        "x-forwarded-for": xForwardedFor,
      },
    });
    return await authenticatePortalRequest(req);
  } catch (error) {
    return null;
  }
}

export default async function BuyPage({
  searchParams,
}: {
  searchParams: Promise<{ plan?: string; cloud?: string }>;
}) {
  const search = await searchParams;
  const initialPlan = search.plan === "enterprise" ? "enterprise" : "pyme";
  const principal = await getSessionPrincipal();

  if (!principal) {
    return (
      <>
        <PageHero eyebrow="Acceso requerido" title="Se requiere una cuenta para cotizar.">
          Para solicitar una cotización o adquirir una licencia de Blue Cat, primero debes contar con una cuenta de cliente activa e iniciar sesión.
        </PageHero>
        <section className="section">
          <div className="container form-shell" style={{ display: "flex", justifyContent: "center" }}>
            <div className="form-card auth-card" style={{ maxWidth: "480px", margin: "0 auto", textAlign: "center" }}>
              <h2>Identidad del solicitante</h2>
              <p className="muted" style={{ margin: "1rem 0 2rem" }}>
                Las licencias, organizaciones y pagos se asocian de forma segura a tu cuenta de usuario. Inicia sesión o regístrate para continuar.
              </p>
              <div style={{ display: "flex", flexDirection: "column", gap: "1rem" }}>
                <Link className="button button-primary" href="/ingresar">
                  Iniciar sesión
                </Link>
                <Link className="button button-secondary" href="/crear-cuenta">
                  Crear cuenta nueva
                </Link>
              </div>
            </div>
          </div>
        </section>
      </>
    );
  }

  return (
    <>
      <PageHero eyebrow="Solicitud comercial" title="Registra tu solicitud.">
        Completa los datos de tu negocio para generar tu cotización o enlace de pago privado.
      </PageHero>
      <section className="section">
        <div className="container form-shell">
          <PurchaseRequestForm
            initialPlan={initialPlan}
            initialCloud={search.cloud === "true"}
            userEmail={principal.email}
            userDisplayName={principal.displayName}
          />
          <aside className="summary-card">
            <h2>Qué ocurrirá</h2>
            <ul>
              <li>Registramos la solicitud.</li>
              <li>Cotizamos el alcance cuando corresponda.</li>
              <li>Habilitamos monto e instrucciones.</li>
              <li>Informas la transferencia.</li>
              <li>El equipo verifica el pago.</li>
              <li>Tras aprobarlo continúa la emisión.</li>
            </ul>
            <p style={{ color: "#8fabc4", fontSize: ".8rem" }}>
              Nunca debes transferir sin ver un monto vigente dentro de tu enlace privado.
            </p>
          </aside>
        </div>
      </section>
    </>
  );
}
