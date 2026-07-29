# Seguridad

## Controles implementados

- Contraseñas con Argon2id y política mínima de longitud.
- Tokens aleatorios de alta entropía; en la base solo se almacena su hash.
- Sesiones opacas revocables, timeout inactivo, límite absoluto y rotación mediante `session_version`.
- Cookies `HttpOnly`, `SameSite=Strict` y `Secure` fuera de desarrollo.
- CSRF de doble envío y validación estricta de `Origin` para mutaciones autenticadas.
- MFA TOTP obligatorio para operadores; los secretos se cifran con AES-256-GCM.
- Bloqueo temporal y rate limit persistido para intentos de autenticación.
- Respuestas genéricas en registro y recuperación para reducir enumeración de cuentas.
- Consentimientos legales versionados y auditables.
- Outbox transaccional con payload cifrado, reintentos, dead letter y purga después del envío.
- Consultas parametrizadas, límites de cuerpo, validación Zod y errores públicos sin detalles internos.
- Comercio real cerrado por defecto mediante `COMMERCE_ENABLED` y `LEGAL_STATUS`.

## Gestión de secretos

`IDENTITY_DATA_KEY`, `IDENTITY_HASH_PEPPER`, `OUTBOX_WORKER_TOKEN`, credenciales de base y proveedores deben administrarse fuera de Git. La pérdida de `IDENTITY_DATA_KEY` impide descifrar tokens pendientes y secretos MFA; debe existir backup cifrado y rotación ensayada.

El bootstrap de operador consume variables efímeras. La contraseña no se imprime, no se persiste en scripts y nunca se envía por correo.

## Riesgos que permanecen

Antes de aceptar pagos reales faltan antivirus/CDR para comprobantes, almacenamiento de objetos en cuarentena, rate limit distribuido, alertas, backups restaurables, doble control de conciliación, CSP con nonces y revisión externa OWASP.

La emisión de licencias, activación y anti-tampering no forman parte del Sprint 2. Se diseñarán con firmas Ed25519, claves privadas fuera del servidor web, verificación offline acotada y defensa en profundidad; nunca mediante una clave simétrica embebida en el instalador.

## Respuesta operativa

- Revocar sesiones incrementando `session_version` o deshabilitando la cuenta.
- Rotar secretos tras cualquier sospecha y volver a emitir tokens pendientes.
- Conservar auditoría sin contraseñas, tokens en claro ni datos bancarios.
- Probar restauración de base y recuperación de outbox antes de producción.
- Ejecutar `npm audit --audit-level=high` y el workflow `portal-identity` en cada PR.
