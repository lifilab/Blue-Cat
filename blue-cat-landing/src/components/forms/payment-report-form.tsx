"use client";

import Link from "next/link";
import { zodResolver } from "@hookform/resolvers/zod";
import { CheckCircle2, FileUp, LoaderCircle } from "lucide-react";
import { useState, useSyncExternalStore } from "react";
import { useForm } from "react-hook-form";
import { paymentReportSchema, type PaymentReportFormInput } from "@/modules/payments/domain/payment-report";

const publicPaymentSchema = paymentReportSchema.omit({ accessToken: true });
type PublicPaymentInput = Omit<PaymentReportFormInput, "accessToken">;
const subscribeHash = (callback: () => void) => { window.addEventListener("hashchange", callback); return () => window.removeEventListener("hashchange", callback); };
const hashSnapshot = () => window.location.hash.startsWith("#token=") ? window.location.hash.slice(7) : "";
const serverHashSnapshot = () => "";

export function PaymentReportForm({ trackingId, defaultCurrency }: { trackingId: string; defaultCurrency: string }) {
  const token = useSyncExternalStore(subscribeHash, hashSnapshot, serverHashSnapshot);
  const [evidence, setEvidence] = useState<File | null>(null);
  const [completed, setCompleted] = useState<{ reportId: string } | null>(null);
  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<PublicPaymentInput>({
    resolver: zodResolver(publicPaymentSchema),
    defaultValues: { trackingId, amountMinor: "", currency: defaultCurrency, transferDate: new Date().toISOString().slice(0,10), bankReference: "", acceptsPrivacy: false, website: "" },
  });

  async function submit(values: PublicPaymentInput) {
    if (!token) { setError("root", { message: "Abre el enlace privado de seguimiento antes de informar el pago." }); return; }
    if (!evidence) { setError("root", { message: "Adjunta un comprobante PDF, PNG o JPEG." }); return; }
    const form = new FormData();
    Object.entries({ ...values, accessToken: token }).forEach(([key,value]) => form.set(key, String(value)));
    form.set("evidence", evidence);
    try {
      const response = await fetch("/api/payment-reports", { method: "POST", body: form });
      const payload = await response.json() as { data?: { reportId: string }; error?: { message?: string } };
      if (!response.ok || !payload.data) throw new Error(payload.error?.message ?? "No pudimos registrar el comprobante.");
      setCompleted({ reportId: payload.data.reportId });
    } catch (error) { setError("root", { message: error instanceof Error ? error.message : "No pudimos registrar el comprobante." }); }
  }

  if (completed) return <div className="card"><span className="card-icon"><CheckCircle2/></span><h2>Pago informado</h2><p>El comprobante quedó almacenado de forma privada y espera revisión. Referencia interna: {completed.reportId}</p><a className="button button-primary" href={`/seguimiento/${trackingId}#token=${token}`}>Volver al seguimiento</a></div>;

  return <form className="form-card" onSubmit={handleSubmit(submit)} noValidate>
    <div className="form-grid">
      <Field label="Código de seguimiento" error={errors.trackingId?.message}><input readOnly {...register("trackingId")}/></Field>
      <Field label="Monto transferido (sin separadores)" error={errors.amountMinor?.message}><input type="number" min="1" inputMode="numeric" {...register("amountMinor")}/></Field>
      <Field label="Moneda" error={errors.currency?.message}><input maxLength={3} {...register("currency")}/></Field>
      <Field label="Fecha de transferencia" error={errors.transferDate?.message}><input type="date" max={new Date().toISOString().slice(0,10)} {...register("transferDate")}/></Field>
      <Field className="full" label="Referencia bancaria" error={errors.bankReference?.message}><input placeholder="Número de operación o referencia" {...register("bankReference")}/></Field>
      <div className="field full"><label htmlFor="evidence"><span>Comprobante privado</span><input id="evidence" type="file" accept="application/pdf,image/png,image/jpeg" onChange={(event)=>setEvidence(event.target.files?.[0] ?? null)}/><small className="muted">PDF, PNG o JPEG real. Máximo 5 MB.</small></label></div>
      <label className="honeypot" aria-hidden="true"><span>No completar</span><input tabIndex={-1} autoComplete="off" {...register("website")}/></label>
      <div className="field full"><div className="check-field"><input id="payment-privacy" type="checkbox" {...register("acceptsPrivacy")}/><label htmlFor="payment-privacy">Acepto que el comprobante se almacene y revise para verificar la transferencia, según la <Link href="/privacidad">política de privacidad</Link>.</label></div>{errors.acceptsPrivacy && <span className="field-error">{errors.acceptsPrivacy.message}</span>}</div>
    </div>
    {errors.root && <p className="form-status" role="alert">{errors.root.message}</p>}
    <button className="button button-primary" type="submit" disabled={isSubmitting} style={{marginTop:"1.5rem",width:"100%"}}>{isSubmitting ? <><LoaderCircle size={18} className="animate-spin"/> Registrando…</> : <><FileUp size={18}/> Informar transferencia</>}</button>
  </form>;
}

function Field({ label, error, className, children }: { label: string; error?: string; className?: string; children: React.ReactNode }) { return <div className={`field ${className ?? ""}`}><label><span>{label}</span>{children}</label>{error && <span className="field-error">{error}</span>}</div>; }
