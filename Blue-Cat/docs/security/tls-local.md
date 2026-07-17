# TLS para Blue-Cat Server en la red local

Blue-Cat permite desarrollo por HTTP, pero el instalador de servidor debe publicar la aplicación por HTTPS y configurar `FORCE_HTTPS=true`. Con esa opción, la API rechaza HTTP con `426 HTTPS_REQUIRED`; las cookies se marcan `Secure` y las respuestas HTTPS incluyen HSTS.

## Diseño para el instalador

1. Crear una autoridad certificadora local exclusiva de la instalación y guardar su clave privada fuera del directorio web con permisos del servicio.
2. Emitir un certificado de servidor con SAN para el nombre estable del equipo y sus direcciones LAN previstas. No depender de `localhost` para otros POS.
3. Instalar únicamente el certificado raíz público en el almacén de confianza de cada dispositivo cliente mediante el asistente de conexión.
4. Configurar el servidor web para TLS 1.2 o superior, iniciar por HTTPS y redirigir la navegación estática desde HTTP.
5. Activar `FORCE_HTTPS=true` solo después de verificar el certificado para evitar bloquear la instalación a mitad del proceso.
6. Renovar el certificado antes de vencer sin reemplazar innecesariamente la CA; registrar emisión, renovación y revocación.

La CA privada nunca se copia a los POS cliente. Un cliente nuevo recibe la CA pública mediante un proceso administrativo confirmado en el servidor, no desde una página HTTP sin autenticar.

## Verificación de aceptación

- Un navegador cliente abre el nombre LAN sin advertencias de certificado.
- Una llamada directa por HTTP a la API devuelve `426` o es redirigida antes de llegar a PHP.
- La cookie `BLUECATSESSID` incluye `Secure`, `HttpOnly` y `SameSite=Lax`.
- La respuesta HTTPS incluye CSP, `nosniff`, protección de frame y HSTS.
- Revocar la confianza del dispositivo impide su reconexión hasta autorizarlo otra vez.
