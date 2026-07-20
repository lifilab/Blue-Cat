# ADR 0004: comprobantes fuera del directorio público

Estado: propuesto para la fase de pagos.

Los comprobantes no se guardarán en `public/` ni en URLs predecibles. El backend validará tipo real, extensión, tamaño y autorización; asignará nombres aleatorios; almacenará mediante un adaptador privado; y registrará cada acceso. Para despliegues cloud se recomienda almacenamiento de objetos con cifrado y URLs temporales. La política de retención está pendiente de aprobación legal.
