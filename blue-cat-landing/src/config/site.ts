export const siteConfig = {
  name: "Blue Cat",
  description:
    "ERP y punto de venta instalable para controlar ventas, caja, inventario y clientes desde tu propia operación.",
  url: process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000",
  email: process.env.NEXT_PUBLIC_COMMERCIAL_EMAIL ?? "ventas@tu-dominio.cl",
  company: "Lifilab",
} as const;

export const navigation = [
  { href: "/producto", label: "Producto" },
  { href: "/modulos", label: "Módulos" },
  { href: "/licencias", label: "Licencias" },
  { href: "/cloud-sync", label: "Cloud Sync" },
  { href: "/tutoriales", label: "Tutoriales" },
] as const;
