@echo off
chcp 65001 >nul
REM Musow Kenèya : installation du service de reconnaissance vocale (Windows)
REM A lancer une seule fois, par double-clic, depuis le dossier C:\musow-ia
cd /d "%~dp0"

where python >nul 2>nul
if errorlevel 1 (
  echo Python est introuvable. Installez Python 3.11 depuis python.org en cochant "Add python.exe to PATH".
  pause
  exit /b 1
)
where ffmpeg >nul 2>nul
if errorlevel 1 (
  echo ffmpeg est introuvable. Ouvrez PowerShell et tapez : winget install --id Gyan.FFmpeg -e
  echo puis fermez et rouvrez cette fenetre.
  pause
  exit /b 1
)

echo Creation de l'environnement Python...
python -m venv venv || goto erreur
call venv\Scripts\activate.bat
python -m pip install --upgrade pip || goto erreur
echo Installation de PyTorch (version processeur, environ 200 Mo)...
pip install torch --index-url https://download.pytorch.org/whl/cpu || goto erreur
echo Installation des autres bibliotheques...
pip install transformers requests numpy || goto erreur
echo.
echo Installation terminee. Remplissez configuration.bat puis lancez lancer_musow_ia.bat
pause
exit /b 0

:erreur
echo.
echo Une erreur s'est produite pendant l'installation. Copiez le message ci-dessus.
pause
exit /b 1
