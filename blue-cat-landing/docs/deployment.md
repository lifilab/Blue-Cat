# Despliegue

## Desarrollo

Ejecuta las migraciones, configura `.env.local` y utiliza `npm run dev`.

## Producción Node/VPS

1. Configura secretos fuera del repositorio y genera tokens aleatorios de al menos 32 caracteres.
2. Ejecuta migraciones con una cuenta de despliegue.
3. Ejecuta `npm ci`, `npm run build` y `npm run start`.
4. Coloca un proxy HTTPS delante de Next.js.
5. Restringe la base de datos y el API `/api/admin/*` a la red privada.
6. No uses el almacenamiento local de comprobantes en hosting efímero.

## Vercel o Cloudflare

Antes de migrar, usar una base administrada compatible y mover futuros comprobantes/instaladores a almacenamiento de objetos privado. No depender del disco efímero. Verificar compatibilidad del driver MySQL con el runtime seleccionado.
