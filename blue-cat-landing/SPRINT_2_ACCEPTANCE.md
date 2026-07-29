# Sprint 2 — Cierre de identidad del portal

## Entregado

- [x] Registro con consentimiento legal versionado.
- [x] Verificación y reenvío de correo.
- [x] Login, logout y sesiones revocables.
- [x] Recuperación de contraseña sin enumeración.
- [x] Argon2id, CSRF, cookies seguras y límites persistidos.
- [x] MFA TOTP para operadores y códigos de recuperación protegidos.
- [x] Organizaciones, propietario, membresías y facturación.
- [x] Outbox transaccional con reintentos y trazabilidad.
- [x] Portal mínimo y bootstrap de operador.
- [x] Migraciones repetibles con checksum.
- [x] Pruebas unitarias, integración MySQL, build y auditoría de dependencias.

## Fuera de alcance

- [ ] Pago productivo y conciliación reforzada — Sprint 3.
- [ ] Panel administrativo completo — Sprint 3.
- [ ] Licencias firmadas y activación — Sprint 6.
- [ ] Descarga protegida del instalador — Sprint 7.
- [ ] Sincronización cloud — fase posterior y servicio separado.

## Evidencia de aceptación

```powershell
npm ci
npm run lint
npm run typecheck
npm test
npm run db:migrate
npm run test:identity
npm run build
npm audit --audit-level=high
```

El workflow `.github/workflows/portal-identity.yml` reproduce el flujo con MySQL 8.4 en cada PR.
