<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('tableau');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $actuel = (string) ($_POST['actuel'] ?? '');
    $nouveau = (string) ($_POST['nouveau'] ?? '');
    if (!password_verify($actuel, $u['mot_de_passe'])) {
        View::flash('erreur', 'Mot de passe actuel incorrect.');
    } elseif (mb_strlen($nouveau) < 12 || $nouveau !== ($_POST['confirmation'] ?? '')) {
        View::flash('erreur', 'Le nouveau mot de passe doit faire au moins 12 caractères, saisi deux fois à l’identique.');
    } else {
        q('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?', [password_hash($nouveau, PASSWORD_DEFAULT), $u['id']]);
        journal(Auth::acteur(), 'mot_de_passe_change');
        View::flash('ok', 'Mot de passe changé.');
    }
    View::rediriger('compte.php');
}

View::entete('Mon compte', $u);
?>
<h1>Mon compte</h1>
<form method="post" class="carte" style="max-width:480px">
  <?= Auth::champJeton() ?>
  <p><?= e($u['nom']) ?> · <?= e($u['email']) ?> · <?= e(Auth::ROLES[$u['role']] ?? $u['role']) ?></p>
  <label for="actuel">Mot de passe actuel</label><input type="password" id="actuel" name="actuel" required autocomplete="current-password">
  <label for="nouveau">Nouveau mot de passe <span class="aide">12 caractères au moins</span></label><input type="password" id="nouveau" name="nouveau" required minlength="12" autocomplete="new-password">
  <label for="confirmation">Confirmation</label><input type="password" id="confirmation" name="confirmation" required minlength="12" autocomplete="new-password">
  <div class="actions"><button class="btn principal">Changer le mot de passe</button></div>
</form>
<?php View::pied();
