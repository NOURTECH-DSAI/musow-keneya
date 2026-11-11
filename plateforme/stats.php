<?php
/**
 * Indicateurs publics du service (EF-14, section 12.3), mis en cache une heure.
 * Agrégats seulement ; aucun chiffre ne porte sur moins de 10 personnes.
 * Format JSON : stats.php?format=json
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$cache = MK_ROOT . '/storage/stats.json';
$s = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
if (!$s || ts(maintenant()) - ts($s['mis_a_jour'] ?? '2000-01-01') > 3600) {
    $s = Indicateurs::publics();
    @file_put_contents($cache, json_encode($s, JSON_UNESCAPED_UNICODE));
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['service' => 'Musow Kènèya', 'editeur' => 'NOUR TECH SARL', 'licence' => 'CC BY 4.0', 'indicateurs' => $s], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$tuiles = [
    ['inscrites', 'femmes inscrites'],
    ['actives', 'participantes actives'],
    ['enceintes', 'femmes enceintes suivies'],
    ['meres', 'mères suivies'],
    ['messages_envoyes', 'messages de santé envoyés en dioula'],
    ['messages_livres', 'messages livrés'],
    ['messages_ecoutes', 'messages ouverts'],
    ['taux_ecoute', 'taux d’écoute après notification'],
    ['taux_confirmation', 'rendez-vous confirmés après rappel'],
    ['questions_bibliotheque', 'questions fréquentes validées dans la bibliothèque'],
    ['questions_posees', 'questions posées à voix haute en dioula'],
    ['couverture', 'questions ayant reçu une réponse validée immédiate'],
    ['taux_erreur', 'réponses automatiques jugées inexactes par l’équipe'],
    ['alertes', 'signes de danger signalés'],
    ['delai_rappel', 'délai médian de rappel par l’équipe'],
    ['ia_rappel', 'IA vocale : alertes détectées (rappel)'],
    ['ia_precision', 'IA vocale : précision des signalements'],
];
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Musow Kènèya · indicateurs publics</title>
<link rel="stylesheet" href="admin/style.css?v=<?= MK_VERSION ?>">
<link rel="icon" href="/favicon.ico?v=2" sizes="any">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2">
</head>
<body>
<header class="barre"><div class="bande" aria-hidden="true"><span></span><span></span><span></span></div>
  <div class="barre-int"><span class="marque">Musow Kènèya <small>santé maternelle en dioula, par message vocal</small></span></div></header>
<main class="contenu">
  <h1>Indicateurs publics du service</h1>
  <p class="discret">Micro-pilote à Abobo (Abidjan, Côte d’Ivoire), porté par NOUR TECH SARL. Mis à jour toutes les heures (dernière mise à jour : <?= e(View::date($s['mis_a_jour'])) ?>). Pour protéger les participantes, aucun chiffre ne porte sur moins de 10 personnes : « – » signifie que l’effectif est encore trop faible.</p>
  <div class="grille">
    <?php foreach ($tuiles as [$k, $lib]): ?>
      <div class="kpi"><b><?= e($s[$k] ?? '–') ?></b><span><?= e($lib) ?></span></div>
    <?php endforeach; ?>
  </div>
  <p class="discret" style="margin-top:24px">Données ouvertes (CC BY 4.0) : <a href="stats.php?format=json">format JSON</a>. Code du service sous licence MIT.</p>
</main>
</body>
</html>
