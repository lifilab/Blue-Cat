import type { Metadata } from "next";
import { OrganizationForm } from "@/components/portal/organization-form";
import { PageHero } from "@/components/marketing/page-hero";

export const metadata: Metadata = { title: "Nueva organización", robots: { index: false, follow: false } };
export default function NewOrganizationPage() { return <><PageHero eyebrow="Perfil comercial" title="Crea tu organización.">Estos datos identificarán al titular de futuras cotizaciones, pedidos y licencias.</PageHero><section className="section"><div className="container auth-shell wide"><OrganizationForm/></div></section></>; }
