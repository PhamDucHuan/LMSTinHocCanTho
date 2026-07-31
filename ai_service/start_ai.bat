@echo off
setlocal
cd /d "%~dp0"

if not exist "node_modules" (
    echo [ERROR] Chua cai Node.js dependencies.
    echo Hay chay npm.cmd install trong thu muc ai_service.
    pause
    exit /b 1
)

echo Starting LMS Node AI service at http://127.0.0.1:8000
node server.js

if errorlevel 1 (
    echo.
    echo [ERROR] AI service stopped with an error.
    pause
)
