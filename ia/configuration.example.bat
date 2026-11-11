@echo off
REM Musow Kenèya : parametres du service de reconnaissance vocale
REM Ce fichier contient un secret : ne l'envoyez a personne.

REM Adresse de la plateforme (sans barre a la fin)
set MK_URL=https://musowkeneya.jidiri.com

REM Valeur de 'jeton_ia' dans la section 'securite' de config.php (sur Hostinger)
set MK_JETON_IA=COLLEZ_ICI_LE_JETON_IA

REM Modele de reconnaissance (MMS de Meta, langue dioula)
set MK_MODELE=facebook/mms-1b-all
set MK_LANGUE_MMS=dyu

REM Dossier ou le modele (environ 4 Go) est telecharge une fois pour toutes
set HF_HOME=%~dp0modeles
