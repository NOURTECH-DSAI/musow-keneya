<?php
declare(strict_types=1);

/**
 * Canal d'envoi. Le moteur du parcours ne connaît que cette interface :
 * on peut changer de canal (API WhatsApp, mode manuel, appel vocal plus
 * tard) sans toucher à la logique (section 12.2 du cahier des charges).
 *
 * Chaque méthode renvoie ['ok' => bool, 'wa_id' => ?string, 'erreur' => ?string].
 */
abstract class Canal
{
    abstract public function nom(): string;

    abstract public function audio(string $numero, string $urlAudio, array $contexte): array;

    abstract public function texte(string $numero, string $texte, array $contexte): array;

    /** @param array<string,string> $boutons identifiant => libellé (3 au plus, 20 caractères) */
    abstract public function boutons(string $numero, string $texte, array $boutons, array $contexte): array;

    /** Modèle préapprouvé par Meta (hors fenêtre de 24 h). */
    abstract public function modele(string $numero, string $nomModele, array $variables, array $payloadsBoutons, array $contexte): array;

    /** Télécharge un média reçu. Renvoie ['octets' => string, 'mime' => string] ou null. */
    abstract public function telechargerMedia(string $mediaId): ?array;

    public static function mode(): string
    {
        $mode = (string) cfg('whatsapp.mode', 'manuel');
        return in_array($mode, ['cloud', 'manuel', 'test'], true) ? $mode : 'manuel';
    }

    public static function instance(): Canal
    {
        if (isset($GLOBALS['MK_CANAL'])) {
            return $GLOBALS['MK_CANAL'];
        }
        return $GLOBALS['MK_CANAL'] = match (self::mode()) {
            'cloud' => new CanalCloud(),
            'test' => new CanalTest(),
            default => new CanalManuel(),
        };
    }
}
