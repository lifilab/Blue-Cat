# Revisión y corrección del proyecto web

## Base utilizada

Se trabajó sobre la carpeta `Blue-Cat_opencode_recovery_v2`, porque era la versión más completa del paquete recibido.

## Correcciones principales realizadas

### 1. Estructura y archivos faltantes

- Se creó `index.php` en la raíz para redirigir correctamente hacia `index.html`.
- Se agregó `.htaccess` con reglas básicas para:
  - Usar `index.php` / `index.html` como entrada.
  - Bloquear listado de directorios.
  - Bloquear acceso directo a archivos sensibles como `.env`.
- Se creó `assets/uploads/.gitkeep` para conservar la carpeta de subidas.
- Se restauraron endpoints PHP faltantes que eran llamados por JavaScript, entre ellos:
  - `assets/PHP/_db.php`
  - `assets/PHP/login.php`
  - `assets/PHP/create_account.php`
  - `assets/PHP/cerrar_sesion.php`
  - `assets/PHP/obtener_validar_sesion.php`
  - `assets/PHP/formulario_apertura.php`
  - `assets/PHP/pedidos.php`
  - `assets/PHP/obtener_productos.php`
  - `assets/PHP/ventas.php`

### 2. Conexión a base de datos

- Se actualizó `assets/api/_db.php` para cargar variables desde `.env`.
- Se agregó compatibilidad con `DB_PASSWORD`, además de `DB_PASS`.
- Se agregó compatibilidad con `DB_PORT`.
- Se agregó una respuesta JSON controlada cuando la extensión PHP `mysqli` no está habilitada.
- Se actualizó `assets/PHP/_db.php` para usar el mismo sistema de configuración por `.env`.
- Se reemplazaron conexiones hardcodeadas a MySQL en scripts PHP heredados.

### 3. Login, registro y sesión

- Se reescribió `assets/PHP/login.php` para:
  - Aceptar usuario o correo.
  - Verificar contraseña con `password_verify`.
  - Validar usuarios activos.
  - Guardar `user_id` y `user_name` en sesión.
  - Responder siempre en JSON para el frontend.
- Se reescribió `assets/PHP/create_account.php` para:
  - Validar correo y contraseñas.
  - Evitar usuarios/correos duplicados.
  - Asignar rol Administrador al primer usuario y Vendedor a los siguientes, si los roles existen.
- Se reescribió `assets/PHP/cerrar_sesion.php` para cerrar sesión de forma segura y responder en JSON.
- Se actualizó `assets/js/Index.js` para manejar correctamente errores JSON y redirecciones.

### 4. Correcciones de API PHP

- Se corrigió `getJsonInput()` para permitir acceso por arreglo y por objeto, ya que diferentes APIs usaban ambos estilos.
- Se corrigió `logAccess()` para usar columnas existentes en `core_auditoria`.
- Se corrigieron consultas de cuenta/usuarios para usar `usuario.id_cuenta`, evitando tablas inexistentes.
- Se corrigió `actualizarKardex()` para usar columnas reales del esquema SQL:
  - `saldo`
  - `tipo_movimiento`
  - `id_documento`
  - `documento_tipo`
  - `observaciones`
- Se agregó `requierePermiso()` en `assets/api/core.php`, porque era llamado pero no existía.
- Se corrigieron errores de cantidad/tipo en `bind_param()` en:
  - `assets/api/inventario.php`
  - `assets/api/empleados.php`
  - `assets/api/facturas.php`

### 5. Seguridad básica

- Se eliminó el uso público de scripts instaladores PHP dentro de `assets/sql/`.
- Se reescribió `assets/PHP/subir_archivo.php` para:
  - Usar conexión centralizada.
  - Validar sesión.
  - Generar nombres seguros para archivos subidos.
  - Usar consultas preparadas.
  - Responder en JSON.
- Se redujeron mensajes de error internos expuestos al frontend.

## Validaciones realizadas

### PHP

Resultado:

```text
PHP lint errors: 0
```

Se ejecutó validación sintáctica sobre todos los archivos `.php`.

### JavaScript

Resultado:

```text
JS lint errors: 0
```

Se ejecutó validación sintáctica sobre todos los archivos `.js`.

### Referencias HTML

Resultado:

```text
No missing HTML refs
```

No se encontraron archivos CSS/JS/imagen faltantes en las referencias directas de los HTML.

### Prueba local con servidor PHP

Se probó con servidor PHP local desde la raíz del proyecto:

```text
200 /index.html
302 /index.php
200 /public/Inicio.html
200 /public/pos.html
200 /assets/css/blue-cat.css
200 /assets/js/Index.js
401 /assets/api/inventario.php
302 /assets/PHP/login.php
```

La respuesta `401` en API sin sesión es correcta: indica que el endpoint existe y exige autenticación.

## Requisitos para instalar

- PHP 8.x recomendado.
- Extensión PHP `mysqli` habilitada.
- MySQL o MariaDB.
- Servidor Apache/Nginx o servidor PHP local para pruebas.

## Pasos de instalación

1. Copiar `.env.example` como `.env`.
2. Configurar los datos reales de base de datos:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=bluecat_erp
DB_USER=TU_USUARIO
DB_PASSWORD=TU_PASSWORD
```

3. Crear la base de datos en MySQL/MariaDB.
4. Importar los SQL principales en este orden:

```text
assets/sql/01_core.sql
assets/sql/02_pos.sql
assets/sql/03_inventario.sql
assets/sql/04_crm.sql
assets/sql/05_empleados.sql
assets/sql/06_facturacion.sql
assets/sql/07_proveedores.sql
assets/sql/08_config.sql
assets/sql/09_seed.sql
```

5. Abrir el proyecto desde la raíz en un servidor PHP/Apache.

Para prueba local:

```bash
php -S localhost:8000
```

Luego abrir:

```text
http://localhost:8000/index.html
```

## Limitación de la validación

En este entorno no está habilitada la extensión PHP `mysqli` ni hay un servidor MySQL activo. Por eso se validaron sintaxis, rutas, carga de páginas y respuestas controladas, pero no fue posible ejecutar flujos reales con base de datos como login completo, ventas o inventario con datos persistidos.
