import type { Metadata } from "next";
import { RegisterForm } from "@/components/portal/register-form";
import { PageHero } from "@/components/marketing/page-hero";

export const metadata: Metadata = { title: "Crear cuenta", description: "Crea una cuenta segura para el portal de Blue Cat." };

export default function CreateAccountPage() {
  const termsVersion = process.env.CURRENT_TERMS_VERSION || "terms-2026-07";
  const privacyVersion = process.env.CURRENT_PRIVACY_VERSION || "privacy-2026-07";
  return <><PageHero eyebrow="Cuenta comercial" title="Tu identidad para comprar y administrar Blue Cat.">Verifica tu correo, crea tu organización y conserva el control de tus futuros pedidos desde un portal seguro.</PageHero><section className="section"><div className="container auth-shell"><RegisterForm termsVersion={termsVersion} privacyVersion={privacyVersion}/></div></section></>;
}
