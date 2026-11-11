@echo off
chcp 65001 >nul
title Musow Kenèya - reconnaissance vocale
cd /d "%~dp0"
call configuration.bat
call venv\Scripts\activate.bat
set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8

:boucle
echo [%date% %time%] Demarrage du service...
python worker.py --boucle >> journal_ia.txt 2>&1
echo [%date% %time%] Le service s'est arrete. Redemarrage dans 30 secondes... >> journal_ia.txt
timeout /t 30 /nobreak >nul
goto boucle
