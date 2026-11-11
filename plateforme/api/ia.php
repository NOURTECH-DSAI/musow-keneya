<?php
/**
 * Interface du service IA (ia/worker.py).
 * Authentification : en-tête « Authorization: Bearer <securite.jeton_ia> ».
 *
 *   GET  api/ia.php?action=a_faire        → vocaux à transcrire
 *   GET  api/ia.php?action=audio&id=12    → fichier audio
 *   POST api/ia.php?action=resultat       → {"id":12,"transcription":"…","score":0.82,"modele":"…"}
 *        Le serveur rapproche la transcription des questions validées et répond à la femme.
 *   POST api/ia.php?action=apparier       → {"texte":"…","profil":"enceinte"} : questions candidates (évaluation)
 *
 * La transcription d'un vocal sert à répondre à la femme (finalité du service).
 * Seuls les vocaux des femmes qui y ont consenti peuvent servir à entraîner
 * des modèles : le champ « consent_ia » l'indique au service IA.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$jeton = (string) cfg('securite.jeton_ia', '');
$recu = preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
if ($jeton === '' || !hash_equals($jeton, (string) $recu)) {
    http_response_code(401);
    exit(json_encode(['erreur' => 'non autorisé']));
}
parametre('dernier_appel_ia', maintenant());

$action = (string) ($_GET['action'] ?? '');
$corps = static function (): array {
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
};

switch ($action) {
    case 'a_faire':
        Questions::expirer();
        $lignes = q("SELECT id, recu_le, consent_ia FROM vocaux WHERE ia_statut = 'a_faire' AND fichier IS NOT NULL ORDER BY id LIMIT 20")->fetchAll();
        echo json_encode(['vocaux' => $lignes]);
        break;

    case 'audio':
        $v = q1("SELECT fichier FROM vocaux WHERE id = ? AND ia_statut IN ('a_faire', 'en_cours')", [(int) ($_GET['id'] ?? 0)]);
        $chemin = $v ? MK_ROOT . '/storage/vocaux/' . basename((string) $v['fichier']) : '';
        if (!$v || !is_file($chemin)) {
            http_response_code(404);
            exit(json_encode(['erreur' => 'introuvable']));
        }
        q("UPDATE vocaux SET ia_statut = 'en_cours' WHERE id = ?", [(int) $_GET['id']]);
        header('Content-Type: audio/ogg');
        readfile($chemin);
        break;

    case 'resultat':
        $d = $corps();
        $id = (int) ($d['id'] ?? 0);
        if (!$id || !qv('SELECT COUNT(*) FROM vocaux WHERE id = ?', [$id])) {
            http_response_code(400);
            exit(json_encode(['erreur' => 'données invalides']));
        }
        $transcription = mb_substr((string) ($d['transcription'] ?? ''), 0, 5000);
        $score = isset($d['score']) ? max(0.0, min(1.0, (float) $d['score'])) : null;
        $termes = array_values(array_filter(array_map('strval', (array) ($d['termes'] ?? []))));
        q(
            "UPDATE vocaux SET ia_statut = 'fait', ia_transcription = ?, ia_score = ?, ia_alerte = ?, ia_termes = ?, ia_modele = ?, ia_traite_le = ? WHERE id = ?",
            [$transcription, $score, $termes ? 1 : 0, mb_substr(implode(', ', $termes), 0, 255), mb_substr((string) ($d['modele'] ?? ''), 0, 80), maintenant(), $id]
        );
        $decision = Questions::analyser($id, $transcription, $score, $termes);
        echo json_encode(['ok' => true] + $decision, JSON_UNESCAPED_UNICODE);
        break;

    case 'apparier':
        $d = $corps();
        $profil = in_array($d['profil'] ?? null, ['enceinte', 'mere'], true) ? $d['profil'] : null;
        echo json_encode([
            'candidats' => Questions::apparier((string) ($d['texte'] ?? ''), $profil, 5),
            'danger' => Questions::termesDanger((string) ($d['texte'] ?? '')),
            'oui_non' => Questions::lireOuiNon((string) ($d['texte'] ?? '')),
            'seuil' => (float) cfg('questions.seuil_reconnaissance', 0.55),
            'marge' => (float) cfg('questions.marge', 0.06),
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(400);
        echo json_encode(['erreur' => 'action inconnue']);
}
