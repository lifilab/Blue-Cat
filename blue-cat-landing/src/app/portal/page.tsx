import type { Metadata } from "next";
import { PortalDashboard } from "@/components/portal/portal-dashboard";

export const metadata: Metadata = { title: "Mi portal", robots: { index: false, follow: false } };
export default function PortalPage() { return <section className="section portal-page"><div className="container"><PortalDashboard/></div></section>; }
