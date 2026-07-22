@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
    echo PHP nao foi encontrado no PATH.
    exit /b 1
)
if not exist ".env" (
    echo Arquivo .env nao encontrado. Copie .env.example para .env e configure os valores locais.
    exit /b 1
)
echo Alug Facil disponivel em http://127.0.0.1:8000
php -S 127.0.0.1:8000 -t public public/router.php
