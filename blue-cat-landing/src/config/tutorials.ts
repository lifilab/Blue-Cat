export interface TutorialStep {
  id: string;
  title: string;
  description: string;
  cue: "navigate" | "select" | "input" | "confirm";
}

export interface Tutorial {
  slug: string;
  title: string;
  description: string;
  category: string;
  level: "Básico" | "Intermedio";
  estimatedMinutes: number;
  steps: readonly TutorialStep[];
}

export const tutorials: readonly Tutorial[] = [
  {
    slug: "abrir-y-cerrar-caja",
    title: "Abrir y cerrar caja",
    description: "Recorre el ciclo de una sesión de caja antes y después de vender.",
    category: "POS",
    level: "Básico",
    estimatedMinutes: 4,
    steps: [
      { id: "open-pos", title: "Ingresa al POS", description: "Desde Inicio, abre el módulo POS con un usuario autorizado.", cue: "navigate" },
      { id: "open-cash", title: "Abre la caja", description: "Selecciona Abrir caja, identifica la caja y registra el monto inicial.", cue: "input" },
      { id: "review", title: "Revisa la sesión", description: "Comprueba ventas y medios de pago en el detalle de la sesión.", cue: "select" },
      { id: "close", title: "Cierra la caja", description: "Registra el monto real, revisa la diferencia y confirma el cierre.", cue: "confirm" },
    ],
  },
  {
    slug: "registrar-una-venta",
    title: "Registrar una venta",
    description: "Aprende el recorrido básico desde el producto hasta el cobro.",
    category: "Ventas",
    level: "Básico",
    estimatedMinutes: 5,
    steps: [
      { id: "search", title: "Busca el producto", description: "Usa el nombre, código o lector para encontrar el producto.", cue: "input" },
      { id: "cart", title: "Revisa el carro", description: "Confirma cantidad, precio y beneficios aplicados antes de cobrar.", cue: "select" },
      { id: "customer", title: "Asocia un cliente", description: "Opcionalmente busca y selecciona un cliente para la venta.", cue: "select" },
      { id: "pay", title: "Confirma el pago", description: "Selecciona el medio de pago y finaliza la operación.", cue: "confirm" },
    ],
  },
  {
    slug: "crear-un-producto",
    title: "Crear un producto",
    description: "Configura los datos esenciales antes de incorporarlo al inventario.",
    category: "Inventario",
    level: "Básico",
    estimatedMinutes: 6,
    steps: [
      { id: "inventory", title: "Abre Inventario", description: "Entra al módulo Inventario y selecciona Productos.", cue: "navigate" },
      { id: "identity", title: "Identifica el producto", description: "Completa código o SKU, nombre y clasificación.", cue: "input" },
      { id: "pricing", title: "Define valores", description: "Registra costo y precio de venta según la configuración tributaria.", cue: "input" },
      { id: "save", title: "Guarda y revisa", description: "Confirma los datos y luego registra existencias mediante un movimiento de stock.", cue: "confirm" },
    ],
  },
] as const;

export function getTutorial(slug: string): Tutorial | undefined {
  return tutorials.find((tutorial) => tutorial.slug === slug);
}
