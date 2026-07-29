import Link from "next/link";
export default function NotFound(){return <section className="section"><div className="container prose"><span className="eyebrow">404</span><h1 className="headline">Esta página no existe.</h1><p>Vuelve al inicio o revisa las licencias disponibles.</p><Link className="button button-primary" href="/">Volver al inicio</Link></div></section>}
