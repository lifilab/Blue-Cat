#define AppName "Blue-Cat Server"
#define AppVersion "0.1.0-beta.1"
#define Publisher "Lifilab"

[Setup]
AppId={{8D39F3C4-312D-4F28-A458-B810DC62D9C1}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#Publisher}
DefaultDirName={autopf}\Blue-Cat
DefaultGroupName=Blue-Cat
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=admin
OutputDir=..\output
OutputBaseFilename=BlueCat-Server-Setup
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
WizardSizePercent=110,110
UninstallDisplayIcon={app}\app\assets\images\logo.png
CloseApplications=yes
RestartApplications=no
RestartIfNeededByRun=yes
SetupLogging=yes
#ifdef SIGNTOOL_NAME
SignTool={#SIGNTOOL_NAME}
SignedUninstaller=yes
#endif

[Files]
Source: "..\build\stage\app\*"; DestDir: "{app}\app"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\build\stage\runtime\*"; DestDir: "{app}\runtime"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\build\stage\installer\*"; DestDir: "{app}\installer"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\build\stage\artifact-manifest.json"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{commonappdata}\Blue-Cat"; Permissions: admins-full system-full
Name: "{commonappdata}\Blue-Cat\config"; Permissions: admins-full system-full

[Icons]
Name: "{group}\Abrir Blue-Cat ERP"; Filename: "http://localhost/Blue-Cat/"; IconFilename: "{app}\app\assets\images\logo.png"
Name: "{commondesktop}\Blue-Cat ERP"; Filename: "http://localhost/Blue-Cat/"; IconFilename: "{app}\app\assets\images\logo.png"
Name: "{group}\Diagnóstico Blue-Cat"; Filename: "http://localhost/Blue-Cat/diagnostico.html"

[Run]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\Install-Prerequisites.ps1"" -RuntimeRoot ""{app}\runtime"" -LockFile ""{app}\installer\runtime-lock.json"""; StatusMsg: "Instalando componentes de Microsoft..."; Flags: runhidden waituntilterminated
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\Initialize-BlueCatServer.ps1"" -AppRoot ""{app}\app"" -RuntimeRoot ""{app}\runtime"" -DataRoot ""{commonappdata}\Blue-Cat"" -InstallationConfig ""{tmp}\bluecat-installation.json"""; StatusMsg: "Configurando Blue-Cat Server..."; Flags: runhidden waituntilterminated
Filename: "http://localhost/Blue-Cat/"; Description: "Abrir Aplicación Web Blue-Cat ERP"; Flags: postinstall shellexec skipifsilent nowait

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\Uninstall-BlueCatServices.ps1"" -RuntimeRoot ""{app}\runtime"" -DataRoot ""{commonappdata}\Blue-Cat"""; Flags: runhidden waituntilterminated; RunOnceId: "BlueCatServices"

[Code]
var
  CompanyPage1, CompanyPage2, LicensePage, AdminPage, StorePage: TInputQueryWizardPage;

function JsonEscape(Value: String): String;
begin
  Result := Value;
  StringChangeEx(Result, '\', '\\', True);
  StringChangeEx(Result, '"', '\"', True);
  StringChangeEx(Result, #13#10, '\n', True);
end;

procedure InitializeWizard;
begin
  // Página 1: Datos de la Empresa (3 campos compactos)
  CompanyPage1 := CreateInputQueryPage(wpSelectDir, 'Datos del Comercio (1/2)', 'Identificación de la Empresa', 'Ingrese la información principal de su negocio.');
  CompanyPage1.Add('Razón social (Obligatorio):', False);
  CompanyPage1.Add('Nombre comercial:', False);
  CompanyPage1.Add('RUT / NIT (Obligatorio):', False);

  // Página 2: Ubicación y Giro (3 campos compactos)
  CompanyPage2 := CreateInputQueryPage(CompanyPage1.ID, 'Datos del Comercio (2/2)', 'Ubicación y Rubro', 'Ingrese la dirección y rubro de su empresa.');
  CompanyPage2.Add('Giro / Rubro:', False);
  CompanyPage2.Add('Dirección Comercial:', False);
  CompanyPage2.Add('Ciudad:', False);

  // Página 3: Licencia Comercial y Conexión al Servidor
  LicensePage := CreateInputQueryPage(CompanyPage2.ID, 'Licencia Comercial de 1 Uso', 'Validación y Servidor de Licencias', 'Ingrese su correo registrado y la clave de licencia asignada para activar el producto.');
  LicensePage.Add('Correo Electrónico Verificador:', False);
  LicensePage.Add('Clave de Licencia (1 Uso):', False);
  LicensePage.Add('URL del Servidor de Licencias:', False);
  LicensePage.Values[2] := 'http://localhost:3050';

  // Página 4: Credenciales del Administrador
  AdminPage := CreateInputQueryPage(LicensePage.ID, 'Administrador del Sistema', 'Primera credencial de acceso', 'Elija un usuario y contraseña segura para su inicio de sesión.');
  AdminPage.Add('Usuario Administrador:', False);
  AdminPage.Add('Nombre Completo:', False);
  AdminPage.Add('Correo de Acceso:', False);
  AdminPage.Add('Contraseña (min. 10 caract.):', True);
  AdminPage.Values[0] := 'administrador';

  // Página 5: Operación Inicial
  StorePage := CreateInputQueryPage(AdminPage.ID, 'Operación Inicial', 'Sucursal, bodega y caja principal', 'Nombres iniciales para la primera sucursal.');
  StorePage.Add('Sucursal:', False); StorePage.Add('Bodega:', False); StorePage.Add('Caja:', False);
  StorePage.Values[0] := 'Principal'; StorePage.Values[1] := 'Bodega Principal'; StorePage.Values[2] := 'Caja Principal';
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var P: String;
begin
  Result := True;
  if CurPageID = CompanyPage1.ID then begin
    if (Trim(CompanyPage1.Values[0]) = '') or (Trim(CompanyPage1.Values[2]) = '') then begin
      MsgBox('Razón social y RUT son obligatorios.', mbError, MB_OK);
      Result := False;
    end;
  end;

  if CurPageID = LicensePage.ID then begin
    if (Trim(LicensePage.Values[0]) = '') or (Trim(LicensePage.Values[1]) = '') then begin
      MsgBox('El correo electrónico y la clave de licencia son obligatorios para continuar.', mbError, MB_OK);
      Result := False;
    end;
    if (Trim(LicensePage.Values[2]) = '') then begin
      LicensePage.Values[2] := 'http://localhost:3050';
    end;
  end;

  if CurPageID = AdminPage.ID then begin
    P := AdminPage.Values[3];
    if (Length(AdminPage.Values[0]) < 3) or (Pos('@', AdminPage.Values[2]) = 0) or (Length(P) < 10) then begin
      MsgBox('Ingrese usuario, correo válido y una contraseña de al menos 10 caracteres.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var Json, LicenseJson, ConfigDir, AppConfigDir: String;
begin
  if CurStep = ssInstall then begin
    Json := '{' +
      '"company":{"legal_name":"' + JsonEscape(CompanyPage1.Values[0]) + '","trade_name":"' + JsonEscape(CompanyPage1.Values[1]) + '","tax_id":"' + JsonEscape(CompanyPage1.Values[2]) + '","business_activity":"' + JsonEscape(CompanyPage2.Values[0]) + '","address":"' + JsonEscape(CompanyPage2.Values[1]) + '","city":"' + JsonEscape(CompanyPage2.Values[2]) + '"},' +
      '"administrator":{"username":"' + JsonEscape(AdminPage.Values[0]) + '","full_name":"' + JsonEscape(AdminPage.Values[1]) + '","email":"' + JsonEscape(AdminPage.Values[2]) + '","password":"' + JsonEscape(AdminPage.Values[3]) + '"},' +
      '"currency":{"code":"CLP","name":"Peso chileno","symbol":"$","decimals":0},' +
      '"tax":{"code":"IVA","name":"IVA","rate":19},' +
      '"branch":{"code":"SUC-001","name":"' + JsonEscape(StorePage.Values[0]) + '"},' +
      '"warehouse":{"code":"BOD-001","name":"' + JsonEscape(StorePage.Values[1]) + '"},' +
      '"cash_register":{"code":"CAJA-01","name":"' + JsonEscape(StorePage.Values[2]) + '"}' +
    '}';
    SaveStringToFile(ExpandConstant('{tmp}\bluecat-installation.json'), Json, False);

    // Generar archivo license_config.json con los datos ingresados en el instalador
    LicenseJson := '{' +
      '"server_url":"' + JsonEscape(LicensePage.Values[2]) + '",' +
      '"email":"' + JsonEscape(LicensePage.Values[0]) + '",' +
      '"license_key":"' + JsonEscape(LicensePage.Values[1]) + '",' +
      '"customer_name":"' + JsonEscape(CompanyPage1.Values[0]) + '"' +
    '}';
    
    ConfigDir := ExpandConstant('{commonappdata}\Blue-Cat\config');
    CreateDir(ConfigDir);
    SaveStringToFile(ConfigDir + '\license_config.json', LicenseJson, False);

    AppConfigDir := ExpandConstant('{app}\app\config');
    CreateDir(AppConfigDir);
    SaveStringToFile(AppConfigDir + '\license_config.json', LicenseJson, False);
  end;
  if CurStep = ssDone then DeleteFile(ExpandConstant('{tmp}\bluecat-installation.json'));
end;
