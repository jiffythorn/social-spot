@echo off
REM Double-click backup for the person who looks after the server.
REM
REM Writes a dated database dump into packaging\backups\. The database IS the
REM organization's data, so this single file is the backup - copy the newest one
REM somewhere that is not this computer (USB stick, shared drive, cloud).
setlocal EnableDelayedExpansion

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HHmm"') do set TODAY=%%i
for /f "tokens=1,* delims==" %%A in ('findstr /b /v "#" "%~dp0.env"') do set "%%A=%%B"

if not defined DB_ROOT_PASSWORD (
  echo No answers file found - is the server installed?
  pause
  exit /b 1
)

if not exist "%~dp0backups" mkdir "%~dp0backups"
set OUT=%~dp0backups\social-spot-backup-%TODAY%.sql

pushd "%~dp0"
docker compose exec -T db mariadb-dump -u root -p"%DB_ROOT_PASSWORD%" --single-transaction "%DB_NAME%" > "%OUT%"
if errorlevel 1 (
  echo Backup FAILED - is the server running?
  del "%OUT%" 2>nul
  popd
  pause
  exit /b 1
)
popd

echo Backed up to %OUT%
echo Now copy it somewhere that is not this computer.
echo.
pause
