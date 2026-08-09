# Checklist E2E de staging

## Registro de ejecución

| Campo | Valor |
|---|---|
| URL | |
| Commit / imagen | |
| Fecha y zona horaria | |
| QA responsable | |
| Navegadores | Edge / Chrome / Firefox |
| Dispositivos | 390 px / 768 px / 1366 px |
| Base restaurada desde backup | Sí / No |
| Evidencias | |

Estados permitidos: `PASS`, `FAIL`, `BLOCKED` o `N/A` con justificación. Ningún caso crítico puede quedar `FAIL`, `BLOCKED` o sin evidencia.

## A. Gate técnico y perímetro — crítico

- [ ] Todos los checks del commit están verdes: baseline, secretos, dependencias, CodeQL, MySQL, portal y staging readiness.
- [ ] La imagen desplegada coincide con el SHA aprobado y tiene SBOM/procedencia.
- [ ] DNS resuelve al servidor de staging y HTTPS usa un certificado válido.
- [ ] HTTP redirige a HTTPS; TLS obsoleto está deshabilitado.
- [ ] Solo 80/443 son públicos. 3000/3306 no responden desde Internet.
- [ ] `/api/health/live` responde `200` sin secretos ni detalles internos.
- [ ] `/api/health/ready` responde `200` con la base disponible y `503` al interrumpirla.
- [ ] `/Blue-Cat/`, `/public/pos.html` y `/assets/api/auth.php` responden `404`.
- [ ] No existen artefactos, rutas, archivos PHP, dumps ni credenciales del ERP/POS en la imagen.
- [ ] `robots.txt` bloquea todo staging y todas las respuestas incluyen `X-Robots-Tag: noindex`.
- [ ] El smoke automático termina con `status: passed`.

## B. Landing, contenido y experiencia visual — alta

- [ ] Inicio carga sin errores de consola, recursos `4xx/5xx` ni saltos visibles de layout.
- [ ] Header, menú móvil, footer, CTA, licencias, módulos, Cloud Sync, tutoriales, FAQ y contacto navegan correctamente.
- [ ] No aparece “Crear cuenta” en el ERP/POS local; el registro comercial vive solo en el portal.
- [ ] Planes PYME y Enterprise explican diferencias sin prometer funciones no entregadas.
- [ ] Cloud Sync aparece como servicio mensual separado, no incluido por defecto.
- [ ] Compra por transferencia se presenta deshabilitada o como entorno de prueba, sin datos bancarios reales.
- [ ] Textos, acentos, moneda CLP, fechas y enlaces legales se renderizan correctamente.
- [ ] Logo, favicon, Open Graph y estados hover/focus mantienen calidad visual.
- [ ] Vista 390 px no tiene scroll horizontal, superposición de modales ni botones fuera de pantalla.
- [ ] Vista 768 px mantiene jerarquía y controles táctiles de al menos 44 px.
- [ ] Vista 1366 px no deja secciones rotas, excesivamente vacías o ilegibles.
- [ ] Navegación completa con teclado: orden lógico, foco visible, Escape y retorno de foco en modales.
- [ ] “Saltar al contenido”, títulos, labels, mensajes de error y contraste cumplen WCAG 2.1 AA.
- [ ] Con animaciones reducidas activadas no quedan transiciones obligatorias.

## C. Registro y verificación de correo — crítico

- [ ] Cuenta nueva válida devuelve respuesta genérica y crea usuario pendiente.
- [ ] Consentimientos obligatorios registran exactamente la versión vigente; marketing conserva el valor elegido.
- [ ] Términos desactualizados devuelven `409 LEGAL_VERSION_OUTDATED` y la UI solicita recargar.
- [ ] Correo duplicado recibe la misma respuesta genérica y no revela si existe.
- [ ] Email inválido, contraseña débil, cuerpo no JSON y cuerpo grande muestran errores seguros y útiles.
- [ ] Enlace de verificación real llega desde el remitente de staging y apunta al dominio correcto.
- [ ] Token válido activa la cuenta una sola vez.
- [ ] Token reutilizado, alterado o vencido es rechazado sin filtrar datos.
- [ ] Reenvío invalida enlaces anteriores y respeta rate limit.
- [ ] Outbox no conserva el payload cifrado después del envío exitoso.

## D. Login, sesiones y recuperación — crítico

- [ ] Usuario no verificado no puede iniciar sesión.
- [ ] Credenciales correctas crean cookies `HttpOnly`, `Secure` y `SameSite=Strict`.
- [ ] Credenciales incorrectas no distinguen correo inexistente de contraseña errónea.
- [ ] Cinco fallos activan bloqueo temporal y el desbloqueo respeta la política.
- [ ] Navegar o llamar APIs sin sesión devuelve `401` coherente.
- [ ] Mutaciones sin `Origin` válido o sin CSRF devuelven `403`.
- [ ] Logout revoca la sesión servidor y elimina cookies.
- [ ] Timeout inactivo y límite absoluto invalidan la sesión.
- [ ] Solicitar recuperación siempre entrega respuesta genérica.
- [ ] Enlace de recuperación llega al correo correcto, vence y solo funciona una vez.
- [ ] Cambiar la contraseña revoca todas las sesiones anteriores.
- [ ] La contraseña nunca aparece en URL, correo, logs, auditoría o respuestas.

## E. MFA y operador — crítico

- [ ] Bootstrap crea o actualiza únicamente al operador solicitado y no imprime la contraseña.
- [ ] Variables `OPERATOR_*` quedan eliminadas después del bootstrap.
- [ ] Operador sin MFA no puede usar endpoints administrativos.
- [ ] Enrolamiento genera TOTP y códigos de recuperación; el secreto se almacena cifrado.
- [ ] Código TOTP válido activa MFA; código inválido no lo activa.
- [ ] Login posterior exige TOTP y una sesión con nivel MFA.
- [ ] Cliente normal no puede elevarse a operador ni acceder a `/api/admin/*`.

## F. Organización, membresía y aislamiento — crítico

- [ ] Usuario verificado crea una organización con identificador tributario válido.
- [ ] Usuario pendiente no puede crear organizaciones.
- [ ] La membresía inicial queda como propietario de esa organización.
- [ ] Límite de organizaciones se aplica en backend, no solo en UI.
- [ ] Actualizar perfil de facturación requiere sesión, CSRF y pertenencia.
- [ ] Cambiar el ID de organización en URL/cuerpo no permite leer o editar otra cuenta (IDOR).
- [ ] Dos usuarios de organizaciones distintas no comparten datos comerciales, consentimientos ni sesiones.
- [ ] Auditoría registra actor, organización, evento, request ID y fecha sin secretos.

## G. Correo y outbox — alta

- [ ] Worker sin bearer token devuelve `401`.
- [ ] Token incorrecto devuelve `401` con comparación segura.
- [ ] Dos workers simultáneos no envían dos veces el mismo mensaje.
- [ ] Fallo transitorio reintenta con backoff y conserva trazabilidad.
- [ ] Quinto fallo mueve el mensaje a `dead` y genera alerta operativa.
- [ ] Un mensaje enviado purga payload y registra ID del proveedor.
- [ ] HTML escapa nombre y URL; no admite inyección.
- [ ] Correos se visualizan correctamente en Gmail, Outlook y móvil.

## H. Canal comercial de staging — crítico

- [ ] `COMMERCE_ENABLED=false` y `LEGAL_STATUS=draft` pasan el preflight.
- [ ] No se muestran cuentas bancarias reales, links de pago productivos ni carga de comprobantes operativa.
- [ ] Conciliación administrativa devuelve `503 COMMERCE_NOT_ENABLED` antes de modificar datos.
- [ ] Una solicitud de información/cotización válida genera tracking sin prometer licencia activada.
- [ ] Idempotency key repetida con el mismo contenido no duplica la solicitud.
- [ ] Idempotency key repetida con contenido distinto devuelve conflicto.
- [ ] Honeypot, rate limit y validación de tamaño bloquean abuso.
- [ ] Seguimiento con token alterado no expone datos ni permite enumerar solicitudes.

## I. Base, migraciones y resiliencia — crítico

- [ ] Base comercial usa credenciales y esquema distintos al ERP/POS.
- [ ] MySQL no tiene puerto publicado y acepta conexiones solo en red privada.
- [ ] Migrar una base vacía aplica todas las migraciones en orden.
- [ ] Repetir migraciones no modifica datos y reporta `skip`.
- [ ] Modificar una migración aplicada provoca `MIGRATION_CHANGED_*` y bloquea el arranque.
- [ ] Backup previo se crea, cifra, copia fuera del host y valida por checksum.
- [ ] Restauración en una base temporal recupera conteos, relaciones y restricciones.
- [ ] Reiniciar portal, proxy, worker y servidor conserva datos y sesiones válidas.
- [ ] Caída de MySQL vuelve readiness `503` sin mostrar stack trace; al volver se recupera.
- [ ] Disco lleno o permisos inválidos producen alerta y no una respuesta engañosa.

## J. Seguridad y observabilidad — crítico

- [ ] CSP, HSTS, `nosniff`, `DENY`, Referrer-Policy y Permissions-Policy están presentes.
- [ ] No hay CORS abierto; peticiones cross-origin mutantes son rechazadas.
- [ ] SQL usa parámetros y pruebas de payloads comunes no alteran consultas.
- [ ] Valores HTML/URL controlados por usuario no ejecutan XSS almacenado o reflejado.
- [ ] Rate limits persisten entre réplicas/reinicios según el diseño actual.
- [ ] Logs no contienen contraseña, token, cookie, API key, comprobante ni datos bancarios.
- [ ] Eventos críticos incluyen request ID y pueden correlacionarse entre proxy, app y auditoría.
- [ ] Alertas se disparan por health, reinicios, disco, backup, errores 5xx y outbox `dead`.
- [ ] Dependencias y secretos vuelven a escanearse sobre la imagen/commit desplegado.

## K. Fallos, rollback y cierre — crítico

- [ ] Despliegue de una imagen inválida no reemplaza la instancia sana.
- [ ] Rollback a SHA anterior recupera servicio sin reutilizar secretos ni imágenes locales.
- [ ] Si una migración requiere restauración, el procedimiento fue ensayado con doble aprobación.
- [ ] Después del rollback pasan smoke, login, aislamiento y consulta de organización.
- [ ] Se documentan defectos con severidad, pasos, evidencia, responsable y sprint.
- [ ] Product Owner, Tech Lead y QA firman el resultado.

## L. Pruebas fuera del staging público

Estas pruebas pertenecen al laboratorio local/RC y nunca justifican publicar el ERP/POS:

- [ ] Instalación limpia y actualización conservando usuarios, productos y ventas.
- [ ] Acceso directo, aplicación de escritorio, certificados locales y arranque sin Laragon.
- [ ] Crear/importar/exportar productos, actualizar stock, costo, precio e impuestos.
- [ ] Crear empleados y validar permisos reales de cajero, bodeguero y supervisor.
- [ ] Abrir caja, vender por unidad/peso, promociones, clientes, pago y boleta con logo.
- [ ] Venta visible el mismo día para empleado autorizado y administrador; cuadratura consistente.
- [ ] Anulación con autorización temporal de supervisor y auditoría completa.
- [ ] Dos POS en LAN conectan al servidor local sin exponerlo a Internet.
- [ ] Activación/licencia, descarga protegida y verificación firmada se prueban cuando el sprint de licenciamiento esté implementado.

## Resultado

| Severidad | PASS | FAIL | BLOCKED | N/A |
|---|---:|---:|---:|---:|
| Crítica | | | | |
| Alta | | | | |
| Total | | | | |

Decisión: `APROBADO`, `APROBADO CON RIESGO ACEPTADO` o `RECHAZADO`.
