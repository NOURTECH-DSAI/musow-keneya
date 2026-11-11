<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('utilisateurs');

$motDePasseAffiche = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'creer':
                $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
                $role = (string) ($_POST['role'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(Auth::ROLES[$role]) || trim((string) $_POST['nom']) === '') {
                    throw new InvalidArgumentException('Nom, adresse et rôle obligatoires.');
                }
                if (qv('SELECT COUNT(*) FROM utilisateurs WHERE email = ?', [$email])) {
                    throw new InvalidArgumentException('Cette adresse a déjà un compte.');
                }
                $motDePasseAffiche = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'kM'), '=');
                q(
                    'INSERT INTO utilisateurs (email, nom, role, mot_de_passe, actif, cree_le) VALUES (?, ?, ?, ?, 1, ?)',
                    [$email, trim((string) $_POST['nom']), $role, password_hash($motDePasseAffiche, PASSWORD_DEFAULT), maintenant()]
                );
                journal(Auth::acteur(), 'utilisateur_cree', ['email' => $email, 'role' => $role]);
                break;
            case 'basculer':
                $id = (int) $_POST['uid'];
                if ($id === (int) $u['id']) {
                    throw new InvalidArgumentException('Vous ne pouvez pas désactiver votre propre compte.');
                }
                q('UPDATE utilisateurs SET actif = 1 - actif WHERE id = ?', [$id]);
                journal(Auth::acteur(), 'utilisateur_bascule', ['id' => $id]);
                break;
            case 'reinitialiser':
                $id = (int) $_POST['uid'];
                $motDePasseAffiche = rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'kM'), '=');
                q('UPDATE utilisateurs SET mot_de_passe = ?, totp_secret = NULL WHERE id = ?', [password_hash($motDePasseAffiche, PASSWORD_DEFAULT), $id]);
                journal(Auth::acteur(), 'utilisateur_reinitialise', ['id' => $id]);
                break;
            case 'centre':
                $nom = trim((string) ($_POST['nom_centre'] ?? ''));
                if ($nom === '') {
                    throw new InvalidArgumentException('Nom du centre obligatoire.');
                }
                q('INSERT INTO centres (nom, commune, telephone, actif) VALUES (?, ?, ?, 1)', [$nom, trim((string) $_POST['commune']), trim((string) $_POST['telephone'])]);
                journal(Auth::acteur(), 'centre_cree', ['nom' => $nom]);
                View::flash('ok', 'Centre ajouté.');
                View::rediriger('utilisateurs.php');
        }
    } catch (InvalidArgumentException $e) {
        View::flash('erreur', $e->getMessage());
        View::rediriger('utilisateurs.php');
    }
}

$liste = q('SELECT * FROM utilisateurs ORDER BY actif DESC, nom')->fetchAll();
$centres = q('SELECT * FROM centres ORDER BY nom')->fetchAll();
View::entete('Équipe', $u);
?>
<h1>Équipe et centres</h1>
<?php if ($motDePasseAffiche): ?>
  <div class="flash info">Mot de passe provisoire, affiché une seule fois : <span class="code"><?= e($motDePasseAffiche) ?></span>. Transmettez-le de vive voix ; la personne le changera dans « Compte ».</div>
<?php endif; ?>
<div class="table-defile"><table>
  <tr><th>Nom</th><th>Adresse</th><th>Rôle</th><th>2FA</th><th>Dernière connexion</th><th></th></tr>
  <?php foreach ($liste as $x): ?>
  <tr>
    <td><?= e($x['nom']) ?><?= $x['actif'] ? '' : ' <span class="etiquette">désactivé</span>' ?></td>
    <td><?= e($x['email']) ?></td>
    <td><?= e(Auth::ROLES[$x['role']] ?? $x['role']) ?></td>
    <td><?= in_array($x['role'], Auth::ROLES_2FA, true) ? ($x['totp_secret'] ? 'active' : 'à la 1re connexion') : 'non requise' ?></td>
    <td><?= e(View::date($x['derniere_connexion'])) ?></td>
    <td style="white-space:nowrap">
      <form method="post" style="display:inline"><?= Auth::champJeton() ?><input type="hidden" name="action" value="basculer"><input type="hidden" name="uid" value="<?= (int) $x['id'] ?>"><button class="btn petit"><?= $x['actif'] ? 'Désactiver' : 'Réactiver' ?></button></form>
      <form method="post" style="display:inline" data-confirmer="Réinitialiser le mot de passe et la double authentification ?"><?= Auth::champJeton() ?><input type="hidden" name="action" value="reinitialiser"><input type="hidden" name="uid" value="<?= (int) $x['id'] ?>"><button class="btn petit">Réinitialiser</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>

<div class="deux">
<form method="post" class="carte">
  <?= Auth::champJeton() ?><input type="hidden" name="action" value="creer">
  <h2>Ajouter un membre</h2>
  <label for="nom">Nom</label><input type="text" id="nom" name="nom" required>
  <label for="email">Adresse électronique</label><input type="email" id="email" name="email" required>
  <label for="role">Rôle</label>
  <select id="role" name="role"><?php foreach (Auth::ROLES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
  <p class="discret">La direction médicale est seule à pouvoir valider les messages. Administration et direction médicale utilisent la double authentification.</p>
  <div class="actions"><button class="btn principal">Créer le compte</button></div>
</form>

<div class="carte">
  <h2>Centres de santé</h2>
  <?php foreach ($centres as $c): ?><p><?= e($c['nom']) ?> · <?= e($c['commune']) ?> · <?= e($c['telephone']) ?></p><?php endforeach; ?>
  <form method="post">
    <?= Auth::champJeton() ?><input type="hidden" name="action" value="centre">
    <label for="nom_centre">Nom du centre</label><input type="text" id="nom_centre" name="nom_centre" required>
    <label for="commune">Commune</label><input type="text" id="commune" name="commune" value="Abobo">
    <label for="telephone">Téléphone transmis aux femmes</label><input type="tel" id="telephone" name="telephone">
    <div class="actions"><button class="btn">Ajouter le centre</button></div>
  </form>
</div>
</div>
<?php View::pied();
