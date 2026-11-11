<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('file');

$issues = [
    'rendue_centre' => 'Elle se rend au centre ou y est allée',
    'conseil' => 'Rassurée et conseillée, pas d’urgence',
    'urgences' => 'Orientée vers les urgences',
    'injoignable' => 'Injoignable (nouvel essai prévu)',
    'fausse' => 'Fausse manipulation',
    'autre' => 'Autre (préciser)',
];
$numeroVisible = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) $u['id'];
    try {
        switch ($action) {
            case 'numero_alerte':
                $a = q1('SELECT a.*, p.pseudo FROM alertes a JOIN participantes p ON p.id = a.participante_id WHERE a.id = ?', [(int) $_POST['alerte_id']]);
                if ($a) {
                    $numeroVisible[(int) $a['id']] = Parcours::numero((int) $a['participante_id']);
                    journal(Auth::acteur(), 'acces_numero', ['participante' => $a['pseudo'], 'motif' => 'rappel alerte ' . $a['id']]);
                }
                break;
            case 'cloturer_alerte':
                $issue = (string) ($_POST['issue'] ?? '');
                if (!isset($issues[$issue])) {
                    throw new InvalidArgumentException('Choisissez l’issue de l’appel.');
                }
                $statut = $issue === 'injoignable' ? 'ouverte' : 'close';
                q(
                    'UPDATE alertes SET rappel_le = COALESCE(rappel_le, ?), issue = ?, note = ?, traite_par = ?, statut = ? WHERE id = ?',
                    [maintenant(), $issue, trim((string) ($_POST['note'] ?? '')), $uid, $statut, (int) $_POST['alerte_id']]
                );
                journal(Auth::acteur(), 'alerte_traitee', ['alerte' => (int) $_POST['alerte_id'], 'issue' => $issue]);
                View::flash('ok', $statut === 'close' ? 'Alerte close.' : 'Noté : l’alerte reste ouverte pour un nouvel essai.');
                break;
            case 'prendre_vocal':
                q("UPDATE vocaux SET statut = 'en_cours', pris_en_charge_le = COALESCE(pris_en_charge_le, ?), traite_par = ? WHERE id = ? AND statut = 'a_traiter'", [maintenant(), $uid, (int) $_POST['vocal_id']]);
                break;
            case 'traiter_vocal':
                $vid = (int) $_POST['vocal_id'];
                $v = q1('SELECT v.*, p.pseudo FROM vocaux v JOIN participantes p ON p.id = v.participante_id WHERE v.id = ?', [$vid]);
                if (!$v) {
                    throw new InvalidArgumentException('Message introuvable.');
                }
                if (!isset($_POST['alerte']) || !in_array($_POST['alerte'], ['0', '1'], true)) {
                    throw new InvalidArgumentException('Indiquez si le message évoque un signe de danger.');
                }
                $alerte = (int) $_POST['alerte'];
                q(
                    "UPDATE vocaux SET statut = 'traite', annotation_alerte = ?, transcription_humaine = ?, note = ?, traite_par = ?, pris_en_charge_le = COALESCE(pris_en_charge_le, ?) WHERE id = ?",
                    [$alerte, trim((string) ($_POST['transcription'] ?? '')) ?: null, trim((string) ($_POST['note'] ?? '')) ?: null, $uid, maintenant(), $vid]
                );
                if ($alerte && !qv('SELECT COUNT(*) FROM alertes WHERE vocal_id = ?', [$vid])) {
                    Parcours::danger(Parcours::participante((int) $v['participante_id']), 'vocal', $vid);
                    View::flash('erreur', 'Signe de danger : une alerte a été ouverte pour ' . $v['pseudo'] . '. Rappelez-la.');
                } else {
                    View::flash('ok', 'Message vocal traité.');
                }
                journal(Auth::acteur(), 'vocal_traite', ['vocal' => $vid, 'alerte' => $alerte]);
                break;
        }
    } catch (InvalidArgumentException $e) {
        View::flash('erreur', $e->getMessage());
    }
    if ($action !== 'numero_alerte') {
        View::rediriger('file.php');
    }
}

$alertes = q(
    "SELECT a.*, p.pseudo, p.profil, p.dpa, p.naissance_enfant, c.nom AS centre FROM alertes a
     JOIN participantes p ON p.id = a.participante_id LEFT JOIN centres c ON c.id = p.centre_id
     WHERE a.statut = 'ouverte' ORDER BY a.cree_le"
)->fetchAll();
$vocaux = q(
    "SELECT v.*, p.pseudo FROM vocaux v JOIN participantes p ON p.id = v.participante_id
     WHERE v.statut IN ('a_traiter', 'en_cours') ORDER BY v.priorite DESC, v.recu_le"
)->fetchAll();
$recents = q(
    "SELECT v.*, p.pseudo FROM vocaux v JOIN participantes p ON p.id = v.participante_id WHERE v.statut = 'traite' ORDER BY v.id DESC LIMIT 10"
)->fetchAll();

View::entete('File de traitement', $u);
?>
<h1>File de traitement</h1>
<p class="discret">Protocole : rappel de chaque alerte pendant les heures ouvrables, issue consignée, revue hebdomadaire par la directrice médicale. Tous les messages vocaux sont écoutés par une personne de l’équipe.</p>

<h2>Alertes ouvertes (<?= count($alertes) ?>)</h2>
<?php foreach ($alertes as $a): $attente = ts(maintenant()) - ts($a['cree_le']); ?>
<div class="carte" style="border-color:var(--rouge)">
  <p><strong><a href="participante.php?id=<?= (int) $a['participante_id'] ?>"><?= e($a['pseudo']) ?></a></strong>
    · <?= e(libelle_profil($a['profil'])) ?> · <?= e(Parcours::libelleStade($a)) ?> · <?= e($a['centre'] ?? '') ?>
    · alerte <?= e($a['origine'] === 'menu' ? 'déclenchée par la touche 2' : 'issue d’un message vocal') ?>
    · <span class="etiquette urgent">depuis <?= e(View::duree($attente)) ?></span>
    <?php if ($a['issue'] === 'injoignable'): ?> <span class="etiquette">déjà essayé : injoignable</span><?php endif; ?></p>
  <p class="discret">Consigne d’urgence envoyée : <?= e(View::date($a['consigne_envoyee_le'])) ?></p>
  <?php if (isset($numeroVisible[(int) $a['id']])): ?>
    <p>À appeler : <span class="numero">+<?= e($numeroVisible[(int) $a['id']]) ?></span></p>
  <?php else: ?>
    <form method="post"><?= Auth::champJeton() ?><input type="hidden" name="action" value="numero_alerte"><input type="hidden" name="alerte_id" value="<?= (int) $a['id'] ?>"><button class="btn">Afficher le numéro pour rappeler</button></form>
  <?php endif; ?>
  <form method="post">
    <?= Auth::champJeton() ?><input type="hidden" name="action" value="cloturer_alerte"><input type="hidden" name="alerte_id" value="<?= (int) $a['id'] ?>">
    <label for="issue<?= (int) $a['id'] ?>">Issue de l’appel</label>
    <select id="issue<?= (int) $a['id'] ?>" name="issue" required><option value="">Choisir…</option><?php foreach ($issues as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
    <label for="note<?= (int) $a['id'] ?>">Note <span class="aide">sans diagnostic ni détail inutile</span></label>
    <input type="text" id="note<?= (int) $a['id'] ?>" name="note">
    <div class="actions"><button class="btn principal">Enregistrer l’appel</button></div>
  </form>
</div>
<?php endforeach; ?>
<?php if (!$alertes): ?><div class="carte discret">Aucune alerte ouverte.</div><?php endif; ?>

<h2>Messages vocaux à écouter (<?= count($vocaux) ?>)</h2>
<?php foreach ($vocaux as $v): ?>
<div class="carte">
  <p><strong><a href="participante.php?id=<?= (int) $v['participante_id'] ?>"><?= e($v['pseudo']) ?></a></strong> · reçu le <?= e(View::date($v['recu_le'])) ?>
</p>
  <?php if ($v['fichier']): ?>
    <audio controls preload="none" src="audio.php?id=<?= (int) $v['id'] ?>"></audio>
  <?php else: ?>
    <p class="discret">Audio non disponible ici (mode manuel : écoutez-le sur le téléphone du service).</p>
  <?php endif; ?>
  <?php $qpv = q1('SELECT qp.*, q.code, q.intitule_fr FROM questions_posees qp LEFT JOIN questions q ON q.id = qp.question_id WHERE qp.vocal_id = ? ORDER BY qp.id DESC LIMIT 1', [$v['id']]); ?>
  <?php if ($qpv): ?>
    <p class="discret">Question vocale · <?= e(['sans_reponse' => 'pas de réponse automatique', 'refusee' => 'la femme a répondu « Non » à la reformulation', 'expiree' => 'pas de confirmation', 'danger' => 'signe de danger : consigne d’urgence déjà envoyée', 'en_analyse' => 'en cours d’analyse'][$qpv['statut']] ?? $qpv['statut']) ?><?= $qpv['motif'] ? ' (' . e($qpv['motif']) . ')' : '' ?>.
      <?php if ($qpv['transcription']): ?>Transcription : <span lang="dyu">« <?= e($qpv['transcription']) ?> »</span><?php endif; ?>
      <?php if ($qpv['code']): ?> · question la plus proche : <?= e($qpv['code']) ?> (<?= round(100 * (float) $qpv['score']) ?> %)<?php endif; ?>
      · la femme a été informée qu’une personne allait la rappeler.</p>
  <?php endif; ?>
  <?php if ($v['statut'] === 'a_traiter'): ?>
    <form method="post"><?= Auth::champJeton() ?><input type="hidden" name="action" value="prendre_vocal"><input type="hidden" name="vocal_id" value="<?= (int) $v['id'] ?>"><button class="btn petit">Je m’en occupe</button></form>
  <?php endif; ?>
  <form method="post">
    <?= Auth::champJeton() ?><input type="hidden" name="action" value="traiter_vocal"><input type="hidden" name="vocal_id" value="<?= (int) $v['id'] ?>">
    <label>Le message évoque-t-il un signe de danger ?</label>
    <div class="choix">
      <label><input type="radio" name="alerte" value="1" required> Oui : ouvrir une alerte</label>
      <label><input type="radio" name="alerte" value="0"> Non</label>
    </div>
    <?php if ($v['consent_ia']): ?>
    <label for="tr<?= (int) $v['id'] ?>">Transcription en dioula <span class="aide">facultatif, sert de référence pour mesurer l’IA</span></label>
    <textarea id="tr<?= (int) $v['id'] ?>" name="transcription" rows="2" lang="dyu"></textarea>
    <?php endif; ?>
    <label for="nv<?= (int) $v['id'] ?>">Suite donnée</label>
    <input type="text" id="nv<?= (int) $v['id'] ?>" name="note" placeholder="ex. question sur la prochaine consultation, rappelée">
    <div class="actions"><button class="btn principal">Marquer comme traité</button></div>
  </form>
</div>
<?php endforeach; ?>
<?php if (!$vocaux): ?><div class="carte discret">Aucun message vocal en attente.</div><?php endif; ?>

<?php if ($recents): ?>
<h2>Derniers messages traités</h2>
<div class="table-defile"><table>
  <tr><th>Participante</th><th>Reçu</th><th>Pris en charge</th><th>Signe de danger</th><th>IA (observation)</th></tr>
  <?php foreach ($recents as $v): ?>
  <tr><td><?= e($v['pseudo']) ?></td><td><?= e(View::date($v['recu_le'])) ?></td>
    <td><?= e(View::duree($v['pris_en_charge_le'] ? ts($v['pris_en_charge_le']) - ts($v['recu_le']) : null)) ?></td>
    <td><?= (int) $v['annotation_alerte'] ? 'oui' : 'non' ?></td>
    <td class="discret"><?= $v['ia_alerte'] === null ? '–' : ((int) $v['ia_alerte'] ? 'signalé' : 'non signalé') ?><?= $v['ia_transcription'] ? ' · « ' . e(mb_substr($v['ia_transcription'], 0, 80)) . ' »' : '' ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php View::pied();
