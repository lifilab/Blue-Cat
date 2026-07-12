# ADR-0001: servidor local y clientes LAN

- Estado: Aceptado
- Fecha: 2026-07-12
- Responsable: Pablo-Millones

## Contexto

El POS debe tener baja latencia y continuar operando cuando falle Internet. Un minimarket puede usar dos o más cajas que comparten productos, stock y sesiones.

## Decisión

Blue-Cat usará un servidor local único por instalación. El servidor ejecuta PHP y MySQL/MariaDB; los clientes acceden por HTTPS mediante navegador/PWA. MySQL solo escucha en localhost. Internet se usa para activación, actualizaciones y servicios opcionales, nunca para autorizar cada venta.

## Consecuencias

- Las cajas comparten estado consistente sin sincronizar bases independientes.
- El equipo servidor requiere inicio automático, UPS, backup y recuperación.
- Una falla del servidor afecta temporalmente a todos los POS.
- El modo desconectado por terminal queda fuera de la primera Beta.
