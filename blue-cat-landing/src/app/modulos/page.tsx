import type { Metadata } from "next";
import { PageHero } from "@/components/marketing/page-hero";
import { CtaBand } from "@/components/marketing/cta-band";
import { productCapabilities } from "@/config/commercial";
export const metadata: Metadata={title:"Módulos",description:"Módulos y capacidades verificadas de Blue Cat."};
const labels={available:"Disponible",configuration:"Según configuración",custom:"Integración personalizada",planned:"Planificado"} as const;
export default function ModulesPage(){return <><PageHero eyebrow="Capacidades verificadas" title="Módulos conectados por una misma operación.">Esta lista se basa en pantallas y flujos presentes en Blue Cat. Una etiqueta distingue lo disponible de lo que necesita configuración o desarrollo personalizado.</PageHero><section className="section"><div className="container grid-3">{productCapabilities.map(item=><article className="card" key={item.name}><h2 style={{fontSize:"1.2rem",marginTop:0}}>{item.name}</h2><p>{item.description}</p><span className={`status ${item.availability === "configuration" ? "config" : item.availability === "custom" ? "custom" : ""}`}>{labels[item.availability]}</span></article>)}</div></section><CtaBand/></>}
