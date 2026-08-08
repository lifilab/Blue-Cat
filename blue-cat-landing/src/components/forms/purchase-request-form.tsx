"use client";

import Link from "next/link";
import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowRight, LoaderCircle } from "lucide-react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { useState } from "react";
import { licensePlans } from "@/config/commercial";
import { purchaseRequestSchema, type CreatedPurchaseRequest, type PurchaseRequestFormInput, type PurchaseRequestInput } from "@/modules/purchases/domain/purchase-request";

interface ApiError { error?: { message?: string; trackingId?: string }; }

export function PurchaseRequestForm({ initialPlan, initialCloud }: { initialPlan: "pyme" | "enterprise"; initialCloud: boolean }) {
  const router = useRouter();
  const [idempotencyKey] = useState(() => crypto.randomUUID());
  const { register, handleSubmit, formState: { errors, isSubmitting }, setError } = useForm<PurchaseRequestFormInput, unknown, PurchaseRequestInput>({
    resolver: zodResolver(purchaseRequestSchema),
    defaultValues: { planId: initialPlan, estimatedBranches: 1, wantsCloudSync: initialCloud, acceptsTerms: false, acceptsPrivacy: false, website: "" },
  });

  async function onSubmit(values: PurchaseRequestInput) {
    try {
      const response = await fetch("/api/purchase-requests", { method: "POST", headers: { "Content-Type": "application/json", "Idempotency-Key": idempotencyKey }, body: JSON.stringify(values) });
      const payload = await response.json() as { data?: CreatedPurchaseRequest } & ApiError;
      if (!response.ok || !payload.data) throw new Error(payload.error?.message ?? "No pudimos registrar la solicitud.");
      router.push(`/seguimiento/${encodeURIComponent(payload.data.trackingId)}#token=${payload.data.accessToken}`);
    } catch (error) {
      setError("root", { message: error instanceof Error ? error.message : "No pudimos registrar la solicitud." });
    }
  }

  return <form className="form-card" onSubmit={handleSubmit(onSubmit)} noValidate>
    <div className="form-grid">
      <Field label="Nombre o razón social" error={errors.businessName?.message}><input autoComplete="organization" aria-invalid={Boolean(errors.businessName)} {...register("businessName")}/></Field>
      <Field label="Nombre del contacto" error={errors.contactName?.message}><input autoComplete="name" aria-invalid={Boolean(errors.contactName)} {...register("contactName")}/></Field>
      <Field label="Correo" error={errors.email?.message}><input type="email" autoComplete="email" aria-invalid={Boolean(errors.email)} {...register("email")}/></Field>
      <Field label="Teléfono" error={errors.phone?.message}><input type="tel" autoComplete="tel" aria-invalid={Boolean(errors.phone)} {...register("phone")}/></Field>
      <Field label="País" error={errors.country?.message}><input autoComplete="country-name" aria-invalid={Boolean(errors.country)} {...register("country")}/></Field>
      <Field label="Ciudad" error={errors.city?.message}><input autoComplete="address-level2" aria-invalid={Boolean(errors.city)} {...register("city")}/></Field>
      <Field label="Identificación fiscal (opcional)" error={errors.taxId?.message}><input aria-invalid={Boolean(errors.taxId)} {...register("taxId")}/></Field>
      <Field label="Licencia" error={errors.planId?.message}><select aria-invalid={Boolean(errors.planId)} {...register("planId")}>{licensePlans.map(plan=><option value={plan.id} key={plan.id}>{plan.name}</option>)}</select></Field>
      <Field label="Sucursales estimadas" error={errors.estimatedBranches?.message}><input type="number" min="1" max="999" aria-invalid={Boolean(errors.estimatedBranches)} {...register("estimatedBranches")}/></Field>
      <div className="field" style={{justifyContent:"end"}}><div className="check-field"><input id="cloud" type="checkbox" {...register("wantsCloudSync")}/><label htmlFor="cloud">Quiero evaluar Blue Cat Cloud Sync como servicio mensual adicional.</label></div></div>
      <Field className="full" label="Cuéntanos sobre tu operación (opcional)" error={errors.message?.message}><textarea {...register("message")}/></Field>
      <label className="honeypot" aria-hidden="true"><span>No completar</span><input tabIndex={-1} autoComplete="off" {...register("website")}/></label>
      <div className="field full"><div className="check-field"><input id="terms" type="checkbox" {...register("acceptsTerms")}/><label htmlFor="terms">Acepto los <Link href="/terminos">términos provisionales</Link> y entiendo que la solicitud no constituye todavía una licencia emitida.</label></div>{errors.acceptsTerms && <span className="field-error">{errors.acceptsTerms.message}</span>}</div>
      <div className="field full"><div className="check-field"><input id="privacy" type="checkbox" {...register("acceptsPrivacy")}/><label htmlFor="privacy">Acepto el tratamiento de mis datos según la <Link href="/privacidad">política de privacidad</Link>.</label></div>{errors.acceptsPrivacy && <span className="field-error">{errors.acceptsPrivacy.message}</span>}</div>
    </div>
    {errors.root && <p className="form-status" role="alert">{errors.root.message}</p>}
    <button className="button button-primary" type="submit" disabled={isSubmitting} style={{marginTop:"1.5rem",width:"100%"}}>{isSubmitting ? <><LoaderCircle size={18} className="animate-spin"/> Registrando…</> : <>Continuar a transferencia <ArrowRight size={18}/></>}</button>
  </form>;
}

function Field({ label, error, className, children }: { label: string; error?: string; className?: string; children: React.ReactNode }) {
  return <div className={`field ${className ?? ""}`}><label><span>{label}</span>{children}</label>{error && <span className="field-error">{error}</span>}</div>;
}
