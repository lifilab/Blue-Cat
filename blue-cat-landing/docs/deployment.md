# Despliegue

El entorno público de staging tiene un procedimiento reproducible y una frontera explícita en [staging-deployment.md](staging-deployment.md). Su aceptación se registra con [staging-e2e-checklist.md](staging-e2e-checklist.md). Ningún despliegue de la landing autoriza exponer el ERP/POS local.

## Desarrollo

1. Copia `.env.example` a `.env.local`.
2. Genera secretos únicos y configura una base exclusiva.
3. Ejecuta `npm ci`, `npm run db:migrate` y `npm run dev`.
4. Usa `EMAIL_PROVIDER=console`; no reutilices datos productivos.

## Producción Node/VPS

1. Gestiona secretos en el proveedor de despliegue; nunca en archivos versionados.
2. Ejecuta migraciones con una cuenta limitada antes de iniciar la nueva versión.
3. Ejecuta `npm ci`, `npm run build` y `npm run start`.
4. Coloca un proxy HTTPS delante de Next.js y habilita headers de seguridad.
5. Ejecuta el outbox desde un job privado autenticado, no desde el navegador.
6. Crea el operador mediante bootstrap, borra las variables efímeras y activa MFA.
7. Restringe base, `/api/admin/*` y `/api/internal/*` por red y monitoreo.
8. Verifica health checks, alertas, backup y restauración antes de habilitar tráfico.

`COMMERCE_ENABLED=false` y `LEGAL_STATUS=draft` son los valores seguros. Solo se cambian con aprobación legal, precios, conciliación, soporte y controles de archivos listos.

## Plataformas efímeras

Antes de Vercel o servicios similares, utiliza MySQL administrado y mueve comprobantes/instaladores a almacenamiento de objetos privado. El disco local no es persistente ni compartido. El worker de correo debe ejecutarse con exclusión mutua o confiar en el claim transaccional del outbox.
