# Gobierno de Blue-Cat

## Propiedad y responsabilidades

- Propietario del producto: Pablo-Millones.
- Repositorio oficial: `Pablo-Millones/Blue-Cat`.
- Rama estable: `master`.
- El propietario aprueba alcance, precios, licencias y releases.
- Todo cambio funcional debe incluir validación proporcional al riesgo.
- Los cambios de arquitectura se registran en `docs/adr/`.

## Flujo de cambios

1. Crear una rama `feature/<tema>`, `fix/<tema>` o `docs/<tema>`.
2. Mantener cada cambio enfocado y verificable.
3. Abrir pull request contra `master`.
4. Exigir CI verde antes de fusionar.
5. Para seguridad, caja, stock, permisos, migraciones o licencias se requiere revisión del propietario.
6. Fusionar sin commits de prueba y registrar cambios visibles en `CHANGELOG.md`.

## Versionado

Se usa SemVer: `MAJOR.MINOR.PATCH`.

- Antes de la versión estable: `0.x.y`.
- Beta: `0.x.y-beta.n`.
- `PATCH`: corrección compatible.
- `MINOR`: funcionalidad compatible.
- `MAJOR`: cambio incompatible o nueva generación contractual.

La versión fuente está en `VERSION`. Un tag `vX.Y.Z` identifica cada artefacto distribuible.

## Definición de terminado

- Criterios de aceptación satisfechos.
- Autorización y aislamiento por cuenta revisados.
- Sintaxis PHP/JavaScript y comprobaciones de repositorio aprobadas.
- Migraciones hacia delante y rollback documentados cuando correspondan.
- Sin secretos, datos reales, backups ni archivos de entorno incluidos.
- Documentación y changelog actualizados.

## Política de ramas

- `master` debe estar protegida.
- No se permiten force-push ni eliminación de `master`.
- Los cambios entran mediante pull request.
- Se exige el check `baseline` y conversaciones resueltas.
- Durante el equipo unipersonal, la aprobación obligatoria puede mantenerse en cero; al incorporar otro desarrollador debe exigirse al menos una aprobación.

## Incidentes

Los incidentes de pérdida de ventas, stock, aislamiento, autenticación o licencia son críticos. Se corrigen en una rama `fix/`, se agrega prueba de regresión y se publica una versión PATCH.
