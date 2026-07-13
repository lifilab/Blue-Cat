# ADR-0003: migraciones, demos y datos reales

- Estado: Aceptado
- Fecha: 2026-07-12
- Responsable: Pablo-Millones

## Decisión

- Las migraciones canónicas viven en `database/migrations/` y son inmutables una vez liberadas.
- Los datos demostrativos viven en `database/demo/` y nunca contienen información de clientes reales.
- Los datos locales, dumps, hojas de cálculo, backups y `.env` quedan fuera de Git.
- Cada instalación registra la versión de esquema aplicada.

## Consecuencias

Una instalación y una actualización pueden reproducirse desde Git, mientras los datos de operación permanecen fuera del código fuente.
