@echo off
setlocal enabledelayedexpansion
title Estatell MyKad Reader Bridge
echo ====================================================
echo   Estatell / Wastays MyKad Smart Card Reader Bridge
echo ====================================================
echo.

:: 1. Check Python installation
where python >nul 2>nul
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Python 3 is not found in your system PATH.
    echo Please install Python 3 from https://www.python.org/downloads/
    echo Make sure to check "Add Python to PATH" during installation.
    echo.
    pause
    exit /b 1
)

:: 2. Verify / install pyscard
echo Checking smartcard library (pyscard)...
python -c "import smartcard" >nul 2>nul
if %ERRORLEVEL% neq 0 (
    echo Installing required library 'pyscard'...
    python -m pip install pyscard
    if %ERRORLEVEL% neq 0 (
        echo [ERROR] Failed to install pyscard. Please ensure internet access or install manually via 'pip install pyscard'.
        pause
        exit /b 1
    )
)

:: 3. Launch bridge daemon
echo.
echo Starting MyKad bridge on http://127.0.0.1:9292...
echo Leave this window open while using the front desk portal.
echo.
python "%~dp0mykad_bridge.py" %*

pause
