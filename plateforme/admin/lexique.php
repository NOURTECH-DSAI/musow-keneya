<?php
/**
 * Lexique des signes de danger en dioula. Si un de ces termes apparaît dans
 * une question vocale, la consigne d'urgence part immédiatement, quelle que
 * soit la question reconnue. Toute modification doit être revalidée.
 */
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('questions_voir');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = (string) ($_POST['action'] ?? '');
    $lid = (int) ($_POST['lid'] ?? 0);
    try {
        if ($action === 'termes') {
            if (!Auth::peut('questions_modifier', $u)) {
                throw new InvalidArgumentException('Accès refusé.');
            }
            $termes = array_filter(array_map('trim', preg_split('/[|\n]+/u', (string) ($_POST['termes'] ?? '')) ?: []));
            q('UPDATE lexique SET termes = ?, valide = 0, valide_par = NULL, valide_le = NULL, modifie_le = ? WHERE id = ?', [implode('|', $termes) ?: null, maintenant(), $lid]);
            journal(Auth::acteur(), 'lexique_modifie', ['id' => $lid]);
            View::flash('info', 'Termes enregistrés. Ils seront utilisés après validation par la direction médicale.');
        } elseif ($action === 'valider') {
            if (!Auth::peut('lexique_valider', $u)) {
                throw new InvalidArgumentException('Seule la direction médicale valide le lexique.');
            }
            q("UPDATE lexique SET valide = 1, valide_par = ?, valide_le = ? WHERE id = ? AND termes IS NOT NULL AND termes <> ''", [$u['id'], maintenant(), $lid]);
            journal(Auth::acteur(), 'lexique_valide', ['id' => $lid]);
            View::flash('ok', 'Termes validés : ils déclenchent désormais la consigne d’urgence.');
        } elseif ($action === 'ajouter') {
            if (!Auth::peut('questions_modifier', $u) || trim((string) $_POST['concept']) === '') {
                throw new InvalidArgumentException('Concept obligatoire.');
            }
            q('INSERT INTO lexique (concept_fr, termes, valide, modifie_le) VALUES (?, NULL, 0, ?)', [trim((string) $_POST['concept']), maintenant()]);
        }
    } catch (InvalidArgumentException $e) {
        View::flash('erreur', $e->getMessage());
    }
    View::rediriger('lexique.php');
}

$liste = q('SELECT l.*, u.nom FROM lexique l LEFT JOIN utilisateurs u ON u.id = l.valide_par ORDER BY l.id')->fetchAll();
View::entete('Lexique des signes de danger', $u);
?>
<p><a href="questions.php">← Questions fréquentes</a></p>
<h1>Lexique des signes de danger</h1>
<p class="discret">Les linguistes saisissent les mots et expressions en dioula (plusieurs variantes séparées par « | »), la direction médicale les valide. Un terme validé entendu dans une question déclenche immédiatement la consigne d’urgence et une alerte, même si la question n’est pas reconnue. Préférez trop de termes que pas assez : une fausse alerte coûte un appel, une alerte manquée peut coûter une vie.</p>
<div class="table-defile"><table>
  <tr><th>Concept</th><th>Termes en dioula</th><th>État</th></tr>
  <?php foreach ($liste as $l): ?>
  <tr>
    <td><?= e($l['concept_fr']) ?></td>
    <td>
      <?php if (Auth::peut('questions_modifier', $u)): ?>
        <form method="post" style="display:flex;gap:6px"><?= Auth::champJeton() ?><input type="hidden" name="action" value="termes"><input type="hidden" name="lid" value="<?= (int) $l['id'] ?>">
          <input type="text" name="termes" lang="dyu" value="<?= e($l['termes']) ?>" placeholder="terme 1 | terme 2"><button class="btn petit">OK</button></form>
      <?php else: ?><span lang="dyu"><?= e($l['termes'] ?? '–') ?></span><?php endif; ?>
    </td>
    <td style="white-space:nowrap">
      <?php if ($l['valide']): ?><span class="etiquette valide">validé</span> <span class="discret"><?= e($l['nom']) ?></span>
      <?php elseif ($l['termes'] && Auth::peut('lexique_valider', $u)): ?>
        <form method="post"><?= Auth::champJeton() ?><input type="hidden" name="action" value="valider"><input type="hidden" name="lid" value="<?= (int) $l['id'] ?>"><button class="btn petit principal">Valider</button></form>
      <?php else: ?><span class="etiquette brouillon"><?= $l['termes'] ? 'à valider' : 'à remplir' ?></span><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php if (Auth::peut('questions_modifier', $u)): ?>
<form method="post" class="carte actions"><?= Auth::champJeton() ?><input type="hidden" name="action" value="ajouter">
  <input type="text" name="concept" placeholder="Nouveau concept (en français)" required style="max-width:340px"><button class="btn">Ajouter</button></form>
<?php endif; ?>
<?php View::pied();
