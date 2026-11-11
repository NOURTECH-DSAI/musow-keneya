<?php
/**
 * Musow Kènèya : amorçage commun (configuration, base de données, utilitaires).
 * NOUR TECH SARL, licence MIT.
 */
declare(strict_types=1);

define('MK_ROOT', dirname(__DIR__));
define('MK_VERSION', '2.1.0');

date_default_timezone_set('Africa/Abidjan');

$GLOBALS['MK_CONFIG'] = $GLOBALS['MK_CONFIG'] ?? null;
if ($GLOBALS['MK_CONFIG'] === null) {
    $fichier = getenv('MK_CONFIG_FICHIER') ?: MK_ROOT . '/config.php';
    if (!is_file($fichier)) {
        if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
            header('Location: ' . mk_racine_url() . 'install.php');
            exit;
        }
        $GLOBALS['MK_CONFIG'] = [];
    } else {
        $GLOBALS['MK_CONFIG'] = require $fichier;
    }
}

spl_autoload_register(static function (string $classe): void {
    $chemin = MK_ROOT . '/src/' . $classe . '.php';
    if (is_file($chemin)) {
        require $chemin;
    }
});

/** Lit une valeur de configuration (notation pointée : 'whatsapp.jeton'). */
function cfg(string $cle, mixed $defaut = null): mixed
{
    $valeur = $GLOBALS['MK_CONFIG'];
    foreach (explode('.', $cle) as $morceau) {
        if (!is_array($valeur) || !array_key_exists($morceau, $valeur)) {
            return $defaut;
        }
        $valeur = $valeur[$morceau];
    }
    return $valeur;
}

/** Connexion PDO unique. */
function db(): PDO
{
    static $pdo = null;
    if (isset($GLOBALS['MK_PDO'])) {
        return $GLOBALS['MK_PDO'];
    }
    if ($pdo === null && cfg('db.pilote') === 'sqlite') {
        // Utilisé uniquement pour les tests automatisés
        $pdo = new PDO('sqlite:' . cfg('db.chemin'), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 3000');
    }
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            cfg('db.hote', 'localhost'),
            (int) cfg('db.port', 3306),
            cfg('db.base', '')
        );
        $pdo = new PDO($dsn, (string) cfg('db.utilisateur', ''), (string) cfg('db.mot_de_passe', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/** Exécute une requête préparée et renvoie le PDOStatement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q1(string $sql, array $params = []): ?array
{
    $ligne = q($sql, $params)->fetch();
    return $ligne === false ? null : $ligne;
}

function qv(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Horloge injectable (tests). Abidjan est en UTC+0 toute l'année. */
function maintenant(): string
{
    return $GLOBALS['MK_NOW'] ?? date('Y-m-d H:i:s');
}

function aujourdhui(): string
{
    return substr(maintenant(), 0, 10);
}

function ts(string $dateHeure): int
{
    return (int) strtotime($dateHeure);
}

function plus(string $dateHeure, string $intervalle): string
{
    return date('Y-m-d H:i:s', strtotime($intervalle, ts($dateHeure)));
}

/** Journal d'audit (ENF-07). */
function journal(string $acteur, string $action, array $details = []): void
{
    q(
        'INSERT INTO journal (acteur, action, details, cree_le) VALUES (?, ?, ?, ?)',
        [$acteur, $action, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, maintenant()]
    );
}

/** Lit ou écrit un paramètre persistant. */
function parametre(string $cle, ?string $valeur = null): ?string
{
    if ($valeur === null) {
        $v = qv('SELECT valeur FROM parametres WHERE cle = ?', [$cle]);
        return $v === null ? null : (string) $v;
    }
    if (q('UPDATE parametres SET valeur = ? WHERE cle = ?', [$valeur, $cle])->rowCount() === 0
        && !qv('SELECT COUNT(*) FROM parametres WHERE cle = ?', [$cle])) {
        q('INSERT INTO parametres (cle, valeur) VALUES (?, ?)', [$cle, $valeur]);
    }
    return $valeur;
}

/** Journal technique dans storage/logs. */
function log_tech(string $canal, string $message): void
{
    $dir = MK_ROOT . '/storage/logs';
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents(
            $dir . '/' . $canal . '-' . date('Y-m') . '.log',
            '[' . maintenant() . '] ' . $message . "\n",
            FILE_APPEND
        );
    }
}

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mk_racine_url(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    foreach (['/admin', '/api'] as $sous) {
        if (str_ends_with($dir, $sous)) {
            $dir = substr($dir, 0, -strlen($sous));
        }
    }
    return $dir . '/';
}

function url_publique(string $chemin): string
{
    return rtrim((string) cfg('url_base', ''), '/') . '/' . ltrim($chemin, '/');
}

/** Libellés lisibles. */
function libelle_profil(string $p): string
{
    return ['enceinte' => 'Enceinte', 'mere' => 'Mère', 'toutes' => 'Toutes'][$p] ?? $p;
}

function libelle_statut(string $s): string
{
    return [
        'active' => 'Active',
        'stoppee' => 'A arrêté',
        'terminee' => 'Parcours terminé',
        'transferee' => 'Numéro transféré',
    ][$s] ?? $s;
}
