import Link from "next/link";
import { siteConfig } from "@/config/site";

const groups = [
  { title: "Producto", links: [["Producto", "/producto"], ["Módulos", "/modulos"], ["Tutoriales", "/tutoriales"], ["Actualizaciones", "/actualizaciones"]] },
  { title: "Comercial", links: [["Licencias", "/licencias"], ["Cloud Sync", "/cloud-sync"], ["Comprar", "/comprar"], ["Contacto", "/contacto"]] },
  { title: "Legal", links: [["Privacidad", "/privacidad"], ["Términos", "/terminos"], ["Licencia", "/licencia"], ["Preguntas frecuentes", "/preguntas-frecuentes"]] },
] as const;

export function SiteFooter() {
  return (
    <footer className="site-footer">
      <div className="container">
        <div className="footer-grid">
          <div><strong style={{ color: "white" }}>Blue Cat</strong><p>Control local para una operación que necesita velocidad, trazabilidad y claridad.</p></div>
          {groups.map((group) => <div key={group.title}><h3>{group.title}</h3>{group.links.map(([label, href]) => <Link key={href} href={href}>{label}</Link>)}</div>)}
        </div>
        <div className="footer-bottom"><span>© {new Date().getFullYear()} {siteConfig.company}. Blue Cat.</span><span>Información comercial sujeta a confirmación.</span></div>
      </div>
    </footer>
  );
}
