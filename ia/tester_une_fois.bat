@echo off
chcp 65001 >nul
title Musow Kenèya - test du service
cd /d "%~dp0"
call configuration.bat
call venv\Scripts\activate.bat
set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8
echo Premier lancement : le modele (environ 4 Go) se telecharge, cela peut prendre longtemps.
python worker.py
echo.
echo Si vous voyez "Pret (cpu)" ci-dessus, tout fonctionne.
pause
