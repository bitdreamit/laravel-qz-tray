@echo off
setlocal EnableExtensions EnableDelayedExpansion
title QZ Tray - Trust Domain Installer

rem ===========================================================================
rem  trust-domain.bat  --  QZ Tray zero-prompt trust installer (standalone)
rem  Companion of bitdreamit/laravel-qz-tray - but needs NO Laravel, NO PHP.
rem
rem  HOW THE VENDOR PREPARES THIS FILE (one time, before distributing):
rem    1. Open this file in Notepad.
rem    2. Paste your ROOT CA certificate (X.509 PEM, the block that starts
rem       with -----BEGIN CERTIFICATE-----) between the CA-PEM-START and
rem       CA-PEM-END markers at the bottom of this file.
rem       Several certificates (a chain) may be pasted one after another.
rem    3. Set LNA_DOMAINS below to your site domain(s), comma separated.
rem    4. Save the file (plain text) and send it to your customers.
rem
rem  WHAT THE CUSTOMER DOES: double-click, accept the UAC prompt, done.
rem    Alternative: drag any .pem or .crt file onto this .bat - the dropped
rem    file is then used as the CA and this file does not need editing.
rem
rem  WHAT IT CHANGES ON THE CUSTOMER MACHINE (all reversible):
rem    - ProgramFiles\QZ Tray\override.crt   your CA (backup: override.crt.bak)
rem    - qz-tray.properties                  authcert.override line added
rem    - Windows Root store                  QZ Tray localhost root via certutil
rem    - HKLM Policies Google Chrome + Microsoft Edge
rem        LocalNetworkAccessAllowedForUrls  = your domains
rem    - restarts QZ Tray
rem
rem  RESULT: printing from YOUR domains shows no Allow dialog and no
rem  Chrome/Edge local-network prompt. Other websites still prompt normally.
rem ===========================================================================

rem --- VENDOR CONFIG ---------------------------------------------------------
rem Site domains for the Chrome/Edge local-network policy (no prompt):
rem Two domains example:  set "LNA_DOMAINS=*.shop.example.com,*.erp.example.net"
set "LNA_DOMAINS=*.example.com"
rem ---------------------------------------------------------------------------

fltmc >nul 2>&1
if errorlevel 1 (
  echo Requesting administrator rights - please accept the UAC prompt.
  if "%~1"=="" (
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  ) else (
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -ArgumentList '\"%~1\"' -Verb RunAs"
  )
  exit /b
)

echo.
echo ==============================================================
echo  QZ Tray - Trust Domain Installer
echo ==============================================================
echo.

rem --- resolve the certificate source ----------------------------------------
set "PEMSRC="
if not "%~1"=="" (
  if exist "%~1" (
    set "PEMSRC=%~1"
  ) else (
    echo ERROR: dropped file not found: "%~1"
    echo.
    pause
    exit /b 1
  )
)

set "PEMTMP=%TEMP%\qz-trust-domain-ca.pem"

if "%PEMSRC%"=="" (
  echo Reading the CA certificate embedded in this file...
  set "INBLK=0"
  (for /f "usebackq delims=" %%L in ("%~f0") do (
    set "L=%%L"
    if "!L:~0,5!"=="-----" (
      if /i not "!L:BEGIN=!"=="!L!" (
        set "INBLK=1"
        echo(!L!
      ) else (
        if /i not "!L:END=!"=="!L!" (
          echo(!L!
          set "INBLK=0"
        )
      )
    ) else (
      if "!INBLK!"=="1" echo(!L!
    )
  )) > "%PEMTMP%"
  set "PEMSRC=%PEMTMP%"
)

findstr /i /c:"-----BEGIN" "%PEMSRC%" >nul 2>&1
if errorlevel 1 (
  echo.
  echo ERROR: no X.509 PEM certificate found.
  if "%~1"=="" (
    echo   Open this .bat in Notepad and paste your CA certificate PEM
    echo   between the CA-PEM-START and CA-PEM-END markers at the bottom
    echo   of this file, save it, then run it again.
  ) else (
    echo   The dropped file does not contain a PEM certificate.
  )
  echo.
  pause
  exit /b 1
)

set "NCERT=0"
for /f %%C in ('findstr /i /l /c:"-----BEGIN" "%PEMSRC%" ^| find /c /v ""') do set "NCERT=%%C"
echo Certificate blocks found: %NCERT%

rem --- locate QZ Tray --------------------------------------------------------
set "QZDIR=%ProgramFiles%\QZ Tray"
if exist "%QZDIR%\qz-tray.exe" goto qz_found
if exist "%QZDIR%\qz-tray.jar" goto qz_found
set "QZDIR=%ProgramFiles(x86)%\QZ Tray"
if exist "%QZDIR%\qz-tray.exe" goto qz_found
if exist "%QZDIR%\qz-tray.jar" goto qz_found
for /f "tokens=2*" %%A in ('reg query "HKLM\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\QZ Tray" /v InstallLocation 2^>nul ^| findstr /i "InstallLocation"') do set "QZDIR=%%B"
if exist "%QZDIR%\qz-tray.exe" goto qz_found
if exist "%QZDIR%\qz-tray.jar" goto qz_found
echo.
echo ERROR: QZ Tray was not found on this computer.
echo   Install it first: https://qz.io/download
echo.
pause
exit /b 1
:qz_found
echo QZ Tray found: %QZDIR%

rem --- stop the tray so override.crt is not locked ---------------------------
echo Stopping QZ Tray if it is running...
taskkill /f /im qz-tray.exe >nul 2>&1
timeout /t 2 /nobreak >nul 2>&1

rem --- install override.crt --------------------------------------------------
if exist "%QZDIR%\override.crt" (
  copy /y "%QZDIR%\override.crt" "%QZDIR%\override.crt.bak" >nul 2>&1
  echo Backup created: override.crt.bak
)
copy /y "%PEMSRC%" "%QZDIR%\override.crt" >nul 2>&1
if errorlevel 1 (
  echo.
  echo ERROR: could not write "%QZDIR%\override.crt".
  echo   Run this file as administrator and try again.
  echo.
  pause
  exit /b 1
)
echo Installed: "%QZDIR%\override.crt"

rem --- belt and suspenders: authcert.override in qz-tray.properties ----------
set "PROPS=%QZDIR%\qz-tray.properties"
findstr /i /c:"authcert.override" "%PROPS%" >nul 2>&1
if errorlevel 1 (
  if exist "%PROPS%" >>"%PROPS%" echo(
  >>"%PROPS%" echo authcert.override=%QZDIR:\=/%/override.crt
  echo qz-tray.properties: authcert.override added.
) else (
  echo qz-tray.properties: authcert.override already present - skipped.
)

rem --- transport layer: trust the QZ localhost root in Windows ---------------
set "QZROOT=%ProgramData%\qz\root-ca.crt"
if not exist "%QZROOT%" set "QZROOT=%APPDATA%\qz\root-ca.crt"
if exist "%QZROOT%" (
  certutil -addstore -f Root "%QZROOT%" >nul 2>&1
  if errorlevel 1 (
    echo WARN: could not import the QZ localhost root - browser warnings about wss localhost may remain.
  ) else (
    echo Trusted QZ localhost root in the Windows Root store - no more wss warnings.
  )
) else (
  echo SKIP: QZ localhost root not found yet - start QZ Tray once, then re-run this file to also silence wss warnings.
)

rem --- Chrome and Edge local network access policy ---------------------------
if "%LNA_DOMAINS%"=="" goto lna_done
set "LNA_JSON=[\"%LNA_DOMAINS:,=\",\"%\"]"
reg add "HKLM\SOFTWARE\Policies\Google\Chrome" /v LocalNetworkAccessAllowedForUrls /t REG_SZ /d "%LNA_JSON%" /f >nul 2>&1
reg add "HKLM\SOFTWARE\Policies\Microsoft\Edge" /v LocalNetworkAccessAllowedForUrls /t REG_SZ /d "%LNA_JSON%" /f >nul 2>&1
echo Chrome/Edge local-network policy set for: %LNA_DOMAINS%
:lna_done

rem --- restart QZ Tray -------------------------------------------------------
echo.
echo Restarting QZ Tray...
if exist "%QZDIR%\qz-tray.exe" (
  start "" "%QZDIR%\qz-tray.exe"
  echo QZ Tray restarted.
) else (
  echo Please start QZ Tray manually from the Start Menu.
)

echo.
echo ==============================================================
echo  DONE - your domains are now trusted by QZ Tray.
echo  Open your site and print a test page - no Allow dialog and
echo  no local-network prompt should appear.
echo.
echo  Rollback: restore "%QZDIR%\override.crt.bak"
echo  Re-run this file after every QZ Tray upgrade if prompts return.
echo ==============================================================
echo.
pause
exit /b 0

rem ===========================================================================
rem  VENDOR: paste your ROOT CA certificate PEM below, between the two
rem  CA-PEM marker lines. Keep the BEGIN and END lines of the certificate.
rem  A certificate looks like this:
rem      -----BEGIN CERTIFICATE-----
rem      MIIFazCCA1OgAwIBAgIRAK ... your base64 data ...
rem      -----END CERTIFICATE-----
rem ===========================================================================
::-------- CA-PEM-START - paste your certificate below this line --------


::-------- CA-PEM-END - stop pasting above this line --------
