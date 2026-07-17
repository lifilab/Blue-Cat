# ADR-0006: runtime e instalador de Blue-Cat Server para Windows

- Estado: Aceptado para prototipo Beta
- Fecha: 2026-07-17
- Responsable: Pablo-Millones

## Contexto

Blue-Cat Server debe instalarse en Windows 10/11 x64 sin Laragon, iniciar automáticamente, funcionar sin Internet y permitir que varios POS usen la misma API por HTTPS. El runtime debe poder actualizarse por componente, registrar fallos y mantener la base de datos fuera del alcance de la red.

## Decisión

El paquete Beta usará componentes oficiales y versiones fijadas en `packaging/windows/runtime-lock.json`:

- **Caddy 2.11.4** como servidor HTTPS y proxy FastCGI. Su CA interna permite emitir certificados LAN; la confianza de otros dispositivos se resuelve en la Fase 5.
- **PHP 8.3.32 NTS x64** ejecutado como FastCGI únicamente en `127.0.0.1:9074`.
- **MariaDB 11.4.12 LTS x64** en formato ZIP, escuchando únicamente en `127.0.0.1:3307`.
- **WinSW 2.12.0 x64** para registrar tres servicios: `BlueCatDatabase`, `BlueCatPhp` y `BlueCatWeb`.
- **Microsoft Visual C++ Redistributable 14.44.35211 x64** como prerrequisito verificado de PHP, instalado silenciosamente antes de crear los servicios.
- **Inno Setup 6.7.3** como herramienta de construcción del instalador, no como componente instalado.

Los servicios arrancan en ese orden. WinSW aplica reinicio ante fallos y conserva logs separados. La aplicación nunca contiene la contraseña administrativa de MariaDB: el bootstrap genera credenciales aleatorias para un usuario limitado a la base `bluecat` y las guarda en `%ProgramData%\Blue-Cat\config\.env` con ACL restringida.

## Layout

| Ruta | Contenido | Política |
|---|---|---|
| `%ProgramFiles%\Blue-Cat\app` | código versionado | solo lectura durante operación |
| `%ProgramFiles%\Blue-Cat\runtime` | Caddy, PHP, MariaDB y WinSW | reemplazable por actualización firmada |
| `%ProgramData%\Blue-Cat\config` | `.env`, Caddyfile, php.ini y mariadb.ini | secreto, ACL de administrador/servicios |
| `%ProgramData%\Blue-Cat\data` | datadir de MariaDB y estado de Caddy | nunca se elimina en una actualización |
| `%ProgramData%\Blue-Cat\logs` | logs por componente | rotación y diagnóstico |
| `%ProgramData%\Blue-Cat\backups` | respaldos previos a migración | retención configurable |
| `%ProgramData%\Blue-Cat\install` | estado y manifiestos de instalación | sin contraseñas en texto de diagnóstico |

## Seguridad y operación

- Caddy replica los bloqueos que Apache aplicaba mediante `.htaccess`.
- El puerto de MariaDB no se abre en Windows Firewall.
- Solo HTTPS se abre en el perfil de red privado.
- El servicio PHP no escucha fuera de loopback.
- Instalación, reparación y actualización son idempotentes y nunca sustituyen `data`, `config` o `backups` sin respaldo.
- Los binarios se verifican antes de extraerse mediante SHA-256 o SHA-512 fijado.
- El instalador final se firma con Authenticode; la clave privada no reside en Git ni dentro del paquete.

## Licencias y procedencia

Caddy usa Apache-2.0, PHP usa PHP License 3.01, WinSW usa MIT, MariaDB Community Server usa GPLv2 y el runtime de Microsoft se redistribuye bajo sus términos oficiales. La distribución incluirá sus avisos, textos de licencia y una oferta/copia de código fuente correspondiente cuando sea jurídicamente requerida. Antes de vender el instalador se exige revisión legal de las obligaciones de MariaDB; este ADR no sustituye asesoría legal.

La licencia base de Inno Setup permite producir instaladores comerciales, pero su proveedor solicita que los usuarios comerciales adquieran una licencia cuando el instalador esté listo para producción. Blue-Cat registra esa compra como requisito previo a la primera distribución pagada, aunque la clave no necesita almacenarse en CI.

Fuentes oficiales: [Caddy releases](https://github.com/caddyserver/caddy/releases), [PHP para Windows](https://windows.php.net/download/), [MariaDB 11.4](https://mariadb.com/kb/en/changes-improvements-in-mariadb-11-4/), [WinSW](https://github.com/winsw/winsw), [Inno Setup](https://jrsoftware.org/isinfo.php).

## Consecuencias

- Existen tres servicios visibles en vez de un proceso opaco; esto mejora recuperación y diagnóstico.
- Caddy administra certificados, pero la instalación de confianza en otros POS pertenece al flujo de emparejamiento.
- PHP NTS requiere un gestor FastCGI y el Microsoft Visual C++ Redistributable compatible.
- MariaDB GPLv2 obliga a mantener trazabilidad del binario y sus fuentes correspondientes.
