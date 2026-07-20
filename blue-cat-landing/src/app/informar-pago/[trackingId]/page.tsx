import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PaymentReportForm } from "@/components/forms/payment-report-form";
import { PageHero } from "@/components/marketing/page-hero";

export const metadata: Metadata = { title: "Informar transferencia", robots: { index: false, follow: false }, referrer: "no-referrer" };

export default async function ReportPaymentPage({ params }: { params: Promise<{ trackingId: string }> }) {
  const { trackingId: raw } = await params;
  const trackingId = raw.toUpperCase();
  if (!/^BC-\d{4}-[A-F0-9]{10}$/.test(trackingId)) notFound();
  return <><PageHero eyebrow="Transferencia bancaria" title="Informa el pago para iniciar la revisión.">El comprobante se valida por su contenido y se almacena fuera del directorio público. Informarlo no significa que el pago ya esté aprobado.</PageHero><section className="section"><div className="container form-shell"><PaymentReportForm trackingId={trackingId} defaultCurrency={(process.env.COMMERCIAL_CURRENCY ?? "CLP").toUpperCase()}/><aside className="summary-card"><h2>Controles aplicados</h2><ul><li>Acceso mediante enlace privado.</li><li>Máximo 5 MB.</li><li>Validación binaria PDF, PNG o JPEG.</li><li>Nombre aleatorio y hash SHA-256.</li><li>Almacenamiento fuera de la web pública.</li><li>Revisión manual antes de aprobar.</li></ul></aside></div></section></>;
}
