# Rotación de secretos

1. Revocar inmediatamente el secreto expuesto.
2. Emitir una credencial nueva con privilegio mínimo.
3. Actualizar el secreto en el servidor o GitHub Environment.
4. Reiniciar únicamente los servicios dependientes.
5. Verificar acceso y revisar auditoría desde la exposición estimada.
6. Escanear el historial completo.
7. Si el secreto llegó a Git, considerar comprometidas todas sus versiones aunque el archivo se elimine.

Rotar periódicamente credenciales de base, certificados TLS, tokens de licencia, claves de backup y certificado de firma según su criticidad. Las claves privadas nunca deben residir en el repositorio ni dentro del artefacto cliente.
