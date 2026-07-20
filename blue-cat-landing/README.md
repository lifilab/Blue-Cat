# Blue Cat Landing

Sitio comercial independiente para presentar Blue Cat, explicar sus licencias y registrar solicitudes de compra por transferencia bancaria.

## Requisitos

- Node.js 20.9 o superior.
- MySQL 8 o MariaDB compatible para el flujo comercial.
- Laragon es opcional; Next.js se ejecuta mediante Node, no mediante Apache/PHP.

## Inicio local

```powershell
cd C:\laragon\www\blue-cat-landing
Copy-Item .env.example .env.local
npm install
Get-Content database/migrations/001_commercial_foundation.sql -Raw | mysql -u root
Get-Content database/migrations/002_payment_transfer_workflow.sql -Raw | mysql -u root
npm run dev
```

Abre `http://localhost:3000`. Si deseas usar un dominio de Laragon, configúralo como proxy inverso hacia el puerto de Next.js; no sirvas la carpeta `.next` desde Apache.

## Variables de entorno

- `NEXT_PUBLIC_SITE_URL`: URL canónica del sitio.
- `NEXT_PUBLIC_COMMERCIAL_EMAIL`: correo comercial público.
- `DATABASE_URL`: conexión MySQL exclusiva de la landing.
- `BANK_TRANSFER_INSTRUCTIONS`: instrucciones entregadas solo dentro del seguimiento privado.
- `PURCHASE_TOKEN_SECRET`: deriva tokens de seguimiento; mínimo 32 caracteres aleatorios.
- `PYME_PRICE_MINOR` y `ENTERPRISE_PRICE_MINOR`: importe en unidad mínima. Si falta, la compra queda `pending_quote`.
- `COMMERCIAL_CURRENCY`, `OFFER_VERSION` y `OFFER_VALID_DAYS`: snapshot de la oferta directa.
- `PAYMENT_EVIDENCE_DIR`: ruta absoluta o nombre de subdirectorio privado dentro de `storage/private/`, siempre fuera de `public/`.
- `ADMIN_API_TOKEN`: token temporal para el API interno de revisión; mínimo 32 caracteres.

Nunca confirmes pagos reales con los valores de ejemplo.

## Calidad

```powershell
npm run lint
npm run typecheck
npm run test
npm run build
npm run start
```

## Estado del alcance

Incluido: landing, rutas comerciales, contenido centralizado, tutoriales interactivos, solicitud idempotente, seguimiento privado, cotización configurable, reporte de transferencia, almacenamiento privado, revisión administrativa por API y auditoría.

Pendiente: identidad administrativa con MFA, antivirus/CDR de comprobantes, emisión criptográfica, activación y descargas protegidas. El token administrativo actual es una operación Beta local y no debe publicarse en Internet.

Consulta [docs/architecture.md](docs/architecture.md), [docs/commercial-model.md](docs/commercial-model.md) y [docs/payment-transfer-workflow.md](docs/payment-transfer-workflow.md).
