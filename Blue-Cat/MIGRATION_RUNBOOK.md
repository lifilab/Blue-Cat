# Runbook de migración y rollback

## Regla principal

Nunca ejecutar una migración nueva por primera vez sobre la base operativa. El procedimiento obligatorio es backup → restauración aislada → migración → integridad → ventana de actualización.

## Ensayo

1. Crear dump con `mysqldump --single-transaction --routines --triggers --events`.
2. Calcular SHA-256 y guardar el dump fuera del disco principal.
3. Restaurar en un esquema temporal.
4. Ejecutar `php scripts/migrate.php --env=<entorno-copia>`.
5. Repetir el migrador y confirmar que todas las versiones informan `SKIP`.
6. Ejecutar `php scripts/verify-integrity.php --env=<entorno> --source=<real> --target=<copia>`.
7. Ejecutar pruebas de login, apertura, venta, stock, anulación y cierre.

## Producción

1. Cerrar cajas y bloquear temporalmente nuevas escrituras.
2. Verificar espacio libre, servicios y último backup externo.
3. Crear backup previo con checksum.
4. Aplicar migraciones.
5. Ejecutar controles de integridad y smoke tests.
6. Reabrir el servicio solo si todos los controles pasan.

## Rollback

Las migraciones publicadas no se editan y no se confía en un “down” destructivo automático. Si falla la actualización:

1. detener el servidor web;
2. conservar logs y la base fallida para diagnóstico;
3. restaurar aplicación de la versión anterior;
4. restaurar el dump previo en un esquema limpio;
5. verificar checksum, conteos y acceso;
6. cambiar de forma atómica la configuración a la base restaurada;
7. reabrir y registrar el incidente.

## Evidencia Fase 0

El 12 de julio de 2026 se respaldó `erp`, se restauró como `erp_phase0_copy`, se aplicaron ocho migraciones, se repitió el migrador de forma idempotente y se compararon las 90 tablas comunes sin diferencias de conteo. La base operativa no fue modificada.
