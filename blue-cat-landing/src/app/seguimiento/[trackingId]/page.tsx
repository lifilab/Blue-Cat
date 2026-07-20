import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { PageHero } from "@/components/marketing/page-hero";
import { PurchaseTracker } from "@/components/purchases/purchase-tracker";

export const metadata: Metadata = { title: "Seguimiento de compra", robots: { index: false, follow: false }, referrer: "no-referrer" };

export default async function TrackingPage({ params }: { params: Promise<{ trackingId: string }> }) {
  const { trackingId: raw } = await params;
  const trackingId = raw.toUpperCase();
  if (!/^BC-\d{4}-[A-F0-9]{10}$/.test(trackingId)) notFound();
  return <><PageHero eyebrow="Seguimiento privado" title="Estado de tu solicitud.">El estado se actualiza a medida que cotizamos, verificamos el pago y preparamos la entrega.</PageHero><section className="section"><div className="container prose"><PurchaseTracker trackingId={trackingId}/></div></section></>;
}
