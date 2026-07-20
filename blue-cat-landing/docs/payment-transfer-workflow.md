# Flujo de transferencia

## Estados

```text
pending_quote → pending_payment → payment_reported → under_review → approved
                                            └──────→ rejected → pending_payment
```

Si un plan no tiene precio, versión de oferta e instrucciones configuradas, la solicitud queda `pending_quote`. Un operador emite una cotización y recién entonces el cliente ve monto e instrucciones dentro de su enlace privado.

## Acceso del cliente

La referencia `BC-…` es humana y no autoriza operaciones. El servidor deriva un token de 256 bits, almacena solamente su hash y lo entrega en el fragmento `#token=`. El fragmento no viaja en la petición HTTP ni aparece en logs del servidor. El cliente lo envía mediante el header `Purchase-Token`.

## Comprobantes

- PDF, PNG o JPEG, máximo 5 MB.
- Firma binaria y MIME coherentes.
- PDF con cierre estructural básico y sin acciones/adjuntos conocidos.
- Nombre aleatorio; el nombre original no se conserva.
- SHA-256 para detectar reutilización.
- Directorio privado fuera de `public/`.
- Descarga únicamente por endpoint administrativo protegido y auditado.

Este control no sustituye antivirus, sandbox ni CDR. Antes de producción pública debe implementarse cuarentena y servir solo un derivado sanitizado.

## API pública

```text
POST /api/purchase-requests
GET  /api/purchase-requests/{trackingId}       Purchase-Token: <token>
POST /api/payment-reports                      multipart/form-data
```

## API administrativa temporal

Todas las llamadas requieren `Authorization: Bearer <ADMIN_API_TOKEN>` y deben limitarse por firewall a la red operativa.

```text
POST /api/admin/purchase-requests/{trackingId}/quote
GET  /api/admin/payment-reports
POST /api/admin/payment-reports
GET  /api/admin/payment-reports/{id}/evidence
```

Ejemplo de decisión:

```powershell
$headers = @{ Authorization = "Bearer $env:ADMIN_API_TOKEN" }
$body = @{ reportId = "uuid"; decision = "approved"; note = "Monto conciliado" } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri http://localhost:3000/api/admin/payment-reports -Headers $headers -ContentType application/json -Body $body
```

El rechazo exige motivo y devuelve la compra a `pending_payment` para permitir un comprobante reemplazante. Aprobar un monto/moneda diferente de la cotización también exige una nota.

## Limitaciones conocidas

- El token administrativo es compartido y no identifica personas individualmente.
- No existe todavía panel administrativo, MFA ni segregación de funciones.
- La verificación sigue siendo manual contra la cuenta bancaria.
- El almacenamiento local debe reemplazarse antes de Vercel/Cloudflare.
- La emisión de licencia comienza en Fase 4 y no forma parte de este flujo.
