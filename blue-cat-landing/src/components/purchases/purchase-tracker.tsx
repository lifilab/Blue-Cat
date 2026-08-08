"use client";

import Link from "next/link";
import { CheckCircle2, Clock3, LoaderCircle, ShieldAlert } from "lucide-react";
import { useEffect, useState, useSyncExternalStore } from "react";
import type { PurchaseStatus } from "@/config/commercial";

interface PurchaseView {
  trackingId: string;
  status: PurchaseStatus;
  expectedAmountMinor?: number;
  currency?: string;
  offerVersion?: string;
  offerExpiresAt?: string;
  offerExpired: boolean;
  updatedAt: string;
  bankInstructions?: string;
}

const subscribeHash = (callback: () => void) => { window.addEventListener("hashchange", callback); return () => window.removeEventListener("hashchange", callback); };
const hashSnapshot = () => window.location.hash.startsWith("#token=") ? window.location.hash.slice(7) : "";
const serverHashSnapshot = () => "";

const statusCopy: Record<PurchaseStatus, { title: string; text: string }> = {
  draft: { title: "Borrador", text: "La solicitud todavía no ha sido enviada." },
  pending_quote: { title: "Pendiente de cotización", text: "Revisaremos el alcance y prepararemos el monto antes de habilitar la transferencia." },
  pending_payment: { title: "Pendiente de transferencia", text: "La cotización está disponible. Realiza la transferencia y luego informa el pago." },
  payment_reported: { title: "Pago informado", text: "Recibimos el comprobante y está esperando revisión." },
  under_review: { title: "Pago en revisión", text: "Un responsable está verificando la transferencia." },
  approved: { title: "Pago aprobado", text: "La transferencia fue aprobada. La emisión de licencia continúa en la siguiente etapa." },
  rejected: { title: "Solicitud rechazada", text: "Contacta al equipo comercial para revisar el motivo y los próximos pasos." },
  license_generated: { title: "Licencia generada", text: "La licencia fue emitida." },
  download_available: { title: "Descarga disponible", text: "Existe una descarga autorizada para esta compra." },
  completed: { title: "Proceso completado", text: "La compra y entrega finalizaron." },
  cancelled: { title: "Solicitud cancelada", text: "Esta solicitud ya no está activa." },
};

export function PurchaseTracker({ trackingId }: { trackingId: string }) {
  const token = useSyncExternalStore(subscribeHash, hashSnapshot, serverHashSnapshot);
  const [view, setView] = useState<PurchaseView | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!token) return;
    const controller = new AbortController();
    fetch(`/api/purchase-requests/${encodeURIComponent(trackingId)}`, { headers: { "Purchase-Token": token }, cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        const payload = await response.json() as { data?: PurchaseView; error?: { message?: string } };
        if (!response.ok || !payload.data) throw new Error(payload.error?.message ?? "No pudimos consultar la solicitud.");
        setView(payload.data);
        setError("");
      })
      .catch((reason: unknown) => { if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : "No pudimos consultar la solicitud."); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [token, trackingId]);

  if (!token) return <StateCard icon={<ShieldAlert/>} title="Falta el acceso seguro">Abre el enlace completo entregado al registrar la solicitud. El código visible por sí solo no autoriza el acceso.</StateCard>;
  if (loading) return <StateCard icon={<LoaderCircle className="animate-spin"/>} title="Consultando solicitud">Estamos recuperando el estado más reciente.</StateCard>;
  if (error || !view) return <StateCard icon={<ShieldAlert/>} title="No pudimos abrir el seguimiento">{error || "El enlace no es válido o expiró."}</StateCard>;
  const copy = statusCopy[view.status];
  return <div className="card purchase-status-card">
    <span className="status"><CheckCircle2 size={15}/> Acceso verificado</span>
    <p className="plan-label" style={{marginTop:"1.25rem"}}>Seguimiento {view.trackingId}</p>
    <h2>{copy.title}</h2><p>{copy.text}</p>
    {view.status === "pending_payment" && view.offerExpired && <div className="notice"><strong>Cotización vencida.</strong> Solicita al equipo comercial que emita una actualización antes de transferir.</div>}
    {view.status === "pending_payment" && !view.offerExpired && view.expectedAmountMinor && view.currency && <>
      <div className="payment-amount"><span>Monto cotizado</span><strong>{formatMinor(view.expectedAmountMinor, view.currency)}</strong>{view.offerExpiresAt && <small>Válido hasta {new Date(view.offerExpiresAt).toLocaleDateString("es-CL")}</small>}</div>
      {view.bankInstructions && <div className="notice"><strong>Instrucciones de transferencia</strong><p style={{whiteSpace:"pre-wrap",marginBottom:0}}>{view.bankInstructions}</p></div>}
      <a className="button button-primary" href={`/informar-pago/${encodeURIComponent(trackingId)}#token=${token}`}>Ya realicé la transferencia</a>
    </>}
    <div className="secure-link-note"><Clock3 size={16}/><span>Guarda este enlace privado. No lo compartas: permite consultar e informar el pago.</span></div>
    <Link className="button button-secondary" href="/contacto">Necesito ayuda</Link>
  </div>;
}

function StateCard({icon,title,children}:{icon:React.ReactNode;title:string;children:React.ReactNode}){return <div className="card purchase-status-card"><span className="card-icon">{icon}</span><h2>{title}</h2><p>{children}</p><Link className="button button-secondary" href="/contacto">Contactar al equipo</Link></div>}

function formatMinor(amount: number, currency: string) {
  const decimals = Number(process.env.NEXT_PUBLIC_COMMERCIAL_CURRENCY_DECIMALS ?? 0);
  return new Intl.NumberFormat("es-CL", { style: "currency", currency, minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(amount / 10 ** decimals);
}
