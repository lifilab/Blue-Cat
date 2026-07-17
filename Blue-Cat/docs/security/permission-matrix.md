# Matriz canónica de autorización

Esta matriz define la política Beta. La interfaz solo refleja permisos; la decisión final siempre ocurre en la API mediante `requireUser()`, `requirePermission()` o una autorización puntual de supervisor.

## Alcances

- **Propio:** registros creados por el empleado o asociados a su sesión/caja.
- **Cuenta:** datos compartidos por propietario y empleados de la misma cuenta SaaS.
- **Sucursal:** reservado para la etapa multi-sucursal; nunca amplía el acceso a otra cuenta.
- **Puntual:** una sola operación, contexto y plazo, aprobada por PIN/tarjeta de Supervisor.

## Roles base

| Capacidad | Administrador | Supervisor | Cajero | Bodeguero | Vendedor |
|---|---:|---:|---:|---:|---:|
| POS y venta | Cuenta | Cuenta | Propio | No | Propio |
| Ver ventas | Cuenta | Cuenta | Propio | No | Propio |
| Anular/devolver | Directo | Directo | Puntual | No | Puntual |
| Abrir/cerrar caja | Cuenta | Cuenta | Propio | No | Propio |
| Inventario operativo | Cuenta | Cuenta | No | Cuenta | No |
| Ajustes sensibles | Directo | Directo | No | Puntual | No |
| Clientes | Cuenta | Cuenta | Según permiso | No | Cuenta |
| Empleados | Cuenta | Lectura según permiso | No | No | No |
| Roles y permisos | Cuenta | No, salvo asignación explícita | No | No | No |
| Sesiones y auditoría | Cuenta | No, salvo asignación explícita | No | No | No |

Los roles son plantillas provisionadas dentro de cada cuenta. Un empleado nunca es un tenant separado: productos, stock, clientes y ventas pertenecen a la cuenta y se filtran adicionalmente por alcance cuando corresponde.

## Rutas sensibles

| Endpoint / acción | Permiso base | Permiso específico / alcance |
|---|---|---|
| `pos.php` GET/POST | `pos.ver` | La acción exige además abrir/cerrar/realizar venta o política puntual de Supervisor |
| `ventas.php` lectura | `ventas.ver` | Propio; `ventas.ver_todos` amplía solo a la misma cuenta |
| `inventario.php` | `inventario.ver` | Crear, editar, eliminar, importar, exportar, movimientos y transferencias son independientes |
| `core.php` roles | `configuracion.ver` | `configuracion.gestionar_roles` |
| `core.php` usuarios | `configuracion.ver` | `configuracion.gestionar_usuarios` |
| cambio de contraseña | autenticado | `usuarios.restablecer_password`, revoca sesiones del afectado |
| sesiones activas | autenticado | `seguridad.ver_sesiones`, alcance cuenta |
| revocar sesión | autenticado | `seguridad.revocar_sesiones`, alcance cuenta |
| auditoría | autenticado | `seguridad.ver_auditoria`, alcance cuenta |
| exportación de facturas | autenticado | `facturas.exportar`, alcance cuenta |

## Invariantes comprobables

1. Ocultar o mostrar un botón nunca concede acceso.
2. Un identificador de otra cuenta se responde como recurso inexistente o no autorizado.
3. `ventas.ver_todos` no permite cruzar cuentas.
4. El token de Supervisor es de un solo uso, dura 90 segundos y está ligado a acción y contexto.
5. Cambiar contraseña, desactivar credenciales o eliminar un empleado revoca sus sesiones activas.
6. Toda petición de escritura requiere defensa CSRF y una sesión vigente registrada por dispositivo.
