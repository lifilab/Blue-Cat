import type { Metadata } from "next";
import { ResetPasswordForm } from "@/components/portal/password-recovery";

export const metadata: Metadata = { title: "Nueva contraseña", robots: { index: false, follow: false } };
export default function ResetPasswordPage() { return <section className="section"><div className="container auth-shell"><ResetPasswordForm/></div></section>; }
