# Arquitectura

Blue Cat Landing es un proyecto Next.js App Router independiente del ERP PHP. Aplica un monolito modular: las rutas y componentes renderizan la experiencia; `modules/purchases` contiene validación y reglas del caso de uso; `infrastructure` implementa MySQL.

## Límites

- La landing no accede directamente a la base operativa del ERP.
- Los precios, planes y capacidades públicas viven en `src/config`.
- El navegador nunca emite licencias ni conoce claves privadas.
- Las APIs administrativas no se publican hasta tener autenticación y autorización.

La Fase 3 incorpora un API operativo interno autenticado mediante token de entorno para pilotos locales. No equivale a la autenticación administrativa definitiva: antes de exposición pública debe reemplazarse por identidades individuales, MFA y RBAC.

## Evolución

La persistencia puede migrar a MySQL administrado sin cambiar la UI. Los comprobantes usan disco privado local detrás de funciones aisladas; en cloud deben migrar a almacenamiento de objetos privado (S3/R2 o equivalente), cuarentena y escaneo. La entrega de instaladores debe usar grants temporales, no archivos públicos.
