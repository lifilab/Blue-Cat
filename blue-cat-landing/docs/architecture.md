# Arquitectura

Blue Cat Portal es una aplicación Next.js App Router independiente del ERP/POS PHP. Sigue un monolito modular: las rutas HTTP traducen solicitudes; los módulos concentran casos de uso y reglas; la infraestructura encapsula MySQL, correo y almacenamiento privado.

```text
Navegador
  ├─ Landing pública
  └─ Portal autenticado
       ↓ HTTPS
Next.js Route Handlers
  ├─ identity
  ├─ organizations
  ├─ notifications/outbox
  ├─ purchases
  └─ payments
       ↓
MySQL exclusivo del portal
```

## Límites

- El portal no accede a la base operativa del ERP.
- Los empleados del comercio se administran dentro del ERP; las cuentas del portal son propietarios y operadores comerciales.
- Los precios, planes y capacidades públicas viven en `src/config`.
- El navegador nunca emite licencias ni conoce claves privadas.
- Las rutas `/api/admin/*` exigen sesión individual de operador y MFA.
- Las mutaciones autenticadas exigen origen válido y token CSRF.
- Las rutas `/api/internal/*` no son públicas; se protegen con secretos dedicados y controles de red.

## Módulos del Sprint 2

- `modules/identity`: registro, credenciales Argon2id, verificación, sesiones, recuperación y MFA.
- `modules/organizations`: organizaciones, propietario, membresías y facturación.
- `modules/notifications`: outbox transaccional con reintentos y trazabilidad.
- `infrastructure/database`: pool MySQL compartido y transacciones.

Los Route Handlers no contienen SQL. Las consultas están parametrizadas y los cambios que abarcan varias tablas se ejecutan dentro de transacciones.

## Evolución

El flujo de pagos se endurecerá en Sprint 3. La emisión de licencias Ed25519 y la descarga protegida se incorporarán después, sin acoplarlas al ERP local. Los comprobantes deberán migrar desde disco privado a almacenamiento de objetos con cuarentena, antivirus/CDR y retención formal antes de una publicación comercial.
