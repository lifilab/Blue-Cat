import type { Metadata } from "next";
import { PurchaseConfirmation } from "@/components/forms/purchase-confirmation";
import { PageHero } from "@/components/marketing/page-hero";
export const metadata:Metadata={title:"Confirmación de solicitud",robots:{index:false,follow:false}};
export default function ConfirmationPage(){return <><PageHero eyebrow="Seguimiento seguro" title="Solicitud de licencia.">Las solicitudes nuevas se administran mediante un enlace privado que no expone su token al servidor durante la navegación inicial.</PageHero><section className="section"><div className="container prose"><PurchaseConfirmation/></div></section></>}
