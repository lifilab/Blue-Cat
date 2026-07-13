# ADR-0002: API canónica

- Estado: Aceptado y completado
- Fecha: 2026-07-12
- Responsable: Pablo-Millones

## Contexto

El proyecto mantuvo dos generaciones de backend: `assets/api/` y `assets/PHP/`. Varios flujos duplicaban autenticación, caja, ventas, importación y acceso a datos.

## Decisión

`assets/api/` es la única raíz de backend. Los consumidores históricos se migraron a:

- `auth.php` para login, registro, estado y logout;
- `pos.php` para apertura/cierre y ventas;
- `ventas.php` para consultas de pedidos;
- `importar_productos.php` para importación autorizada.

Las carpetas `assets/PHP/` y `assets/api/compat/` quedan prohibidas. CI falla si reaparece compatibilidad.

## Consecuencias

- Existe una sola conexión, sesión y verificación de permisos compartida.
- Nuevas funciones se incorporan al controlador de su dominio.
- Los contratos antiguos no justifican duplicar reglas de negocio.
