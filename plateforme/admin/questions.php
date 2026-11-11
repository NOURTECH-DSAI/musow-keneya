<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('questions_voir');
$peutModifier = Auth::peut('questions_modifier', $u);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id && !empty($_GET['code'])) {
    $id = (int) qv('SELECT id FROM questions WHERE code = ?', [strtoupper((string) $_GET['code'])]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) $u['id'];
    try {
        if (!$peutModifier && $action !== 'tester') {
            throw new InvalidArgumentException('Votre rôle ne permet pas de modifier les questions.');
        }
        switch ($action) {
            case 'creer':
                $id = Questions::creer((string) $_POST['code'], (string) $_POST['theme'], (string) $_POST['intitule'], !empty($_POST['alerte']), (string) ($_POST['profil'] ?? 'toutes'), $uid);
                if (!empty($_POST['variantes'])) {
                    foreach (preg_split('/\R/u', (string) $_POST['variantes']) ?: [] as $ligne) {
                        Questions::ajouterVariante($id, $ligne, 'linguiste', $uid);
                    }
                }
                if (!empty($_POST['depuis_vocal'])) {
                    journal(Auth::acteur(), 'question_creee_depuis_pilote', ['question' => $id, 'question_posee' => (int) $_POST['depuis_vocal']]);
                }
                View::flash('ok', 'Question créée. Ses fiches de confirmation et de réponse attendent maintenant leur texte, leur traduction, leur enregistrement et la validation médicale.');
                break;
            case 'modifier':
                $alerte = !empty($_POST['alerte']) ? 1 : 0;
                q(
                    'UPDATE questions SET intitule_fr = ?, theme = ?, profil = ?, alerte = ?, statut = ?, modifie_le = ? WHERE id = ?',
                    [
                        trim((string) $_POST['intitule']),
                        isset(Questions::THEMES[$_POST['theme'] ?? '']) ? $_POST['theme'] : 'autre',
                        in_array($_POST['profil'] ?? '', ['enceinte', 'mere', 'toutes'], true) ? $_POST['profil'] : 'toutes',
                        $alerte,
                        ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif',
                        maintenant(), $id,
                    ]
                );
                journal(Auth::acteur(), 'question_modifiee', ['question' => $id, 'alerte' => $alerte]);
                View::flash('ok', 'Question enregistrée.');
                break;
            case 'variantes':
                $n = 0;
                foreach (preg_split('/\R/u', (string) ($_POST['variantes'] ?? '')) ?: [] as $ligne) {
                    $n += Questions::ajouterVariante($id, $ligne, 'linguiste', $uid) ? 1 : 0;
                }
                journal(Auth::acteur(), 'variantes_ajoutees', ['question' => $id, 'n' => $n]);
                View::flash('ok', $n . ' formulation(s) ajoutée(s).');
                break;
            case 'supprimer_variante':
                q('DELETE FROM variantes WHERE id = ? AND question_id = ?', [(int) $_POST['variante_id'], $id]);
                journal(Auth::acteur(), 'variante_supprimee', ['question' => $id, 'variante' => (int) $_POST['variante_id']]);
                break;
            case 'import_questions':
            case 'import_variantes':
                if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
                    throw new InvalidArgumentException('Choisissez un fichier CSV.');
                }
                if ($action === 'import_questions') {
                    $r = Questions::importerQuestions($_FILES['csv']['tmp_name'], $uid);
                    View::flash('ok', $r['crees'] . ' question(s) créée(s), ' . $r['ignores'] . ' ignorée(s) (code existant ou ligne incomplète).');
                } else {
                    $r = Questions::importerVariantes($_FILES['csv']['tmp_name'], $uid);
                    View::flash('ok', $r['ajoutees'] . ' formulation(s) ajoutée(s), ' . $r['ignorees'] . ' ignorée(s) (code inconnu ou doublon).');
                }
                journal(Auth::acteur(), $action, $r);
                $id = 0;
                break;
            case 'tester':
                $_SESSION['test_question'] = [
                    'texte' => (string) $_POST['texte'],
                    'profil' => (string) ($_POST['profil'] ?? ''),
                ];
                $id = 0;
                break;
        }
    } catch (InvalidArgumentException $e) {
        View::flash('erreur', $e->getMessage());
    }
    View::rediriger('questions.php' . ($id ? '?id=' . $id : ''));
}

$libStatut = ['brouillon' => 'brouillon', 'valide' => 'validé', 'remplace' => 'remplacé', 'retire' => 'retiré'];
$ficheEtat = static function (string $code): array {
    $derniere = q1('SELECT id, statut, version, fichier_audio FROM messages WHERE code = ? ORDER BY version DESC LIMIT 1', [$code]);
    $valide = qv("SELECT COUNT(*) FROM messages WHERE code = ? AND statut = 'valide' AND fichier_audio IS NOT NULL", [$code]);
    return ['derniere' => $derniere, 'diffusable' => (bool) $valide];
};

// ---------------------------------------------------------------- fiche
if ($id) {
    $qq = q1('SELECT * FROM questions WHERE id = ?', [$id]);
    if (!$qq) {
        View::rediriger('questions.php');
    }
    $variantes = q('SELECT v.*, u.nom FROM variantes v LEFT JOIN utilisateurs u ON u.id = v.cree_par WHERE v.question_id = ? ORDER BY v.id', [$id])->fetchAll();
    $conf = $ficheEtat('FAQ-' . $qq['code'] . '-C');
    $rep = $ficheEtat('FAQ-' . $qq['code'] . '-R');
    $posees = (int) qv("SELECT COUNT(*) FROM questions_posees WHERE question_id = ? AND statut IN ('repondue', 'a_confirmer', 'refusee', 'expiree')", [$id]);
    $repondues = (int) qv("SELECT COUNT(*) FROM questions_posees WHERE question_id = ? AND statut = 'repondue'", [$id]);
    $ro = $peutModifier ? '' : 'disabled';
    View::entete($qq['code'], $u);
    ?>
    <p><a href="questions.php">← Questions fréquentes</a></p>
    <h1><?= e($qq['code']) ?> · <?= e($qq['intitule_fr']) ?>
      <?php if ($qq['alerte']): ?><span class="etiquette urgent">signe de danger</span><?php endif; ?>
      <span class="etiquette <?= Questions::prete($qq) ? 'valide' : 'brouillon' ?>"><?= Questions::prete($qq) ? 'utilisée par le service' : 'incomplète' ?></span></h1>
    <p class="discret">Reconnue <?= $posees ?> fois, <?= $repondues ?> réponse(s) envoyée(s).</p>

    <?php if ($qq['alerte']): ?>
      <div class="flash info">Question de danger : quand le service la reconnaît, il n’envoie ni confirmation ni réponse propre, mais immédiatement la consigne d’urgence (signes de danger et numéros), et ouvre une alerte pour que l’équipe rappelle la femme.</div>
    <?php endif; ?>

    <div class="deux">
      <div>
        <form method="post" class="carte">
          <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="modifier">
          <strong>Question</strong>
          <label for="intitule">Intitulé en français</label><input type="text" id="intitule" name="intitule" value="<?= e($qq['intitule_fr']) ?>" <?= $ro ?>>
          <div class="deux">
            <div><label for="theme">Thème</label><select id="theme" name="theme" <?= $ro ?>><?php foreach (Questions::THEMES as $k => $l): ?><option value="<?= $k ?>" <?= $qq['theme'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div><label for="profil">Concerne</label><select id="profil" name="profil" <?= $ro ?>><?php foreach (['toutes', 'enceinte', 'mere'] as $pr): ?><option value="<?= $pr ?>" <?= $qq['profil'] === $pr ? 'selected' : '' ?>><?= e(libelle_profil($pr)) ?></option><?php endforeach; ?></select></div>
          </div>
          <label class="case"><input type="checkbox" name="alerte" value="1" <?= $qq['alerte'] ? 'checked' : '' ?> <?= $ro ?>><span>Signe de danger : déclenche la consigne d’urgence et une alerte</span></label>
          <label for="statut">Statut</label><select id="statut" name="statut" <?= $ro ?>><option value="actif" <?= $qq['statut'] === 'actif' ? 'selected' : '' ?>>Active</option><option value="inactif" <?= $qq['statut'] === 'inactif' ? 'selected' : '' ?>>Désactivée</option></select>
          <?php if ($peutModifier): ?><div class="actions"><button class="btn">Enregistrer</button></div><?php endif; ?>
        </form>

        <?php if (!$qq['alerte']): ?>
        <div class="carte">
          <strong>Audios (circuit de validation médicale)</strong>
          <?php foreach (['1. Confirmation « Vous voulez savoir… ? »' => $conf, '2. Réponse' => $rep] as $lib => $f): $m = $f['derniere']; ?>
            <p><?= e($lib) ?> :
              <?php if ($m): ?><a href="messages.php?id=<?= (int) $m['id'] ?>">fiche v<?= (int) $m['version'] ?></a> <span class="etiquette <?= e($m['statut']) ?>"><?= e($libStatut[$m['statut']] ?? $m['statut']) ?></span>
                <?= $m['fichier_audio'] ? '· audio ✓' : '· audio à enregistrer' ?><?= $f['diffusable'] ? '' : ' · <strong>pas encore diffusable</strong>' ?>
              <?php else: ?><span class="discret">fiche absente</span><?php endif; ?></p>
          <?php endforeach; ?>
          <p class="discret">Texte source, traduction en dioula, contre-traduction, enregistrement, puis validation par Dr FOFANA : le même circuit que tous les messages.</p>
        </div>
        <?php endif; ?>
      </div>

      <div>
        <div class="carte">
          <strong>Formulations en dioula (<?= count($variantes) ?>)</strong>
          <p class="discret">Plus il y a de façons différentes de poser la question, mieux le service la reconnaît. Ajoutez celles des linguistes et celles réellement entendues pendant le pilote.</p>
          <div class="table-defile"><table>
            <?php foreach ($variantes as $v): ?>
            <tr><td lang="dyu"><?= e($v['texte']) ?></td><td class="discret"><?= e($v['source']) ?></td>
              <td><?php if ($peutModifier): ?><form method="post" data-confirmer="Supprimer cette formulation ?"><?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="supprimer_variante"><input type="hidden" name="variante_id" value="<?= (int) $v['id'] ?>"><button class="btn petit">×</button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$variantes): ?><tr><td class="discret">Aucune formulation : la question ne peut pas encore être reconnue.</td></tr><?php endif; ?>
          </table></div>
          <?php if ($peutModifier): ?>
          <form method="post">
            <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="variantes">
            <label for="variantes">Nouvelles formulations <span class="aide">une par ligne</span></label>
            <textarea id="variantes" name="variantes" rows="4" lang="dyu"></textarea>
            <div class="actions"><button class="btn principal">Ajouter</button></div>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    View::pied();
    exit;
}

// ---------------------------------------------------------------- liste
$filtre = (string) ($_GET['f'] ?? 'toutes');
$liste = q(
    "SELECT q.*, (SELECT COUNT(*) FROM variantes v WHERE v.question_id = q.id) AS nb_variantes,
        (SELECT COUNT(*) FROM questions_posees p WHERE p.question_id = q.id AND p.statut = 'repondue') AS nb_repondues
     FROM questions q ORDER BY q.theme, q.code"
)->fetchAll();
$ind = Questions::indicateurs();
$test = $_SESSION['test_question'] ?? null;
unset($_SESSION['test_question']);

View::entete('Questions fréquentes', $u);
?>
<h1>Questions fréquentes</h1>
<p class="discret">Le service ne répond qu’aux questions de cette bibliothèque, et seulement quand elles sont complètes : formulations en dioula, audios de confirmation et de réponse validés par la direction médicale. Sinon, la question part à l’équipe.</p>

<div class="grille">
  <div class="kpi"><b><?= $ind['pretes'] ?>/<?= $ind['bibliotheque'] ?></b><span>questions utilisées par le service</span></div>
  <div class="kpi"><b><?= $ind['variantes'] ?></b><span>formulations en dioula</span></div>
  <div class="kpi"><b><?= $ind['couverture'] === null ? '–' : round(100 * $ind['couverture']) . ' %' ?></b><span>questions reçues ayant obtenu une réponse validée</span></div>
  <div class="kpi"><b><?= $ind['taux_erreur'] === null ? '–' : round(100 * $ind['taux_erreur'], 1) . ' %' ?></b><span>réponses jugées inexactes (<?= $ind['verifiees'] ?> vérifiées)</span></div>
</div>

<div class="deux">
  <form method="post" class="carte">
    <?= Auth::champJeton() ?><input type="hidden" name="action" value="tester">
    <strong>Tester la reconnaissance</strong>
    <p class="discret">Écrivez une question en dioula comme le ferait la transcription automatique.</p>
    <input type="text" name="texte" lang="dyu" required value="<?= e($test['texte'] ?? '') ?>">
    <select name="profil" style="margin-top:8px"><option value="">Tous profils</option><option value="enceinte" <?= ($test['profil'] ?? '') === 'enceinte' ? 'selected' : '' ?>>Enceinte</option><option value="mere" <?= ($test['profil'] ?? '') === 'mere' ? 'selected' : '' ?>>Mère</option></select>
    <div class="actions"><button class="btn">Tester</button></div>
    <?php if ($test):
        $cands = Questions::apparier($test['texte'], in_array($test['profil'], ['enceinte', 'mere'], true) ? $test['profil'] : null, 3);
        $danger = Questions::termesDanger($test['texte']);
        $seuil = (float) cfg('questions.seuil_reconnaissance', 0.55); ?>
      <?php if ($danger): ?><p class="flash erreur">Signe de danger repéré par le lexique : <?= e(implode(', ', $danger)) ?>. Consigne d’urgence.</p><?php endif; ?>
      <?php foreach ($cands as $i => $c): ?>
        <p><?= $i === 0 ? '<strong>' : '' ?><?= e($c['code']) ?> · <?= e($c['intitule']) ?> · <?= round(100 * $c['score']) ?> %<?= $i === 0 ? '</strong>' : '' ?></p>
      <?php endforeach; ?>
      <p class="discret">Décision : <?= !$cands || $cands[0]['score'] < $seuil ? 'pas de réponse, transfert à l’équipe' : ((isset($cands[1]) && $cands[0]['score'] - $cands[1]['score'] < (float) cfg('questions.marge', 0.06)) ? 'deux questions possibles, transfert à l’équipe' : 'confirmation de ' . e($cands[0]['code'])) ?> (seuil <?= round(100 * $seuil) ?> %).</p>
    <?php endif; ?>
  </form>

  <?php if ($peutModifier): ?>
  <div class="carte">
    <strong>Importer</strong>
    <form method="post" enctype="multipart/form-data">
      <?= Auth::champJeton() ?><input type="hidden" name="action" value="import_variantes">
      <label for="csvv">Formulations <span class="aide">CSV : code;variante</span></label>
      <input type="file" id="csvv" name="csv" accept=".csv,text/csv" required>
      <div class="actions"><button class="btn">Importer les formulations</button></div>
    </form>
    <form method="post" enctype="multipart/form-data">
      <?= Auth::champJeton() ?><input type="hidden" name="action" value="import_questions">
      <label for="csvq">Questions <span class="aide">CSV : code;theme;intitule_fr;alerte;profil</span></label>
      <input type="file" id="csvq" name="csv" accept=".csv,text/csv" required>
      <div class="actions"><button class="btn">Importer les questions</button></div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($peutModifier): ?>
<details class="carte" <?= !empty($_GET['nouvelle']) ? 'open' : '' ?>>
  <summary><strong>Nouvelle question</strong></summary>
  <form method="post">
    <?= Auth::champJeton() ?><input type="hidden" name="action" value="creer">
    <input type="hidden" name="depuis_vocal" value="<?= (int) ($_GET['depuis'] ?? 0) ?>">
    <div class="deux">
      <div><label for="code">Code</label><input type="text" id="code" name="code" placeholder="Q117" required></div>
      <div><label for="theme_n">Thème</label><select id="theme_n" name="theme"><?php foreach (Questions::THEMES as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <label for="intitule_n">Intitulé en français</label><input type="text" id="intitule_n" name="intitule" required>
    <label for="profil_n">Concerne</label><select id="profil_n" name="profil"><option value="toutes">Toutes</option><option value="enceinte">Enceinte</option><option value="mere">Mère</option></select>
    <label class="case"><input type="checkbox" name="alerte" value="1"><span>Signe de danger</span></label>
    <label for="var_n">Formulations en dioula <span class="aide">une par ligne</span></label>
    <textarea id="var_n" name="variantes" rows="3" lang="dyu"><?= e((string) ($_GET['texte'] ?? '')) ?></textarea>
    <div class="actions"><button class="btn principal">Créer la question</button></div>
  </form>
</details>
<?php endif; ?>

<div class="barre-filtres">
  <?php foreach (['toutes' => 'Toutes', 'pretes' => 'Utilisées par le service', 'incompletes' => 'Incomplètes', 'danger' => 'Signes de danger'] as $k => $lib): ?>
    <a href="?f=<?= $k ?>" class="<?= $filtre === $k ? 'actif' : '' ?>"><?= e($lib) ?></a>
  <?php endforeach; ?>
</div>
<?php $theme = null; foreach ($liste as $qq):
    $prete = Questions::prete($qq);
    if (($filtre === 'pretes' && !$prete) || ($filtre === 'incompletes' && $prete) || ($filtre === 'danger' && !$qq['alerte'])) continue;
    if ($qq['theme'] !== $theme):
        if ($theme !== null) echo '</table></div>';
        $theme = $qq['theme']; ?>
  <h2><?= e(Questions::THEMES[$theme] ?? $theme) ?></h2>
  <div class="table-defile"><table>
  <tr><th>Code</th><th>Question</th><th>Formulations</th><th>Audios</th><th>Réponses</th><th>État</th></tr>
<?php endif;
    $f = $qq['alerte'] ? null : Questions::fiches($qq); ?>
  <tr>
    <td><a href="?id=<?= (int) $qq['id'] ?>"><?= e($qq['code']) ?></a></td>
    <td><?= e($qq['intitule_fr']) ?><?php if ($qq['alerte']): ?> <span class="etiquette urgent">danger</span><?php endif; ?></td>
    <td><?= (int) $qq['nb_variantes'] ?></td>
    <td class="discret"><?= $qq['alerte'] ? 'consigne d’urgence' : (($f['confirmation'] ? '✓' : '–') . ' confirmation · ' . ($f['reponse'] ? '✓' : '–') . ' réponse') ?></td>
    <td><?= (int) $qq['nb_repondues'] ?></td>
    <td><span class="etiquette <?= $prete ? 'valide' : ($qq['statut'] === 'inactif' ? '' : 'brouillon') ?>"><?= $prete ? 'utilisée' : ($qq['statut'] === 'inactif' ? 'désactivée' : 'incomplète') ?></span></td>
  </tr>
<?php endforeach; if ($theme !== null) echo '</table></div>'; ?>
<?php if (!$liste): ?><div class="carte discret">Aucune question. Importez la liste initiale (contenus/questions_initiales.csv).</div><?php endif; ?>
<p><a href="lexique.php">Lexique des signes de danger →</a></p>
<?php View::pied();
