# Auditoría del estado actual

## Fuente inspeccionada

La aplicación operativa inspeccionada está en `Blue-Cat/`. El portal comercial vive en `blue-cat-landing/` y no importa código ni accede a la base de esa aplicación.

## Capacidades verificadas

En el ERP/POS se observaron rutas y lógica para Inicio, POS, caja y cuadre, ventas, inventario y bodegas, CRM de clientes, cotizaciones, promociones, facturas, proveedores, empleados, roles/permisos y configuración de empresas/sucursales.

## Identidades separadas

El registro público del ERP está deshabilitado: los empleados se administran dentro de Configuración. El portal sí permite crear una cuenta de propietario, verificarla y asociarla a una organización. Estas identidades no comparten tablas, permisos ni sesiones con los empleados del POS.

## Material visual

Se reutiliza únicamente el logotipo transparente. La demostración del producto se construye con HTML/CSS y no replica capturas antiguas, evitando mostrar datos o flujos obsoletos. Las capturas reales deben anonimizarse antes de publicarse.

## Clasificación

- Logo: **keep**.
- Capacidades verificadas: **keep**, con textos comerciales prudentes.
- Registro público dentro del ERP: **remove**.
- Cuenta de propietario dentro del portal: **keep**.
- Capturas antiguas con registro del ERP: **replace**.
- Multi-sucursal avanzada, automatizaciones y Cloud Sync: **needs-validation** antes de prometer alcance.
