# ADR-0002: API canónica

- Estado: Aceptado
- Fecha: 2026-07-12
- Responsable: Pablo-Millones

## Contexto

Existen endpoints modernos en `assets/api/` y scripts históricos en `assets/PHP/`. Algunos flujos todavía referencian la generación histórica, lo que duplica autenticación, acceso a datos y reglas de negocio.

## Decisión

`assets/api/` es la única ubicación permitida para nuevos endpoints. Los puentes indispensables se concentran temporalmente en `assets/api/compat/`: no reciben funcionalidades y sus consumidores deben migrarse por flujo antes de eliminar cada archivo. La carpeta `assets/PHP/` queda eliminada. CI impide agregar nuevos scripts o nuevas referencias legacy.

## Consecuencias

- La eliminación completa será incremental y verificable, sin romper login o caja.
- Toda corrección de seguridad compartida se implementa en `assets/api/_db.php`.
- La Beta no puede salir mientras existan consumidores runtime de `assets/api/compat/`.
