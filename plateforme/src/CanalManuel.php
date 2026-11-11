<?php
declare(strict_types=1);

/**
 * Mode de repli (section 6 du cahier des charges) : si le compte WhatsApp
 * Business Platform n'est pas encore vérifié par Meta, chaque envoi devient
 * une tâche que l'équipe réalise depuis l'application WhatsApp Business.
 * Les réponses des femmes sont ensuite saisies dans « Envois manuels » et
 * passent par exactement la même logique de parcours.
 */
final class CanalManuel extends Canal
{
    public function nom(): string
    {
        return 'manuel';
    }

    private function tache(array $contexte, string $nature, string $consigne, ?string $fichier = null): array
    {
        q(
            'INSERT INTO taches_manuelles (envoi_id, participante_id, nature, consigne, fichier_audio, cree_le) VALUES (?, ?, ?, ?, ?, ?)',
            [$contexte['envoi_id'] ?? null, $contexte['participante_id'], $nature, $consigne, $fichier, maintenant()]
        );
        return ['ok' => true, 'wa_id' => 'manuel-' . db()->lastInsertId(), 'erreur' => null];
    }

    public function audio(string $numero, string $urlAudio, array $contexte): array
    {
        $titre = $contexte['titre'] ?? 'message vocal';
        return $this->tache($contexte, 'audio', 'Envoyer le message vocal « ' . $titre . ' »', basename(parse_url($urlAudio, PHP_URL_PATH) ?: ''));
    }

    public function texte(string $numero, string $texte, array $contexte): array
    {
        return $this->tache($contexte, 'texte', "Envoyer ce texte :\n" . $texte);
    }

    public function boutons(string $numero, string $texte, array $boutons, array $contexte): array
    {
        $choix = implode(' / ', $boutons);
        return $this->tache($contexte, 'texte', "Envoyer ce texte :\n" . $texte . "\n(Choix possibles : " . $choix . ')');
    }

    public function modele(string $numero, string $nomModele, array $variables, array $payloadsBoutons, array $contexte): array
    {
        $texte = (string) cfg('whatsapp.texte_notification', 'Bonjour, un nouveau message vocal vous attend. Envoyez 1 pour l’écouter.');
        return $this->tache($contexte, 'notif', "Envoyer la notification neutre :\n" . $texte);
    }

    public function telechargerMedia(string $mediaId): ?array
    {
        return null;
    }
}
