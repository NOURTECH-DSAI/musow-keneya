<?php
/**
 * Point de réception des notifications de Meta (WhatsApp Business Platform).
 * URL à déclarer dans le tableau de bord Meta : https://<domaine>/<dossier>/webhook.php
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

// 1. Vérification de l'abonnement (requête GET envoyée une fois par Meta)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $jeton = $_GET['hub_verify_token'] ?? '';
    $defi = $_GET['hub_challenge'] ?? '';
    if ($mode === 'subscribe' && $jeton !== '' && hash_equals((string) cfg('whatsapp.jeton_verification', ''), (string) $jeton)) {
        header('Content-Type: text/plain');
        echo $defi;
        exit;
    }
    http_response_code(403);
    exit;
}

// 2. Notifications (POST) : on vérifie la signature avant toute lecture
$brut = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$attendue = 'sha256=' . hash_hmac('sha256', $brut, (string) cfg('whatsapp.secret_application', ''));
if (cfg('whatsapp.secret_application', '') === '' || !hash_equals($attendue, $signature)) {
    log_tech('webhook', 'Signature invalide, notification ignorée');
    http_response_code(401);
    exit;
}

$donnees = json_decode($brut, true);
if (!is_array($donnees)) {
    http_response_code(400);
    exit;
}

try {
    traiter_notification($donnees);
} catch (Throwable $e) {
    // On répond quand même 200 : sinon Meta renvoie la notification en boucle.
    log_tech('webhook', 'Erreur : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
http_response_code(200);
echo 'ok';

function traiter_notification(array $donnees): void
{
    foreach ($donnees['entry'] ?? [] as $entree) {
        foreach ($entree['changes'] ?? [] as $changement) {
            $valeur = $changement['value'] ?? [];

            foreach ($valeur['statuses'] ?? [] as $s) {
                $erreur = isset($s['errors'][0]) ? (($s['errors'][0]['title'] ?? '') . ' ' . ($s['errors'][0]['message'] ?? '')) : null;
                Parcours::statut((string) $s['id'], (string) $s['status'], $erreur);
            }

            foreach ($valeur['messages'] ?? [] as $m) {
                $de = (string) ($m['from'] ?? '');
                $id = (string) ($m['id'] ?? '');
                $type = 'autre';
                $contenu = null;
                $media = null;
                switch ($m['type'] ?? '') {
                    case 'text':
                        $type = 'texte';
                        $contenu = (string) ($m['text']['body'] ?? '');
                        break;
                    case 'button': // bouton d'un modèle
                        $type = 'bouton';
                        $contenu = (string) ($m['button']['payload'] ?? $m['button']['text'] ?? '');
                        break;
                    case 'interactive': // bouton d'un message interactif
                        $type = 'bouton';
                        $contenu = (string) ($m['interactive']['button_reply']['id'] ?? $m['interactive']['list_reply']['id'] ?? '');
                        break;
                    case 'audio':
                        $type = 'audio';
                        $media = (string) ($m['audio']['id'] ?? '');
                        break;
                }
                Parcours::recevoir($de, $type, $contenu, $media, $id);
                $canal = Canal::instance();
                if ($canal instanceof CanalCloud && $id !== '') {
                    $canal->marquerLu($id);
                }
            }
        }
    }
}
