"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { portalMutation } from "@/lib/portal-client";

export function OrganizationForm() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError("");
    const form = new FormData(event.currentTarget);
    const data = Object.fromEntries(form.entries());
    try {
      await portalMutation<{ id: string; slug: string }>("/api/organizations", "POST", data);
      window.location.assign("/portal");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "No pudimos crear la organización.");
      setLoading(false);
    }
  }

  return <form className="form-card organization-form" onSubmit={submit}>
    <div className="form-grid">
      <div className="field full"><label>Razón social<input name="legalName" autoComplete="organization" maxLength={180} required /></label></div>
      <div className="field full"><label>Nombre comercial<input name="tradingName" maxLength={180} /></label></div>
      <div className="field"><label>RUT / Identificador tributario<input name="taxId" maxLength={50} /></label></div>
      <div className="field"><label>Correo de facturación<input name="billingEmail" type="email" autoComplete="email" required /></label></div>
      <div className="field"><label>País<select name="country" defaultValue="CL" required><option value="CL">Chile</option><option value="AR">Argentina</option><option value="PE">Perú</option><option value="CO">Colombia</option><option value="MX">México</option></select></label></div>
      <div className="field"><label>Ciudad<input name="city" autoComplete="address-level2" required /></label></div>
      <div className="field full"><label>Dirección de facturación<input name="addressLine" autoComplete="street-address" required /></label></div>
      <div className="field"><label>Región / Estado<input name="region" autoComplete="address-level1" /></label></div>
      <div className="field"><label>Código postal<input name="postalCode" autoComplete="postal-code" /></label></div>
      <div className="field"><label>Moneda<select name="currency" defaultValue="CLP"><option value="CLP">CLP — Peso chileno</option><option value="USD">USD — Dólar</option></select></label></div>
    </div>
    {error && <p className="form-status" role="alert">{error}</p>}
    <div className="form-actions"><Link className="button button-secondary" href="/portal">Cancelar</Link><button className="button button-primary" disabled={loading}>{loading ? "Creando…" : "Crear organización"}</button></div>
  </form>;
}
