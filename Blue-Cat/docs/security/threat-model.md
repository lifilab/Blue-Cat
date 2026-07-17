# Modelo de amenazas Beta

## Activos y límites de confianza

Los activos prioritarios son ventas, caja, stock, credenciales, permisos, datos de clientes, auditoría y respaldos. El navegador del POS, incluso dentro de la LAN, no es confiable. La API es el límite de autorización; MySQL y el proceso local de Blue-Cat forman el núcleo confiable. Cada `cuenta` es un tenant y ningún rol puede ampliar acceso a otra cuenta.

## Amenazas principales y controles

| Amenaza | Ejemplo | Control Beta | Evidencia |
|---|---|---|---|
| Suplantación | contraseña robada o fuerza bruta | bcrypt, política mínima, bloqueo progresivo, respuesta genérica, sesión regenerada | `test-security-foundation.php` |
| Secuestro/reutilización de sesión | cookie copiada o empleado desactivado | cookie HttpOnly/SameSite, registro por dispositivo, caducidad por inactividad, versión y revocación | prueba de login/logout/revocación |
| CSRF | sitio externo intenta cerrar caja | token/cabecera de misma procedencia y SameSite | POST sin defensa devuelve `CSRF_REJECTED` |
| Elevación de privilegio | mostrar un botón oculto o llamar la API manualmente | `requirePermission()` y políticas específicas en servidor | `configuracion.ver` no permite gestionar roles |
| IDOR / cruce de tenant | cambiar un `id_pedido` por el de otra cuenta | `TenantContext`, filtros `id_cuenta` y validadores de entidad | pruebas de aislamiento existentes |
| Abuso de Supervisor | reutilizar PIN o aprobación | credencial hasheada, token opaco de un uso, 90 s, acción y contexto exactos | `test-supervisor-authorization.php` |
| Archivo malicioso | renombrar un ejecutable a `.xls` | tamaño, extensión, MIME, carga HTTP real, máximo de filas/celdas y parseo sin red | importador de inventario |
| XSS / clickjacking | dato de cliente insertado en HTML | escape en render, CSP, `nosniff`, `frame-ancestors` y `X-Frame-Options` | cabeceras Apache/API |
| Exposición de secretos/backups | navegar a `.env` o `storage/backups` | reglas Apache, sin listado de directorios | petición de respaldo devuelve 403 |
| Repudio | negar una anulación o cambio de rol | auditoría con usuario, cuenta, IP/contexto y fecha; fallos de telemetría no rompen caja | tablas de auditoría |

## Riesgos residuales coordinados con las fases siguientes

1. El mecanismo `FORCE_HTTPS` y HSTS ya existe; la emisión e instalación de certificados confiables se integra en el instalador de la Fase 4 según `tls-local.md`.
2. `APP_KEY` ya se genera de forma única y atómica; una rotación controlada de claves queda como mantenimiento posterior porque invalida hashes de sesiones y rate limiting.
3. Los mensajes no confiables de las API ya se renderizan como texto y los datos de factura/configuración revisados se escapan; CI bloquea la reintroducción del patrón inseguro de toast. La eliminación total de HTML construido dinámicamente es una refactorización progresiva.
4. El baseline estático ya corre en CI. Un DAST autenticado y un SAST de terceros se incorporarán cuando el entorno piloto sea reproducible.
5. La recuperación actual es administrativa, exige permiso y revoca sesiones. El autoservicio con token temporal queda fuera del alcance Beta local-first.
6. La retención, exportación firmada y protección criptográfica de auditoría se definirá junto con backups y operación en Fase 7.

## Criterio de salida

La Fase 3 solo se cierra cuando las pruebas negativas demuestran que una sesión vencida, una petición sin CSRF, un usuario sin permiso, un identificador de otro tenant y un token de Supervisor reutilizado son rechazados por el backend, independientemente de la interfaz.
