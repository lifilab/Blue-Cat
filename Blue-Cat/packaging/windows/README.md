# Blue-Cat Server para Windows

Este directorio contiene el contrato reproducible del instalador. Los binarios externos no se guardan en Git: `Fetch-Runtimes.ps1` descarga exactamente las versiones de `runtime-lock.json`, verifica sus hashes y prepara un directorio de runtime para la compilación.

## Estructura

- `runtime-lock.json`: versiones, procedencia, checksum y licencia.
- `templates/`: configuraciones que el bootstrap renderiza con rutas absolutas.
- `services/`: definiciones WinSW con dependencias y recuperación.
- `scripts/`: descarga, validación, bootstrap y construcción de Windows.
- `installer/`: fuente Inno Setup.

## Principios

1. Ninguna contraseña predeterminada ni secreto entra en el instalador.
2. `Program Files` contiene ejecutables; `ProgramData` contiene configuración y estado mutable.
3. Reparar no borra configuración, base, logs ni backups.
4. Desinstalar conserva los datos salvo una confirmación separada y explícita.
5. El artefacto final no se publica si falta un hash, aviso de licencia, SBOM o firma.

## Construcción

En Windows, con Inno Setup 6.7.3 instalado:

```powershell
.\scripts\Build-Installer.ps1
```

El comando descarga y verifica los runtimes si faltan, crea un staging limpio y produce en `output/` el EXE, `SHA256SUMS.txt`, un SBOM SPDX 2.3 y metadatos de construcción. Una compilación local puede quedar sin firma para pruebas aisladas; no es publicable.

El pipeline de release usa `-RequireSignature` y el certificado Authenticode protegido por GitHub. Si el certificado falta, está vencido o la firma no resulta válida, la publicación falla antes de crear el release.
