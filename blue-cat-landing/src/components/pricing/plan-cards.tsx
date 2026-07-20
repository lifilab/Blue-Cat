import Link from "next/link";
import { Check } from "lucide-react";
import { licensePlans } from "@/config/commercial";

export function PlanCards() {
  return <div className="pricing-grid">{licensePlans.map((plan) => <article className={`pricing-card ${plan.highlighted ? "highlight" : ""}`} key={plan.id}><span className="plan-label">{plan.billingLabel}</span><h3>{plan.name}</h3><p className="muted">{plan.tagline}</p><div className="price">{plan.priceLabel}<small>No publicamos un precio hasta definir el alcance.</small></div><p className="muted" style={{ marginTop: 0 }}>{plan.description}</p><ul className="feature-list">{plan.features.map((feature) => <li key={feature}><Check size={17}/><span>{feature}</span></li>)}</ul><Link className={`button ${plan.highlighted ? "button-primary" : "button-secondary"}`} href={`/comprar?plan=${plan.id}`}>Solicitar {plan.name}</Link></article>)}</div>;
}
