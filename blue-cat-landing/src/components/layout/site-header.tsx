"use client";

import Image from "next/image";
import Link from "next/link";
import { Menu, X } from "lucide-react";
import { useState } from "react";
import { navigation } from "@/config/site";

export function SiteHeader() {
  const [open, setOpen] = useState(false);
  return (
    <header className="site-header">
      <div className="container header-row">
        <Link className="brand" href="/" aria-label="Blue Cat, inicio">
          <Image src="/brand/blue-cat-logo.png" alt="" width={48} height={48} priority />
          <span>Blue Cat</span>
        </Link>
        <nav className="desktop-nav" aria-label="Navegación principal">
          {navigation.map((item) => <Link key={item.href} href={item.href}>{item.label}</Link>)}
          <Link className="button button-primary" href="/comprar">Solicitar licencia</Link>
        </nav>
        <button className="menu-button" type="button" onClick={() => setOpen((value) => !value)} aria-expanded={open} aria-controls="mobile-navigation" aria-label={open ? "Cerrar menú" : "Abrir menú"}>
          {open ? <X size={20} /> : <Menu size={20} />}
        </button>
      </div>
      <nav id="mobile-navigation" className={`container mobile-nav ${open ? "open" : ""}`} aria-label="Navegación móvil">
        {navigation.map((item) => <Link key={item.href} href={item.href} onClick={() => setOpen(false)}>{item.label}</Link>)}
        <Link href="/comprar" onClick={() => setOpen(false)}>Solicitar licencia</Link>
      </nav>
    </header>
  );
}
