@echo off
REM Double-click entry point for the Windows installer.
REM
REM A .ps1 cannot simply be double-clicked (the execution policy stops it, with
REM an error most people have never seen), so this one-line wrapper exists to be
REM the thing office staff actually click. It forwards any arguments (e.g. -Force)
REM and then waits, so the window stays open and the final address stays readable.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
echo.
pause
