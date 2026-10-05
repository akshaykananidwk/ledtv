@echo off
REM HotelCast bulk TV setup - double-click to run.
REM Put HotelCast-TV-*.apk and tvs.csv (Admin -> Rooms -> Download setup file) in this folder.
chcp 65001 >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0HotelCast-Setup.ps1" %*
echo.
pause
