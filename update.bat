@echo off
cd /d "%~dp0"
set "PHPEXE=php"
if exist "%~dp0..\..\php\php.exe" set "PHPEXE=%~dp0..\..\php\php.exe"
echo === Update TokoKu POS (role admin / kasir / user) ===
"%PHPEXE%" tools\update.php
pause
