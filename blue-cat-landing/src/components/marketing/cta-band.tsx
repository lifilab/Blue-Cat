import Link from "next/link";
import { ArrowRight } from "lucide-react";

export function CtaBand() {
  return <section className="section-tight"><div className="container"><div className="cta-band"><h2>Conversemos sobre la operación que Blue Cat debe resolver.</h2><Link className="button button-ghost" href="/comprar">Iniciar solicitud <ArrowRight size={18} /></Link></div></div></section>;
}
