import type { Metadata } from "next";
import "./globals.css";
import { SiteFooter } from "@/components/layout/site-footer";
import { SiteHeader } from "@/components/layout/site-header";
import { siteConfig } from "@/config/site";

export const metadata: Metadata = {
  metadataBase: new URL(siteConfig.url),
  title: { default: "Blue Cat — ERP y POS instalable", template: "%s | Blue Cat" },
  description: siteConfig.description,
  openGraph: { title: "Blue Cat — ERP y POS instalable", description: siteConfig.description, url: siteConfig.url, siteName: "Blue Cat", locale: "es_CL", type: "website", images: [{ url: "/og.png", width: 1792, height: 1024, alt: "Blue Cat, ERP y POS instalable" }] },
  twitter: { card: "summary_large_image", title: "Blue Cat", description: siteConfig.description, images: ["/og.png"] },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="es"><body><a className="skip-link" href="#contenido">Saltar al contenido</a><SiteHeader/><main id="contenido">{children}</main><SiteFooter/></body></html>;
}
