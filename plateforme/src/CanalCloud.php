<?php
declare(strict_types=1);

/** WhatsApp Business Platform, API Cloud de Meta. */
final class CanalCloud extends Canal
{
    public function nom(): string
    {
        return 'cloud';
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/' . cfg('whatsapp.version_api', 'v21.0') . '/';
    }

    private function appel(string $methode, string $url, ?array $corps = null, bool $brut = false): array
    {
        $ch = curl_init($url);
        $entetes = ['Authorization: Bearer ' . cfg('whatsapp.jeton', '')];
        if ($corps !== null) {
            $entetes[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $reponse = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $erreurCurl = curl_error($ch);
        curl_close($ch);
        if ($reponse === false) {
            return ['code' => 0, 'json' => null, 'brut' => null, 'mime' => null, 'erreur' => $erreurCurl];
        }
        return [
            'code' => $code,
            'json' => $brut ? null : json_decode((string) $reponse, true),
            'brut' => $brut ? $reponse : null,
            'mime' => $mime,
            'erreur' => null,
        ];
    }

    private function envoyer(string $numero, array $charge): array
    {
        $corps = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $numero] + $charge;
        $r = $this->appel('POST', $this->base() . cfg('whatsapp.id_numero') . '/messages', $corps);
        $id = $r['json']['messages'][0]['id'] ?? null;
        if ($r['code'] >= 200 && $r['code'] < 300 && $id) {
            return ['ok' => true, 'wa_id' => $id, 'erreur' => null];
        }
        $erreur = $r['erreur'] ?? ($r['json']['error']['message'] ?? ('HTTP ' . $r['code']));
        log_tech('whatsapp', 'Échec envoi : ' . $erreur);
        return ['ok' => false, 'wa_id' => null, 'erreur' => (string) $erreur];
    }

    public function audio(string $numero, string $urlAudio, array $contexte): array
    {
        return $this->envoyer($numero, ['type' => 'audio', 'audio' => ['link' => $urlAudio]]);
    }

    public function texte(string $numero, string $texte, array $contexte): array
    {
        return $this->envoyer($numero, ['type' => 'text', 'text' => ['body' => $texte, 'preview_url' => false]]);
    }

    public function boutons(string $numero, string $texte, array $boutons, array $contexte): array
    {
        $liste = [];
        foreach (array_slice($boutons, 0, 3, true) as $id => $titre) {
            $liste[] = ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => mb_substr($titre, 0, 20)]];
        }
        return $this->envoyer($numero, [
            'type' => 'interactive',
            'interactive' => ['type' => 'button', 'body' => ['text' => $texte], 'action' => ['buttons' => $liste]],
        ]);
    }

    public function modele(string $numero, string $nomModele, array $variables, array $payloadsBoutons, array $contexte): array
    {
        $composants = [];
        if ($variables) {
            $composants[] = [
                'type' => 'body',
                'parameters' => array_map(static fn($v) => ['type' => 'text', 'text' => (string) $v], array_values($variables)),
            ];
        }
        foreach (array_values($payloadsBoutons) as $i => $payload) {
            $composants[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) $i,
                'parameters' => [['type' => 'payload', 'payload' => $payload]],
            ];
        }
        return $this->envoyer($numero, [
            'type' => 'template',
            'template' => [
                'name' => $nomModele,
                'language' => ['code' => cfg('whatsapp.langue_modeles', 'fr')],
                'components' => $composants,
            ],
        ]);
    }

    public function telechargerMedia(string $mediaId): ?array
    {
        $meta = $this->appel('GET', $this->base() . rawurlencode($mediaId));
        $url = $meta['json']['url'] ?? null;
        if (!$url) {
            log_tech('whatsapp', 'Média introuvable : ' . $mediaId);
            return null;
        }
        $fichier = $this->appel('GET', $url, null, true);
        if ($fichier['code'] !== 200 || !$fichier['brut']) {
            log_tech('whatsapp', 'Téléchargement impossible : ' . $mediaId);
            return null;
        }
        return ['octets' => $fichier['brut'], 'mime' => $meta['json']['mime_type'] ?? $fichier['mime']];
    }

    /** Affiche les coches bleues côté utilisatrice (facultatif). */
    public function marquerLu(string $waId): void
    {
        $this->appel('POST', $this->base() . cfg('whatsapp.id_numero') . '/messages', [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waId,
        ]);
    }
}
