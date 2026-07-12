# Blue-Cat: arquitectura de producto y hoja de ruta hacia Beta

## 1. Definición del producto

Blue-Cat será una aplicación de gestión comercial y punto de venta **local-first**, orientada a minimarkets y pequeños comercios. La instalación principal se ejecutará dentro del negocio y seguirá funcionando aunque se interrumpa Internet. Los terminales POS, computadores, tablets o teléfonos autorizados se conectarán al servidor mediante la red local.

El producto no debe comercializarse como una “suscripción de por vida”. El concepto correcto es una **licencia perpetua de pago único**, limitada por plan. El cliente conserva el derecho a usar la versión adquirida. Servicios opcionales como actualizaciones mayores, soporte remoto, respaldo en nube o módulos futuros pueden venderse mediante mantenimiento anual sin desactivar la operación local ya licenciada.

### Principios del producto

- Operación de caja rápida y disponible sin Internet.
- Una cuenta comercial comparte productos, stock, clientes y configuración.
- Cada empleado tiene identidad, rol, permisos y trazabilidad propios.
- Separación estricta entre cuentas, incluso cuando varias cuentas existen en el mismo servidor.
- La interfaz nunca sustituye la seguridad: todo permiso se valida también en la API.
- Instalación, actualización, respaldo y restauración deben ser aptos para usuarios no técnicos.
- Ningún terminal cliente se conecta directamente a MySQL.

## 2. Arquitectura objetivo

### 2.1 Componentes

1. **Blue-Cat Server**
   - Aplicación PHP y servidor web.
   - MySQL/MariaDB accesible únicamente desde el equipo servidor.
   - Servicio de Windows con inicio automático.
   - Administrador local para instalación, diagnóstico, respaldo y actualización.
   - API HTTP para todos los clientes.

2. **Blue-Cat Client**
   - Primera opción: navegador o PWA instalada desde el servidor local.
   - Opción posterior: envoltorio de escritorio liviano para kiosco, accesos directos, impresoras y actualización controlada.
   - No contiene base de datos empresarial ni credenciales de MySQL.
   - Descubre o recuerda la dirección del servidor y abre el POS correspondiente.

3. **Servicio de licencias**
   - Necesario para activar, transferir y administrar licencias, pero no para procesar cada venta.
   - Emite una licencia firmada criptográficamente que el servidor puede validar sin Internet.
   - Permite un periodo de gracia amplio para revalidación y nunca debe interrumpir una venta en curso.

4. **Servicios opcionales en nube**
   - Respaldo cifrado externo.
   - Telemetría autorizada y diagnósticos.
   - Portal de licencias y descarga de actualizaciones.
   - No son dependencia de la operación cotidiana del POS.

### 2.2 Conexión de varios POS en un minimarket

El comercio instala **Blue-Cat Server en un solo equipo estable**, preferiblemente un mini PC dedicado, UPS y conexión Ethernet. Todos los dispositivos se conectan al mismo router por cable o Wi-Fi:

```text
Internet (opcional)
        |
     Router LAN
       / | \
      /  |  \
Server  POS 1  POS 2 / tablet
PHP+DB  navegador/PWA navegador/PWA
```

El servidor tendrá una reserva DHCP o IP fija. Los clientes accederán mediante una dirección como `https://bluecat.local` o `https://192.168.1.20`. Se puede agregar descubrimiento mDNS, pero siempre debe existir configuración manual como respaldo.

El instalador del servidor deberá:

- detectar la red privada;
- solicitar autorización para abrir solo el puerto web en el firewall privado;
- mantener MySQL escuchando únicamente en `127.0.0.1`;
- mostrar un código QR y URL para conectar terminales;
- registrar dispositivos autorizados;
- comprobar conectividad y latencia;
- advertir si el servidor usa Wi-Fi, cambia de IP o carece de respaldo.

Para Beta no se recomienda una base de datos independiente en cada POS: sincronizar ventas, stock y cajas agrega conflictos difíciles. Si el servidor se apaga, los clientes de la red dejan de operar; esto se mitiga con equipo dedicado, UPS, inicio automático, health checks y recuperación rápida. Un modo POS desconectado con cola local puede evaluarse después de la versión estable.

## 3. Modelo SaaS/local multiusuario

La unidad de aislamiento es `cuenta`. Una cuenta contiene usuarios, empleados, sucursales, bodegas, productos, existencias, cajas, sesiones, pedidos, clientes y configuración. Todas las tablas de negocio deben pertenecer de forma directa o derivable a una cuenta.

### Reglas obligatorias

- Un administrador solo administra su cuenta.
- Un empleado pertenece a una cuenta y puede estar vinculado a una sucursal.
- `ver` permite consultar registros propios o del alcance asignado.
- `ver_todos` amplía la consulta únicamente dentro de la misma cuenta.
- `crear`, `editar`, `eliminar`, `exportar`, `abrir_caja`, `cerrar_caja`, `anular` y acciones sensibles son permisos separados.
- Los filtros de cuenta se aplican en SQL/API, no después de obtener datos.
- Todo cambio crítico genera auditoría válida e inmutable.
- Los roles incluidos por defecto pueden clonarse, pero nunca compartirse accidentalmente entre cuentas.

## 4. Planes comerciales propuestos

Los límites deben definirse en una tabla de capacidades y validarse en servidor. “Usuario” significa una credencial activa; “dispositivo POS” significa un terminal autorizado. No conviene limitar sesiones del navegador de forma ambigua.

| Plan | Usuarios activos | POS autorizados | Sucursales | Alcance sugerido |
|---|---:|---:|---:|---|
| Single | 1 | 1 | 1 | Dueño que atiende su caja |
| Emprendedor | 4 | 2 | 1 | Minimarket pequeño |
| Negocio | 10 | 5 | 2 | Comercio con turnos y administración |
| Enterprise | Configurable | Configurable | Configurable | Varias sucursales, soporte y despliegue administrado |

Decisiones comerciales pendientes antes de Beta:

- si el precio incluye actualizaciones menores para siempre;
- duración del soporte inicial incluido;
- precio por usuarios, POS o sucursales adicionales;
- política de transferencia a otro computador;
- reemplazo por falla de hardware;
- módulos incluidos por plan;
- condiciones del respaldo en nube opcional.

## 5. Fases para llegar a Beta

### Fase 0 — Gobierno del producto y repositorio

**Objetivo:** establecer una línea base reproducible.

- Definir propietario, alcance Beta y decisiones técnicas registradas mediante ADR.
- Configurar remoto Git, ramas protegidas, revisión y versiones semánticas.
- Eliminar duplicados legacy y declarar una única API activa.
- Separar datos de demostración, migraciones y datos reales.
- Crear entornos desarrollo, prueba, piloto y producción local.
- Incorporar análisis de secretos y dependencias.

**Salida:** una instalación limpia puede construirse desde Git y todos conocen qué entra o no en Beta.

### Fase 1 — Modelo de datos y aislamiento de cuenta

**Objetivo:** hacer confiable el núcleo multiusuario.

- Inventariar tablas y agregar alcance de cuenta donde corresponda.
- Definir claves foráneas, índices, unicidad y reglas de borrado.
- Reemplazar scripts SQL acumulativos por migraciones ordenadas y versionadas.
- Crear servicio común de contexto: cuenta, usuario, sucursal, bodega y caja.
- Revisar cada endpoint contra fugas entre cuentas.
- Asegurar roles propios de cada cuenta o plantillas globales de solo lectura.
- Preparar migración de datos existentes y rollback probado.

**Salida:** pruebas automatizadas demuestran que una cuenta nunca puede leer o modificar datos de otra.

### Fase 2 — Integridad funcional del POS

**Objetivo:** ninguna venta puede dejar stock, pagos o caja inconsistentes.

- Venta atómica con transacción de base de datos.
- Idempotencia para impedir ventas duplicadas por doble clic o reintento.
- Productos por unidad, peso y volumen con precisión decimal definida.
- Pagos mixtos, vuelto, efectivo, tarjeta y transferencia normalizados.
- Apertura y cierre por caja física y cajero.
- Anulación/devolución con autorización, motivo, stock y auditoría.
- Folios/documentos e impresión tolerante a fallas.
- Control de concurrencia cuando dos POS venden el último producto.
- Cuadre por sesión con ventas, pagos, retiros, ingresos y diferencias.

**Salida:** escenarios críticos pasan pruebas repetibles y el stock nunca queda negativo salvo política explícita.

### Fase 3 — Permisos y seguridad

**Objetivo:** cada casilla de permiso produce un efecto real y verificable.

- Catálogo canónico de permisos y descripción comprensible.
- Middleware único para autenticación, cuenta, rol y acción.
- Matriz automatizada de rol × endpoint × alcance.
- CSRF, cookies seguras, regeneración de sesión y cierre por inactividad.
- Rate limiting y bloqueo progresivo de inicio de sesión.
- Política de contraseñas y recuperación administrada.
- Validación de entradas, cargas de archivos y salidas contra XSS.
- Auditoría estructurada que nunca interrumpe operaciones.
- Encabezados de seguridad y TLS en la red local.

**Salida:** pruebas negativas confirman que ocultar o manipular botones/requests no evita los controles del servidor.

### Fase 4 — Aplicación servidor e instalador

**Objetivo:** instalar Blue-Cat Server en Windows sin Laragon manual.

- Elegir runtime empaquetado y con licencia redistribuible: servidor web, PHP y MySQL/MariaDB.
- Crear servicio de Windows y supervisor de procesos.
- Instalador firmado con instalación, reparación y desinstalación.
- Asistente inicial: empresa, administrador, moneda, impuestos, bodega y caja.
- Creación automática de base de datos y migraciones.
- Directorios separados para aplicación, configuración, datos, logs y backups.
- Health check local y panel de diagnóstico.
- No incluir contraseñas predeterminadas conocidas.

**Salida:** una máquina Windows limpia queda operativa después de un único instalador y reinicio.

### Fase 5 — Clientes LAN y dispositivos

**Objetivo:** conectar múltiples POS de forma sencilla y segura.

- PWA responsive con modo kiosco y acceso directo.
- Pantalla del servidor con URL, QR y estado de red.
- Emparejamiento mediante código temporal y aprobación del administrador.
- Registro, nombre, revocación y límite de dispositivos según plan.
- Detección de servidor por mDNS más entrada manual.
- Pruebas con Ethernet, Wi-Fi, cambios de IP y reinicios del router.
- Compatibilidad con impresora térmica, lector de código como teclado y balanza según alcance.

**Salida:** dos POS venden simultáneamente contra un servidor local sin conflictos ni acceso directo a la base de datos.

### Fase 6 — Licencia perpetua y planes

**Objetivo:** proteger el producto sin hacer dependiente la caja de Internet.

- Licencia firmada con clave privada fuera del instalador.
- Activación online y alternativa offline por archivo/desafío-respuesta.
- Identificador de instalación tolerante a cambios menores de hardware.
- Capacidades por plan, usuarios, dispositivos, sucursales y módulos.
- Transferencia/revocación de licencia y recuperación por falla.
- Periodo de gracia para validación; nunca validar en cada venta.
- Pantallas claras de estado, límites y acciones de solución.
- Contrato de licencia perpetua y política de actualizaciones.

**Salida:** manipular fecha, archivo o respuesta de red no concede capacidades; una caída de Internet no detiene el POS.

### Fase 7 — Respaldo, actualización y recuperación

**Objetivo:** hacer operable el producto durante años.

- Backups automáticos cifrados, rotación y verificación de integridad.
- Exportación manual a USB y destino externo opcional.
- Restauración asistida probada, no solo creación del backup.
- Actualizador firmado con migración, backup previo y rollback.
- Canal estable y canal piloto.
- Logs estructurados, rotación y paquete de diagnóstico sin datos sensibles.
- Procedimiento documentado para reemplazar el servidor.

**Salida:** se restaura una instalación completa en otra máquina y se recupera de una actualización fallida.

### Fase 8 — Calidad, rendimiento y observabilidad

**Objetivo:** medir que el sistema cumple su promesa.

- Pruebas unitarias de dinero, stock, permisos y licencias.
- Pruebas de integración sobre MySQL real.
- Pruebas E2E de login, apertura, venta, anulación, cierre y exportación.
- Pruebas de concurrencia con varios POS.
- Presupuesto de rendimiento: acciones POS comunes por debajo de 300 ms en LAN.
- Pruebas de recuperación tras corte de energía y reinicio abrupto.
- Matriz de navegadores, resoluciones, impresoras y lectores compatibles.
- Escaneo de seguridad y revisión de configuración de producción.

**Salida:** pipeline verde, cero defectos críticos abiertos y métricas dentro del presupuesto.

### Fase 9 — Piloto Beta

**Objetivo:** validar el producto en comercios reales con riesgo controlado.

- Seleccionar entre 3 y 5 comercios con hardware diverso.
- Levantar inventario y capacitar a usuarios.
- Ejecutar en paralelo con el sistema anterior durante un periodo acordado.
- Canal de soporte, severidades y tiempos de respuesta.
- Telemetría opcional con consentimiento y anonimización.
- Revisión semanal de incidentes y priorización.
- Congelamiento de alcance antes del lanzamiento Beta público.

**Salida:** al menos dos semanas operativas sin pérdida de ventas, stock ni datos y con restauración probada.

## 6. Alcance recomendado para la primera Beta

### Incluido

- Instalador Blue-Cat Server para Windows 10/11 de 64 bits.
- Uno a cinco terminales LAN mediante navegador/PWA.
- Planes Single y Emprendedor completamente aplicados.
- POS, productos, inventario, clientes, empleados básicos, ventas y cuadre.
- Roles y permisos reales.
- Productos por peso.
- Importación/exportación XLS.
- Backup local, restauración y actualización firmada.
- Licencia online y activación offline asistida.

### Fuera de alcance inicial

- Operación de un POS sin conexión al servidor local.
- Sincronización bidireccional entre varios servidores/sucursales.
- Aplicaciones móviles nativas.
- Contabilidad completa, remuneraciones o ERP generalista.
- Integraciones tributarias de múltiples países.
- Marketplace de extensiones.

## 7. Criterios de salida a Beta

- Cero defectos críticos o altos conocidos en venta, caja, stock, permisos y aislamiento.
- Instalación y actualización exitosas en una máquina limpia.
- Dos POS concurrentes probados durante una jornada simulada.
- Restauración completa desde backup verificada.
- Matriz de permisos automatizada y aprobada.
- Licencias y límites de plan probados online/offline.
- Manual de usuario, administrador, instalación y recuperación actualizados.
- Inventario de terceros y licencias de redistribución aprobado.
- Soporte piloto y procedimiento de incidentes disponibles.
- Firma de código y canal de distribución definidos.

## 8. Orden inmediato de ejecución

1. Configurar remoto Git, integración continua y estrategia de versiones.
2. Congelar alcance Beta y resolver duplicidad entre API activa y scripts legacy.
3. Auditar esquema completo y aislamiento por cuenta.
4. Crear pruebas de regresión para venta, stock, caja y permisos.
5. Estabilizar POS y cuadre bajo concurrencia.
6. Prototipar Blue-Cat Server como servicio Windows.
7. Probar dos clientes LAN mediante PWA.
8. Diseñar licencia firmada y tabla formal de planes.
9. Implementar backup/restauración y actualizador.
10. Ejecutar piloto controlado y cerrar defectos de salida.

## 9. Decisiones arquitectónicas que deben formalizarse

- Windows como plataforma inicial del servidor.
- MariaDB o MySQL y sus condiciones de redistribución.
- Apache, Nginx, Caddy u otro servidor embebible.
- PWA primero versus cliente de escritorio desde Beta.
- Certificados TLS locales y proceso de confianza en clientes.
- Proveedor y operación del servicio de licencias.
- Política de actualizaciones incluida con licencia perpetua.
- Soporte de hardware: impresoras, gavetas, balanzas y lectores.
- Estrategia futura para múltiples sucursales y acceso remoto.

Estas decisiones deben registrarse antes de construir el instalador, porque afectan licencias de terceros, soporte, actualizaciones, seguridad y costo operativo.
