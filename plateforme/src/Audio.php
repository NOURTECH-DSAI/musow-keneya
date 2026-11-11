<?php
declare(strict_types=1);

/** Contrôle des fichiers audio importés (ENF-01 : Ogg Opus mono, léger). */
final class Audio
{
    public const TAILLE_MAX = 1024 * 1024;   // refus au-delà de 1 Mo
    public const TAILLE_CONSEILLEE = 400 * 1024;

    /**
     * @return array{ok:bool, erreur:?string, duree:?int, canaux:?int, avertissement:?string}
     */
    public static function analyser(string $chemin): array
    {
        $taille = filesize($chemin) ?: 0;
        if ($taille === 0) {
            return self::refus('Fichier vide.');
        }
        if ($taille > self::TAILLE_MAX) {
            return self::refus('Fichier trop lourd (' . round($taille / 1024) . ' Ko). Réencodez-le en Opus à 24 kbit/s.');
        }
        $debut = (string) file_get_contents($chemin, false, null, 0, 4096);
        if (!str_starts_with($debut, 'OggS')) {
            return self::refus('Ce n’est pas un fichier Ogg. WhatsApp exige le format OGG avec le codec Opus.');
        }
        $pos = strpos($debut, 'OpusHead');
        if ($pos === false) {
            return self::refus('Fichier Ogg sans codec Opus (Vorbis ?). Réencodez-le en Opus.');
        }
        $canaux = ord($debut[$pos + 9]);
        $preSkip = unpack('v', substr($debut, $pos + 10, 2))[1];

        // Durée : position du dernier granule Ogg, à 48 kHz pour Opus
        $finLongueur = min($taille, 65536);
        $fin = (string) file_get_contents($chemin, false, null, $taille - $finLongueur, $finLongueur);
        $dernier = strrpos($fin, 'OggS');
        $duree = null;
        if ($dernier !== false && strlen($fin) >= $dernier + 14) {
            $granule = unpack('P', substr($fin, $dernier + 6, 8))[1];
            if ($granule > $preSkip) {
                $duree = (int) round(($granule - $preSkip) / 48000);
            }
        }
        $avert = [];
        if ($canaux !== 1) {
            $avert[] = 'audio en stéréo : préférez le mono (fichier deux fois plus léger)';
        }
        if ($taille > self::TAILLE_CONSEILLEE) {
            $avert[] = 'fichier lourd (' . round($taille / 1024) . ' Ko) pour les forfaits limités';
        }
        if ($duree !== null && $duree > 120) {
            $avert[] = 'message long (' . $duree . ' s) : la cible est de 45 à 90 secondes';
        }
        return ['ok' => true, 'erreur' => null, 'duree' => $duree, 'canaux' => $canaux, 'avertissement' => $avert ? implode(' ; ', $avert) : null];
    }

    private static function refus(string $m): array
    {
        return ['ok' => false, 'erreur' => $m, 'duree' => null, 'canaux' => null, 'avertissement' => null];
    }
}
