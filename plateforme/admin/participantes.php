<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('participantes');

$filtre = $_GET['f'] ?? 'active';
$cherche = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if (in_array($filtre, ['active', 'stoppee', 'terminee'], true)) {
    $where[] = 'p.statut = ?';
    $params[] = $filtre;
}
if ($cherche !== '') {
    $where[] = 'p.pseudo LIKE ?';
    $params[] = '%' . $cherche . '%';
}
$liste = q(
    'SELECT p.*, c.nom AS centre,
        (SELECT MIN(date_rdv) FROM rendez_vous r WHERE r.participante_id = p.id AND r.date_rdv >= ?) AS prochain_rdv,
        (SELECT COUNT(*) FROM alertes a WHERE a.participante_id = p.id AND a.statut = \'ouverte\') AS alertes
     FROM participantes p LEFT JOIN centres c ON c.id = p.centre_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.id DESC LIMIT 500',
    array_merge([aujourdhui()], $params)
)->fetchAll();

View::entete('Participantes', $u);
?>
<h1>Participantes</h1>
<div class="barre-filtres">
  <?php foreach (['active' => 'Actives', 'stoppee' => 'Ont arrêté', 'terminee' => 'Parcours terminé', 'toutes' => 'Toutes'] as $k => $lib): ?>
    <a href="?f=<?= $k ?>" class="<?= $filtre === $k ? 'actif' : '' ?>"><?= e($lib) ?></a>
  <?php endforeach; ?>
  <form method="get" style="margin-left:auto;display:flex;gap:6px">
    <input type="hidden" name="f" value="<?= e($filtre) ?>">
    <input type="text" name="q" value="<?= e($cherche) ?>" placeholder="MK-0001" style="width:140px">
    <button class="btn petit">Chercher</button>
  </form>
</div>
<div class="table-defile"><table>
  <tr><th>Code</th><th>Profil</th><th>Stade</th><th>Centre</th><th>Prochain RDV</th><th>Inscrite le</th><th>Statut</th></tr>
  <?php foreach ($liste as $p): ?>
  <tr>
    <td><a href="participante.php?id=<?= (int) $p['id'] ?>"><?= e($p['pseudo']) ?></a><?php if ($p['alertes']): ?> <span class="etiquette urgent">alerte</span><?php endif; ?></td>
    <td><?= e(libelle_profil($p['profil'])) ?></td>
    <td><?= e(Parcours::libelleStade($p)) ?></td>
    <td><?= e($p['centre'] ?? '–') ?></td>
    <td><?= e(View::date($p['prochain_rdv'], false)) ?></td>
    <td><?= e(View::date($p['inscrite_le'], false)) ?></td>
    <td><span class="etiquette <?= e($p['statut']) ?>"><?= e(libelle_statut($p['statut'])) ?></span></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$liste): ?><tr><td colspan="7" class="discret">Aucune participante.</td></tr><?php endif; ?>
</table></div>
<?php View::pied();
