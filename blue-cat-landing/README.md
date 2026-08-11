# Blue Cat Portal Comercial

Landing y portal independiente para presentar Blue Cat, registrar organizaciones y preparar el flujo de compra de licencias. El ERP/POS local continúa aislado en `Blue-Cat/`; este proyecto nunca accede a su base operativa.

## Requisitos

- Node.js 20.9 o superior.
- MySQL 8.0+.
- HTTPS obligatorio fuera del entorno local.

## Inicio local

```powershell
cd C:\laragon\www\blue-cat-landing
Copy-Item .env.example .env.local
npm ci
npm run db:migrate
npm run dev
```

Abre `http://localhost:3000`. Laragon es opcional: Next.js se ejecuta con Node y no debe servirse la carpeta `.next` desde Apache.

Antes de migrar, configura en `.env.local`:

- `DATABASE_URL`: conexión PostgreSQL. En Supabase desplegado usa el pooler (puerto 6543), nunca el host directo `db.PROJECT_REF.supabase.co`; el portal mantiene sus tablas en el esquema `landing`.
- `IDENTITY_DATA_KEY`: exactamente 32 bytes codificados en Base64.
- `IDENTITY_HASH_PEPPER`: secreto aleatorio de 32 caracteres o más.
- `OUTBOX_WORKER_TOKEN`: secreto aleatorio de 32 caracteres o más.
- `CURRENT_TERMS_VERSION` y `CURRENT_PRIVACY_VERSION`: versiones legales vigentes.
- `PURCHASE_TOKEN_SECRET`: secreto aleatorio de 32 caracteres o más.

Generación de ejemplo en PowerShell:

```powershell
$bytes = [byte[]]::new(32)
[Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
[Convert]::ToBase64String($bytes)
```

No copies valores de producción en el repositorio.

## Operación de identidad

Crear o actualizar el primer operador:

```powershell
$env:OPERATOR_EMAIL = "operaciones@tu-dominio.cl"
$env:OPERATOR_NAME = "Operaciones Blue Cat"
$env:OPERATOR_PASSWORD = "una-clave-unica-y-larga"
npm run identity:bootstrap-operator
Remove-Item Env:OPERATOR_EMAIL, Env:OPERATOR_NAME, Env:OPERATOR_PASSWORD
```

El operador debe activar MFA desde `/portal/seguridad` antes de utilizar las rutas administrativas. Las contraseñas nunca se generan ni se envían por correo.

Procesar el outbox de correo desde un job privado:

```powershell
$headers = @{ Authorization = "Bearer $env:OUTBOX_WORKER_TOKEN" }
Invoke-RestMethod -Method Post -Uri http://localhost:3000/api/internal/email-outbox/process -Headers $headers
```

En desarrollo, `EMAIL_PROVIDER=console` registra enlaces de prueba sin enviar correo. En producción se requiere `EMAIL_PROVIDER=resend`, `RESEND_API_KEY` y un remitente verificado.

## Comercio real

Las transferencias están bloqueadas por defecto. Solo se habilitan cuando:

```dotenv
COMMERCE_ENABLED=true
LEGAL_STATUS=approved
OFFER_VERSION=...
CURRENT_TERMS_VERSION=...
CURRENT_PRIVACY_VERSION=...
```

También deben estar definidos precios, datos bancarios, proceso de conciliación y controles de comprobantes. Esta barrera evita recibir pagos durante desarrollo o revisión legal.

## Calidad

```powershell
npm run lint
npm run typecheck
npm test
npm run db:migrate
npm run test:identity
npm run build
npm audit --audit-level=high
```

`test:identity` usa la base definida en `DATABASE_URL` y debe ejecutarse únicamente sobre una base de prueba vacía o desechable.

## Estado del alcance

Sprint 2 incluye registro, verificación de correo, login/logout, recuperación de contraseña, sesiones revocables, MFA para operadores, organizaciones, membresías, perfil de facturación, consentimientos y outbox transaccional.

La recepción productiva de transferencias, conciliación bancaria reforzada, licencias Ed25519, descargas protegidas y activación del instalador pertenecen a sprints posteriores. Consulta [docs/portal-identity.md](docs/portal-identity.md), [docs/architecture.md](docs/architecture.md), [docs/security.md](docs/security.md) y [docs/payment-transfer-workflow.md](docs/payment-transfer-workflow.md).
