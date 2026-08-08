# Flujo de transferencia

> La recepción real permanece bloqueada hasta completar el Sprint 3 y aprobar la operación legal. El Sprint 2 entrega identidad y organización, no autoriza por sí solo pagos productivos.

## Estados

```text
pending_quote → pending_payment → payment_reported → under_review → approved
                                            └──────→ rejected → pending_payment
```

Si un plan no tiene precio, versión de oferta e instrucciones configuradas, la solicitud queda `pending_quote`. Un operador emite una cotización y recién entonces el cliente ve monto e instrucciones dentro de su enlace privado.

## Acceso del cliente

La referencia `BC-…` es humana y no autoriza operaciones. El servidor deriva un token de 256 bits, almacena solamente su hash y lo entrega en el fragmento `#token=`. El fragmento no viaja en la petición HTTP ni aparece en logs del servidor. El cliente lo envía mediante `Purchase-Token`.

## API pública

```text
POST /api/purchase-requests
GET  /api/purchase-requests/{trackingId}       Purchase-Token: <token>
POST /api/payment-reports                      multipart/form-data
```

Estas rutas responden `503 COMMERCE_DISABLED` mientras no estén aprobados `COMMERCE_ENABLED`, `LEGAL_STATUS` y las versiones legales/comerciales.

## API administrativa

```text
POST /api/admin/purchase-requests/{trackingId}/quote
GET  /api/admin/payment-reports
POST /api/admin/payment-reports
GET  /api/admin/payment-reports/{id}/evidence
```

Requieren cuenta individual `operator`, correo verificado, sesión vigente y MFA. Las operaciones POST también requieren `Origin` válido y el header `X-CSRF-Token`, obtenido desde la cookie pública de CSRF. No existe un token administrativo compartido.

El rechazo exige motivo y devuelve la compra a `pending_payment`. Aprobar un monto o moneda diferente de la cotización también exige una nota y debe quedar auditado.

## Comprobantes

- PDF, PNG o JPEG, máximo 5 MB.
- Firma binaria y MIME coherentes.
- Nombre aleatorio y SHA-256 para detectar reutilización.
- Directorio privado fuera de `public/`.
- Descarga únicamente por endpoint administrativo autenticado y auditado.

Antes de producción se requiere cuarentena, antivirus/CDR, retención, doble control y un almacenamiento no efímero. La verificación sigue siendo manual contra la cuenta bancaria durante el piloto.
