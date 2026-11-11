<?php
/** Lecture d'un message vocal reçu : réservée à l'équipe, chaque écoute est journalisée. */
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::utilisateur();
if (!$u || !(Auth::peut('file', $u) || Auth::peut('questions_recues', $u))) {
    http_response_code(403);
    exit;
}

$v = q1('SELECT v.fichier, p.pseudo FROM vocaux v JOIN participantes p ON p.id = v.participante_id WHERE v.id = ?', [(int) ($_GET['id'] ?? 0)]);
$chemin = $v && $v['fichier'] ? MK_ROOT . '/storage/vocaux/' . basename($v['fichier']) : null;
if (!$chemin || !is_file($chemin)) {
    http_response_code(404);
    exit;
}
// Une seule entrée de journal par session et par fichier (le navigateur fait plusieurs requêtes)
$cle = 'ecoute_' . (int) $_GET['id'];
if (empty($_SESSION[$cle])) {
    journal(Auth::acteur(), 'ecoute_vocal', ['participante' => $v['pseudo'], 'vocal' => (int) $_GET['id']]);
    $_SESSION[$cle] = 1;
}
header('Content-Type: audio/ogg');
header('Content-Length: ' . filesize($chemin));
header('Cache-Control: private, no-store');
readfile($chemin);
