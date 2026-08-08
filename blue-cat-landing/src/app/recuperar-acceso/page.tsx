import type { Metadata } from "next";
import { ForgotPasswordForm } from "@/components/portal/password-recovery";
import { PageHero } from "@/components/marketing/page-hero";

export const metadata: Metadata = { title: "Recuperar acceso", robots: { index: false, follow: false } };
export default function RecoverAccessPage() { return <><PageHero eyebrow="Recuperación segura" title="Vuelve a tu cuenta.">El enlace es de un solo uso, vence en 30 minutos y nunca contiene tu contraseña.</PageHero><section className="section"><div className="container auth-shell"><ForgotPasswordForm/></div></section></>; }
