<?php
declare(strict_types=1);

/** Double authentification TOTP (RFC 6238), compatible Google Authenticator. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function nouveauSecret(): string
    {
        $octets = random_bytes(20);
        $bits = '';
        foreach (str_split($octets) as $o) {
            $bits .= str_pad(decbin(ord($o)), 8, '0', STR_PAD_LEFT);
        }
        $secret = '';
        foreach (str_split($bits, 5) as $bloc) {
            $secret .= self::ALPHABET[bindec(str_pad($bloc, 5, '0'))];
        }
        return $secret;
    }

    private static function decoder(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $octets = '';
        foreach (str_split($bits, 8) as $bloc) {
            if (strlen($bloc) === 8) {
                $octets .= chr(bindec($bloc));
            }
        }
        return $octets;
    }

    public static function code(string $secret, ?int $instant = null): string
    {
        $compteur = intdiv($instant ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $compteur), self::decoder($secret), true);
        $decalage = ord($hash[19]) & 0x0F;
        $valeur = ((ord($hash[$decalage]) & 0x7F) << 24)
            | (ord($hash[$decalage + 1]) << 16)
            | (ord($hash[$decalage + 2]) << 8)
            | ord($hash[$decalage + 3]);
        return str_pad((string) ($valeur % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verifier(string $secret, string $saisie): bool
    {
        $saisie = preg_replace('/\D/', '', $saisie) ?? '';
        if (strlen($saisie) !== 6) {
            return false;
        }
        foreach ([-1, 0, 1] as $fenetre) {
            if (hash_equals(self::code($secret, time() + 30 * $fenetre), $saisie)) {
                return true;
            }
        }
        return false;
    }

    public static function uri(string $secret, string $compte): string
    {
        return 'otpauth://totp/' . rawurlencode('Musow Kènèya:' . $compte)
            . '?secret=' . $secret . '&issuer=' . rawurlencode('Musow Kènèya');
    }
}
