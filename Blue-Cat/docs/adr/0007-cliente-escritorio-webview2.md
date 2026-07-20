# ADR-0007: cliente de escritorio Windows con WebView2

- Estado: Aceptado para Beta
- Fecha: 2026-07-17
- Responsable: Pablo-Millones

## Contexto

Blue-Cat Server debe seguir siendo una aplicación web accesible desde varios POS, pero el equipo servidor necesita una experiencia de escritorio sin pestañas, barra de direcciones ni dependencia visible del navegador. El cliente debe abrir el servidor solo cuando base de datos, PHP y HTTPS estén disponibles, ofrecer modo kiosco y dejar una ruta técnica para impresión y periféricos.

## Decisión

Se distribuye `BlueCatDesktop.exe`, una ventana WPF ligera que hospeda Microsoft Edge WebView2. El SDK y el runtime Evergreen x64 están fijados por versión, URL y SHA-256 en `packaging/windows/runtime-lock.json`; el instalador incluye el runtime completo para funcionar sin Internet.

El launcher:

- comprueba e intenta iniciar `BlueCatDatabase`, `BlueCatPhp` y `BlueCatWeb` en orden;
- espera el health check antes de mostrar la interfaz;
- muestra un error comprensible y permite reintentar en lugar de quedar en blanco;
- mantiene `https://localhost` fuera de la interfaz visible;
- abre enlaces externos en el navegador del sistema y conserva rutas internas en la ventana;
- inicia maximizado, recuerda tamaño y admite F11/Escape;
- ofrece un acceso separado `--pos --fullscreen` para modo kiosco POS;
- deshabilita DevTools y reduce menús del navegador en el POS.

El cierre de la ventana no detiene los servicios: otros terminales LAN pueden estar vendiendo. El POS continúa siendo local-first respecto de Internet, pero no funciona si pierde conexión con Blue-Cat Server; el modo POS totalmente desconectado permanece fuera de la primera Beta.

## Alternativas

- **Electron:** buen ecosistema de periféricos, pero duplica Chromium y eleva memoria, tamaño y superficie de actualización.
- **Tauri:** liviano, pero agrega Rust, otro pipeline y una integración Windows más compleja para el estado actual del producto.
- **PWA/Edge app mode:** simple, pero ofrece menos control sobre arranque, errores, enlaces, servicios y comportamiento kiosco.

WebView2 equilibra consumo, soporte de Windows 10/11 y mantenimiento. Lectores de código de barras que emulan teclado funcionan sin integración adicional. Impresoras térmicas, gavetas, balanzas e impresoras fiscales se incorporarán mediante un puente local firmado y permisos explícitos; no se habilita acceso genérico del contenido web al sistema operativo.

## Seguridad y actualización

El contenido cargado se limita al origen HTTPS local. DevTools se deshabilita en producción y ningún secreto se pasa por argumentos ni URL. El ejecutable, sus DLL y el runtime se actualizan exclusivamente mediante el instalador firmado. WebView2 conserva cookies y preferencias por usuario en `%LocalAppData%\Blue-Cat`.
