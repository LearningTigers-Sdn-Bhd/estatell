; Builds estatell-mykad-setup.exe with Inno Setup 6.
; Build PyInstaller output first, then:
;   iscc /DAppVersion=1.1.0 packaging\windows\installer.iss
; Installs per machine and starts the bridge at every user's login (HKLM Run key).

#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif

[Setup]
AppId={{6F0B3C2E-6E8A-4C5B-9A51-2E7C0D4B8F13}
AppName=Estatell MyKad Bridge
AppVersion={#AppVersion}
AppPublisher=Estatell
AppPublisherURL=https://app.estatell.com
DefaultDirName={autopf}\Estatell MyKad Bridge
DisableDirPage=yes
DisableProgramGroupPage=yes
DisableWelcomePage=yes
DisableReadyPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir=..\..\dist
OutputBaseFilename=estatell-mykad-setup
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
UninstallDisplayName=Estatell MyKad Bridge
CloseApplications=no

[Files]
Source: "..\..\build\pyinstaller-dist\estatell-mykad-bridge\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Registry]
Root: HKLM; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "EstatellMyKadBridge"; ValueData: """{app}\estatell-mykad-bridge.exe"""; Flags: uninsdeletevalue

[Run]
; Start now for the person installing, so no restart is needed.
Filename: "{app}\estatell-mykad-bridge.exe"; Flags: nowait runasoriginaluser

[UninstallRun]
Filename: "{sys}\taskkill.exe"; Parameters: "/F /IM estatell-mykad-bridge.exe"; Flags: runhidden; RunOnceId: "StopBridge"

[Code]
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  ResultCode: Integer;
begin
  { Stop an older bridge so its files can be replaced. }
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/F /IM estatell-mykad-bridge.exe', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Result := '';
end;
