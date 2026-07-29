import type { Metadata } from "next";
import { EmailVerification } from "@/components/portal/email-verification";

export const metadata: Metadata = { title: "Verificar correo", robots: { index: false, follow: false } };
export default function VerifyEmailPage() { return <section className="section"><div className="container auth-shell"><EmailVerification/></div></section>; }
