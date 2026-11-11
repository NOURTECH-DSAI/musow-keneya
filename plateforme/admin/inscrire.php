<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('inscrire');

$centres = q('SELECT id, nom FROM centres WHERE actif = 1 ORDER BY nom')->fetchAll();
$valeurs = $_POST;
$erreur = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    try {
        $r = Parcours::inscrire([
            'numero' => (string) ($_POST['numero'] ?? ''),
            'profil' => (string) ($_POST['profil'] ?? ''),
            'dpa' => $_POST['dpa'] ?? null,
            'semaine' => ($_POST['semaine'] ?? '') !== '' ? (int) $_POST['semaine'] : null,
            'naissance_enfant' => $_POST['naissance_enfant'] ?? null,
            'centre_id' => ($_POST['centre_id'] ?? '') !== '' ? (int) $_POST['centre_id'] : null,
            'consent_service' => !empty($_POST['consent_service']),
            'consent_ia' => !empty($_POST['consent_ia']),
            'rdv_type' => $_POST['rdv_type'] ?? null,
            'rdv_date' => $_POST['rdv_date'] ?? null,
        ], (int) $u['id']);
        View::flash('ok', 'Inscription enregistrée : ' . $r['pseudo'] . '. Demandez-lui d’envoyer « bonjour » au numéro du service : le message de bienvenue partira aussitôt.');
        View::rediriger('participante.php?id=' . $r['id']);
    } catch (InvalidArgumentException $e) {
        $erreur = $e->getMessage();
    }
}

$v = static fn(string $k): string => e($valeurs[$k] ?? '');
View::entete('Inscrire une participante', $u);
?>
<h1>Inscrire une participante</h1>
<?php if ($erreur): ?><div class="flash erreur"><?= e($erreur) ?></div><?php endif; ?>
<form method="post" class="carte" autocomplete="off">
  <?= Auth::champJeton() ?>
  <p class="discret">Avant de remplir : lisez la notice d’information en dioula à la femme, seule, et répondez à ses questions.</p>

  <label for="numero">Numéro WhatsApp de la femme</label>
  <input type="tel" id="numero" name="numero" value="<?= $v('numero') ?>" placeholder="07 01 02 03 04" required>
  <span class="aide">Le numéro est chiffré dès l’enregistrement. Vérifiez qu’elle a accès à ce téléphone.</span>

  <label>Profil</label>
  <div class="choix">
    <label><input type="radio" name="profil" value="enceinte" <?= ($valeurs['profil'] ?? 'enceinte') === 'enceinte' ? 'checked' : '' ?>> Enceinte</label>
    <label><input type="radio" name="profil" value="mere" <?= ($valeurs['profil'] ?? '') === 'mere' ? 'checked' : '' ?>> Mère d’un enfant de moins d’un an</label>
  </div>

  <div class="deux">
    <div>
      <label for="dpa">Si enceinte : date prévue d’accouchement</label>
      <input type="date" id="dpa" name="dpa" value="<?= $v('dpa') ?>">
    </div>
    <div>
      <label for="semaine">ou semaine de grossesse actuelle</label>
      <input type="number" id="semaine" name="semaine" min="4" max="42" value="<?= $v('semaine') ?>">
    </div>
  </div>
  <label for="naissance_enfant">Si mère : date de naissance de l’enfant</label>
  <input type="date" id="naissance_enfant" name="naissance_enfant" value="<?= $v('naissance_enfant') ?>">

  <label for="centre_id">Centre de santé</label>
  <select id="centre_id" name="centre_id">
    <?php foreach ($centres as $c): ?>
      <option value="<?= (int) $c['id'] ?>" <?= ($valeurs['centre_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option>
    <?php endforeach; ?>
  </select>

  <div class="deux">
    <div>
      <label for="rdv_date">Prochain rendez-vous fixé</label>
      <input type="date" id="rdv_date" name="rdv_date" value="<?= $v('rdv_date') ?>" min="<?= e(aujourdhui()) ?>">
    </div>
    <div>
      <label for="rdv_type">Type de rendez-vous</label>
      <select id="rdv_type" name="rdv_type">
        <?php foreach (['prenatal' => 'Consultation prénatale', 'postnatal' => 'Consultation postnatale', 'vaccination' => 'Vaccination', 'autre' => 'Autre'] as $k => $lib): ?>
          <option value="<?= $k ?>" <?= ($valeurs['rdv_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($lib) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <h2>Consentements</h2>
  <label class="case"><input type="checkbox" name="consent_service" value="1" required <?= !empty($valeurs['consent_service']) ? 'checked' : '' ?>>
    <span>La notice a été lue et la femme accepte de recevoir les messages du service. Elle sait que ses questions vocales sont transcrites par un ordinateur pour trouver une réponse validée par une médecin, qu’une personne de l’équipe peut les écouter, et qu’elle peut arrêter à tout moment en envoyant 9 ou « stop ».</span></label>
  <label class="case"><input type="checkbox" name="consent_ia" value="1" <?= !empty($valeurs['consent_ia']) ? 'checked' : '' ?>>
    <span>Facultatif : elle accepte que ses messages vocaux soient conservés, sans son nom ni son numéro, pour entraîner la reconnaissance du dioula. Si elle refuse, elle reçoit exactement le même service.</span></label>

  <div class="actions"><button class="btn principal">Inscrire</button></div>
</form>
<?php View::pied();
