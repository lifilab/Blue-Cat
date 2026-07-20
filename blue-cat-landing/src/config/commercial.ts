export type Availability = "available" | "configuration" | "custom" | "planned";

export interface LicensePlan {
  id: "pyme" | "enterprise";
  name: string;
  tagline: string;
  description: string;
  billingLabel: string;
  priceLabel: string;
  features: readonly string[];
  updateMonths: number;
  perpetual: true;
  highlighted?: boolean;
}

export const licensePlans: readonly LicensePlan[] = [
  {
    id: "pyme",
    name: "Blue Cat PYME",
    tagline: "Listo para usar / Estándar",
    description: "Para comercios y empresas que quieren operar localmente con una implementación directa.",
    billingLabel: "Pago único",
    priceLabel: "Cotización personalizada",
    features: [
      "Instalación local",
      "POS, ventas y caja",
      "Productos e inventario",
      "Clientes y configuración operativa",
      "12 meses de actualizaciones",
      "Uso perpetuo de la última versión obtenida",
    ],
    updateMonths: 12,
    perpetual: true,
    highlighted: true,
  },
  {
    id: "enterprise",
    name: "Blue Cat Enterprise",
    tagline: "Automatización y Multi-sucursal",
    description: "Para operaciones que requieren evaluar sucursales, integraciones y configuraciones avanzadas.",
    billingLabel: "Pago único",
    priceLabel: "Evaluación comercial",
    features: [
      "Base funcional de Blue Cat",
      "Configuración para escenarios multi-sucursal",
      "Automatizaciones según evaluación",
      "Integraciones personalizadas",
      "Acompañamiento de implementación",
      "12 meses de actualizaciones",
    ],
    updateMonths: 12,
    perpetual: true,
  },
] as const;

export const cloudService = {
  id: "cloud-sync",
  name: "Blue Cat Cloud Sync",
  tagline: "Infraestructura mensual opcional",
  priceLabel: "Cotización según operación",
  description:
    "Servicio separado de la licencia para evaluar sincronización entre sucursales, respaldo remoto y continuidad operativa.",
  note: "La disponibilidad y alcance se confirman mediante evaluación técnica. No es obligatorio para usar Blue Cat localmente.",
} as const;

export const productCapabilities = [
  { name: "POS y caja", description: "Venta, apertura y cierre de caja, medios de pago y cotizaciones.", availability: "available" },
  { name: "Inventario", description: "Productos, bodegas, movimientos, ajustes, conteos, kardex, lotes y alertas.", availability: "available" },
  { name: "Ventas", description: "Historial, detalle, métricas operativas y cuadre por sesión.", availability: "available" },
  { name: "Clientes CRM", description: "Clientes, contactos, seguimiento comercial y reportes disponibles en el ERP.", availability: "available" },
  { name: "Promociones", description: "Reglas promocionales integradas al flujo del POS.", availability: "available" },
  { name: "Sucursales", description: "Modelo operativo preparado para configuraciones por empresa y sucursal.", availability: "configuration" },
  { name: "Integraciones", description: "Conectores y automatizaciones definidos según el caso de negocio.", availability: "custom" },
] as const satisfies readonly { name: string; description: string; availability: Availability }[];

export const purchaseStatuses = [
  "draft",
  "pending_quote",
  "pending_payment",
  "payment_reported",
  "under_review",
  "approved",
  "rejected",
  "license_generated",
  "download_available",
  "completed",
  "cancelled",
] as const;

export type PurchaseStatus = (typeof purchaseStatuses)[number];
