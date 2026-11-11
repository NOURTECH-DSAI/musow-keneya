<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('manuel');

$numeros = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'fait') {
            $t = q1('SELECT * FROM taches_manuelles WHERE id = ? AND fait_le IS NULL', [(int) $_POST['tache_id']]);
            if ($t) {
                q('UPDATE taches_manuelles SET fait_le = ?, fait_par = ? WHERE id = ?', [maintenant(), $u['id'], $t['id']]);
                if ($t['envoi_id']) {
                    q("UPDATE envois SET statut = 'envoye', envoye_le = ? WHERE id = ?", [maintenant(), $t['envoi_id']]);
                }
                journal(Auth::acteur(), 'envoi_manuel', ['tache' => (int) $t['id']]);
            }
        } elseif ($action === 'numero') {
            $pid = (int) $_POST['participante_id'];
            $numeros[$pid] = Parcours::numero($pid);
            journal(Auth::acteur(), 'acces_numero', ['participante' => Parcours::participante($pid)['pseudo'], 'motif' => 'envoi manuel']);
        } elseif ($action === 'reponse') {
            $pseudo = strtoupper(trim((string) ($_POST['pseudo'] ?? '')));
            $pid = (int) qv('SELECT id FROM participantes WHERE pseudo = ?', [$pseudo]);
            if (!$pid) {
                throw new InvalidArgumentException('Code participante inconnu : ' . $pseudo);
            }
            $choix = (string) ($_POST['choix'] ?? '');
            if ($choix === 'vocal') {
                Parcours::traiter($pid, 'audio', null, null, null);
            } elseif (preg_match('/^[0-9]$/', $choix)) {
                Parcours::traiter($pid, 'texte', $choix, null, null);
            } else {
                Parcours::traiter($pid, 'texte', trim((string) ($_POST['texte'] ?? '')), null, null);
            }
            journal(Auth::acteur(), 'reponse_saisie', ['participante' => $pseudo, 'choix' => $choix]);
            View::flash('ok', 'Réponse de ' . $pseudo . ' enregistrée. Les envois qui en découlent sont listés ci-dessous.');
        }
    } catch (InvalidArgumentException $e) {
        View::flash('erreur', $e->getMessage());
    }
    if ($action !== 'numero') {
        View::rediriger('manuel.php');
    }
}

$taches = q(
    'SELECT t.*, p.pseudo FROM taches_manuelles t JOIN participantes p ON p.id = t.participante_id WHERE t.fait_le IS NULL ORDER BY t.id LIMIT 200'
)->fetchAll();

View::entete('Envois manuels', $u);
?>
<h1>Envois manuels</h1>
<?php if (Canal::mode() !== 'manuel'): ?>
  <div class="flash info">Le service fonctionne avec l’API WhatsApp : cette page ne sert qu’en mode manuel.</div>
<?php endif; ?>
<p class="discret">Mode de repli : chaque ligne est un message à envoyer depuis l’application WhatsApp Business du service. Les audios sont téléchargeables ; envoyez-les comme fichier audio. Cochez la ligne une fois l’envoi fait.</p>

<div class="carte">
  <form method="post">
    <?= Auth::champJeton() ?><input type="hidden" name="action" value="reponse">
    <strong>Saisir une réponse reçue sur le téléphone du service</strong>
    <div class="deux">
      <div><label for="pseudo">Code participante</label><input type="text" id="pseudo" name="pseudo" placeholder="MK-0001" required></div>
      <div><label for="choix">Elle a envoyé</label>
        <select id="choix" name="choix">
          <option value="1">1 : écouter</option>
          <option value="2">2 : urgence</option>
          <option value="3">3 : contact du centre</option>
          <option value="4">4 : je viendrai au rendez-vous</option>
          <option value="5">5 : je ne peux pas venir</option>
          <option value="9">9 / stop : arrêter</option>
          <option value="vocal">un message vocal</option>
          <option value="texte">autre chose (texte ci-dessous)</option>
        </select></div>
    </div>
    <label for="texte">Texte reçu (si « autre chose »)</label><input type="text" id="texte" name="texte">
    <div class="actions"><button class="btn principal">Enregistrer la réponse</button></div>
  </form>
</div>

<h2>À envoyer (<?= count($taches) ?>)</h2>
<div class="table-defile"><table>
  <tr><th>Participante</th><th>Consigne</th><th>Fichier</th><th></th></tr>
  <?php foreach ($taches as $t): ?>
  <tr>
    <td><strong><?= e($t['pseudo']) ?></strong><br>
      <?php if (isset($numeros[(int) $t['participante_id']])): ?><span class="numero">+<?= e($numeros[(int) $t['participante_id']]) ?></span>
      <?php else: ?><form method="post"><?= Auth::champJeton() ?><input type="hidden" name="action" value="numero"><input type="hidden" name="participante_id" value="<?= (int) $t['participante_id'] ?>"><button class="btn petit">Numéro</button></form><?php endif; ?></td>
    <td><?= nl2br(e($t['consigne'])) ?><br><span class="discret"><?= e(View::date($t['cree_le'])) ?></span></td>
    <td><?php if ($t['fichier_audio']): ?><a class="btn petit" href="../audio/<?= e($t['fichier_audio']) ?>" download>Télécharger l’audio</a><?php endif; ?></td>
    <td><form method="post"><?= Auth::champJeton() ?><input type="hidden" name="action" value="fait"><input type="hidden" name="tache_id" value="<?= (int) $t['id'] ?>"><button class="btn petit">Envoyé ✓</button></form></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$taches): ?><tr><td colspan="4" class="discret">Rien à envoyer.</td></tr><?php endif; ?>
</table></div>
<?php View::pied();
