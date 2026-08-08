import type { Metadata } from "next";
import { LoginForm } from "@/components/portal/login-form";
import { PageHero } from "@/components/marketing/page-hero";

export const metadata: Metadata = { title: "Ingresar", description: "Accede al portal seguro de Blue Cat." };
export default function LoginPage() { return <><PageHero eyebrow="Portal Blue Cat" title="Accede a tu cuenta.">Tus organizaciones, datos de facturación y seguridad en un solo lugar.</PageHero><section className="section"><div className="container auth-shell"><LoginForm/></div></section></>; }
