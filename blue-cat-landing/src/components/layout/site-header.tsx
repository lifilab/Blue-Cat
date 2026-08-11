"use client";

import Image from "next/image";
import Link from "next/link";
import { Menu, X, LogOut, User } from "lucide-react";
import { useState, useEffect } from "react";
import { navigation } from "@/config/site";
import { readPortalCsrf } from "@/lib/portal-client";

export function SiteHeader() {
  const [open, setOpen] = useState(false);
  const [user, setUser] = useState<{ displayName: string } | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    fetch("/api/auth/session", { cache: "no-store", signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) return;
        const payload = await response.json();
        if (payload?.data?.account) {
          setUser(payload.data.account);
        }
      })
      .catch(() => {});
    return () => controller.abort();
  }, []);

  async function handleLogout() {
    try {
      await fetch("/api/auth/logout", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": readPortalCsrf(),
        },
      });
      setUser(null);
      window.location.assign("/");
    } catch (e) {
      console.error("Logout failed", e);
    }
  }

  return (
    <header className="site-header">
      <div className="container header-row">
        <Link className="brand" href="/" aria-label="Blue Cat, inicio">
          <Image src="/brand/blue-cat-logo.png" alt="" width={48} height={48} priority />
          <span>Blue Cat</span>
        </Link>
        <nav className="desktop-nav" aria-label="Navegación principal">
          {navigation.map((item) => <Link key={item.href} href={item.href}>{item.label}</Link>)}
          {user ? (
            <>
              <Link className="account-access-link" href="/portal" style={{ display: "inline-flex", alignItems: "center", gap: "0.3rem" }}>
                <User size={15} /> {user.displayName}
              </Link>
              <button 
                type="button" 
                onClick={handleLogout} 
                style={{ 
                  background: "none", 
                  border: "none", 
                  color: "var(--muted)", 
                  fontSize: "0.93rem", 
                  fontWeight: 650, 
                  cursor: "pointer",
                  display: "inline-flex",
                  alignItems: "center",
                  gap: "0.3rem"
                }}
              >
                <LogOut size={15} /> Salir
              </button>
              <Link className="button button-request-license" href="/comprar">Solicitar licencia</Link>
            </>
          ) : (
            <Link className="account-access-link" href="/ingresar">Iniciar sesión o crear cuenta</Link>
          )}
        </nav>
        <button className="menu-button" type="button" onClick={() => setOpen((value) => !value)} aria-expanded={open} aria-controls="mobile-navigation" aria-label={open ? "Cerrar menú" : "Abrir menú"}>
          {open ? <X size={20} /> : <Menu size={20} />}
        </button>
      </div>
      <nav id="mobile-navigation" className={`container mobile-nav ${open ? "open" : ""}`} aria-label="Navegación móvil">
        {navigation.map((item) => <Link key={item.href} href={item.href} onClick={() => setOpen(false)}>{item.label}</Link>)}
        {user ? (
          <>
            <Link href="/portal" onClick={() => setOpen(false)} style={{ display: "flex", alignItems: "center", gap: "0.5rem" }}>
              <User size={16} /> Mi portal ({user.displayName})
            </Link>
            <button 
              type="button" 
              onClick={() => { setOpen(false); handleLogout(); }} 
              style={{ 
                display: "flex", 
                width: "100%", 
                textAlign: "left", 
                padding: "0.8rem 0.25rem", 
                borderBottom: "1px solid var(--line)", 
                fontWeight: 650,
                background: "none",
                border: "none",
                color: "var(--ink)",
                alignItems: "center",
                gap: "0.5rem",
                cursor: "pointer"
              }}
            >
              <LogOut size={16} /> Cerrar sesión
            </button>
            <Link className="button button-request-license" href="/comprar" onClick={() => setOpen(false)} style={{ marginTop: "0.8rem" }}>
              Solicitar licencia
            </Link>
          </>
        ) : (
          <Link href="/ingresar" onClick={() => setOpen(false)}>Iniciar sesión o crear cuenta</Link>
        )}
      </nav>
    </header>
  );
}
