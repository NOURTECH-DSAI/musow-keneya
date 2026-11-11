<?php
/**
 * Questions posées par les femmes : vérification des réponses automatiques
 * et enrichissement de la bibliothèque (le service apprend sous contrôle médical).
 */
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('questions_recues');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = (string) ($_POST['action'] ?? '');
    $qp = q1('SELECT * FROM questions_posees WHERE id = ?', [(int) ($_POST['qp_id'] ?? 0)]);
    try {
        if (!$qp) {
            throw new InvalidArgumentException('Question introuvable.');
        }
        if ($action === 'verifier') {
            if (!Auth::peut('questions_verifier', $u)) {
                throw new InvalidArgumentException('Votre rôle ne permet pas de vérifier les réponses.');
            }
            $correct = ($_POST['correct'] ?? '') === '1' ? 1 : 0;
            q('UPDATE questions_posees SET verifie_correct = ?, verifie_par = ?, verifie_le = ? WHERE id = ?', [$correct, $u['id'], maintenant(), $qp['id']]);
            if ($correct) {
                q("UPDATE vocaux SET statut = 'traite', traite_par = ?, pris_en_charge_le = COALESCE(pris_en_charge_le, ?) WHERE id = ? AND statut = 'auto'", [$u['id'], maintenant(), $qp['vocal_id']]);
                View::flash('ok', 'Réponse confirmée comme correcte.');
            } else {
                // Réponse inexacte : la femme doit être rappelée.
                q("UPDATE vocaux SET statut = 'a_traiter', priorite = 1 WHERE id = ?", [$qp['vocal_id']]);
                View::flash('erreur', 'Réponse inexacte enregistrée : le message est remis en tête de la file de traitement pour rappeler la femme.');
            }
            journal(Auth::acteur(), 'reponse_verifiee', ['question_posee' => (int) $qp['id'], 'correct' => $correct]);
        } elseif ($action === 'rattacher') {
            if (!Auth::peut('questions_modifier', $u)) {
                throw new InvalidArgumentException('Votre rôle ne permet pas de modifier la bibliothèque.');
            }
            $texte = trim((string) ($_POST['texte'] ?? $qp['transcription'] ?? ''));
            $qid = (int) ($_POST['question_id'] ?? 0);
            if (!$qid || $texte === '') {
                throw new InvalidArgumentException('Choisissez la question et vérifiez le texte.');
            }
            if (Questions::ajouterVariante($qid, $texte, 'pilote', (int) $u['id'])) {
                journal(Auth::acteur(), 'variante_depuis_pilote', ['question' => $qid, 'question_posee' => (int) $qp['id']]);
                View::flash('ok', 'Formulation ajoutée : la prochaine fois, cette question sera reconnue.');
            } else {
                View::flash('info', 'Cette formulation existe déjà.');
            }
        }
    } catch (InvalidArgumentException $e) {
        View::flash('erreur', $e->getMessage());
    }
    View::rediriger('questions_recues.php?f=' . urlencode((string) ($_POST['f'] ?? 'a_verifier')));
}

$filtres = [
    'a_verifier' => ['Réponses à vérifier', "qp.statut = 'repondue' AND qp.verifie_correct IS NULL"],
    'sans_reponse' => ['Sans réponse', "qp.statut IN ('sans_reponse', 'refusee', 'expiree')"],
    'danger' => ['Signes de danger', "qp.statut = 'danger'"],
    'toutes' => ['Toutes', '1 = 1'],
];
$f = isset($filtres[$_GET['f'] ?? '']) ? $_GET['f'] : 'a_verifier';
$liste = q(
    'SELECT qp.*, p.pseudo, q1.code AS code1, q1.intitule_fr AS intitule1, q2.code AS code2, v.fichier
     FROM questions_posees qp JOIN participantes p ON p.id = qp.participante_id
     LEFT JOIN questions q1 ON q1.id = qp.question_id LEFT JOIN questions q2 ON q2.id = qp.second_id
     LEFT JOIN vocaux v ON v.id = qp.vocal_id
     WHERE ' . $filtres[$f][1] . ' ORDER BY qp.id DESC LIMIT 200'
)->fetchAll();
$toutes = q("SELECT id, code, intitule_fr FROM questions WHERE statut = 'actif' ORDER BY code")->fetchAll();
$ind = Questions::indicateurs();
$libStatut = [
    'en_analyse' => 'en analyse', 'a_confirmer' => 'attend la confirmation', 'repondue' => 'répondue', 'refusee' => 'mal comprise (« Non »)',
    'sans_reponse' => 'sans réponse', 'expiree' => 'pas de confirmation', 'danger' => 'signe de danger', 'ignoree' => 'ignorée', 'salutation' => 'salutation',
];

View::entete('Questions reçues', $u);
?>
<h1>Questions reçues</h1>
<div class="grille">
  <div class="kpi"><b><?= $ind['posees'] ?></b><span>questions analysées</span></div>
  <div class="kpi"><b><?= $ind['couverture'] === null ? '–' : round(100 * $ind['couverture']) . ' %' ?></b><span>réponse validée immédiate (<?= $ind['repondues'] ?>)</span></div>
  <div class="kpi"><b><?= $ind['sans_reponse'] + $ind['refusees'] ?></b><span>transmises à l’équipe</span></div>
  <div class="kpi <?= $ind['erreurs'] ? 'alerte' : '' ?>"><b><?= $ind['erreurs'] ?></b><span>réponses inexactes sur <?= $ind['verifiees'] ?> vérifiées</span></div>
  <div class="kpi"><b><?= View::duree($ind['delai_median_s']) ?></b><span>délai médian de réponse</span></div>
</div>
<p class="discret">Pendant le pilote, chaque réponse automatique est réécoutée par l’équipe : c’est ce qui permet de mesurer honnêtement le taux d’erreur. Chaque question sans réponse peut devenir une nouvelle formulation ou une nouvelle question.</p>

<div class="barre-filtres">
  <?php foreach ($filtres as $k => [$lib]): ?><a href="?f=<?= $k ?>" class="<?= $f === $k ? 'actif' : '' ?>"><?= e($lib) ?></a><?php endforeach; ?>
</div>

<?php foreach ($liste as $l): ?>
<div class="carte">
  <p><strong><?php if (Auth::peut('participantes', $u)): ?><a href="participante.php?id=<?= (int) $l['participante_id'] ?>"><?= e($l['pseudo']) ?></a><?php else: ?><?= e($l['pseudo']) ?><?php endif; ?></strong> · <?= e(View::date($l['cree_le'])) ?>
    · <span class="etiquette <?= $l['statut'] === 'danger' ? 'urgent' : ($l['statut'] === 'repondue' ? 'valide' : 'a_traiter') ?>"><?= e($libStatut[$l['statut']] ?? $l['statut']) ?></span>
    <?= $l['motif'] ? '<span class="discret">' . e($l['motif']) . '</span>' : '' ?>
    <?php if ($l['verifie_correct'] !== null): ?> · vérifiée : <?= (int) $l['verifie_correct'] ? 'correcte' : '<strong>inexacte</strong>' ?><?php endif; ?></p>
  <?php if ($l['fichier']): ?><audio controls preload="none" src="audio.php?id=<?= (int) $l['vocal_id'] ?>"></audio><?php endif; ?>
  <p>Transcription : <span lang="dyu">« <?= e($l['transcription'] ?? '…') ?> »</span>
    <?php if ($l['score_asr'] !== null): ?><span class="discret">(audio <?= round(100 * (float) $l['score_asr']) ?> %)</span><?php endif; ?></p>
  <?php if ($l['code1']): ?>
    <p class="discret">Question la plus proche : <a href="questions.php?id=<?= (int) $l['question_id'] ?>"><?= e($l['code1']) ?></a> · <?= e($l['intitule1']) ?> · <?= round(100 * (float) $l['score']) ?> %
      <?= $l['code2'] ? '· deuxième : ' . e($l['code2']) . ' ' . round(100 * (float) $l['second_score']) . ' %' : '' ?></p>
  <?php endif; ?>
  <?php if ($l['termes']): ?><p class="discret">Termes de danger : <?= e($l['termes']) ?></p><?php endif; ?>

  <?php if ($l['statut'] === 'repondue' && $l['verifie_correct'] === null && Auth::peut('questions_verifier', $u)): ?>
    <form method="post" class="actions">
      <?= Auth::champJeton() ?><input type="hidden" name="action" value="verifier"><input type="hidden" name="qp_id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>">
      <span>La réponse envoyée correspondait-elle à la question ?</span>
      <button class="btn petit" name="correct" value="1">Oui, correcte</button>
      <button class="btn petit danger" name="correct" value="0">Non, inexacte</button>
    </form>
  <?php endif; ?>

  <?php if (in_array($l['statut'], ['sans_reponse', 'refusee', 'expiree'], true) && $l['transcription'] && Auth::peut('questions_modifier', $u)): ?>
    <form method="post">
      <?= Auth::champJeton() ?><input type="hidden" name="action" value="rattacher"><input type="hidden" name="qp_id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>">
      <div class="deux">
        <div><label>Formulation à ajouter <span class="aide">corrigez la transcription si besoin</span></label><input type="text" name="texte" lang="dyu" value="<?= e($l['transcription']) ?>"></div>
        <div><label>Question correspondante</label>
          <select name="question_id"><option value="">Choisir…</option><?php foreach ($toutes as $qq): ?><option value="<?= (int) $qq['id'] ?>" <?= (int) $qq['id'] === (int) $l['question_id'] && (float) $l['score'] >= 0.3 ? 'selected' : '' ?>><?= e($qq['code'] . ' · ' . mb_strimwidth($qq['intitule_fr'], 0, 70, '…')) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="actions">
        <button class="btn">Ajouter à cette question</button>
        <a class="btn" href="questions.php?nouvelle=1&amp;depuis=<?= (int) $l['id'] ?>&amp;texte=<?= urlencode((string) $l['transcription']) ?>">Créer une nouvelle question</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$liste): ?><div class="carte discret">Rien dans cette rubrique.</div><?php endif; ?>
<?php View::pied();
