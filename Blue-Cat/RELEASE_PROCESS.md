# Proceso de releases

## Canales

- `pilot`: versiones `v0.x.y-beta.n`, destinadas a instalaciones piloto.
- `stable`: versiones sin sufijo, aprobadas después del piloto.

## Preparación

1. CI verde en `master`.
2. Cero defectos críticos o altos abiertos.
3. Backup y restauración de la versión anterior probados.
4. Migraciones probadas sobre base vacía y copia anonimizada.
5. Actualizar `VERSION` y `CHANGELOG.md`.
6. Crear tag firmado `vX.Y.Z` o `vX.Y.Z-beta.N`.

El tag dispara `release.yml`, que valida la versión, crea ZIP, checksum SHA-256, SBOM SPDX y release notes automáticas.

## Firma

El código fuente y los checksums se publican desde CI. El instalador ejecutable deberá firmarse con Authenticode mediante un certificado almacenado como secreto protegido del entorno de release. Nunca se almacena la clave privada en Git.

## Promoción

Una Beta solo se promueve a estable después del periodo piloto y una restauración comprobada. La promoción crea una versión nueva; no se reemplazan artefactos ya publicados.
