# Tutoriales

Los tutoriales se definen en `src/config/tutorials.ts`. Cada tutorial tiene slug, categoría, nivel, duración y pasos. El reproductor permite avanzar, retroceder, reiniciar, seleccionar un paso y usar flechas del teclado.

Framer Motion anima cambios de paso y respeta `prefers-reduced-motion`. GSAP se evaluará únicamente cuando existan secuencias complejas basadas en flujos reales; no se agregó al MVP porque duplicaría la solución actual.
