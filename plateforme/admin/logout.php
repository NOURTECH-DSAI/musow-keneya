<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
if (Auth::utilisateur()) {
    journal(Auth::acteur(), 'deconnexion');
}
Auth::deconnecter();
header('Location: login.php');
