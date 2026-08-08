# Portal de identidad y organizaciones

## Alcance del Sprint 2

Un cliente puede crear una cuenta, aceptar términos versionados, verificar su correo, iniciar/cerrar sesión, recuperar su clave y crear una organización con perfil de facturación. Un operador interno usa una identidad individual con MFA obligatorio.

## Flujos

### Registro

1. `POST /api/auth/register` valida correo, nombre, contraseña y consentimientos.
2. La contraseña se transforma con Argon2id.
3. Usuario, consentimientos y mensaje de verificación se guardan en una transacción.
4. La respuesta es deliberadamente genérica.
5. El outbox entrega un enlace de un solo uso; el token expira y en la base solo queda su hash.

### Login y sesión

1. `POST /api/auth/login` valida credenciales y estado.
2. Un operador sin MFA confirmado no obtiene acceso administrativo.
3. Se emite una sesión opaca y revocable en cookie `HttpOnly`.
4. `GET /api/auth/session` devuelve solo el contexto mínimo.
5. `POST /api/auth/logout` revoca la sesión actual.

### Recuperación

`forgot-password` no revela si existe el correo. `reset-password` consume un token de un solo uso, cambia el hash, incrementa `session_version` y revoca todas las sesiones.

### Organización

`POST /api/organizations` requiere correo verificado y crea organización, membresía `owner` y facturación dentro de una transacción. Las lecturas y actualizaciones comprueban membresía para evitar IDOR.

## Endpoints

```text
POST /api/auth/register
POST /api/auth/verify-email
POST /api/auth/resend-verification
POST /api/auth/login
GET  /api/auth/session
POST /api/auth/logout
POST /api/auth/forgot-password
POST /api/auth/reset-password
POST /api/auth/mfa/enroll
POST /api/auth/mfa/confirm
GET|POST /api/organizations
PATCH /api/organizations/{id}/billing
POST /api/internal/email-outbox/process
```

Las mutaciones con sesión exigen `X-CSRF-Token`; el cliente lo toma de la cookie de CSRF. Nunca debe registrarse el valor de tokens, códigos MFA o contraseñas.

## Outbox

Los correos se insertan junto al cambio de negocio para evitar estados parciales. Un worker reclama lotes mediante bloqueo, reintenta con backoff exponencial, mueve fallos permanentes a `dead_letter` y purga el payload al enviarlo. Los enlaces sensibles se cifran con AES-256-GCM mientras esperan.

## Criterios de aceptación

- Una cuenta no verificada no puede crear organizaciones.
- Verificar el correo permite crear una organización y su propietario.
- Registro y recuperación no enumeran cuentas.
- Restablecer clave revoca sesiones anteriores.
- Un operador sin MFA no puede usar `/api/admin/*`.
- Contraseñas y tokens nunca se almacenan en claro ni se envían por correo.
- Migraciones, integración, unit tests y build pasan en CI con MySQL real.
