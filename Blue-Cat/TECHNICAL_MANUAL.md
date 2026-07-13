# Manual Técnico - Blue-Cat ERP

## Arquitectura del Sistema

### Stack Tecnológico

**Backend:**
- PHP 8.3
- MySQL 8.0
- Apache 2.4

**Frontend:**
- HTML5
- CSS3
- JavaScript (Vanilla)
- Font Awesome 5.15

**Infraestructura:**
- Docker (opcional)
- Docker Compose (opcional)

### Estructura de Directorios

```
Blue-Cat/
├── assets/
│   ├── api/              # Endpoints de API
│   │   ├── _db.php       # Conexión a BD
│   │   ├── env_loader.php # Cargador de variables de entorno
│   │   ├── pos.php       # Módulo POS
│   │   ├── inventario.php # Módulo Inventario
│   │   ├── ventas.php    # Módulo Ventas
│   │   ├── crm.php       # Módulo CRM
│   │   ├── empleados.php # Módulo Empleados
│   │   ├── facturas.php  # Módulo Facturas
│   │   ├── proveedores.php # Módulo Proveedores
│   │   ├── core.php      # Configuración
│   │   └── dashboard.php # Dashboard
│   ├── css/              # Estilos
│   │   └── blue-cat.css  # Estilos principales
│   ├── js/               # JavaScript
│   │   ├── pos.js        # Lógica POS
│   │   ├── inventario.js # Lógica Inventario
│   │   ├── ventas.js     # Lógica Ventas
│   │   ├── crm.js        # Lógica CRM
│   │   ├── empleados.js  # Lógica Empleados
│   │   ├── facturas.js   # Lógica Facturas
│   │   ├── proveedores.js # Lógica Proveedores
│   │   ├── configuracion.js # Lógica Configuración
│   │   ├── navbar.js     # Navegación
│   │   └── login_pvt.js  # Login
│   ├── sql/              # Scripts SQL
│   │   ├── core.sql      # Tablas core
│   │   ├── pos.sql       # Tablas POS
│   │   ├── inventario.sql # Tablas Inventario
│   │   ├── crm.sql       # Tablas CRM
│   │   ├── empleados.sql # Tablas Empleados
│   │   ├── facturacion.sql # Tablas Facturación
│   │   └── proveedores.sql # Tablas Proveedores
│   ├── PHP/              # Scripts PHP legacy
│   │   ├── login.php     # Login
│   │   └── cerrar_sesion.php # Logout
│   ├── img/              # Imágenes
│   └── uploads/          # Archivos subidos
├── public/               # Páginas HTML
│   ├── pos.html          # POS
│   ├── inventario.html   # Inventario
│   ├── ventas.html       # Ventas
│   ├── crm.html          # CRM
│   ├── empleados.html    # Empleados
│   ├── facturas.html     # Facturas
│   ├── proveedores.html  # Proveedores
│   ├── configuracion.html # Configuración
│   ├── cuadre_de_ventas.html # Cuadre
│   └── Inicio.html       # Dashboard
├── .env                  # Variables de entorno (no subir a git)
├── .env.example          # Ejemplo de variables de entorno
├── .gitignore            # Archivos ignorados por git
├── index.php             # Página principal
├── INSTALL.md            # Guía de instalación
├── USER_GUIDE.md         # Manual de usuario
├── TECHNICAL_MANUAL.md   # Manual técnico (este archivo)
├── CHANGELOG.md          # Registro de cambios
└── README.md             # Documentación principal
```

---

## Base de Datos

### Esquema Principal

#### Tablas Core

**empresa**
```sql
- id_empresa (INT, PK, AUTO_INCREMENT)
- razon_social (VARCHAR 200, NOT NULL)
- nombre_comercial (VARCHAR 200)
- rut (VARCHAR 20, UNIQUE, NOT NULL)
- giro (VARCHAR 200)
- representante_legal (VARCHAR 100)
- direccion (TEXT)
- region (VARCHAR 100)
- ciudad (VARCHAR 100)
- pais (VARCHAR 50, DEFAULT 'Chile')
- telefono (VARCHAR 30)
- correo (VARCHAR 100)
- sitio_web (VARCHAR 100)
- regimen_tributario (VARCHAR 50)
- actividad_economica (VARCHAR 100)
- moneda_base (VARCHAR 10, DEFAULT 'CLP')
- logo (VARCHAR 255)
- color_primario (VARCHAR 7, DEFAULT '#4f46e5')
- activo (TINYINT 1, DEFAULT 1)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)
```

**sucursal**
```sql
- id_sucursal (INT, PK, AUTO_INCREMENT)
- id_empresa (INT, FK)
- codigo (VARCHAR 20, UNIQUE, NOT NULL)
- nombre (VARCHAR 100, NOT NULL)
- direccion (TEXT)
- responsable (VARCHAR 100)
- telefono (VARCHAR 30)
- correo (VARCHAR 100)
- horario_apertura (TIME)
- horario_cierre (TIME)
- zona_horaria (VARCHAR 50, DEFAULT 'America/Santiago')
- activo (TINYINT 1, DEFAULT 1)
- created_at (TIMESTAMP)
```

**usuario**
```sql
- id_user (INT, PK, AUTO_INCREMENT)
- id_empresa (INT, FK)
- id_sucursal (INT, FK)
- id_cuenta (INT)
- id_empleado (INT)
- nombre (VARCHAR 100, NOT NULL)
- nombre_completo (VARCHAR 200)
- correo (VARCHAR 100, NOT NULL)
- password (VARCHAR 255, NOT NULL)
- telefono (VARCHAR 30)
- cargo (VARCHAR 100)
- idioma (VARCHAR 10, DEFAULT 'es')
- validar_sesion (TINYINT 1, DEFAULT 0)
- intentos_fallidos (INT, DEFAULT 0)
- ultimo_acceso (DATETIME)
- activo (TINYINT 1, DEFAULT 1)
- fecha_creacion (DATETIME)
```

**rol**
```sql
- id_rol (INT, PK, AUTO_INCREMENT)
- nombre (VARCHAR 50, UNIQUE, NOT NULL)
- descripcion (TEXT)
- es_sistema (TINYINT 1, DEFAULT 0)
- activo (TINYINT 1, DEFAULT 1)
- created_at (TIMESTAMP)
```

**permiso**
```sql
- id_permiso (INT, PK, AUTO_INCREMENT)
- modulo (VARCHAR 50, NOT NULL)
- accion (VARCHAR 50, NOT NULL)
- descripcion (VARCHAR 200)
- UNIQUE KEY (modulo, accion)
```

**rol_permiso**
```sql
- id_rol_permiso (INT, PK, AUTO_INCREMENT)
- id_rol (INT, FK)
- id_permiso (INT, FK)
- UNIQUE KEY (id_rol, id_permiso)
```

**usuario_rol**
```sql
- id_usuario_rol (INT, PK, AUTO_INCREMENT)
- id_user (INT, FK)
- id_rol (INT, FK)
- UNIQUE KEY (id_user, id_rol)
```

#### Tablas POS

**sesion**
```sql
- id_sesion (INT, PK, AUTO_INCREMENT)
- id_user (INT, FK)
- fecha_ingreso (VARCHAR 50)
- fecha_cierre (DATETIME)
- monto_apertura (INT)
- empleado (VARCHAR 30)
- nota (VARCHAR 200)
```

**pos_caja**
```sql
- id_caja (INT, PK, AUTO_INCREMENT)
- id_user (INT, FK)
- codigo (VARCHAR 20, NOT NULL)
- nombre (VARCHAR 100, NOT NULL)
- sucursal (VARCHAR 100, DEFAULT 'Principal')
- estado (VARCHAR 20, DEFAULT 'CERRADA')
- monto_apertura (INT, DEFAULT 0)
- monto_actual (INT, DEFAULT 0)
- monto_cierre (INT, DEFAULT 0)
- fecha_apertura (DATETIME)
- fecha_cierre (DATETIME)
- id_sesion (INT, FK)
```

**pedido**
```sql
- id_pedido (INT, PK, AUTO_INCREMENT)
- id_sesion (INT, FK)
- id_cliente (INT, FK)
- id_caja (INT, FK)
- id_bodega (INT, FK)
- tipo_documento (VARCHAR 30)
- cliente_nombre (VARCHAR 150)
- cliente_rut (VARCHAR 20)
- cliente_correo (VARCHAR 100)
- cliente_telefono (VARCHAR 30)
- anulado (TINYINT 1, DEFAULT 0)
- devuelto (TINYINT 1, DEFAULT 0)
- precio_total (DECIMAL 10,2)
- pago_total (DECIMAL 10,2)
- diferencia (DECIMAL 10,2)
- fecha (DATETIME)
```

**detalle_pedido**
```sql
- id_detalle_pedido (INT, PK, AUTO_INCREMENT)
- id_pedido (INT, FK)
- id_producto (INT, FK)
- cantidad_pedida (DECIMAL 10,3, NOT NULL)
- precio_total (DECIMAL 10,2)
```

**metodo_de_pago**
```sql
- id_metodo_de_pago (INT, PK, AUTO_INCREMENT)
- id_pedido (INT, FK)
- nombre_metodo_pago (VARCHAR 50)
- monto (DECIMAL 10,2)
```

#### Tablas Inventario

**producto**
```sql
- id_producto (INT, PK, AUTO_INCREMENT)
- id_user (INT, FK)
- nombre_producto (VARCHAR 100, NOT NULL)
- precio_venta (DECIMAL 10,2)
- codigo_de_barras (VARCHAR 30)
- cantidad (DECIMAL 10,3)
- categoria (VARCHAR 100)
- descripcion (TEXT)
- sku (VARCHAR 50)
- id_categoria (INT, FK)
- id_subcategoria (INT, FK)
- id_marca (INT, FK)
- id_proveedor (INT, FK)
- tipo (ENUM: PRODUCTO, SERVICIO, MATERIA_PRIMA, TERMINADO, ACTIVO, CONSUMIBLE, KIT, COMBO, VARIANTE)
- precio_costo (DECIMAL 10,2, DEFAULT 0)
- costo_promedio (DECIMAL 10,2, DEFAULT 0)
- ultimo_costo (DECIMAL 10,2, DEFAULT 0)
- imagen (VARCHAR 255)
- activo (TINYINT 1, DEFAULT 1)
- peso (DECIMAL 10,2, DEFAULT 0)
- volumen (DECIMAL 10,2, DEFAULT 0)
- alto (DECIMAL 10,2, DEFAULT 0)
- ancho (DECIMAL 10,2, DEFAULT 0)
- largo (DECIMAL 10,2, DEFAULT 0)
- id_unidad (INT, FK)
- stock_minimo (DECIMAL 10,3, DEFAULT 0)
- stock_maximo (DECIMAL 10,3, DEFAULT 0)
- punto_reposicion (DECIMAL 10,3, DEFAULT 0)
- stock_seguridad (DECIMAL 10,3, DEFAULT 0)
- lead_time (INT, DEFAULT 0)
- control_lote (TINYINT 1, DEFAULT 0)
- control_serie (TINYINT 1, DEFAULT 0)
- tipo_venta (ENUM: UNIDAD, PESO, VOLUMEN, DEFAULT UNIDAD)
- precio_por_unidad (VARCHAR 20, DEFAULT 'UNIDAD')
```

**stock**
```sql
- id_stock (INT, PK, AUTO_INCREMENT)
- id_producto (INT, FK)
- id_bodega (INT, FK)
- id_ubicacion (INT, FK)
- disponible (DECIMAL 10,3, DEFAULT 0)
- reservado (DECIMAL 10,3, DEFAULT 0)
- comprometido (DECIMAL 10,3, DEFAULT 0)
- en_transito (DECIMAL 10,3, DEFAULT 0)
- danado (DECIMAL 10,3, DEFAULT 0)
- bloqueado (DECIMAL 10,3, DEFAULT 0)
- devuelto (DECIMAL 10,3, DEFAULT 0)
- produccion (DECIMAL 10,3, DEFAULT 0)
- updated_at (TIMESTAMP)
```

**kardex**
```sql
- id_kardex (INT, PK, AUTO_INCREMENT)
- id_producto (INT, FK)
- id_bodega (INT, FK)
- tipo_movimiento (VARCHAR 30)
- id_documento (INT)
- documento_tipo (VARCHAR 30)
- entrada (DECIMAL 10,3, DEFAULT 0)
- salida (DECIMAL 10,3, DEFAULT 0)
- saldo (DECIMAL 10,3, DEFAULT 0)
- costo_unitario (DECIMAL 10,2, DEFAULT 0)
- costo_total (DECIMAL 10,2, DEFAULT 0)
- id_user (INT, FK)
- observaciones (TEXT)
- fecha (DATETIME)
```

**bodega**
```sql
- id_bodega (INT, PK, AUTO_INCREMENT)
- id_user (INT, FK)
- codigo (VARCHAR 20, UNIQUE, NOT NULL)
- nombre (VARCHAR 100, NOT NULL)
- responsable (VARCHAR 100)
- direccion (TEXT)
- telefono (VARCHAR 30)
- estado (ENUM: ACTIVA, INACTIVA, MANTENCION, DEFAULT ACTIVA)
- capacidad (INT, DEFAULT 0)
- observaciones (TEXT)
- created_at (TIMESTAMP)
```

---

## API Endpoints

### Autenticación

**POST /assets/api/auth.php?accion=login**
- Descripción: Iniciar sesión
- Parámetros: username, password, csrf_token
- Respuesta: JSON con mensaje de éxito/error

**POST /assets/api/auth.php?accion=logout**
- Descripción: Cerrar sesión
- Respuesta: JSON con mensaje de éxito

### POS

**GET /assets/api/pos.php?accion=dashboard**
- Descripción: Obtener datos del dashboard POS
- Autenticación: Requerida
- Respuesta: JSON con estadísticas

**GET /assets/api/pos.php?accion=productos**
- Descripción: Listar productos
- Parámetros: q (búsqueda), cat (categoría), page, limit
- Autenticación: Requerida
- Respuesta: JSON con lista de productos

**POST /assets/api/pos.php**
- Descripción: Operaciones POS
- Body: JSON con accion y datos
- Acciones:
  - caja_abrir: Abrir caja
  - caja_cerrar: Cerrar caja
  - venta_crear: Crear venta
  - venta_anular: Anular venta
  - cliente_crear: Crear cliente
  - promocion_crear: Crear promoción
  - promocion_validar: Validar promoción
  - cotizacion_crear: Crear cotización
  - cotizacion_convertir: Convertir cotización
  - devolucion_crear: Crear devolución

### Inventario

**GET /assets/api/inventario.php?accion=dashboard**
- Descripción: Dashboard de inventario
- Autenticación: Requerida

**GET /assets/api/inventario.php?accion=productos**
- Descripción: Listar productos
- Parámetros: search, id_categoria, id_marca, estado, page, limit
- Autenticación: Requerida

**POST /assets/api/inventario.php**
- Descripción: Operaciones de inventario
- Acciones:
  - producto_crear: Crear producto
  - producto_editar: Editar producto
  - categoria_crear: Crear categoría
  - marca_crear: Crear marca
  - bodega_crear: Crear bodega
  - movimiento_crear: Crear movimiento
  - transferencia_crear: Crear transferencia
  - ajuste_crear: Crear ajuste

### Ventas

**GET /assets/api/ventas.php?accion=listar**
- Descripción: Listar ventas
- Parámetros: desde, hasta, periodo, id_user, estado, metodo, busqueda, page, limit
- Autenticación: Requerida

**POST /assets/api/ventas.php**
- Descripción: Operaciones de ventas
- Acciones:
  - editar: Editar venta
  - eliminar: Eliminar venta
  - cuadre: Realizar cuadre

### CRM

**GET /assets/api/crm.php?accion=clientes**
- Descripción: Listar clientes
- Parámetros: search, page, limit
- Autenticación: Requerida

**POST /assets/api/crm.php**
- Descripción: Operaciones CRM
- Acciones:
  - cliente_crear: Crear cliente
  - cliente_editar: Editar cliente
  - cliente_eliminar: Eliminar cliente

### Empleados

**GET /assets/api/empleados.php?accion=empleados**
- Descripción: Listar empleados
- Parámetros: search, page, limit
- Autenticación: Requerida

**POST /assets/api/empleados.php**
- Descripción: Operaciones de empleados
- Acciones:
  - empleado_crear: Crear empleado
  - empleado_editar: Editar empleado
  - empleado_eliminar: Eliminar empleado
  - crear_credenciales: Crear credenciales

### Facturas

**GET /assets/api/facturas.php?accion=listar**
- Descripción: Listar facturas
- Parámetros: search, page, limit
- Autenticación: Requerida

**POST /assets/api/facturas.php**
- Descripción: Operaciones de facturas
- Acciones:
  - crear: Crear factura
  - anular: Anular factura
  - nota_credito: Emitir nota de crédito

### Proveedores

**GET /assets/api/proveedores.php?accion=listar**
- Descripción: Listar proveedores
- Parámetros: search, page, limit
- Autenticación: Requerida

**POST /assets/api/proveedores.php**
- Descripción: Operaciones de proveedores
- Acciones:
  - crear: Crear proveedor
  - editar: Editar proveedor
  - eliminar: Eliminar proveedor

### Configuración

**POST /assets/api/core.php**
- Descripción: Operaciones de configuración
- Acciones:
  - dashboard: Dashboard de configuración
  - empresas: Listar empresas
  - empresa_crear: Crear empresa
  - empresa_editar: Editar empresa
  - sucursales: Listar sucursales
  - sucursal_crear: Crear sucursal
  - usuarios: Listar usuarios
  - usuario_crear: Crear usuario
  - usuario_editar: Editar usuario
  - usuario_cambiar_password: Cambiar contraseña
  - roles: Listar roles
  - permisos: Listar permisos
  - monedas: Listar monedas
  - impuestos: Listar impuestos
  - config_boleta: Obtener configuración de boleta
  - config_boleta_guardar: Guardar configuración de boleta
  - auditoria: Ver auditoría

---

## Seguridad

### Autenticación

**Método:** Session-based authentication

**Flujo:**
1. Usuario ingresa credenciales
2. Sistema valida contra base de datos
3. Se crea sesión PHP
4. Se almacena user_id en $_SESSION
5. Cada request valida sesión activa

**Contraseñas:**
- Almacenamiento: password_hash() con PASSWORD_DEFAULT (bcrypt)
- Verificación: password_verify()
- Rondas: Configurable (default: 12)

### Autorización

**Sistema:** Role-Based Access Control (RBAC)

**Flujo:**
1. Usuario tiene uno o más roles
2. Cada rol tiene permisos asignados
3. Cada permiso es un par (modulo, accion)
4. Cada endpoint verifica permisos requeridos

**Verificación:**
```php
function verificarPermiso($modulo, $accion) {
    // Consulta BD para verificar si usuario tiene permiso
    // Cache de permisos en memoria
}
```

### Protección contra Inyección SQL

**Método:** Prepared Statements (PDO/MySQLi)

**Ejemplo:**
```php
$stmt = $conn->prepare("SELECT * FROM usuario WHERE id_user = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
```

### Protección contra XSS

**Método:** Escaping de salida

**Ejemplo:**
```php
echo htmlspecialchars($user_input, ENT_QUOTES, 'UTF-8');
```

### Protección contra CSRF

**Método:** Tokens CSRF en formularios

**Flujo:**
1. Generar token aleatorio
2. Incluir token en formulario
3. Validar token en servidor

### Rate Limiting

**Login:**
- Máximo 5 intentos
- Bloqueo por 5 minutos (300 segundos)
- Contador por sesión

### Variables de Entorno

**Archivo:** .env (no subir a git)

**Variables críticas:**
- DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD
- APP_KEY, SESSION_LIFETIME, BCRYPT_ROUNDS
- MAIL_* (si aplica)

---

## Rendimiento

### Optimizaciones Implementadas

1. **Prepared Statements:** Todas las consultas SQL
2. **Caché de Permisos:** En memoria durante request
3. **Índices BD:** En campos de búsqueda frecuentes
4. **Paginación:** En todas las listas
5. **Lazy Loading:** De productos en POS

### Recomendaciones

1. **Caché:** Implementar Redis/Memcached para datos estáticos
2. **CDN:** Para archivos estáticos (CSS, JS, imágenes)
3. **Compresión:** Gzip en Apache/Nginx
4. **Minificación:** CSS y JavaScript
5. **Lazy Loading:** De imágenes
6. **Database Connection Pooling:** Para alta concurrencia

---

## Monitoreo

### Logs

**Ubicación:** /opt/bluecat/logs/

**Tipos:**
- Error logs (Apache/PHP)
- Access logs
- Application logs
- Audit logs

**Rotación:**
- Máximo 10 archivos
- Tamaño máximo: 10 MB por archivo

### Métricas

**Implementar:**
- Tiempo de respuesta de API
- Tasa de errores
- Uso de CPU/Memoria
- Conexiones activas a BD
- Consultas lentas

### Alertas

**Configurar alertas para:**
- Errores críticos
- Caída del servicio
- Uso de disco >80%
- Uso de memoria >90%
- Consultas lentas (>5s)

---

## Backup y Recuperación

### Estrategia de Backup

**Frecuencia:**
- Base de datos: Cada 6 horas
- Archivos: Diario
- Configuración: Semanal

**Retención:**
- Diarios: 7 días
- Semanales: 4 semanas
- Mensuales: 12 meses

**Ubicación:**
- Local: /opt/bluecat/backups
- Remoto: S3, Google Cloud, o servidor externo

### Script de Backup

```bash
#!/bin/bash
# backup.sh

BACKUP_DIR="/opt/bluecat/backups"
DATE=$(date +%Y%m%d_%H%M%S)

# Backup BD
mysqldump -u$DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Backup archivos
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /var/www/bluecat/assets/uploads

# Eliminar antiguos
find $BACKUP_DIR -name "*.sql.gz" -mtime +30 -delete
```

### Procedimiento de Restauración

1. Detener servicios
2. Restaurar base de datos
3. Restaurar archivos
4. Restaurar configuración
5. Iniciar servicios
6. Verificar funcionamiento

---

## Actualizaciones

### Procedimiento de Actualización

1. **Backup:** Crear backup completo
2. **Verificar:** Compatibilidad de versión
3. **Descargar:** Nueva versión
4. **Detener:** Servicios
5. **Migrar:** Base de datos
6. **Desplegar:** Nueva versión
7. **Probar:** Funcionalidad básica
8. **Iniciar:** Servicios
9. **Verificar:** Funcionamiento completo

### Rollback

Si la actualización falla:

1. Detener servicios
2. Restaurar backup de BD
3. Restaurar backup de archivos
4. Iniciar servicios
5. Verificar funcionamiento

---

## Troubleshooting

### Error: "Database connection failed"

**Causas:**
- Credenciales incorrectas
- MySQL no está corriendo
- Firewall bloqueando conexión

**Solución:**
```bash
# Verificar MySQL
sudo systemctl status mysql

# Verificar credenciales
cat /var/www/bluecat/.env | grep DB_

# Probar conexión
mysql -u bluecat_user -p bluecat_erp
```

### Error: "Sesión no válida"

**Causas:**
- Sesión expirada
- Cookies deshabilitadas
- Configuración de sesión incorrecta

**Solución:**
- Limpiar cookies del navegador
- Verificar configuración de sesión en PHP
- Verificar permisos de directorio de sesiones

### Error 500 Internal Server Error

**Causas:**
- Error de sintaxis PHP
- Permisos incorrectos
- Configuración incorrecta

**Solución:**
```bash
# Ver logs
sudo tail -f /var/log/apache2/bluecat_error.log

# Verificar sintaxis
php -l /var/www/bluecat/assets/api/_db.php

# Verificar permisos
ls -la /var/www/bluecat
```

### Error: "Permiso denegado"

**Causas:**
- Permisos de archivos incorrectos
- SELinux bloqueando acceso

**Solución:**
```bash
# Establecer permisos
sudo chown -R www-data:www-data /var/www/bluecat
sudo chmod -R 755 /var/www/bluecat
sudo chmod -R 777 /var/www/bluecat/assets/uploads
```

---

## Desarrollo

### Configuración de Entorno de Desarrollo

1. **Clonar repositorio:**
```bash
git clone https://github.com/usuario/bluecat-erp.git
cd bluecat-erp
```

2. **Instalar dependencias:**
```bash
composer install
```

3. **Configurar variables de entorno:**
```bash
cp .env.example .env
# Editar .env con configuración de desarrollo
```

4. **Crear base de datos:**
```bash
mysql -u root -p -e "CREATE DATABASE bluecat_dev;"
```

5. **Ejecutar migraciones:**
```bash
php migrate.php
```

6. **Iniciar servidor de desarrollo:**
```bash
php -S localhost:8000
```

### Estándares de Código

**PHP:**
- PSR-12 (coding style)
- PSR-4 (autoloading)
- PHPDoc para funciones públicas

**JavaScript:**
- ESLint
- Prettier
- ES6+

**SQL:**
- Nombres en minúsculas
- Snake_case
- Comentarios para queries complejas

### Testing

**PHPUnit:**
```bash
./vendor/bin/phpunit
```

**Cobertura:**
- Objetivo: 70% mínimo
- Crítico: 100% en funciones de seguridad

---

## Soporte

### Contacto

- **Email:** soporte@bluecat.com
- **Teléfono:** +56 9 1234 5678
- **Documentación:** https://docs.bluecat.com

### Recursos

- **Documentación:** https://docs.bluecat.com
- **API Reference:** https://api.bluecat.com
- **Foro:** https://forum.bluecat.com
- **GitHub:** https://github.com/usuario/bluecat-erp

---

**Última actualización:** Julio 2026  
**Versión:** 1.0.0
