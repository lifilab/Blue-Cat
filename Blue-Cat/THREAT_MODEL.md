# Modelo de amenazas inicial

## Activos

Ventas, stock, caja, credenciales, clientes, auditoría, backups, licencia y claves de firma.

## Fronteras de confianza

- Navegador/PWA ↔ servidor web local.
- Servidor web ↔ base de datos local.
- Servidor local ↔ servicio de licencias/actualizaciones.
- Administrador ↔ empleados y dispositivos autorizados.

## Amenazas prioritarias

- Acceso de una cuenta a datos de otra.
- Empleado que invoca una API sin permiso ocultando o manipulando botones.
- Doble venta o repetición de requests.
- Alteración de pagos, stock, cierre o auditoría.
- Robo de sesión en la LAN.
- Archivo XLS/imagen malicioso.
- Backup sin cifrar o restauración manipulada.
- Actualización o licencia falsificada.
- Servidor perdido, robado o apagado abruptamente.

## Controles

Alcance de cuenta en SQL, permisos en API, sesiones seguras, TLS local, validación de archivos, transacciones, idempotencia, backups cifrados, checksums, firma de artefactos y auditoría estructurada.

Este modelo debe revisarse antes de cada Beta y cuando se agregue acceso remoto, nube o sincronización.
