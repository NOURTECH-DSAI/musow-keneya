<?php
declare(strict_types=1);

/** Gabarit commun de l'espace équipe. */
final class View
{
    public static function entete(string $titre, ?array $u = null): void
    {
        $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $liens = [
            ['index.php', 'Tableau de bord', 'tableau'],
            ['inscrire.php', 'Inscrire', 'inscrire'],
            ['participantes.php', 'Participantes', 'participantes'],
            ['file.php', 'File de traitement', 'file'],
            ['questions_recues.php', 'Questions reçues', 'questions_recues'],
            ['questions.php', 'Questions fréquentes', 'questions_voir'],
            ['messages.php', 'Bibliothèque audio', 'messages_voir'],
            ['manuel.php', 'Envois manuels', 'manuel'],
            ['export.php', 'Exports', 'export'],
            ['utilisateurs.php', 'Équipe', 'utilisateurs'],
            ['journal.php', 'Journal', 'journal'],
        ];
        $mode = Canal::mode();
        $nbFile = 0;
        if ($u && Auth::peut('file', $u)) {
            $nbFile = (int) qv("SELECT COUNT(*) FROM alertes WHERE statut = 'ouverte'")
                + (int) qv("SELECT COUNT(*) FROM vocaux WHERE statut = 'a_traiter'");
        }
        ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titre) ?> · Musow Kènèya</title>
<link rel="stylesheet" href="style.css?v=<?= MK_VERSION ?>">
<link rel="icon" href="/favicon.ico?v=2" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
<script src="app.js?v=<?= MK_VERSION ?>" defer></script>
</head>
<body>
<header class="barre">
  <div class="bande" aria-hidden="true"><span></span><span></span><span></span></div>
  <div class="barre-int">
    <a class="marque" href="index.php">Musow Kènèya <small>espace équipe</small></a>
    <?php if ($u): ?>
    <div class="moi"><?= e($u['nom']) ?> · <?= e(Auth::ROLES[$u['role']] ?? $u['role']) ?> · <a href="compte.php">Compte</a> · <a href="logout.php">Sortir</a></div>
    <?php endif; ?>
  </div>
  <?php if ($u): ?>
  <nav class="menu">
    <?php foreach ($liens as [$href, $lib, $droit]): if (!Auth::peut($droit, $u)) continue; ?>
      <a href="<?= $href ?>" class="<?= $page === $href ? 'actif' : '' ?>"><?= e($lib) ?><?php if ($href === 'file.php' && $nbFile): ?> <b class="pastille"><?= $nbFile ?></b><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($mode === 'manuel'): ?>
    <div class="bandeau-mode">Mode manuel actif : les envois sont préparés dans « Envois manuels » et faits depuis l’application WhatsApp Business.</div>
  <?php elseif ($mode === 'test'): ?>
    <div class="bandeau-mode">Mode test : aucun message n’est réellement envoyé.</div>
  <?php endif; ?>
  <?php endif; ?>
</header>
<main class="contenu">
<?php
        foreach (self::flashs() as [$type, $texte]) {
            echo '<div class="flash ' . e($type) . '">' . e($texte) . '</div>';
        }
    }

    public static function pied(): void
    {
        ?></main>
<footer class="pied">Musow Kènèya v<?= MK_VERSION ?> · NOUR TECH SARL · code sous licence MIT · <a href="../stats.php">indicateurs publics</a></footer>
</body>
</html><?php
    }

    public static function flash(string $type, string $texte): void
    {
        Auth::demarrer();
        $_SESSION['flash'][] = [$type, $texte];
    }

    private static function flashs(): array
    {
        Auth::demarrer();
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }

    public static function rediriger(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    public static function date(?string $d, bool $heure = true): string
    {
        if (!$d) {
            return '–';
        }
        return $heure ? date('d/m/Y H:i', ts($d)) : date('d/m/Y', ts($d));
    }

    public static function duree(?int $secondes): string
    {
        if ($secondes === null) {
            return '–';
        }
        if ($secondes < 60) {
            return $secondes . ' s';
        }
        if ($secondes < 3600) {
            return round($secondes / 60) . ' min';
        }
        return round($secondes / 3600, 1) . ' h';
    }
}
