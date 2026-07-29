import type { MetadataRoute } from "next";
import { siteConfig } from "@/config/site";
import { tutorials } from "@/config/tutorials";
export default function sitemap():MetadataRoute.Sitemap{const routes=["","/producto","/modulos","/licencias","/cloud-sync","/tutoriales","/actualizaciones","/preguntas-frecuentes","/contacto"];return [...routes.map(path=>({url:`${siteConfig.url}${path}`,lastModified:new Date(),changeFrequency:path===""?"weekly" as const:"monthly" as const,priority:path===""?1:.7})),...tutorials.map(item=>({url:`${siteConfig.url}/tutoriales/${item.slug}`,lastModified:new Date(),changeFrequency:"monthly" as const,priority:.6}))];}
