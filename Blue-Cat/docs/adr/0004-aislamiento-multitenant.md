# ADR 0004 — Aislamiento multitenant por cuenta

- Estado: aceptado
- Fecha: 2026-07-13

## Contexto

Blue-Cat es un SaaS local-first. Una cuenta puede tener varios usuarios empleados y todos comparten productos, stock, clientes, proveedores y ventas, con permisos distintos. Usar `id_user` como propietario de los datos aislaba al empleado de su empresa y permitía consultas por ID sin una frontera SaaS uniforme.

## Decisión

`cuenta` es la raíz tenant. `id_user` identifica al actor y se conserva para autenticación y auditoría; `id_cuenta` decide qué datos puede leer o modificar.

- Las entidades raíz persistentes tienen `id_cuenta` obligatorio y clave foránea.
- Las entidades hijas se autorizan a través de su padre tenant.
- El contexto común resuelve cuenta, usuario, sucursal, empresa, empleado, bodega, sesión y caja.
- Los intentos de acceder a otra cuenta responden como recurso no encontrado para no revelar su existencia.
- Los roles globales son plantillas de solo lectura. Cada cuenta recibe copias locales editables con sus permisos.
- Los triggers de escritura derivan la cuenta desde `usuario` o `sesion`; son defensa adicional para endpoints legacy, no sustituyen la autorización de API.
- Las restricciones `UNIQUE` de datos comerciales deben incluir `id_cuenta` cuando el valor solo es único dentro de una empresa.

## Consecuencias

Los empleados de una misma cuenta pueden compartir la operación sin convertirse en propietarios separados. Toda nueva entidad raíz o endpoint debe declarar su alcance tenant. Las migraciones DDL de MySQL no se consideran reversibles de forma transaccional: actualizar requiere backup verificado y el rollback operativo restaura ese backup.
