import Link from "next/link";

export function PurchaseConfirmation(){return <div className="card"><h2>El seguimiento ahora utiliza un enlace privado</h2><p>Para proteger cotizaciones y datos bancarios, la confirmación se abre desde el enlace seguro generado al enviar la solicitud. Si lo perdiste, contacta al equipo comercial.</p><Link className="button button-primary" href="/comprar">Crear una solicitud</Link></div>}
