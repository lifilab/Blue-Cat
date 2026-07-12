# Política de actualizaciones compatibles

- Los parches mantienen esquema, API y configuración compatibles.
- Las versiones menores pueden agregar migraciones, nunca reescribir una migración publicada.
- Todo cambio incompatible requiere versión mayor y guía de migración.
- Antes de actualizar se crea un backup verificable.
- Si la migración falla, se detiene el despliegue y se restaura aplicación y base desde el backup previo.
- Una licencia perpetua conserva el uso de la versión adquirida; el acceso a versiones mayores depende del contrato comercial.
- El canal piloto recibe versiones Beta; producción estable no instala Betas automáticamente.
