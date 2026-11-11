<?php
declare(strict_types=1);

/**
 * Chiffrement des numéros (ENF-06) : libsodium secretbox pour le stockage,
 * HMAC-SHA256 comme index aveugle pour retrouver une participante à partir
 * du numéro reçu de WhatsApp sans jamais stocker le numéro en clair.
 */
final class Crypto
{
    private static function cle(string $nom): string
    {
        $b64 = (string) cfg('securite.' . $nom, '');
        $cle = base64_decode($b64, true);
        if ($cle === false || strlen($cle) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException("Clé de sécurité « {$nom} » absente ou invalide dans config.php");
        }
        return $cle;
    }

    public static function nouvelleCle(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public static function chiffrer(string $clair): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($clair, $nonce, self::cle('cle_chiffrement')));
    }

    public static function dechiffrer(string $chiffre): string
    {
        $brut = base64_decode($chiffre, true);
        if ($brut === false || strlen($brut) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Donnée chiffrée illisible');
        }
        $nonce = substr($brut, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $clair = sodium_crypto_secretbox_open(substr($brut, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::cle('cle_chiffrement'));
        if ($clair === false) {
            throw new RuntimeException('Échec du déchiffrement');
        }
        return $clair;
    }

    public static function index(string $numeroNormalise): string
    {
        return hash_hmac('sha256', $numeroNormalise, self::cle('cle_index'));
    }

    /**
     * Normalise un numéro ivoirien au format international sans « + »
     * (forme utilisée par WhatsApp, ex. 2250701020304). Renvoie null si le
     * numéro n'est pas reconnaissable.
     */
    public static function normaliser(string $saisie): ?string
    {
        $chiffres = preg_replace('/\D+/', '', $saisie) ?? '';
        if (str_starts_with($chiffres, '00')) {
            $chiffres = substr($chiffres, 2);
        }
        if (strlen($chiffres) === 10) {
            $chiffres = '225' . $chiffres;
        }
        if (!preg_match('/^\d{11,15}$/', $chiffres)) {
            return null;
        }
        return $chiffres;
    }

    /**
     * Formes possibles d'un même numéro ivoirien. WhatsApp renvoie parfois
     * l'identifiant des comptes créés avant 2021 avec l'ancien numéro à
     * 8 chiffres (225 + 8 chiffres) : on retrouve alors le numéro à
     * 10 chiffres en essayant les préfixes 01, 05 et 07.
     * @return string[]
     */
    public static function variantes(string $numeroNormalise): array
    {
        $formes = [$numeroNormalise];
        if (preg_match('/^225(\d{8})$/', $numeroNormalise, $m)) {
            foreach (['01', '05', '07'] as $prefixe) {
                $formes[] = '225' . $prefixe . $m[1];
            }
        }
        return $formes;
    }

    /** Affichage partiel pour l'équipe : 225 07 •• •• 04. */
    public static function masquer(string $numero): string
    {
        if (strlen($numero) < 6) {
            return '••••';
        }
        return substr($numero, 0, 5) . str_repeat('•', strlen($numero) - 7) . substr($numero, -2);
    }
}
