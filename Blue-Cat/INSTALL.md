# Guía de Instalación - Blue-Cat ERP

## Requisitos del Sistema

### Servidor
- **Sistema Operativo:** Ubuntu 22.04 LTS / Windows Server 2022 / CentOS 8+
- **Procesador:** 2 cores mínimo (4 cores recomendado)
- **Memoria RAM:** 4 GB mínimo (8 GB recomendado)
- **Almacenamiento:** 50 GB SSD mínimo (100 GB recomendado)
- **Red:** 100 Mbps mínimo (1 Gbps recomendado)

### Software
- **PHP:** 8.3 o superior
- **MySQL:** 8.0 o superior
- **Apache:** 2.4 o superior / Nginx 1.18+
- **Extensiones PHP:** mysqli, pdo_mysql, mbstring, json, openssl

### Navegadores Soportados
- Chrome 90+
- Firefox 88+
- Edge 90+
- Safari 14+

---

## Instalación en Servidor Linux (Ubuntu 22.04)

### Paso 1: Actualizar Sistema

```bash
sudo apt update
sudo apt upgrade -y
```

### Paso 2: Instalar Apache, PHP y MySQL

```bash
# Instalar Apache
sudo apt install apache2 -y

# Instalar PHP 8.3
sudo apt install software-properties-common -y
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install php8.3 php8.3-mysql php8.3-mbstring php8.3-json php8.3-curl -y

# Instalar MySQL
sudo apt install mysql-server -y

# Asegurar MySQL
sudo mysql_secure_installation
```

### Paso 3: Configurar Base de Datos

```bash
# Iniciar sesión en MySQL
sudo mysql -u root -p

# Crear base de datos
CREATE DATABASE bluecat_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Crear usuario
CREATE USER 'bluecat_user'@'localhost' IDENTIFIED BY 'TuPasswordSeguro123!';
GRANT ALL PRIVILEGES ON bluecat_erp.* TO 'bluecat_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Paso 4: Descargar e Instalar Blue-Cat ERP

```bash
# Crear directorio
sudo mkdir -p /var/www/bluecat
cd /var/www/bluecat

# Copiar archivos (ajustar ruta según ubicación)
sudo cp -r /ruta/a/Blue-Cat/* /var/www/bluecat/

# Establecer permisos
sudo chown -R www-data:www-data /var/www/bluecat
sudo chmod -R 755 /var/www/bluecat
sudo chmod -R 777 /var/www/bluecat/assets/uploads
```

### Paso 5: Configurar Variables de Entorno

```bash
# Copiar archivo de ejemplo
sudo cp /var/www/bluecat/.env.example /var/www/bluecat/.env

# Editar archivo
sudo nano /var/www/bluecat/.env
```

Configurar las siguientes variables:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=bluecat_erp
DB_USER=bluecat_user
DB_PASSWORD=TuPasswordSeguro123!

APP_NAME=Blue-Cat ERP
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.tudominio.com
APP_TIMEZONE=America/Santiago

APP_KEY=GeneraUnaClaveSeguraDe32Caracteres
SESSION_LIFETIME=120
BCRYPT_ROUNDS=12
```

Generar APP_KEY:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

### Paso 6: Importar Estructura de Base de Datos

```bash
cd /var/www/bluecat
php scripts/migrate.php

# Solo para una instalación de demostración:
php scripts/migrate.php --with-demo
```

Las migraciones canónicas están en database/migrations/; los datos ficticios están separados en database/demo/.

### Paso 7: Crear Usuario Administrador

```bash
# Crear script de instalación
sudo nano /var/www/bluecat/install_admin.php
```

Contenido:

```php
<?php
require_once 'assets/api/_db.php';

$conn = getDB();

// Crear usuario administrador
$username = 'admin';
$password = password_hash('Admin123!', PASSWORD_DEFAULT);
$email = 'admin@tudominio.com';

$stmt = $conn->prepare("INSERT INTO usuario (nombre, password, correo, activo) VALUES (?, ?, ?, 1)");
$stmt->bind_param("sss", $username, $password, $email);
$stmt->execute();
$user_id = $conn->insert_id;
$stmt->close();

// Asignar rol Administrador
$stmt = $conn->prepare("INSERT INTO usuario_rol (id_user, id_rol) SELECT ?, id_rol FROM rol WHERE nombre = 'Administrador'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->close();

echo "Usuario administrador creado exitosamente.\n";
echo "Usuario: admin\n";
echo "Contraseña: Admin123!\n";
echo "IMPORTANTE: Cambia la contraseña inmediatamente después del primer login.\n";
?>
```

Ejecutar:

```bash
cd /var/www/bluecat
php install_admin.php
sudo rm install_admin.php
```

### Paso 8: Configurar Apache

```bash
# Crear virtual host
sudo nano /etc/apache2/sites-available/bluecat.conf
```

Contenido:

```apache
<VirtualHost *:80>
    ServerName erp.tudominio.com
    DocumentRoot /var/www/bluecat
    
    <Directory /var/www/bluecat>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/bluecat_error.log
    CustomLog ${APACHE_LOG_DIR}/bluecat_access.log combined
</VirtualHost>
```

Habilitar sitio:

```bash
sudo a2ensite bluecat.conf
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Paso 9: Configurar SSL (HTTPS)

```bash
# Instalar Certbot
sudo apt install certbot python3-certbot-apache -y

# Obtener certificado
sudo certbot --apache -d erp.tudominio.com

# Renovación automática
sudo certbot renew --dry-run
```

### Paso 10: Configurar Backups Automáticos

```bash
# Crear script de backup
sudo nano /usr/local/bin/backup_bluecat.sh
```

Contenido:

```bash
#!/bin/bash
BACKUP_DIR="/opt/bluecat_backups"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="bluecat_erp"
DB_USER="bluecat_user"
DB_PASS="TuPasswordSeguro123!"

mkdir -p $BACKUP_DIR

# Backup de base de datos
mysqldump -u$DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Backup de archivos
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /var/www/bluecat/assets/uploads

# Eliminar backups antiguos (>30 días)
find $BACKUP_DIR -name "*.sql.gz" -mtime +30 -delete
find $BACKUP_DIR -name "*.tar.gz" -mtime +30 -delete

echo "Backup completado: $DATE"
```

Dar permisos y configurar cron:

```bash
sudo chmod +x /usr/local/bin/backup_bluecat.sh

# Agregar a cron (backup diario a las 2 AM)
(crontab -l 2>/dev/null; echo "0 2 * * * /usr/local/bin/backup_bluecat.sh") | crontab -
```

### Paso 11: Verificar Instalación

```bash
# Verificar que Apache esté corriendo
sudo systemctl status apache2

# Verificar que MySQL esté corriendo
sudo systemctl status mysql

# Verificar permisos
ls -la /var/www/bluecat

# Verificar logs
sudo tail -f /var/log/apache2/bluecat_error.log
```

### Paso 12: Acceder al Sistema

Abrir navegador y navegar a:

```
https://erp.tudominio.com
```

Credenciales:
- **Usuario:** admin
- **Contraseña:** Admin123!

**IMPORTANTE:** Cambiar la contraseña inmediatamente después del primer login.

---

## Instalación en Windows Server

### Paso 1: Instalar IIS, PHP y MySQL

1. **Instalar IIS:**
   - Abrir "Administrador del servidor"
   - Agregar roles y características
   - Seleccionar "Servidor web (IIS)"
   - Incluir CGI, ISAPI Extensions

2. **Instalar PHP:**
   - Descargar PHP 8.3 desde https://windows.php.net/download/
   - Extraer en `C:\php`
   - Agregar `C:\php` al PATH del sistema
   - Copiar `php.ini-production` a `php.ini`
   - Editar `php.ini` y habilitar extensiones:
     ```ini
     extension=mysqli
     extension=mbstring
     extension=openssl
     extension=curl
     ```

3. **Instalar MySQL:**
   - Descargar MySQL 8.0 desde https://dev.mysql.com/downloads/
   - Instalar y configurar
   - Crear base de datos y usuario (igual que en Linux)

### Paso 2: Configurar IIS

1. **Agregar handler para PHP:**
   - Abrir "Administrador de IIS"
   - Seleccionar el sitio
   - Abrir "Asignaciones de controlador"
   - Agregar asignación de script:
     - Solicitud: `*.php`
     - Ejecutable: `C:\php\php-cgi.exe`
     - Nombre: `PHP`

2. **Configurar documento predeterminado:**
   - Agregar `index.php` como documento predeterminado

### Paso 3: Copiar Archivos

```powershell
# Crear directorio
mkdir C:\inetpub\bluecat

# Copiar archivos
xcopy /E /I C:\ruta\a\Blue-Cat C:\inetpub\bluecat

# Establecer permisos
icacls C:\inetpub\bluecat /grant "IIS_IUSRS:(OI)(CI)M" /T
```

### Paso 4: Configurar Variables de Entorno

Crear archivo `C:\inetpub\bluecat\.env` con la configuración (igual que en Linux).

### Paso 5: Importar Base de Datos

Desde PowerShell, dentro del directorio de Blue-Cat, ejecutar php scripts/migrate.php.

### Paso 6: Crear Usuario Administrador

Ejecutar el script `install_admin.php` desde línea de comandos:

```powershell
cd C:\inetpub\bluecat
php install_admin.php
```

### Paso 7: Configurar SSL

Usar Certbot para Windows o comprar certificado SSL comercial.

---

## Instalación con Docker (Recomendada)

### Paso 1: Instalar Docker

**Linux:**
```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER
```

**Windows:**
Descargar Docker Desktop desde https://www.docker.com/products/docker-desktop

### Paso 2: Crear docker-compose.yml

```yaml
version: '3.8'

services:
  app:
    build: .
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./assets/uploads:/var/www/html/assets/uploads
      - ./.env:/var/www/html/.env
    depends_on:
      - mysql
    environment:
      - DB_HOST=mysql
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: rootpassword
      MYSQL_DATABASE: bluecat_erp
      MYSQL_USER: bluecat_user
      MYSQL_PASSWORD: TuPasswordSeguro123!
    volumes:
      - mysql_data:/var/lib/mysql
      - ./backups:/backups
    restart: unless-stopped

volumes:
  mysql_data:
```

### Paso 3: Crear Dockerfile

```dockerfile
FROM php:8.3-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN a2enmod rewrite

COPY . /var/www/html/
WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html
RUN chmod -R 777 /var/www/html/assets/uploads
```

### Paso 4: Desplegar

```bash
# Construir y levantar
docker-compose up -d

# Aplicar migraciones versionadas
docker-compose exec app php scripts/migrate.php

# Crear administrador
docker-compose exec app php install_admin.php
```

---

## Verificación Post-Instalación

### Checklist de Verificación

- [ ] Apache/IIS está corriendo
- [ ] MySQL está corriendo
- [ ] PHP está funcionando
- [ ] Base de datos creada
- [ ] Usuario administrador creado
- [ ] Variables de entorno configuradas
- [ ] Permisos de archivos correctos
- [ ] SSL configurado
- [ ] Backups configurados
- [ ] Sistema accesible desde navegador

### Pruebas Básicas

1. **Login:** Iniciar sesión con credenciales de administrador
2. **POS:** Abrir caja y realizar venta de prueba
3. **Inventario:** Crear producto de prueba
4. **Ventas:** Ver historial de ventas
5. **Reportes:** Generar reporte básico

---

## Solución de Problemas

### Error: "Database connection failed"

**Causa:** Credenciales de BD incorrectas o MySQL no está corriendo.

**Solución:**
```bash
# Verificar que MySQL esté corriendo
sudo systemctl status mysql

# Verificar credenciales en .env
cat /var/www/bluecat/.env | grep DB_

# Probar conexión
mysql -u bluecat_user -p bluecat_erp
```

### Error: "Sesión no válida"

**Causa:** Cookies no se están guardando o sesión expiró.

**Solución:**
- Limpiar cookies del navegador
- Verificar configuración de sesión en PHP
- Verificar permisos de directorio de sesiones

### Error: "Permiso denegado"

**Causa:** Permisos de archivos incorrectos.

**Solución:**
```bash
sudo chown -R www-data:www-data /var/www/bluecat
sudo chmod -R 755 /var/www/bluecat
sudo chmod -R 777 /var/www/bluecat/assets/uploads
```

### Error 500 Internal Server Error

**Causa:** Error de PHP o configuración incorrecta.

**Solución:**
```bash
# Ver logs de Apache
sudo tail -f /var/log/apache2/bluecat_error.log

# Verificar sintaxis PHP
php -l /var/www/bluecat/assets/api/_db.php

# Verificar que .env exista
ls -la /var/www/bluecat/.env
```

---

## Soporte

Para asistencia técnica:
- **Email:** soporte@bluecat.com
- **Teléfono:** +56 9 1234 5678
- **Documentación:** https://docs.bluecat.com

---

## Licencia

Blue-Cat ERP está licenciado bajo Licencia Apache 2.0

---

**Última actualización:** Julio 2026  
**Versión:** 1.0.0
