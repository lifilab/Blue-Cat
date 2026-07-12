# Changelog

Todos los cambios notables en Blue-Cat ERP serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto se adhiere a [Semantic Versioning](https://semver.org/lang/es/).

## [1.0.0] - 2026-07-07

### Añadido

#### Módulo POS (Punto de Venta)
- Interfaz de punto de venta completa
- Búsqueda de productos por nombre o código de barras
- Carrito de compras con modificación de cantidades y precios
- Soporte para productos por unidad, peso y volumen
- Múltiples métodos de pago (Efectivo, Tarjeta, Transferencia, Débito)
- Aplicación de descuentos y promociones
- Gestión de clientes desde POS
- Apertura y cierre de caja
- Cuadre de ventas
- Generación e impresión de boletas personalizables
- Anulación de ventas con restauración de stock
- Devoluciones parciales y totales
- Cotizaciones convertibles a ventas
- Reportes de ventas por hora, cajero y productos top

#### Módulo Inventario
- Gestión completa de productos
- Categorías y subcategorías
- Marcas
- Bodegas múltiples
- Control de stock por bodega
- Kardex de movimientos
- Transferencias entre bodegas
- Ajustes de inventario
- Inventario físico con conciliación
- Alertas de stock mínimo
- Control de lotes y series
- Unidades de medida personalizables
- Reportes de inventario

#### Módulo Ventas
- Historial completo de ventas
- Filtros por periodo, empleado, método de pago, estado
- Búsqueda por número, cliente o empleado
- Edición de ventas con auditoría
- Anulación de ventas
- Exportación a CSV
- Cuadre de caja integrado
- Múltiples vistas: Lista, Gráficos, Tarjetas

#### Módulo CRM (Clientes)
- Gestión de clientes
- Historial de compras por cliente
- Créditos y pagos
- Etiquetas personalizables
- Contactos múltiples
- Reportes de clientes frecuentes

#### Módulo Empleados
- Gestión de empleados
- Creación de credenciales de acceso
- Asignación de roles y permisos
- Gestión de contratos
- Control de asistencia
- Gestión de vacaciones
- Cambio de contraseñas

#### Módulo Facturas
- Creación de facturas
- Anulación de facturas
- Emisión de notas de crédito
- Historial de facturas
- Exportación a CSV

#### Módulo Proveedores
- Gestión de proveedores
- Asociación de productos
- Historial de compras
- Datos bancarios

#### Módulo Configuración
- Dashboard de configuración
- Gestión de empresas
- Gestión de sucursales
- Gestión de usuarios
- Sistema de roles y permisos (RBAC)
- Gestión de monedas
- Gestión de impuestos
- Plantilla de boletas personalizable
- Auditoría de acciones

### Seguridad
- Autenticación basada en sesiones PHP
- Contraseñas hasheadas con bcrypt (12 rondas)
- Sistema de permisos por roles
- Protección contra inyección SQL (prepared statements)
- Tokens CSRF en login
- Rate limiting en login (5 intentos, 5 minutos)
- Variables de entorno para credenciales
- Validación y sanitización de entradas
- Logging de auditoría

### Infraestructura
- Sistema de variables de entorno (.env)
- Archivo .gitignore configurado
- Documentación completa:
  - INSTALL.md (guía de instalación)
  - USER_GUIDE.md (manual de usuario)
  - TECHNICAL_MANUAL.md (manual técnico)
  - CHANGELOG.md (este archivo)
  - README.md (documentación principal)

### Base de Datos
- Esquema normalizado (3NF)
- Claves foráneas con integridad referencial
- Índices en campos de búsqueda frecuentes
- Tipos de datos DECIMAL para precisión monetaria
- Soporte para productos por peso/volumen (3 decimales)

### Documentación
- Guía de instalación paso a paso (Linux, Windows, Docker)
- Manual de usuario completo con capturas
- Manual técnico con arquitectura y API
- Registro de cambios (CHANGELOG)

---

## [0.9.0] - 2026-07-06

### Añadido
- Sistema de boletas personalizables
- Configuración de logo, datos de empresa, mensajes
- Control de cambio de precios por permisos
- Visibilidad de ventas para administradores
- Gestión de cuentas de empleados

### Corregido
- Vulnerabilidad de archivos de instalación expuestos
- Credenciales hardcodeadas migradas a variables de entorno
- Consultas SQL sin prepared statements
- Variables superglobales sin sanitización

### Cambiado
- _db.php ahora usa variables de entorno
- Eliminados archivos de instalación del directorio público
- Implementado sistema de carga de variables de entorno

---

## [0.8.0] - 2026-07-05

### Añadido
- Soporte para productos por peso y volumen
- Modal de ingreso de peso en POS
- Cálculo automático de subtotal por peso
- Migración de campos INT a DECIMAL en base de datos
- Unidades de medida (kg, g, L, mL, etc.)

### Corregido
- Errores de bind_param con decimales
- Cálculos de stock con productos por peso
- Validación de stock suficiente para productos por peso

---

## [0.7.0] - 2026-07-04

### Añadido
- Sistema de cuentas (dueño + empleados)
- Permisos por cuenta
- Visibilidad de ventas por cuenta
- Gestión de empleados vinculados a usuarios

### Corregido
- Problemas de autorización entre empleados
- Filtrado de ventas por cuenta

---

## [0.6.0] - 2026-07-03

### Añadido
- Integración completa de cuadre de caja
- Flujo POS → Cuadre → Cierre de sesión
- Resumen de ventas por método de pago
- Cálculo de diferencias

### Corregido
- Flujo de cierre de caja
- Actualización de stock en anulaciones
- Movimientos de caja en devoluciones

---

## [0.5.0] - 2026-07-02

### Añadido
- Sistema de auditoría completo
- Logging de acciones de usuario
- Registro de accesos denegados
- Visualización de logs en configuración

### Corregido
- Manejo de errores en APIs
- Mensajes de error sin exponer información sensible

---

## [0.4.0] - 2026-07-01

### Añadido
- Múltiples vistas en módulo de ventas
- Vista de lista con tabla expandible
- Vista de gráficos con barras y dona
- Vista de tarjetas
- Switcher de vistas

### Corregido
- Rendimiento en listas largas
- Paginación en todas las vistas

---

## [0.3.0] - 2026-06-30

### Añadido
- Sistema de promociones y descuentos
- Códigos promocionales
- Descuentos por porcentaje y monto
- Validación de promociones
- Historial de descuentos aplicados

### Corregido
- Cálculo de totales con descuentos
- Validación de promociones activas

---

## [0.2.0] - 2026-06-29

### Añadido
- Sistema de roles y permisos (RBAC)
- Roles predefinidos: Administrador, Vendedor, Cajero, Bodeguero
- Permisos granulares por módulo y acción
- Asignación de roles a usuarios
- Verificación de permisos en todos los endpoints

### Corregido
- Autorización en operaciones CRUD
- Control de acceso por roles

---

## [0.1.0] - 2026-06-28

### Añadido
- Estructura inicial del proyecto
- Módulo POS básico
- Módulo de inventario básico
- Sistema de autenticación
- Base de datos MySQL
- Interfaz de usuario responsive

---

## Tipos de Cambios

- **Añadido**: Nuevas características
- **Cambiado**: Cambios en funcionalidades existentes
- **Deprecado**: Características que serán eliminadas pronto
- **Eliminado**: Características eliminadas
- **Corregido**: Corrección de errores
- **Seguridad**: Vulnerabilidades corregidas

---

**Mantenedor:** Blue-Cat Development Team  
**Contacto:** desarrollo@bluecat.com
