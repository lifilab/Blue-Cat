import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { TutorialPlayer } from "@/components/tutorials/tutorial-player";
import { getTutorial, tutorials } from "@/config/tutorials";
export function generateStaticParams(){return tutorials.map(({slug})=>({slug}));}
export async function generateMetadata({params}:{params:Promise<{slug:string}>}):Promise<Metadata>{const {slug}=await params;const tutorial=getTutorial(slug);return tutorial?{title:tutorial.title,description:tutorial.description}:{title:"Tutorial no encontrado"};}
export default async function TutorialPage({params}:{params:Promise<{slug:string}>}){const {slug}=await params;const tutorial=getTutorial(slug);if(!tutorial)notFound();return <><section className="page-hero"><div className="container"><nav aria-label="Migas de pan"><Link href="/tutoriales" className="muted">Tutoriales</Link> / {tutorial.category}</nav><h1 className="headline">{tutorial.title}</h1><p className="lede">{tutorial.description} Usa las flechas izquierda y derecha para avanzar.</p></div></section><section className="section"><div className="container"><TutorialPlayer tutorial={tutorial}/></div></section></>}
