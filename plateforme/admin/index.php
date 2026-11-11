<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('tableau');

$i = Indicateurs::equipe();
$pct = static fn(int $a, int $b): string => $b > 0 ? round(100 * $a / $b) . ' %' : '–';

$prets = (int) qv("SELECT COUNT(DISTINCT code) FROM messages WHERE statut = 'valide' AND fichier_audio IS NOT NULL");
$total = (int) qv('SELECT COUNT(DISTINCT code) FROM messages');
$indispensables = ['bienvenue', 'menu', 'danger', 'accuse_vocal', 'rappel_rdv', 'stop'];
if (Questions::actives()) {
    array_push($indispensables, 'faq_ecoute', 'faq_sans_reponse');
}
$manquants = [];
foreach ($indispensables as $evt) {
    if (!qv("SELECT COUNT(*) FROM messages WHERE evenement = ? AND statut = 'valide' AND fichier_audio IS NOT NULL", [$evt])) {
        $manquants[] = $evt;
    }
}
$dernierCron = parametre('dernier_cron');
$cronEnRetard = !$dernierCron || ts(maintenant()) - ts($dernierCron) > 3600;
$taches = (int) qv('SELECT COUNT(*) FROM taches_manuelles WHERE fait_le IS NULL');

View::entete('Tableau de bord', $u);
?>
<h1>Tableau de bord du micro-pilote</h1>

<?php if ($manquants): ?>
  <div class="flash erreur">Messages indispensables pas encore validés avec leur audio : <?= e(implode(', ', $manquants)) ?>. Le service ne peut pas démarrer correctement sans eux (<a href="messages.php">bibliothèque</a>).</div>
<?php endif; ?>
<?php if ($cronEnRetard): ?>
  <div class="flash info">La tâche planifiée ne s’est pas exécutée depuis plus d’une heure (dernière exécution : <?= e(View::date($dernierCron)) ?>). Vérifiez la tâche cron sur l’hébergement.</div>
<?php endif; ?>

<div class="grille">
  <div class="kpi <?= $i['alertes_ouvertes'] ? 'alerte' : '' ?>"><b><?= $i['alertes_ouvertes'] ?></b><span>alertes à traiter</span></div>
  <div class="kpi"><b><?= $i['actives'] ?></b><span>participantes actives (<?= $i['enceintes'] ?> enceintes, <?= $i['meres'] ?> mères)</span></div>
  <div class="kpi"><b><?= $i['inscrites'] ?></b><span>inscrites au total</span></div>
  <div class="kpi"><b><?= $pct($i['ecoutes_demandees'], $i['notifs']) ?></b><span>taux d’écoute (« Écouter » après notification, <?= $i['ecoutes_demandees'] ?>/<?= $i['notifs'] ?>)</span></div>
  <div class="kpi"><b><?= $i['audios_envoyes'] ?></b><span>messages de santé envoyés</span></div>
  <div class="kpi"><b><?= $pct($i['audios_livres'], $i['audios_envoyes']) ?></b><span>livrés (accusés WhatsApp)</span></div>
  <div class="kpi"><b><?= $pct($i['audios_lus'], $i['audios_envoyes']) ?></b><span>ouverts</span></div>
  <div class="kpi"><b><?= $i['participantes_repondantes'] ?></b><span>femmes ayant répondu au moins une fois (<?= $i['reponses'] ?> réponses)</span></div>
  <div class="kpi"><b><?= $i['vocaux'] ?></b><span>messages vocaux reçus</span></div>
  <div class="kpi"><b><?= $i['alertes'] ?></b><span>alertes au total</span></div>
  <div class="kpi"><b><?= View::duree($i['delai_rappel_median_s']) ?></b><span>délai médian de rappel d’une alerte</span></div>
  <div class="kpi"><b><?= View::duree($i['delai_vocal_median_s']) ?></b><span>délai médian de prise en charge d’un vocal</span></div>
  <div class="kpi"><b><?= $pct($i['rdv_confirmes'], $i['rdv_rappeles']) ?></b><span>rendez-vous confirmés après rappel (<?= $i['rdv_confirmes'] ?>/<?= $i['rdv_rappeles'] ?>)</span></div>
  <div class="kpi"><b><?= $i['rdv_honores'] ?>/<?= $i['rdv_passes'] ?></b><span>rendez-vous honorés (saisis par le centre)</span></div>
  <div class="kpi"><b><?= $i['arrets'] ?></b><span>désinscriptions</span></div>
  <div class="kpi <?= $i['echecs'] ? 'alerte' : '' ?>"><b><?= $pct($i['total_envois'] - $i['echecs'], $i['total_envois']) ?></b><span>fiabilité des envois (<?= $i['echecs'] ?> échecs)</span></div>
</div>

<?php $qi = $i['questions']; ?>
<h2>Questions vocales en dioula</h2>
<div class="grille">
  <div class="kpi"><b><?= $qi['pretes'] ?></b><span>questions utilisées par le service (sur <?= $qi['bibliotheque'] ?>)</span></div>
  <div class="kpi"><b><?= $qi['posees'] ?></b><span>questions posées à voix haute</span></div>
  <div class="kpi"><b><?= $qi['couverture'] === null ? '–' : round(100 * $qi['couverture']) . ' %' ?></b><span>réponse validée immédiate</span></div>
  <div class="kpi"><b><?= $qi['sans_reponse'] + $qi['refusees'] ?></b><span>transmises à l’équipe</span></div>
  <div class="kpi <?= $qi['erreurs'] ? 'alerte' : '' ?>"><b><?= $qi['taux_erreur'] === null ? '–' : round(100 * $qi['taux_erreur'], 1) . ' %' ?></b><span>réponses inexactes (<?= $qi['verifiees'] ?> vérifiées)</span></div>
  <div class="kpi"><b><?= View::duree($qi['delai_median_s']) ?></b><span>délai médian de réponse</span></div>
</div>

<div class="deux">
  <div>
    <h2>Préparation</h2>
    <div class="carte">
      <p>Bibliothèque : <strong><?= $prets ?> messages prêts</strong> (validés avec audio) sur <?= $total ?>.</p>
      <p>Canal : <strong><?= e(['cloud' => 'API WhatsApp Business Platform', 'manuel' => 'mode manuel (application WhatsApp Business)', 'test' => 'test, aucun envoi réel'][Canal::mode()]) ?></strong><?php if ($taches): ?> · <a href="manuel.php"><?= $taches ?> envoi(s) manuel(s) à faire</a><?php endif; ?></p>
      <p>Dernière exécution du planificateur : <?= e(View::date($dernierCron)) ?></p>
      <?php $dernierIa = parametre('dernier_appel_ia'); ?>
      <p>Service IA : <?= $dernierIa && ts(maintenant()) - ts($dernierIa) < 300 ? '<strong>en marche</strong>' : '<strong>arrêté</strong> (les questions partent à l’équipe)' ?> · dernier contact : <?= e(View::date($dernierIa)) ?></p>
    </div>
  </div>
  <div>
    <h2>Détection des signes de danger</h2>
    <div class="carte">
      <p>Vocaux transcrits : <strong><?= $i['ia']['transcrits'] ?></strong> · annotés par l’équipe : <strong><?= $i['ia']['annotes'] ?></strong></p>
      <p>Rappel des alertes : <strong><?= $i['ia']['rappel'] === null ? '–' : round(100 * $i['ia']['rappel']) . ' %' ?></strong> (seuil V2 : 95 % sur 200 messages annotés)</p>
      <p>Précision : <strong><?= $i['ia']['precision'] === null ? '–' : round(100 * $i['ia']['precision']) . ' %' ?></strong></p>
      <p class="discret">Mesuré sur les vocaux annotés par l’équipe. La touche 2 et le lexique validé restent les premiers filets de sécurité.</p>
    </div>
  </div>
</div>

<?php if ($i['sondage']): ?>
<h2>Questionnaire J+10 (moyenne sur 4)</h2>
<div class="table-defile"><table>
  <tr><th>Question</th><th>Moyenne</th><th>Réponses</th></tr>
  <?php $libQ = [1 => 'Messages faciles à comprendre', 2 => 'Messages utiles', 3 => 'Voix et langue', 4 => 'Nombre de messages', 5 => 'Recommanderait le service'];
  foreach ($i['sondage'] as $s): ?>
  <tr><td><?= e($libQ[(int) $s['question']] ?? $s['question']) ?></td><td><?= round((float) $s['moyenne'], 1) ?></td><td><?= (int) $s['n'] ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
<?php View::pied();
