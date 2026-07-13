# Fase 1 — Modelo de datos y aislamiento por cuenta

## Frontera de datos

| Concepto | Identificador | Uso |
|---|---:|---|
| Cuenta SaaS | `id_cuenta` | Frontera obligatoria de lectura y escritura |
| Usuario | `id_user` | Actor autenticado y trazabilidad |
| Sucursal | `id_sucursal` | Segmentación operativa opcional dentro de la cuenta |
| Bodega | `id_bodega` | Stock físico perteneciente a la cuenta |
| Sesión/caja | `id_sesion`, `id_caja` | Operación individual del cajero, siempre dentro de la cuenta |

Las entidades raíz cubiertas son empresas, sucursales, empleados, categorías, marcas, productos, bodegas, clientes, etiquetas, proveedores, sesiones, pedidos, cajas, promociones, cotizaciones, reservas, facturas, configuración de boleta, numeración, parámetros y auditoría. Las tablas detalle heredan el alcance de su padre.

## Reglas de autorización

1. Se autentica un `id_user` activo.
2. El contexto carga su `id_cuenta` y recursos operativos asociados.
3. Las consultas de colección filtran por `id_cuenta`.
4. Las consultas o mutaciones por ID verifican que la entidad raíz pertenezca a la cuenta.
5. Los permisos determinan la acción permitida después de establecer la frontera tenant.
6. Un rol plantilla global puede leerse, pero solo los roles locales de la cuenta pueden editarse o asignarse.

## Migración segura

Antes de actualizar una instalación real:

1. Detener nuevas escrituras y cerrar cajas activas.
2. Crear un `mysqldump --single-transaction --routines --triggers --events`.
3. Verificar que el dump pueda importarse en una base temporal.
4. Ejecutar `php Blue-Cat/scripts/migrate.php --env=<entorno-temporal>` sobre la copia.
5. Comparar conteos de usuarios, empleados, productos, stock, clientes, proveedores, pedidos y facturas.
6. Confirmar cero `id_cuenta` nulos y cero discrepancias entre la cuenta de la entidad y la del usuario origen.
7. Programar la actualización real solo después de completar esas comprobaciones.

Las migraciones 010-014 contienen DDL no transaccional. El rollback soportado durante Beta es restaurar el dump previo; no se debe intentar deshacer parcialmente columnas, claves o triggers sobre datos reales.

## Verificación automatizada

```sh
php Blue-Cat/scripts/migrate.php --env=.env.test
php Blue-Cat/scripts/test-tenant-isolation.php --env=.env.test
php Blue-Cat/scripts/test-api-tenant-isolation.php --env=.env.test
```

La suite comprueba lectura propia y bloqueo cruzado para usuarios, productos y clientes; permisos de roles globales/locales; escritura de stock; claves foráneas; unicidad por cuenta; y corrección de escrituras legacy mediante triggers. GitHub Actions ejecuta la misma prueba en MySQL 8.4 para cada push y Pull Request.

## Migraciones de la fase

- `010_tenant_isolation.sql`: crea la cuenta SaaS, propaga `id_cuenta`, claves e índices.
- `011_role_templates.sql`: formaliza el catálogo obligatorio de permisos y roles.
- `012_tenant_write_guards.sql`: agrega defensa en escritura para endpoints heredados.
- `013_crm_schema_alignment.sql`: alinea instalaciones limpias con el esquema CRM usado por la API.
- `014_role_template_policy.sql`: normaliza plantillas globales sin sobrescribir roles locales personalizados.

## Evidencia de cierre

Se validaron dos recorridos independientes:

- instalación limpia desde migraciones y datos demo;
- copia restaurada de la base `erp`, nunca la base original.

En la copia migrada, 87 tablas de negocio conservaron exactamente sus conteos. Solo crecieron los catálogos esperados de permisos y roles; se verificaron 142 claves foráneas y ninguna diferencia inesperada.

La regresión de API cubre POS, caja, venta, clientes, CRM, inventario, proveedores y facturación. También crea un empleado Vendedor dentro de la cuenta y demuestra simultáneamente que:

- comparte clientes y puede editarlos con `crm.editar`;
- no accede a inventario sin `inventario.ver`;
- no accede a proveedores sin `proveedores.ver`;
- nunca puede leer o modificar entidades de otra cuenta.

## Comandos de aceptación

```sh
php Blue-Cat/scripts/migrate.php --env=.env.test
php Blue-Cat/scripts/test-tenant-isolation.php --env=.env.test
php Blue-Cat/scripts/test-api-tenant-isolation.php --env=.env.test
php Blue-Cat/scripts/verify-integrity.php --env=.env.test --source=erp --target=<copia_migrada>
```
