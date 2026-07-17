# Inventario de terceros del instalador Windows

| Componente | Uso | Licencia/estado |
|---|---|---|
| Caddy 2.11.4 | HTTPS y FastCGI | Apache-2.0; incluir `LICENSE` |
| PHP 8.3.32 NTS x64 | Runtime servidor | PHP License 3.01; incluir `license.txt` y avisos del paquete oficial |
| MariaDB Community Server 11.4.12 | Base local | GPL-2.0-only; incluir `COPYING` y cumplir acceso/oferta de código fuente aplicable |
| WinSW 2.12.0 x64 | Servicios Windows | MIT; incluir licencia y aviso de copyright |
| Microsoft Visual C++ Redistributable 14.44.35211 x64 | Prerrequisito de PHP | Términos de redistribución de Microsoft Visual Studio 2022 |
| Microsoft WebView2 SDK 1.0.4078.44 | API del cliente de escritorio | Microsoft Software License Terms; licencia y avisos incluidos junto al launcher |
| Microsoft Edge WebView2 Runtime 150.0.4078.65 x64 | Motor web del cliente de escritorio | Términos de Microsoft Edge WebView2; instalador oficial firmado y verificado |
| Inno Setup 6.7.3 | Herramienta de construcción; no se instala | Inno Setup License; el proveedor solicita adquirir licencia comercial antes de uso productivo/venta |
| Font Awesome | Iconos web | Confirmar versión y licencia de los recursos distribuidos |
| Navegador/PWA | Cliente | No se redistribuye navegador en la primera Beta |
| GitHub Actions | CI/release | Solo desarrollo y publicación |

Las versiones, fuentes, checksums y enlaces de licencia de los binarios están fijados en `packaging/windows/runtime-lock.json`. Cada release genera además un SBOM SPDX y conserva los textos de licencia incluidos por los proveedores. Antes de vender el producto se mantiene obligatoria una revisión legal específica de MariaDB, Microsoft y los recursos web; el SBOM no reemplaza esa revisión.
