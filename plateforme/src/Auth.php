<?php
declare(strict_types=1);

/** Sessions, rôles, jetons CSRF et double authentification. */
final class Auth
{
    public const ROLES = [
        'admin' => 'Administration',
        'medical' => 'Direction médicale',
        'animatrice' => 'Animatrice / sage-femme',
        'linguiste' => 'Linguiste',
        'lecture' => 'Lecture seule',
    ];

    /** Droits par action. */
    private const DROITS = [
        'tableau' => ['admin', 'medical', 'animatrice', 'linguiste', 'lecture'],
        'inscrire' => ['admin', 'medical', 'animatrice'],
        'participantes' => ['admin', 'medical', 'animatrice', 'lecture'],
        'voir_numero' => ['admin', 'medical', 'animatrice'],
        'actions_parcours' => ['admin', 'medical', 'animatrice'],
        'declarer_perte' => ['admin', 'medical', 'animatrice'],
        'file' => ['admin', 'medical', 'animatrice'],
        'messages_voir' => ['admin', 'medical', 'animatrice', 'linguiste', 'lecture'],
        'messages_modifier' => ['admin', 'medical', 'linguiste'],
        'questions_voir' => ['admin', 'medical', 'animatrice', 'linguiste', 'lecture'],
        'questions_modifier' => ['admin', 'medical', 'linguiste'],
        'questions_recues' => ['admin', 'medical', 'animatrice', 'linguiste'],
        'questions_verifier' => ['admin', 'medical', 'animatrice'],
        'lexique_valider' => ['medical'],
        'messages_valider' => ['medical'],
        'manuel' => ['admin', 'medical', 'animatrice'],
        'export' => ['admin', 'medical'],
        'utilisateurs' => ['admin'],
        'journal' => ['admin', 'medical'],
        'parametres' => ['admin'],
    ];

    /** Rôles soumis à la double authentification (ENF-05). */
    public const ROLES_2FA = ['admin', 'medical'];

    public static function demarrer(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('mk_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        // Expiration après 8 heures d'inactivité
        if (isset($_SESSION['vu_le']) && time() - $_SESSION['vu_le'] > 8 * 3600) {
            $_SESSION = [];
        }
        $_SESSION['vu_le'] = time();
    }

    public static function utilisateur(): ?array
    {
        self::demarrer();
        $id = $_SESSION['uid'] ?? null;
        if (!$id || empty($_SESSION['2fa_ok'])) {
            return null;
        }
        static $cache = [];
        if (!isset($cache[$id])) {
            $cache[$id] = q1('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1', [$id]);
        }
        return $cache[$id];
    }

    public static function exiger(string $droit = 'tableau'): array
    {
        $u = self::utilisateur();
        if (!$u) {
            header('Location: login.php');
            exit;
        }
        if (!self::peut($droit, $u)) {
            http_response_code(403);
            View::entete('Accès refusé', $u);
            echo '<div class="carte"><p>Votre rôle ne permet pas d’accéder à cette page.</p></div>';
            View::pied();
            exit;
        }
        return $u;
    }

    public static function peut(string $droit, ?array $u = null): bool
    {
        $u ??= self::utilisateur();
        return $u !== null && in_array($u['role'], self::DROITS[$droit] ?? [], true);
    }

    public static function acteur(): string
    {
        $u = self::utilisateur();
        return $u ? 'u:' . $u['id'] . ' ' . $u['email'] : 'systeme';
    }

    public static function jeton(): string
    {
        self::demarrer();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function champJeton(): string
    {
        return '<input type="hidden" name="csrf" value="' . e(self::jeton()) . '">';
    }

    public static function verifierJeton(): void
    {
        self::demarrer();
        $envoye = (string) ($_POST['csrf'] ?? '');
        if ($envoye === '' || !hash_equals($_SESSION['csrf'] ?? '', $envoye)) {
            http_response_code(400);
            exit('Jeton de sécurité invalide. Rechargez la page.');
        }
    }

    /** Étape 1 : mot de passe. Renvoie 'ok', '2fa', '2fa_config' ou 'echec'. */
    public static function connecter(string $email, string $mdp): string
    {
        self::demarrer();
        $u = q1('SELECT * FROM utilisateurs WHERE email = ? AND actif = 1', [mb_strtolower(trim($email))]);
        // Limitation simple des tentatives par session
        $_SESSION['essais'] = ($_SESSION['essais'] ?? 0) + 1;
        if ($_SESSION['essais'] > 10) {
            return 'echec';
        }
        if (!$u || !password_verify($mdp, $u['mot_de_passe'])) {
            journal('anonyme', 'connexion_echec', ['email' => $email]);
            return 'echec';
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['essais'] = 0;
        if (in_array($u['role'], self::ROLES_2FA, true)) {
            $_SESSION['2fa_ok'] = false;
            return $u['totp_secret'] ? '2fa' : '2fa_config';
        }
        self::finaliser((int) $u['id']);
        return 'ok';
    }

    public static function finaliser(int $id): void
    {
        $_SESSION['2fa_ok'] = true;
        q('UPDATE utilisateurs SET derniere_connexion = ? WHERE id = ?', [maintenant(), $id]);
        journal('u:' . $id, 'connexion', []);
    }

    public static function enAttente2fa(): ?array
    {
        self::demarrer();
        if (empty($_SESSION['uid']) || !empty($_SESSION['2fa_ok'])) {
            return null;
        }
        return q1('SELECT * FROM utilisateurs WHERE id = ? AND actif = 1', [$_SESSION['uid']]);
    }

    public static function deconnecter(): void
    {
        self::demarrer();
        $_SESSION = [];
        session_destroy();
    }

    public static function exigerPost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            exit;
        }
        self::verifierJeton();
    }
}
