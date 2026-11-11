<?php
/** Exports CSV pour l'analyse du pilote : aucun numéro de téléphone (ENF-06). */
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('export');

$jeux = [
    'participantes' => ['Participantes', 'SELECT p.pseudo, p.profil, p.dpa, p.naissance_enfant, c.nom AS centre, p.statut, p.motif_fin, p.inscrite_le, p.consent_ia, p.bienvenue_envoyee, p.arretee_le FROM participantes p LEFT JOIN centres c ON c.id = p.centre_id ORDER BY p.id'],
    'envois' => ['Envois', 'SELECT p.pseudo, e.nature, e.motif, m.code AS message, m.version, e.canal, e.statut, e.tentatives, e.cree_le, e.envoye_le, e.livre_le, e.lu_le, e.demande_le FROM envois e JOIN participantes p ON p.id = e.participante_id LEFT JOIN messages m ON m.id = e.message_id ORDER BY e.id'],
    'reponses' => ['Réponses reçues', "SELECT p.pseudo, e.type, CASE WHEN e.type = 'texte' AND LENGTH(e.contenu) = 1 THEN e.contenu WHEN e.type = 'bouton' THEN e.contenu ELSE '' END AS touche, e.recu_le FROM entrants e JOIN participantes p ON p.id = e.participante_id ORDER BY e.id"],
    'rendez_vous' => ['Rendez-vous', 'SELECT p.pseudo, r.type, r.date_rdv, r.rappel_j2_le, r.rappel_j1_le, r.reponse, r.reponse_le, r.honore FROM rendez_vous r JOIN participantes p ON p.id = r.participante_id ORDER BY r.id'],
    'alertes' => ['Alertes', 'SELECT p.pseudo, a.origine, a.cree_le, a.consigne_envoyee_le, a.rappel_le, a.issue, a.statut FROM alertes a JOIN participantes p ON p.id = a.participante_id ORDER BY a.id'],
    'vocaux' => ['Messages vocaux (sans audio)', 'SELECT p.pseudo, v.recu_le, v.pris_en_charge_le, v.annotation_alerte, v.consent_ia, v.ia_statut, v.ia_alerte, v.ia_score, v.ia_modele FROM vocaux v JOIN participantes p ON p.id = v.participante_id ORDER BY v.id'],
    'sondages' => ['Questionnaire J+10', 'SELECT p.pseudo, s.question, s.reponse, s.repondu_le FROM sondages s JOIN participantes p ON p.id = s.participante_id ORDER BY s.id'],
    'messages' => ['Bibliothèque des messages', 'SELECT code, version, titre, module, profil, debut, fin, statut, duree_s, valide_le, reference FROM messages ORDER BY code, version'],
];

$jeu = (string) ($_GET['jeu'] ?? '');
if (isset($jeux[$jeu])) {
    journal(Auth::acteur(), 'export', ['jeu' => $jeu]);
    $lignes = q($jeux[$jeu][1])->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="musow-' . $jeu . '-' . date('Ymd', ts(maintenant())) . '.csv"');
    $sortie = fopen('php://output', 'w');
    fwrite($sortie, "\xEF\xBB\xBF"); // pour Excel
    if ($lignes) {
        fputcsv($sortie, array_keys($lignes[0]), ';');
        foreach ($lignes as $l) {
            fputcsv($sortie, $l, ';');
        }
    }
    fclose($sortie);
    exit;
}

View::entete('Exports', $u);
?>
<h1>Exports pour l’analyse</h1>
<p class="discret">Fichiers CSV (séparateur point-virgule, ouverture directe dans Excel). Les participantes n’y figurent que par leur code : aucun numéro, aucun audio. Chaque export est enregistré dans le journal.</p>
<div class="carte">
  <?php foreach ($jeux as $k => [$lib]): ?>
    <p><a class="btn" href="?jeu=<?= $k ?>"><?= e($lib) ?></a></p>
  <?php endforeach; ?>
</div>
<?php View::pied();
