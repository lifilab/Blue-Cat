import type { Metadata } from "next";
import { Boxes, ChartNoAxesCombined, HardDrive, ReceiptText, ShieldCheck, Users } from "lucide-react";
import { CtaBand } from "@/components/marketing/cta-band";
import { PageHero } from "@/components/marketing/page-hero";
import { ProductDemo } from "@/components/marketing/product-demo";

export const metadata: Metadata = { title: "Producto", description: "Conoce el ERP y punto de venta instalable Blue Cat." };
const benefits = [
  [ReceiptText, "Vende y cobra", "POS, sesiones de caja, medios de pago y cotizaciones integrados."],
  [Boxes, "Controla existencias", "Productos, bodegas, entradas, salidas, ajustes, conteos y trazabilidad."],
  [ChartNoAxesCombined, "Lee la operación", "Ventas, cuadros y métricas disponibles desde los módulos del sistema."],
  [Users, "Organiza el equipo", "Usuarios, empleados, roles y permisos para separar responsabilidades."],
  [HardDrive, "Trabaja local", "El servidor se instala dentro de la operación para reducir dependencia de Internet."],
  [ShieldCheck, "Conserva el control", "El acceso, la trazabilidad y los respaldos se diseñan como parte de la operación."],
] as const;
export default function ProductPage(){return <><PageHero eyebrow="El producto" title="Un sistema instalable para el ritmo real de tu negocio.">Blue Cat concentra la operación diaria en una interfaz web ejecutada desde infraestructura local. Los equipos autorizados se conectan al servidor de la empresa por su red.</PageHero><section className="section"><div className="container"><ProductDemo/></div></section><section className="section" style={{background:"white"}}><div className="container grid-3">{benefits.map(([Icon,title,text])=><article className="card" key={title}><span className="card-icon"><Icon size={21}/></span><h3>{title}</h3><p>{text}</p></article>)}</div></section><CtaBand/></>}
