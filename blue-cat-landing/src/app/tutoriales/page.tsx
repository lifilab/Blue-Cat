import type { Metadata } from "next";
import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { PageHero } from "@/components/marketing/page-hero";
import { tutorials } from "@/config/tutorials";
export const metadata:Metadata={title:"Tutoriales",description:"Tutoriales interactivos de los flujos reales de Blue Cat."};
export default function TutorialsPage(){return <><PageHero eyebrow="Centro de aprendizaje" title="Aprende Blue Cat paso a paso.">Tutoriales configurables, navegables con teclado y construidos sobre flujos observados en el producto.</PageHero><section className="section"><div className="container grid-3">{tutorials.map(item=><article className="card" key={item.slug}><span className="plan-label">{item.category} · {item.level}</span><h2 style={{fontSize:"1.25rem"}}>{item.title}</h2><p>{item.description}</p><p className="muted" style={{fontSize:".8rem",marginTop:"1rem"}}>{item.steps.length} pasos · {item.estimatedMinutes} minutos</p><Link className="button button-secondary" style={{marginTop:"1rem"}} href={`/tutoriales/${item.slug}`}>Comenzar <ArrowRight size={17}/></Link></article>)}</div></section></>}
