<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('participantes');

$pid = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$p = q1('SELECT * FROM participantes WHERE id = ?', [$pid]);
if (!$p) {
    View::flash('erreur', 'Participante introuvable.');
    View::rediriger('participantes.php');
}

$numeroVisible = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) $u['id'];
    try {
        switch ($action) {
            case 'voir_numero':
                if (!Auth::peut('voir_numero', $u)) {
                    throw new InvalidArgumentException('Accès refusé.');
                }
                $numeroVisible = Parcours::numero($pid);
                journal(Auth::acteur(), 'acces_numero', ['participante' => $p['pseudo'], 'motif' => mb_substr((string) ($_POST['motif'] ?? ''), 0, 120)]);
                break;
            case 'rdv':
                if (!Auth::peut('actions_parcours', $u)) {
                    throw new InvalidArgumentException('Accès refusé.');
                }
                $date = (string) ($_POST['date_rdv'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < aujourdhui()) {
                    throw new InvalidArgumentException('Date de rendez-vous invalide.');
                }
                Parcours::ajouterRdv($pid, (string) ($_POST['type'] ?? 'autre'), $date, $uid);
                journal(Auth::acteur(), 'rdv_ajoute', ['participante' => $p['pseudo'], 'date' => $date]);
                View::flash('ok', 'Rendez-vous ajouté. Les rappels partiront à J-2 et J-1.');
                break;
            case 'honore':
                if (!Auth::peut('actions_parcours', $u)) {
                    throw new InvalidArgumentException('Accès refusé.');
                }
                q('UPDATE rendez_vous SET honore = ? WHERE id = ? AND participante_id = ?', [(int) ($_POST['honore'] ?? 0), (int) $_POST['rdv_id'], $pid]);
                journal(Auth::acteur(), 'rdv_honore', ['participante' => $p['pseudo'], 'rdv' => (int) $_POST['rdv_id'], 'honore' => (int) ($_POST['honore'] ?? 0)]);
                View::flash('ok', 'Présence enregistrée.');
                break;
            case 'naissance':
                if (!Auth::peut('actions_parcours', $u)) {
                    throw new InvalidArgumentException('Accès refusé.');
                }
                Parcours::declarerNaissance($pid, (string) ($_POST['date'] ?? ''), $uid);
                View::flash('ok', 'Naissance enregistrée : le parcours « mère » commence.');
                break;
            case 'perte':
                if (!Auth::peut('declarer_perte', $u) || empty($_POST['confirmer'])) {
                    throw new InvalidArgumentException('Cochez la confirmation pour continuer.');
                }
                Parcours::declarerPerte($pid, $uid);
                View::flash('ok', 'Enregistré. Un message de soutien unique lui est proposé, puis plus aucun message.');
                break;
            case 'arreter':
                if (!Auth::peut('actions_parcours', $u)) {
                    throw new InvalidArgumentException('Accès refusé.');
                }
                Parcours::arreter($pid, 'equipe', $uid);
                View::flash('ok', 'Le service est arrêté pour cette participante.');
                break;
            case 'reprendre':
                if (!Auth::peut('actions_parcours', $u) || $p['statut'] !== 'stoppee') {
                    throw new InvalidArgumentException('Action impossible.');
                }
                q("UPDATE participantes SET statut = 'active', arretee_le = NULL, motif_fin = NULL WHERE id = ?", [$pid]);
                journal(Auth::acteur(), 'reprise', ['participante' => $p['pseudo']]);
                View::flash('ok', 'Service repris, à sa demande.');
                break;
            case 'numero':
                if (!Auth::peut('actions_parcours', $u)) {
                    throw new InvalidArgumentException('Accès refusé.');
                }
                Parcours::changerNumero($pid, (string) ($_POST['nouveau'] ?? ''), $uid);
                View::flash('ok', 'Nouveau numéro enregistré. Demandez-lui d’envoyer « bonjour » depuis ce numéro.');
                break;
            case 'bienvenue':
                if (!Auth::peut('actions_parcours', $u)) {
                    throw new InvalidArgumentException('Accès refusé.');
                }
                Parcours::accueillir($pid);
                View::flash('ok', 'Message de bienvenue proposé à nouveau.');
                break;
        }
    } catch (InvalidArgumentException $e) {
        View::flash('erreur', $e->getMessage());
    }
    if ($action !== 'voir_numero') {
        View::rediriger('participante.php?id=' . $pid);
    }
    $p = q1('SELECT * FROM participantes WHERE id = ?', [$pid]);
}

$centre = $p['centre_id'] ? q1('SELECT * FROM centres WHERE id = ?', [$p['centre_id']]) : null;
$rdvs = q('SELECT * FROM rendez_vous WHERE participante_id = ? ORDER BY date_rdv DESC', [$pid])->fetchAll();
$envois = q(
    'SELECT e.*, m.titre FROM envois e LEFT JOIN messages m ON m.id = e.message_id WHERE e.participante_id = ? ORDER BY e.id DESC LIMIT 40',
    [$pid]
)->fetchAll();
$entrants = q('SELECT * FROM entrants WHERE participante_id = ? ORDER BY id DESC LIMIT 20', [$pid])->fetchAll();
$alertes = q('SELECT * FROM alertes WHERE participante_id = ? ORDER BY id DESC', [$pid])->fetchAll();
$peutAgir = Auth::peut('actions_parcours', $u) && $p['statut'] === 'active';

$libRdv = ['prenatal' => 'Prénatale', 'postnatal' => 'Postnatale', 'vaccination' => 'Vaccination', 'autre' => 'Autre'];
$libStatutEnvoi = ['a_envoyer' => 'en cours', 'a_faire' => 'à faire (manuel)', 'envoye' => 'envoyé', 'livre' => 'livré', 'lu' => 'ouvert', 'echec' => 'échec', 'annule' => 'annulé'];

View::entete($p['pseudo'], $u);
?>
<h1><?= e($p['pseudo']) ?> <span class="etiquette <?= e($p['statut']) ?>"><?= e(libelle_statut($p['statut'])) ?></span></h1>

<div class="deux">
  <div class="carte">
    <p><strong><?= e(libelle_profil($p['profil'])) ?></strong> · <?= e(Parcours::libelleStade($p)) ?>
      <?php if ($p['dpa']): ?> · accouchement prévu le <?= e(View::date($p['dpa'], false)) ?><?php endif; ?>
      <?php if ($p['naissance_enfant']): ?> · enfant né le <?= e(View::date($p['naissance_enfant'], false)) ?><?php endif; ?></p>
    <p class="discret">Centre : <?= e($centre['nom'] ?? '–') ?> · inscrite le <?= e(View::date($p['inscrite_le'])) ?>
      · consentement IA : <?= $p['consent_ia'] ? 'oui' : 'non' ?>
      · dernier message reçu : <?= e(View::date($p['dernier_entrant_le'])) ?>
      · bienvenue : <?= $p['bienvenue_envoyee'] ? 'reçue' : 'en attente' ?></p>
    <?php if (Auth::peut('voir_numero', $u)): ?>
      <?php if ($numeroVisible): ?>
        <p>Numéro : <span class="numero">+<?= e($numeroVisible) ?></span></p>
        <p class="discret">Cet accès a été enregistré dans le journal.</p>
      <?php else: ?>
        <form method="post" class="actions">
          <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="voir_numero">
          <input type="text" name="motif" placeholder="Motif (ex. rappel après alerte)" required style="max-width:280px">
          <button class="btn petit">Afficher le numéro</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($peutAgir): ?>
  <div class="carte">
    <form method="post">
      <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="rdv">
      <strong>Ajouter un rendez-vous</strong>
      <div class="deux">
        <div><label for="date_rdv">Date</label><input type="date" id="date_rdv" name="date_rdv" min="<?= e(aujourdhui()) ?>" required></div>
        <div><label for="type">Type</label><select id="type" name="type"><?php foreach ($libRdv as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="actions"><button class="btn principal">Ajouter</button></div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($alertes): ?>
<h2>Alertes</h2>
<div class="table-defile"><table>
  <tr><th>Date</th><th>Origine</th><th>Rappel</th><th>Issue</th><th>Statut</th></tr>
  <?php foreach ($alertes as $a): ?>
  <tr><td><?= e(View::date($a['cree_le'])) ?></td><td><?= e($a['origine']) ?></td><td><?= e(View::date($a['rappel_le'])) ?></td><td><?= e($a['issue'] ?? '–') ?></td><td><span class="etiquette <?= e($a['statut']) ?>"><?= e($a['statut']) ?></span></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<h2>Rendez-vous</h2>
<div class="table-defile"><table>
  <tr><th>Date</th><th>Type</th><th>Rappels</th><th>Réponse</th><th>Venue</th></tr>
  <?php foreach ($rdvs as $r): ?>
  <tr>
    <td><?= e(View::date($r['date_rdv'], false)) ?></td>
    <td><?= e($libRdv[$r['type']] ?? $r['type']) ?></td>
    <td class="discret"><?= $r['rappel_j2_le'] ? 'J-2 ✓ ' : '' ?><?= $r['rappel_j1_le'] ? 'J-1 ✓' : '' ?></td>
    <td><?= $r['reponse'] === null ? '–' : ((int) $r['reponse'] === 1 ? 'viendra' : 'empêchée') ?></td>
    <td>
      <?php if ($r['date_rdv'] <= aujourdhui() && Auth::peut('actions_parcours', $u)): ?>
        <form method="post" style="display:flex;gap:4px">
          <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="honore"><input type="hidden" name="rdv_id" value="<?= (int) $r['id'] ?>">
          <button class="btn petit" name="honore" value="1" <?= (string) $r['honore'] === '1' ? 'disabled' : '' ?>>Venue</button>
          <button class="btn petit" name="honore" value="0" <?= (string) $r['honore'] === '0' ? 'disabled' : '' ?>>Absente</button>
        </form>
      <?php else: ?><?= $r['honore'] === null ? '–' : ((int) $r['honore'] ? 'oui' : 'non') ?><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rdvs): ?><tr><td colspan="5" class="discret">Aucun rendez-vous saisi.</td></tr><?php endif; ?>
</table></div>

<h2>Historique des envois</h2>
<div class="table-defile"><table>
  <tr><th>Date</th><th>Nature</th><th>Motif</th><th>Message</th><th>Statut</th><th>Écoute</th></tr>
  <?php foreach ($envois as $en): ?>
  <tr>
    <td><?= e(View::date($en['cree_le'])) ?></td>
    <td><?= e($en['nature']) ?></td>
    <td><?= e($en['motif']) ?></td>
    <td><?= e($en['titre'] ?? '') ?></td>
    <td><span class="etiquette <?= e($en['statut']) ?>"><?= e($libStatutEnvoi[$en['statut']] ?? $en['statut']) ?></span><?php if ($en['erreur']): ?> <span class="discret"><?= e(mb_substr($en['erreur'], 0, 80)) ?></span><?php endif; ?></td>
    <td class="discret"><?= $en['nature'] === 'notif' ? ($en['demande_le'] ? 'demandée ' . e(View::date($en['demande_le'])) : 'pas encore') : ($en['lu_le'] ? 'ouvert' : '') ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>

<h2>Réponses reçues</h2>
<div class="table-defile"><table>
  <tr><th>Date</th><th>Type</th><th>Contenu</th></tr>
  <?php foreach ($entrants as $en): ?>
  <tr><td><?= e(View::date($en['recu_le'])) ?></td><td><?= e($en['type']) ?></td><td><?= e($en['contenu'] ?? ($en['type'] === 'audio' ? 'message vocal' : '')) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$entrants): ?><tr><td colspan="3" class="discret">Aucune réponse pour l’instant.</td></tr><?php endif; ?>
</table></div>

<?php if (Auth::peut('actions_parcours', $u)): ?>
<h2>Actions sur le parcours</h2>
<div class="deux">
  <?php if ($p['statut'] === 'active'): ?>
  <div class="carte">
    <?php if ($p['profil'] === 'enceinte'): ?>
    <form method="post">
      <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="naissance">
      <strong>Déclarer la naissance</strong>
      <label for="dn">Date de naissance</label><input type="date" id="dn" name="date" value="<?= e(aujourdhui()) ?>" max="<?= e(aujourdhui()) ?>">
      <div class="actions"><button class="btn">Passer au parcours mère</button></div>
    </form>
    <?php endif; ?>
    <form method="post">
      <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="numero">
      <label for="nouveau">Changement de numéro</label>
      <input type="tel" id="nouveau" name="nouveau" placeholder="Nouveau numéro WhatsApp" required>
      <div class="actions"><button class="btn">Transférer le suivi</button></div>
    </form>
    <?php if (!$p['bienvenue_envoyee']): ?>
    <form method="post" class="actions"><?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="bienvenue"><button class="btn">Renvoyer la bienvenue</button></form>
    <?php endif; ?>
  </div>
  <div class="carte">
    <form method="post" data-confirmer="Arrêter le service pour cette participante ?">
      <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="arreter">
      <strong>Arrêter le service</strong>
      <p class="discret">À sa demande, si elle l’exprime au centre.</p>
      <button class="btn danger">Arrêter</button>
    </form>
    <?php if ($p['profil'] === 'enceinte'): ?>
    <form method="post">
      <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="perte">
      <h2>Perte de grossesse</h2>
      <p class="discret">Un message de soutien unique, validé par la directrice médicale, lui est proposé. Puis plus aucun message.</p>
      <label class="case"><input type="checkbox" name="confirmer" value="1" required><span>Je confirme, après échange avec elle.</span></label>
      <div class="actions"><button class="btn danger">Enregistrer</button></div>
    </form>
    <?php endif; ?>
  </div>
  <?php elseif ($p['statut'] === 'stoppee'): ?>
  <div class="carte">
    <form method="post"><?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= $pid ?>"><input type="hidden" name="action" value="reprendre">
      <p>Elle souhaite reprendre le service ?</p><button class="btn">Reprendre le service</button></form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php View::pied();
