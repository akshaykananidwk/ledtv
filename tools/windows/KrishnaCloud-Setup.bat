@echo off
REM Krishna Cloud TV Management bulk TV setup - double-click to run.
REM Put KrishnaCloud-TV-*.apk and tvs.csv (Admin -> Screens & TVs -> Download setup file) in this folder.
chcp 65001 >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0KrishnaCloud-Setup.ps1" %*
echo.
pause
