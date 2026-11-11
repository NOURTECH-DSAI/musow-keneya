<?php
declare(strict_types=1);

/** Canal de test : enregistre les envois en mémoire, n'envoie rien. */
final class CanalTest extends Canal
{
    /** @var list<array> */
    public array $envois = [];
    public bool $enPanne = false;
    private int $n = 0;

    public function nom(): string
    {
        return 'test';
    }

    private function noter(string $type, string $numero, array $donnees): array
    {
        if ($this->enPanne) {
            return ['ok' => false, 'wa_id' => null, 'erreur' => 'panne simulée'];
        }
        $id = 'wamid.TEST' . (++$this->n);
        $this->envois[] = ['type' => $type, 'numero' => $numero, 'wa_id' => $id] + $donnees;
        return ['ok' => true, 'wa_id' => $id, 'erreur' => null];
    }

    public function audio(string $numero, string $urlAudio, array $contexte): array
    {
        return $this->noter('audio', $numero, ['url' => $urlAudio, 'titre' => $contexte['titre'] ?? null]);
    }

    public function texte(string $numero, string $texte, array $contexte): array
    {
        return $this->noter('texte', $numero, ['texte' => $texte]);
    }

    public function boutons(string $numero, string $texte, array $boutons, array $contexte): array
    {
        return $this->noter('boutons', $numero, ['texte' => $texte, 'boutons' => $boutons]);
    }

    public function modele(string $numero, string $nomModele, array $variables, array $payloadsBoutons, array $contexte): array
    {
        return $this->noter('modele', $numero, ['modele' => $nomModele, 'payloads' => $payloadsBoutons]);
    }

    public function telechargerMedia(string $mediaId): ?array
    {
        // En-tête Ogg minimal : suffisant pour les tests de stockage
        return ['octets' => "OggS\0test-" . $mediaId, 'mime' => 'audio/ogg'];
    }

    public function vider(): array
    {
        $e = $this->envois;
        $this->envois = [];
        return $e;
    }
}
