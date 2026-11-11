<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if (Auth::utilisateur()) {
    View::rediriger('index.php');
}

$erreur = null;
$etape = 'mdp';
$enAttente = Auth::enAttente2fa();
if ($enAttente) {
    $etape = $enAttente['totp_secret'] ? '2fa' : '2fa_config';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = $_POST['action'] ?? '';
    if ($action === 'mdp') {
        $r = Auth::connecter((string) ($_POST['email'] ?? ''), (string) ($_POST['mot_de_passe'] ?? ''));
        if ($r === 'ok') {
            View::rediriger('index.php');
        }
        if ($r === 'echec') {
            $erreur = 'Adresse ou mot de passe incorrect.';
        } else {
            View::rediriger('login.php');
        }
    } elseif ($action === '2fa' && $enAttente) {
        $secret = $enAttente['totp_secret'] ?: ($_SESSION['totp_nouveau'] ?? '');
        if ($secret && Totp::verifier($secret, (string) ($_POST['code'] ?? ''))) {
            if (!$enAttente['totp_secret']) {
                q('UPDATE utilisateurs SET totp_secret = ? WHERE id = ?', [$secret, $enAttente['id']]);
                journal('u:' . $enAttente['id'], '2fa_activee');
                unset($_SESSION['totp_nouveau']);
            }
            Auth::finaliser((int) $enAttente['id']);
            View::rediriger('index.php');
        }
        $erreur = 'Code incorrect. Vérifiez l’heure de votre téléphone.';
    } elseif ($action === 'annuler') {
        Auth::deconnecter();
        View::rediriger('login.php');
    }
}

if ($etape === '2fa_config' && empty($_SESSION['totp_nouveau'])) {
    $_SESSION['totp_nouveau'] = Totp::nouveauSecret();
}

View::entete('Connexion');
?>
<div class="connexion">
  <h1>Connexion</h1>
  <?php if ($erreur): ?><div class="flash erreur"><?= e($erreur) ?></div><?php endif; ?>
  <div class="carte">
  <?php if ($etape === 'mdp'): ?>
    <form method="post">
      <?= Auth::champJeton() ?>
      <input type="hidden" name="action" value="mdp">
      <label for="email">Adresse électronique</label>
      <input type="email" id="email" name="email" required autofocus autocomplete="username">
      <label for="mdp">Mot de passe</label>
      <input type="password" id="mdp" name="mot_de_passe" required autocomplete="current-password">
      <div class="actions"><button class="btn principal">Se connecter</button></div>
    </form>
  <?php elseif ($etape === '2fa'): ?>
    <form method="post">
      <?= Auth::champJeton() ?>
      <input type="hidden" name="action" value="2fa">
      <p>Entrez le code à 6 chiffres affiché par votre application d’authentification.</p>
      <label for="code">Code</label>
      <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
      <div class="actions"><button class="btn principal">Valider</button></div>
    </form>
  <?php else: $secret = $_SESSION['totp_nouveau']; ?>
    <form method="post">
      <?= Auth::champJeton() ?>
      <input type="hidden" name="action" value="2fa">
      <p><strong>Première connexion : activez la double authentification.</strong></p>
      <p class="discret">Votre rôle donne accès à des données sensibles. Installez Google Authenticator, Microsoft Authenticator ou FreeOTP, ajoutez un compte « avec une clé » et saisissez :</p>
      <p class="code"><?= e(trim(chunk_split($secret, 4, ' '))) ?></p>
      <p class="discret">Type : basé sur l’heure. Lien direct sur téléphone : <a href="<?= e(Totp::uri($secret, $enAttente['email'])) ?>">ajouter à l’application</a>.</p>
      <label for="code">Code affiché par l’application</label>
      <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
      <div class="actions"><button class="btn principal">Activer et entrer</button></div>
    </form>
  <?php endif; ?>
  <?php if ($etape !== 'mdp'): ?>
    <form method="post" class="actions"><?= Auth::champJeton() ?><input type="hidden" name="action" value="annuler"><button class="btn petit">Changer de compte</button></form>
  <?php endif; ?>
  </div>
</div>
<?php View::pied();
