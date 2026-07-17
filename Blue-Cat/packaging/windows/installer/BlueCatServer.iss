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

[Icons]
Name: "{group}\Abrir Blue-Cat"; Filename: "https://localhost"; IconFilename: "{app}\app\assets\images\logo.png"
Name: "{group}\Diagnóstico Blue-Cat"; Filename: "https://localhost/public/diagnostico.html"

[Run]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\Install-Prerequisites.ps1"" -RuntimeRoot ""{app}\runtime"" -LockFile ""{app}\installer\runtime-lock.json"""; StatusMsg: "Instalando componentes de Microsoft..."; Flags: runhidden waituntilterminated
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\Initialize-BlueCatServer.ps1"" -AppRoot ""{app}\app"" -RuntimeRoot ""{app}\runtime"" -DataRoot ""{commonappdata}\Blue-Cat"" -InstallationConfig ""{tmp}\bluecat-installation.json"""; StatusMsg: "Configurando Blue-Cat Server..."; Flags: runhidden waituntilterminated
Filename: "https://localhost"; Description: "Abrir Blue-Cat Server"; Flags: postinstall shellexec skipifsilent nowait

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\Uninstall-BlueCatServices.ps1"" -RuntimeRoot ""{app}\runtime"" -DataRoot ""{commonappdata}\Blue-Cat"""; Flags: runhidden waituntilterminated; RunOnceId: "BlueCatServices"

[Code]
var
  CompanyPage, AdminPage, StorePage: TInputQueryWizardPage;

function JsonEscape(Value: String): String;
begin
  Result := Value;
  StringChangeEx(Result, '\', '\\', True);
  StringChangeEx(Result, '"', '\"', True);
  StringChangeEx(Result, #13#10, '\n', True);
end;

procedure InitializeWizard;
begin
  CompanyPage := CreateInputQueryPage(wpSelectDir, 'Datos del comercio', 'Empresa principal', 'Estos datos se usarán para crear la cuenta local.');
  CompanyPage.Add('Razón social:', False); CompanyPage.Add('Nombre comercial:', False); CompanyPage.Add('RUT:', False);
  CompanyPage.Add('Giro:', False); CompanyPage.Add('Dirección:', False); CompanyPage.Add('Ciudad:', False);

  AdminPage := CreateInputQueryPage(CompanyPage.ID, 'Administrador', 'Primera credencial', 'No existe una contraseña predeterminada. Elija una clave segura.');
  AdminPage.Add('Usuario:', False); AdminPage.Add('Nombre completo:', False); AdminPage.Add('Correo:', False); AdminPage.Add('Contraseña:', True);
  AdminPage.Values[0] := 'administrador';

  StorePage := CreateInputQueryPage(AdminPage.ID, 'Operación inicial', 'Sucursal, bodega y caja', 'Podrá modificar estos nombres después.');
  StorePage.Add('Sucursal:', False); StorePage.Add('Bodega:', False); StorePage.Add('Caja:', False);
  StorePage.Values[0] := 'Principal'; StorePage.Values[1] := 'Bodega Principal'; StorePage.Values[2] := 'Caja Principal';
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var P: String;
begin
  Result := True;
  if CurPageID = CompanyPage.ID then begin
    if (Trim(CompanyPage.Values[0]) = '') or (Trim(CompanyPage.Values[2]) = '') then begin MsgBox('Razón social y RUT son obligatorios.', mbError, MB_OK); Result := False; end;
  end;
  if CurPageID = AdminPage.ID then begin
    P := AdminPage.Values[3];
    if (Length(AdminPage.Values[0]) < 3) or (Pos('@', AdminPage.Values[2]) = 0) or (Length(P) < 10) or
       (P = Lowercase(P)) or (P = Uppercase(P)) then begin
      MsgBox('Ingrese usuario, correo y una contraseña de al menos 10 caracteres con mayúsculas y minúsculas.', mbError, MB_OK); Result := False;
    end;
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var Json: String;
begin
  if CurStep = ssInstall then begin
    Json := '{' +
      '"company":{"legal_name":"' + JsonEscape(CompanyPage.Values[0]) + '","trade_name":"' + JsonEscape(CompanyPage.Values[1]) + '","tax_id":"' + JsonEscape(CompanyPage.Values[2]) + '","business_activity":"' + JsonEscape(CompanyPage.Values[3]) + '","address":"' + JsonEscape(CompanyPage.Values[4]) + '","city":"' + JsonEscape(CompanyPage.Values[5]) + '"},' +
      '"administrator":{"username":"' + JsonEscape(AdminPage.Values[0]) + '","full_name":"' + JsonEscape(AdminPage.Values[1]) + '","email":"' + JsonEscape(AdminPage.Values[2]) + '","password":"' + JsonEscape(AdminPage.Values[3]) + '"},' +
      '"currency":{"code":"CLP","name":"Peso chileno","symbol":"$","decimals":0},' +
      '"tax":{"code":"IVA","name":"IVA","rate":19},' +
      '"branch":{"code":"SUC-001","name":"' + JsonEscape(StorePage.Values[0]) + '"},' +
      '"warehouse":{"code":"BOD-001","name":"' + JsonEscape(StorePage.Values[1]) + '"},' +
      '"cash_register":{"code":"CAJA-01","name":"' + JsonEscape(StorePage.Values[2]) + '"}' +
    '}';
    SaveStringToFile(ExpandConstant('{tmp}\bluecat-installation.json'), Json, False);
  end;
  if CurStep = ssDone then DeleteFile(ExpandConstant('{tmp}\bluecat-installation.json'));
end;
