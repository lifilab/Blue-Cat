import type { Metadata } from "next";
import { MfaEnrollment } from "@/components/portal/mfa-enrollment";
import { PageHero } from "@/components/marketing/page-hero";

export const metadata: Metadata = { title: "Seguridad de la cuenta", robots: { index: false, follow: false } };
export default function PortalSecurityPage() { return <><PageHero eyebrow="Segundo factor" title="Protege las acciones sensibles.">Blue Cat exige MFA a los operadores internos y lo deja disponible para las cuentas de clientes.</PageHero><section className="section"><div className="container auth-shell"><MfaEnrollment/></div></section></>; }
