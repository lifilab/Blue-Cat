-- Las ventas confirmadas son comprobantes inmutables. Estos permisos heredados
-- ofrecían operaciones que el API rechaza con 409 y confundían la matriz RBAC.
-- Las correcciones autorizadas permanecen en pos.cancelar_venta y
-- pos.devoluciones, ambas sujetas al flujo puntual de Supervisor.

DELETE FROM permiso
WHERE modulo = 'ventas'
  AND accion IN ('editar', 'eliminar');
