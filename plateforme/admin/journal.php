<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('journal');

$filtre = (string) ($_GET['action'] ?? '');
$actions = q('SELECT DISTINCT action FROM journal ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$lignes = $filtre !== ''
    ? q('SELECT * FROM journal WHERE action = ? ORDER BY id DESC LIMIT 300', [$filtre])->fetchAll()
    : q('SELECT * FROM journal ORDER BY id DESC LIMIT 300')->fetchAll();

View::entete('Journal', $u);
?>
<h1>Journal d’activité</h1>
<p class="discret">Traçabilité (ENF-07) : chaque validation de contenu, accès à un numéro, écoute d’un vocal, export et action sur un parcours est enregistré.</p>
<div class="barre-filtres">
  <a href="journal.php" class="<?= $filtre === '' ? 'actif' : '' ?>">Tout</a>
  <?php foreach ($actions as $a): ?><a href="?action=<?= e($a) ?>" class="<?= $filtre === $a ? 'actif' : '' ?>"><?= e($a) ?></a><?php endforeach; ?>
</div>
<div class="table-defile"><table>
  <tr><th>Date</th><th>Acteur</th><th>Action</th><th>Détails</th></tr>
  <?php foreach ($lignes as $l): ?>
  <tr><td><?= e(View::date($l['cree_le'])) ?></td><td><?= e($l['acteur']) ?></td><td><?= e($l['action']) ?></td><td class="discret"><?= e($l['details']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php View::pied();
