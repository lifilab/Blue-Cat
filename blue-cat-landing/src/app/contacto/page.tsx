import type { Metadata } from "next";
import Link from "next/link";
import { Mail, MessagesSquare } from "lucide-react";
import { PageHero } from "@/components/marketing/page-hero";
import { siteConfig } from "@/config/site";
export const metadata:Metadata={title:"Contacto",description:"Contacta al equipo comercial de Blue Cat."};
export default function ContactPage(){return <><PageHero eyebrow="Contacto" title="Hablemos con contexto.">Para cotizar una licencia usa la solicitud estructurada. Para una consulta general, escribe al canal comercial configurado.</PageHero><section className="section"><div className="container grid-2"><article className="card"><span className="card-icon"><MessagesSquare size={22}/></span><h2 style={{fontSize:"1.3rem"}}>Solicitud de licencia</h2><p>Entrega los datos necesarios para evaluar plan, sucursales y Cloud Sync.</p><Link className="button button-primary" style={{marginTop:"1.2rem"}} href="/comprar">Iniciar solicitud</Link></article><article className="card"><span className="card-icon"><Mail size={22}/></span><h2 style={{fontSize:"1.3rem"}}>Consulta comercial</h2><p>Configura un correo corporativo real antes de publicar. Dirección actual: {siteConfig.email}</p><a className="button button-secondary" style={{marginTop:"1.2rem"}} href={`mailto:${siteConfig.email}`}>Enviar correo</a></article></div></section></>}
