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
SetupIconFile=..\build\desktop\BlueCat.ico
UninstallDisplayIcon={app}\desktop\BlueCatDesktop.exe
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
Source: "..\build\stage\desktop\*"; DestDir: "{app}\desktop"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\build\stage\prerequisites\*"; DestDir: "{app}\prerequisites"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\build\stage\installer\*"; DestDir: "{app}\installer"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\build\stage\artifact-manifest.json"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{commonappdata}\Blue-Cat"; Permissions: admins-full system-full

[Icons]
Name: "{autodesktop}\Blue-Cat"; Filename: "{app}\desktop\BlueCatDesktop.exe"; WorkingDir: "{app}\desktop"; IconFilename: "{app}\desktop\BlueCatDesktop.exe"
Name: "{group}\Blue-Cat"; Filename: "{app}\desktop\BlueCatDesktop.exe"; WorkingDir: "{app}\desktop"
Name: "{group}\Blue-Cat POS (pantalla completa)"; Filename: "{app}\desktop\BlueCatDesktop.exe"; Parameters: "--pos --fullscreen"; WorkingDir: "{app}\desktop"
Name: "{group}\Diagnóstico Blue-Cat"; Filename: "{app}\desktop\BlueCatDesktop.exe"; Parameters: "--diagnostic"; WorkingDir: "{app}\desktop"
Name: "{group}\Desinstalar Blue-Cat"; Filename: "{uninstallexe}"

[Run]
Filename: "{app}\desktop\BlueCatDesktop.exe"; Description: "Abrir Blue-Cat"; Flags: postinstall skipifsilent nowait

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\scripts\Uninstall-BlueCatServices.ps1"" -RuntimeRoot ""{app}\runtime"" -DataRoot ""{commonappdata}\Blue-Cat"""; Flags: runhidden waituntilterminated; RunOnceId: "BlueCatServices"

[Code]
var
  CompanyPage, AdminPage, StorePage: TInputQueryWizardPage;
  ExistingInstall: Boolean;
  RestartRequired: Boolean;

function JsonEscape(Value: String): String;
begin
  Result := Value;
  StringChangeEx(Result, '\', '\\', True);
  StringChangeEx(Result, '"', '\"', True);
  StringChangeEx(Result, #13#10, '\n', True);
end;

procedure InitializeWizard;
begin
  ExistingInstall := FileExists(ExpandConstant('{commonappdata}\Blue-Cat\install\state.json'));
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

function ShouldSkipPage(PageID: Integer): Boolean;
begin
  Result := ExistingInstall and ((PageID = CompanyPage.ID) or (PageID = AdminPage.ID) or (PageID = StorePage.ID));
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

function PrepareToInstall(var NeedsRestart: Boolean): String;
var StopScript, Parameters: String; ResultCode: Integer;
begin
  Result := '';
  StopScript := ExpandConstant('{app}\installer\scripts\Stop-BlueCatServices.ps1');
  if FileExists(StopScript) then
    Parameters := '-NoProfile -ExecutionPolicy Bypass -File "' + StopScript + '"'
  else begin
    StopScript := ExpandConstant('{app}\installer\scripts\Uninstall-BlueCatServices.ps1');
    Parameters := '-NoProfile -ExecutionPolicy Bypass -File "' + StopScript + '"' +
      ' -RuntimeRoot "' + ExpandConstant('{app}\runtime') + '"' +
      ' -DataRoot "' + ExpandConstant('{commonappdata}\Blue-Cat') + '"';
  end;
  if FileExists(StopScript) then begin
    if (not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), Parameters, '', SW_HIDE,
      ewWaitUntilTerminated, ResultCode)) or (ResultCode <> 0) then
      Result := 'No fue posible detener los servicios Blue-Cat para actualizar. Cierre Blue-Cat y vuelva a intentarlo.';
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var Json, Parameters: String; ResultCode: Integer;
begin
  if CurStep = ssInstall then begin
    if ExistingInstall then Json := '{}'
    else Json := '{' +
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
  if CurStep = ssPostInstall then begin
    WizardForm.StatusLabel.Caption := 'Configurando y verificando Blue-Cat Server...';
    Parameters := '-NoProfile -ExecutionPolicy Bypass -File "' +
      ExpandConstant('{app}\installer\scripts\Invoke-BlueCatInstallation.ps1') + '"' +
      ' -AppRoot "' + ExpandConstant('{app}\app') + '"' +
      ' -RuntimeRoot "' + ExpandConstant('{app}\runtime') + '"' +
      ' -DataRoot "' + ExpandConstant('{commonappdata}\Blue-Cat') + '"' +
      ' -InstallationConfig "' + ExpandConstant('{tmp}\bluecat-installation.json') + '"' +
      ' -PrerequisiteRoot "' + ExpandConstant('{app}\prerequisites') + '"' +
      ' -LockFile "' + ExpandConstant('{app}\installer\runtime-lock.json') + '"';
    if not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), Parameters, '', SW_HIDE,
      ewWaitUntilTerminated, ResultCode) then
      RaiseException('No fue posible iniciar la configuración de Blue-Cat.');
    if (ResultCode <> 0) and (ResultCode <> 3010) then
      RaiseException('Blue-Cat no pudo configurarse. Revise C:\ProgramData\Blue-Cat\logs\installer.log y ejecute Reparar.');
    RestartRequired := ResultCode = 3010;
  end;
  if CurStep = ssDone then DeleteFile(ExpandConstant('{tmp}\bluecat-installation.json'));
end;

function NeedRestart(): Boolean;
begin
  Result := RestartRequired;
end;
