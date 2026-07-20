# Seguridad

El MVP valida entrada con Zod en cliente y servidor, limita cuerpos, usa consultas parametrizadas, valida origen, aplica rate limits persistidos, genera correlación servidor e idempotencia, y entrega cotización/datos bancarios únicamente con token privado. Los comprobantes se validan por firma binaria, se renombran, se hashean y se guardan fuera de `public/`.

Antes de aceptar pagos reales en Internet faltan: identidad administrativa con MFA/RBAC, scanner antivirus y sanitización/CDR de PDF, almacenamiento de objetos en cuarentena, rate limit distribuido, política de retención, alertas, backups, verificación bancaria automatizada o doble control y revisión OWASP externa.

Los datos bancarios se configuran como secreto de entorno. Los logs no contienen datos personales, instrucciones bancarias, comprobantes ni credenciales. El adaptador de disco y el token administrativo compartido son exclusivamente para un piloto local restringido.
