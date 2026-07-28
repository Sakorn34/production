@echo off
chcp 65001 >nul
cd /d "%~dp0.."
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build-patch.ps1" -OpenFolder
if errorlevel 1 goto :end
echo.
echo ========================================
echo  FTP: upload deploy/release/production/
echo  (skip README.html on server)
echo ========================================
echo.
set /p DONE="Type OK after upload is done: "
if /i not "%DONE%"=="OK" (
  echo Skipped baseline save.
  goto :end
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0mark-deployed.ps1"
:end
pause
