# Auditoría del estado actual

## Fuente inspeccionada

El documento inicial apuntaba a `C:\laragon\www\blue-cat-web`, pero esa carpeta no existe. La aplicación encontrada e inspeccionada está en `C:\laragon\www\Blue-Cat`. La landing no importa código de esa aplicación.

## Capacidades verificadas

Se observaron rutas y lógica para Inicio, POS, caja y cuadre, ventas, inventario y bodegas, CRM de clientes, cotizaciones, promociones, facturas, proveedores, empleados, roles/permisos y configuración de empresas/sucursales.

## Registro obsoleto

El endpoint público de registro del ERP responde que el registro está deshabilitado y dirige la administración de usuarios a Configuración. La landing reemplaza la idea de “crear cuenta” por una solicitud comercial; no modifica la autenticación interna de empleados.

## Material visual

Se reutiliza únicamente el logotipo transparente. La demostración del producto se construye con HTML/CSS y no replica capturas antiguas, evitando mostrar flujos obsoletos. Antes de publicar imágenes reales se debe repetir este inventario y anonimizar datos.

## Clasificación

- Logo: **keep**.
- Capacidades verificadas: **keep**, con textos comerciales nuevos.
- Crear cuenta/registro público: **remove** de la comunicación pública.
- Capturas antiguas con registro: **replace**.
- Multi-sucursal avanzada, automatizaciones y Cloud Sync: **needs-validation** antes de prometer alcance.
