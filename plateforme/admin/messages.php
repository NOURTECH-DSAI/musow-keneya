<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$u = Auth::exiger('messages_voir');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::verifierJeton();
    $action = (string) ($_POST['action'] ?? '');
    $m = q1('SELECT * FROM messages WHERE id = ?', [$id]);
    try {
        if (!$m) {
            throw new InvalidArgumentException('Message introuvable.');
        }
        switch ($action) {
            case 'enregistrer':
                if (!Auth::peut('messages_modifier', $u) || $m['statut'] !== 'brouillon') {
                    throw new InvalidArgumentException('Seul un brouillon peut être modifié.');
                }
                $num = static fn(string $k): ?int => ($_POST[$k] ?? '') === '' ? null : (int) $_POST[$k];
                q(
                    'UPDATE messages SET titre = ?, objectif = ?, reference = ?, texte_fr = ?, texte_dyu = ?, contre_traduction = ?,
                        profil = ?, debut = ?, fin = ?, repetition_jours = ?, ordre = ?, modifie_le = ? WHERE id = ?',
                    [
                        trim((string) $_POST['titre']), trim((string) $_POST['objectif']), trim((string) $_POST['reference']),
                        trim((string) $_POST['texte_fr']), trim((string) $_POST['texte_dyu']), trim((string) $_POST['contre_traduction']),
                        in_array($_POST['profil'] ?? '', ['enceinte', 'mere', 'toutes'], true) ? $_POST['profil'] : $m['profil'],
                        $num('debut'), $num('fin'), (int) ($_POST['repetition_jours'] ?? 0), (int) ($_POST['ordre'] ?? 100),
                        maintenant(), $id,
                    ]
                );
                if (!empty($_FILES['audio']['tmp_name']) && is_uploaded_file($_FILES['audio']['tmp_name'])) {
                    $analyse = Audio::analyser($_FILES['audio']['tmp_name']);
                    if (!$analyse['ok']) {
                        throw new InvalidArgumentException('Audio refusé : ' . $analyse['erreur']);
                    }
                    $nom = strtolower($m['code']) . '-v' . $m['version'] . '-' . bin2hex(random_bytes(4)) . '.ogg';
                    if (!move_uploaded_file($_FILES['audio']['tmp_name'], MK_ROOT . '/audio/' . $nom)) {
                        throw new InvalidArgumentException('Impossible d’enregistrer le fichier sur le serveur.');
                    }
                    if ($m['fichier_audio'] && !qv('SELECT COUNT(*) FROM messages WHERE fichier_audio = ? AND id <> ?', [$m['fichier_audio'], $id])) {
                        @unlink(MK_ROOT . '/audio/' . $m['fichier_audio']);
                    }
                    q('UPDATE messages SET fichier_audio = ?, duree_s = ? WHERE id = ?', [$nom, $analyse['duree'], $id]);
                    if ($analyse['avertissement']) {
                        View::flash('info', 'Audio accepté, avec une remarque : ' . $analyse['avertissement'] . '.');
                    }
                }
                journal(Auth::acteur(), 'message_modifie', ['code' => $m['code'], 'version' => (int) $m['version']]);
                View::flash('ok', 'Brouillon enregistré.');
                break;

            case 'nouvelle_version':
                if (!Auth::peut('messages_modifier', $u) || $m['statut'] !== 'valide') {
                    throw new InvalidArgumentException('Action impossible.');
                }
                if (qv("SELECT COUNT(*) FROM messages WHERE code = ? AND statut = 'brouillon'", [$m['code']])) {
                    throw new InvalidArgumentException('Une nouvelle version est déjà en cours pour ce message.');
                }
                $v = (int) qv('SELECT MAX(version) FROM messages WHERE code = ?', [$m['code']]) + 1;
                q(
                    'INSERT INTO messages (code, version, titre, module, evenement, langue, profil, debut, fin, repetition_jours, ordre,
                        objectif, reference, texte_fr, texte_dyu, contre_traduction, fichier_audio, duree_s, statut, remplace_id, cree_le)
                     SELECT code, ?, titre, module, evenement, langue, profil, debut, fin, repetition_jours, ordre,
                        objectif, reference, texte_fr, texte_dyu, contre_traduction, fichier_audio, duree_s, ?, id, ? FROM messages WHERE id = ?',
                    [$v, 'brouillon', maintenant(), $id]
                );
                $nouvel = (int) db()->lastInsertId();
                journal(Auth::acteur(), 'message_nouvelle_version', ['code' => $m['code'], 'version' => $v]);
                View::flash('info', 'Version ' . $v . ' créée en brouillon. La version ' . $m['version'] . ' reste diffusée jusqu’à la validation de la nouvelle.');
                View::rediriger('messages.php?id=' . $nouvel);

            case 'valider':
                if (!Auth::peut('messages_valider', $u)) {
                    throw new InvalidArgumentException('Seule la directrice médicale peut valider un message.');
                }
                if ($m['statut'] !== 'brouillon') {
                    throw new InvalidArgumentException('Ce message n’est pas un brouillon.');
                }
                $manque = [];
                foreach (['texte_fr' => 'texte source en français', 'texte_dyu' => 'texte en dioula', 'contre_traduction' => 'contre-traduction', 'fichier_audio' => 'audio'] as $champ => $lib) {
                    if (trim((string) $m[$champ]) === '') {
                        $manque[] = $lib;
                    }
                }
                if ($manque) {
                    throw new InvalidArgumentException('Validation impossible, il manque : ' . implode(', ', $manque) . '.');
                }
                if (empty($_POST['confirmer'])) {
                    throw new InvalidArgumentException('Cochez la confirmation de validation.');
                }
                q("UPDATE messages SET statut = 'remplace' WHERE code = ? AND statut = 'valide' AND id <> ?", [$m['code'], $id]);
                q("UPDATE messages SET statut = 'valide', valide_par = ?, valide_le = ? WHERE id = ?", [$u['id'], maintenant(), $id]);
                journal(Auth::acteur(), 'message_valide', ['code' => $m['code'], 'version' => (int) $m['version']]);
                View::flash('ok', 'Message ' . $m['code'] . ' (version ' . $m['version'] . ') validé : il peut désormais être diffusé.');
                break;

            case 'retirer':
                if (!Auth::peut('messages_modifier', $u) || $m['statut'] !== 'valide') {
                    throw new InvalidArgumentException('Action impossible.');
                }
                q("UPDATE messages SET statut = 'retire', modifie_le = ? WHERE id = ?", [maintenant(), $id]);
                journal(Auth::acteur(), 'message_retire', ['code' => $m['code'], 'version' => (int) $m['version'], 'motif' => mb_substr((string) ($_POST['motif'] ?? ''), 0, 200)]);
                View::flash('ok', 'Message retiré : il ne sera plus diffusé.');
                break;
        }
    } catch (InvalidArgumentException $e) {
        View::flash('erreur', $e->getMessage());
    }
    View::rediriger('messages.php' . ($id ? '?id=' . $id : ''));
}

$libStatut = ['brouillon' => 'brouillon', 'valide' => 'validé', 'remplace' => 'remplacé', 'retire' => 'retiré'];
$libModule = [
    'bienvenue' => 'Bienvenue et mode d’emploi', 'prenatal' => 'Suivi prénatal', 'danger' => 'Signes de danger pendant la grossesse',
    'nutrition' => 'Nutrition, repos, moustiquaire', 'accouchement' => 'Préparation de l’accouchement', 'postpartum' => 'Après l’accouchement',
    'nouveau_ne' => 'Nouveau-né', 'vaccination' => 'Vaccinations', 'service' => 'Messages de service', 'technique' => 'Messages techniques du parcours',
    'sondage' => 'Questionnaire J+10', 'faq_confirmation' => 'Questions : confirmation', 'faq_reponse' => 'Questions : réponse',
];

if ($id) {
    $m = q1('SELECT m.*, u.nom AS validateur FROM messages m LEFT JOIN utilisateurs u ON u.id = m.valide_par WHERE m.id = ?', [$id]);
    if (!$m) {
        View::rediriger('messages.php');
    }
    $versions = q('SELECT id, version, statut, valide_le FROM messages WHERE code = ? ORDER BY version DESC', [$m['code']])->fetchAll();
    $modifiable = $m['statut'] === 'brouillon' && Auth::peut('messages_modifier', $u);
    $ro = $modifiable ? '' : 'readonly';
    View::entete($m['code'], $u);
    ?>
    <p><a href="<?= str_starts_with($m['module'], 'faq_') ? 'questions.php?code=' . e(substr($m['code'], 4, -2)) : 'messages.php' ?>">← Retour</a></p>
    <h1><?= e($m['code']) ?> · <?= e($m['titre']) ?> <span class="etiquette <?= e($m['statut']) ?>"><?= e($libStatut[$m['statut']] ?? $m['statut']) ?></span></h1>
    <p class="discret">Version <?= (int) $m['version'] ?> · <?= e($libModule[$m['module']] ?? $m['module']) ?>
      <?php if ($m['evenement']): ?> · déclenché par le parcours (<?= e($m['evenement']) ?>)<?php endif; ?>
      <?php if ($m['valide_le']): ?> · validé par <?= e($m['validateur']) ?> le <?= e(View::date($m['valide_le'])) ?><?php endif; ?></p>

    <div class="deux">
    <form method="post" enctype="multipart/form-data" class="carte">
      <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="enregistrer">
      <strong>1. Fiche message</strong>
      <label for="titre">Titre</label><input type="text" id="titre" name="titre" value="<?= e($m['titre']) ?>" <?= $ro ?>>
      <label for="objectif">Objectif <span class="aide">ce que la femme doit savoir ou faire après l’écoute</span></label>
      <textarea id="objectif" name="objectif" <?= $ro ?>><?= e($m['objectif']) ?></textarea>
      <label for="reference">Référence <span class="aide">recommandation OMS, directive nationale, page</span></label>
      <input type="text" id="reference" name="reference" value="<?= e($m['reference']) ?>" <?= $ro ?>>
      <label for="texte_fr">Texte source en français</label>
      <textarea id="texte_fr" name="texte_fr" rows="6" <?= $ro ?>><?= e($m['texte_fr']) ?></textarea>
      <label for="texte_dyu">2. Traduction en dioula <span class="aide">linguiste n°1</span></label>
      <textarea id="texte_dyu" name="texte_dyu" rows="6" lang="dyu" <?= $ro ?>><?= e($m['texte_dyu']) ?></textarea>
      <label for="contre_traduction">3. Contre-traduction en français <span class="aide">linguiste n°2, sans voir le texte source</span></label>
      <textarea id="contre_traduction" name="contre_traduction" rows="6" <?= $ro ?>><?= e($m['contre_traduction']) ?></textarea>
      <?php if (!$m['evenement']): ?>
      <div class="deux">
        <div><label for="profil">Profil</label>
          <select id="profil" name="profil" <?= $modifiable ? '' : 'disabled' ?>><?php foreach (['enceinte', 'mere', 'toutes'] as $pr): ?><option value="<?= $pr ?>" <?= $m['profil'] === $pr ? 'selected' : '' ?>><?= e(libelle_profil($pr)) ?></option><?php endforeach; ?></select></div>
        <div><label for="ordre">Priorité <span class="aide">plus petit = plus tôt</span></label><input type="number" id="ordre" name="ordre" value="<?= (int) $m['ordre'] ?>" <?= $ro ?>></div>
        <div><label for="debut">Début <span class="aide"><?= $m['profil'] === 'mere' ? 'jours de vie' : 'semaine' ?></span></label><input type="number" id="debut" name="debut" value="<?= e($m['debut']) ?>" <?= $ro ?>></div>
        <div><label for="fin">Fin</label><input type="number" id="fin" name="fin" value="<?= e($m['fin']) ?>" <?= $ro ?>></div>
        <div><label for="repetition_jours">Répéter tous les (jours) <span class="aide">0 = une seule fois</span></label><input type="number" id="repetition_jours" name="repetition_jours" min="0" value="<?= (int) $m['repetition_jours'] ?>" <?= $ro ?>></div>
      </div>
      <?php else: ?>
        <input type="hidden" name="profil" value="<?= e($m['profil']) ?>"><input type="hidden" name="ordre" value="<?= (int) $m['ordre'] ?>">
      <?php endif; ?>
      <?php if ($modifiable): ?>
        <label for="audio">5. Enregistrement <span class="aide">OGG Opus mono, 24 kbit/s, 45 à 90 s</span></label>
        <input type="file" id="audio" name="audio" accept=".ogg,audio/ogg">
        <p class="discret">Conversion avec ffmpeg : <span class="code">ffmpeg -i voix.wav -c:a libopus -b:a 24k -ac 1 -ar 48000 message.ogg</span></p>
        <div class="actions"><button class="btn principal">Enregistrer le brouillon</button></div>
      <?php endif; ?>
    </form>

    <div>
      <div class="carte">
        <strong>Audio</strong>
        <?php if ($m['fichier_audio']): ?>
          <p><audio controls preload="none" src="../audio/<?= e($m['fichier_audio']) ?>"></audio></p>
          <p class="discret">Durée : <?= e(View::duree($m['duree_s'] !== null ? (int) $m['duree_s'] : null)) ?></p>
        <?php else: ?><p class="discret">Aucun enregistrement pour l’instant.</p><?php endif; ?>
      </div>

      <?php if ($m['statut'] === 'brouillon' && Auth::peut('messages_valider', $u)): ?>
      <form method="post" class="carte">
        <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="valider">
        <strong>7. Validation finale (directrice médicale)</strong>
        <label class="case"><input type="checkbox" name="confirmer" value="1" required>
          <span>J’ai comparé le texte source et la contre-traduction, arbitré les écarts, pris connaissance du pré-test de compréhension et écouté l’enregistrement. Je valide ce contenu pour diffusion.</span></label>
        <div class="actions"><button class="btn principal">Valider ce message</button></div>
      </form>
      <?php endif; ?>

      <?php if ($m['statut'] === 'valide' && Auth::peut('messages_modifier', $u)): ?>
      <div class="carte">
        <form method="post"><?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="nouvelle_version">
          <strong>Corriger ce message</strong>
          <p class="discret">Toute modification crée une nouvelle version, qui repasse par tout le circuit. L’actuelle reste diffusée en attendant.</p>
          <button class="btn">Créer une nouvelle version</button></form>
        <form method="post" data-confirmer="Retirer ce message de la diffusion ?">
          <?= Auth::champJeton() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="retirer">
          <label for="motif">Retrait immédiat en cas d’erreur</label>
          <input type="text" id="motif" name="motif" placeholder="Motif du retrait" required>
          <div class="actions"><button class="btn danger">Retirer de la diffusion</button></div>
        </form>
      </div>
      <?php endif; ?>

      <div class="carte">
        <strong>Versions</strong>
        <?php foreach ($versions as $v): ?>
          <p><a href="?id=<?= (int) $v['id'] ?>">Version <?= (int) $v['version'] ?></a> · <span class="etiquette <?= e($v['statut']) ?>"><?= e($libStatut[$v['statut']] ?? $v['statut']) ?></span> <?= $v['valide_le'] ? '· ' . e(View::date($v['valide_le'], false)) : '' ?></p>
        <?php endforeach; ?>
      </div>
    </div>
    </div>
    <?php
    View::pied();
    exit;
}

// Liste : la version la plus récente de chaque code
$liste = q(
    "SELECT m.* FROM messages m WHERE m.module NOT IN ('faq_confirmation', 'faq_reponse')
       AND m.version = (SELECT MAX(m2.version) FROM messages m2 WHERE m2.code = m.code) ORDER BY m.module, m.ordre, m.code"
)->fetchAll();
$diffuses = q("SELECT code, version FROM messages WHERE statut = 'valide'")->fetchAll(PDO::FETCH_KEY_PAIR);
$prets = count(array_filter($liste, static fn($m) => isset($diffuses[$m['code']])));

View::entete('Bibliothèque des messages', $u);
?>
<h1>Bibliothèque des messages</h1>
<p class="discret"><?= $prets ?> messages diffusables sur <?= count($liste) ?>. Aucun audio ne peut partir sans le statut « validé » donné par la directrice médicale (EF-10). Les confirmations et réponses des questions fréquentes se gèrent depuis la page <a href="questions.php">Questions fréquentes</a>.</p>
<?php $module = null; foreach ($liste as $m):
    if ($m['module'] !== $module):
        if ($module !== null) echo '</table></div>';
        $module = $m['module']; ?>
  <h2><?= e($libModule[$module] ?? $module) ?></h2>
  <div class="table-defile"><table>
  <tr><th>Code</th><th>Titre</th><th>Profil</th><th>Moment</th><th>Audio</th><th>Statut</th></tr>
<?php endif; ?>
  <tr>
    <td><a href="?id=<?= (int) $m['id'] ?>"><?= e($m['code']) ?></a></td>
    <td><?= e($m['titre']) ?></td>
    <td><?= e(libelle_profil($m['profil'])) ?></td>
    <td class="discret"><?= $m['evenement'] ? 'événement' : e(($m['debut'] ?? '?') . ' → ' . ($m['fin'] ?? '?') . ($m['profil'] === 'mere' ? ' j' : ' sem.')) ?></td>
    <td><?= $m['fichier_audio'] ? '✓ ' . e(View::duree($m['duree_s'] !== null ? (int) $m['duree_s'] : null)) : '–' ?></td>
    <td><span class="etiquette <?= e($m['statut']) ?>"><?= e($libStatut[$m['statut']] ?? $m['statut']) ?></span>
      <?php if ($m['statut'] !== 'valide' && isset($diffuses[$m['code']])): ?> <span class="discret">v<?= (int) $diffuses[$m['code']] ?> diffusée</span><?php endif; ?></td>
  </tr>
<?php endforeach; if ($module !== null) echo '</table></div>'; ?>
<?php View::pied();
