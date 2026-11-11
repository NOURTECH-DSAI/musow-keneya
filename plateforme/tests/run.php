<?php
/**
 * Simulation de bout en bout du parcours, sur SQLite et avec le canal de test.
 *   php tests/run.php
 */
declare(strict_types=1);

$dossierTest = sys_get_temp_dir() . '/musow-test-' . getmypid();
@mkdir($dossierTest, 0777, true);
$base = $dossierTest . '/test.sqlite';
@unlink($base);

$GLOBALS['MK_CONFIG'] = array_replace_recursive(require __DIR__ . '/../config.sample.php', [
    'url_base' => 'https://exemple.test/musow',
    'db' => ['pilote' => 'sqlite', 'chemin' => $base],
    'securite' => [
        'cle_chiffrement' => base64_encode(str_repeat('k', 32)),
        'cle_index' => base64_encode(str_repeat('i', 32)),
        'jeton_ia' => 'jeton-ia-test',
    ],
    'whatsapp' => ['mode' => 'test'],
]);
require __DIR__ . '/../src/bootstrap.php';

foreach (Schema::ddl('sqlite') as $sql) {
    db()->exec($sql);
}
Installation::semerBibliotheque(1);
q("INSERT INTO centres (nom, commune, telephone) VALUES ('CSU Abobo Test', 'Abobo', '27 24 00 00 00')");
q("INSERT INTO utilisateurs (email, nom, role, mot_de_passe, cree_le) VALUES ('sf@test', 'Sage-femme', 'animatrice', 'x', '2026-10-24 08:00:00')");

// Tous les messages deviennent « validés » avec un audio fictif
q("UPDATE messages SET statut = 'valide', valide_par = 1, valide_le = '2026-10-23 10:00:00', fichier_audio = code || '.ogg', duree_s = 60");

$canal = Canal::instance();
assert($canal instanceof CanalTest);

$ok = 0;
$ko = 0;
function verifier(bool $condition, string $libelle, mixed $detail = null): void
{
    global $ok, $ko;
    if ($condition) {
        $ok++;
        echo "  ✓ {$libelle}\n";
    } else {
        $ko++;
        echo "  ✗ {$libelle}\n";
        if ($detail !== null) {
            echo '      obtenu : ' . json_encode($detail, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}
function horloge(string $d): void
{
    $GLOBALS['MK_NOW'] = $d;
}
function types(array $envois): array
{
    return array_map(static fn($e) => $e['type'] . ':' . ($e['titre'] ?? $e['modele'] ?? mb_substr($e['texte'] ?? '', 0, 12)), $envois);
}
function dernierEnvoi(int $pid, string $motif): ?array
{
    return q1('SELECT * FROM envois WHERE participante_id = ? AND motif = ? ORDER BY id DESC LIMIT 1', [$pid, $motif]);
}

echo "1. Inscription d'une femme enceinte (20 semaines, rendez-vous dans 2 jours)\n";
horloge('2026-10-24 09:00:00');
$r = Parcours::inscrire([
    'numero' => '07 01 02 03 04', 'profil' => 'enceinte', 'semaine' => 20, 'centre_id' => 1,
    'consent_service' => true, 'consent_ia' => true, 'rdv_type' => 'prenatal', 'rdv_date' => '2026-10-26',
], 1);
$pid = $r['id'];
verifier($r['pseudo'] === 'MK-0001', 'pseudonyme MK-0001 attribué');
$p = Parcours::participante($pid);
verifier(Parcours::stade($p) === 20, 'stade calculé : 20e semaine');
$corr = q1('SELECT * FROM correspondances WHERE participante_id = ?', [$pid]);
verifier(!str_contains($corr['numero_chiffre'], '0701020304'), 'numéro chiffré en base');
verifier(Parcours::numero($pid) === '2250701020304', 'numéro déchiffrable et normalisé');
$e = $canal->vider();
verifier(count($e) === 1 && $e[0]['type'] === 'modele', 'fenêtre fermée : notification neutre envoyée');
verifier(str_starts_with($e[0]['payloads'][0], 'ECOUTER_'), 'bouton « Écouter » avec identifiant d’envoi');

try {
    Parcours::inscrire(['numero' => '+225 07 01 02 03 04', 'profil' => 'enceinte', 'semaine' => 12, 'consent_service' => true], 1);
    verifier(false, 'doublon refusé');
} catch (InvalidArgumentException $ex) {
    verifier(str_contains($ex->getMessage(), 'MK-0001'), 'doublon refusé (même numéro, autre format)');
}
try {
    Parcours::inscrire(['numero' => '0501010101', 'profil' => 'enceinte', 'semaine' => 12, 'consent_service' => false], 1);
    verifier(false, 'consentement exigé');
} catch (InvalidArgumentException $ex) {
    verifier(true, 'inscription sans consentement refusée');
}

echo "\n2. Elle appuie sur « Écouter »\n";
horloge('2026-10-24 09:02:00');
Parcours::recevoir('2250701020304', 'bouton', $e[0]['payloads'][0], null, 'wamid.IN1');
$e = $canal->vider();
verifier(types($e) === [
    'audio:Bienvenue et présentation de la voix du service',
    "audio:Mode d'emploi : les chiffres du menu",
    'boutons:Touchez un b',
], 'bienvenue + menu vocal + menu à boutons illustrés livrés', types($e));
verifier(array_keys(end($e)['boutons']) === ['ECOUTER', 'URGENCE', 'CENTRE'], 'boutons 🔊 Écouter, 🆘 Urgence, 🏥 Mon centre');
$p = Parcours::participante($pid);
verifier((int) $p['bienvenue_envoyee'] === 1, 'bienvenue marquée comme reçue');
verifier(dernierEnvoi($pid, 'bienvenue')['nature'] === 'audio', 'audio de bienvenue tracé');
verifier(q1("SELECT demande_le FROM envois WHERE participante_id = ? AND nature = 'notif'", [$pid])['demande_le'] !== null, 'écoute demandée enregistrée');
Parcours::recevoir('2250701020304', 'bouton', 'ECOUTER_1', null, 'wamid.IN1');
verifier($canal->vider() === [], 'notification Meta en double ignorée');

echo "\n3. Planificateur à 10 h (fenêtre ouverte)\n";
horloge('2026-10-24 10:00:00');
$bilan = Planificateur::executer();
$e = $canal->vider();
verifier($bilan['rappels'] === 1, 'rappel J-2 programmé');
verifier($bilan['contenus'] === 1, 'un contenu adapté au stade');
verifier(in_array('audio:Rappel de rendez-vous (consultation)', types($e), true), 'audio de rappel envoyé directement');
$boutons = array_values(array_filter($e, static fn($x) => $x['type'] === 'boutons'));
verifier(isset($boutons[0]['boutons']['RDV1_1']), 'boutons de réponse au rendez-vous');
verifier(str_contains($boutons[0]['texte'], 'lundi 26/10'), 'date lisible dans le rappel');
$contenu = dernierEnvoi($pid, 'contenu');
$msgContenu = q1('SELECT code FROM messages WHERE id = ?', [$contenu['message_id']]);
verifier($msgContenu['code'] === 'PRENAT-02', 'premier contenu : suivi prénatal du 2e trimestre (20e semaine)', $msgContenu);
horloge('2026-10-24 10:15:00');
$bilan = Planificateur::executer();
verifier($bilan['contenus'] === 0 && $bilan['rappels'] === 0, 'aucun doublon au passage suivant');
$canal->vider();

echo "\n4. Réponse au rappel et rappel J-1\n";
Parcours::recevoir('2250701020304', 'bouton', 'RDV1_1', null, 'wamid.IN2');
$rdv = q1('SELECT * FROM rendez_vous WHERE id = 1');
verifier((int) $rdv['reponse'] === 1, 'réponse « je viens » enregistrée');
horloge('2026-10-25 10:00:00');
$bilan = Planificateur::executer();
verifier($bilan['rappels'] === 0, 'pas de rappel J-1 après confirmation');
$canal->vider();

echo "\n5. Urgence : touche 2\n";
horloge('2026-10-25 22:40:00');
Parcours::recevoir('2250701020304', 'texte', '2', null, 'wamid.IN3');
$e = $canal->vider();
$alerte = q1('SELECT * FROM alertes WHERE participante_id = ?', [$pid]);
verifier($alerte && $alerte['origine'] === 'menu', 'alerte créée, même la nuit');
verifier($alerte['consigne_envoyee_le'] !== null, 'consigne envoyée immédiatement');
verifier(types($e)[0] === 'audio:Signes de danger pendant la grossesse (message permanent du menu)', 'audio des signes de danger');
verifier(str_contains($e[1]['texte'], '185') && str_contains($e[1]['texte'], '27 24 00 00 00'), 'numéros d’urgence et du centre');

echo "\n6. Message vocal de la femme (question vocale, aucune question encore validée)\n";
Parcours::recevoir('2250701020304', 'audio', null, 'MEDIA123', 'wamid.IN4');
$v = q1('SELECT * FROM vocaux WHERE participante_id = ?', [$pid]);
verifier($v && $v['statut'] === 'ia' && $v['ia_statut'] === 'a_faire', 'vocal transmis au service IA');
verifier($v['fichier'] && is_file(MK_ROOT . '/storage/vocaux/' . $v['fichier']), 'audio stocké chez NOUR TECH');
$e = $canal->vider();
verifier(types($e)[0] === 'audio:Question reçue : « je vous écoute, je vous réponds dans un instant »', 'accusé « je vous écoute »', types($e));
$d = Questions::analyser((int) $v['id'], 'transcription quelconque', 0.9, []);
verifier($d['decision'] === 'sans_reponse', 'bibliothèque vide : pas de réponse inventée');
verifier(qv('SELECT statut FROM vocaux WHERE id = ?', [$v['id']]) === 'a_traiter', 'question transmise à l’équipe');
$e = $canal->vider();
verifier(str_starts_with(types($e)[0], 'audio:Pas encore de réponse'), 'message honnête « pas encore de réponse »', types($e));
@unlink(MK_ROOT . '/storage/vocaux/' . $v['fichier']);

echo "\n7. Fenêtre fermée : notification puis écoute\n";
horloge('2026-10-27 09:30:00');
$bilan = Planificateur::executer();
$e = $canal->vider();
verifier($bilan['contenus'] === 1 && $e[0]['type'] === 'modele', 'notification neutre (fenêtre fermée)');
horloge('2026-10-27 12:00:00');
Parcours::recevoir('2250701020304', 'texte', '1', null, 'wamid.IN5');
$e = $canal->vider();
verifier(count($e) === 1 && $e[0]['type'] === 'audio', 'touche 1 : audio livré');
$livre = dernierEnvoi($pid, 'contenu');
verifier($livre['parent_id'] !== null, 'audio rattaché à sa notification (mesure de l’écoute)');
Parcours::statut((string) $livre['wa_id'], 'delivered');
Parcours::statut((string) $livre['wa_id'], 'read');
$livre = q1('SELECT * FROM envois WHERE id = ?', [$livre['id']]);
verifier($livre['statut'] === 'lu' && $livre['livre_le'] && $livre['lu_le'], 'accusés « livré » et « lu » enregistrés');
Parcours::recevoir('2250701020304', 'texte', '1', null, 'wamid.IN6');
$e = $canal->vider();
verifier(count($e) === 1 && $e[0]['type'] === 'audio', 'touche 1 sans attente : réécoute du dernier contenu');
Parcours::recevoir('2250701020304', 'texte', 'bonjour', null, 'wamid.IN7');
verifier(types($canal->vider())[0] === "audio:Mode d'emploi : les chiffres du menu", 'texte libre : menu vocal');

echo "\n8. Rythme : pas plus de 3 contenus par semaine\n";
foreach (['2026-10-29 10:00:00', '2026-10-31 10:00:00', '2026-11-02 10:00:00'] as $i => $d) {
    horloge($d);
    Parcours::recevoir('2250701020304', 'texte', '1', null, 'wamid.R' . $i);
    $canal->vider();
    Planificateur::executer();
    $canal->vider();
}
$n7 = (int) qv("SELECT COUNT(*) FROM envois WHERE participante_id = ? AND motif = 'contenu' AND parent_id IS NULL AND cree_le > ?", [$pid, '2026-10-26 10:00:00']);
verifier($n7 <= 3, "au plus 3 contenus sur 7 jours ({$n7})");
$codes = q("SELECT DISTINCT m.code FROM envois e JOIN messages m ON m.id = e.message_id WHERE e.participante_id = ? AND e.motif = 'contenu'", [$pid])->fetchAll(PDO::FETCH_COLUMN);
verifier(count($codes) === count(array_unique($codes)) && !in_array('ACCOUCH-01', $codes, true), 'pas de contenu hors stade');

echo "\n9. Numéro inconnu\n";
Parcours::recevoir('2250505050505', 'texte', 'allo', null, 'wamid.X1');
Parcours::recevoir('2250505050505', 'texte', 'allo', null, 'wamid.X2');
$e = $canal->vider();
verifier(count($e) === 1 && str_contains($e[0]['texte'], 'pas encore inscrit'), 'réponse unique sur 24 h');

echo "\n10. Panne de l’API puis relance\n";
horloge('2026-11-05 10:00:00');
$canal->enPanne = true;
Parcours::envoyer($pid, 'texte', 'test', ['texte' => 'essai']);
$echec = dernierEnvoi($pid, 'test');
verifier($echec['statut'] === 'echec', 'échec enregistré');
$canal->enPanne = false;
horloge('2026-11-05 10:15:00');
Planificateur::executer();
verifier(q1('SELECT statut FROM envois WHERE id = ?', [$echec['id']])['statut'] === 'envoye', 'relance automatique réussie');
$canal->vider();

echo "\n11. Arrêt (touche 9 / « stop »)\n";
Parcours::recevoir('2250701020304', 'texte', 'STOP', null, 'wamid.S1');
$p = Parcours::participante($pid);
verifier($p['statut'] === 'stoppee', 'service arrêté');
$e = $canal->vider();
verifier(count($e) === 1 && $e[0]['type'] === 'audio', 'confirmation vocale d’arrêt');
horloge('2026-11-08 10:00:00');
Planificateur::executer();
verifier($canal->vider() === [], 'plus aucun envoi programmé');

echo "\n12. Naissance à la date prévue, puis parcours « mère »\n";
horloge('2026-11-10 09:00:00');
$m = Parcours::inscrire(['numero' => '0505050505', 'profil' => 'enceinte', 'dpa' => '2026-11-12', 'consent_service' => true], 1);
Parcours::recevoir('2250505050505', 'texte', 'bonjour', null, 'wamid.M0');
$canal->vider();
horloge('2026-11-12 10:00:00');
$bilan = Planificateur::executer();
verifier($bilan['naissances'] === 1, 'question de naissance à la date prévue');
$e = $canal->vider();
verifier($e[0]['type'] === 'modele', 'fenêtre fermée : notification neutre', types($e));
Parcours::recevoir('2250505050505', 'texte', '1', null, 'wamid.M05');
$e = $canal->vider();
$bt = array_values(array_filter($e, static fn($x) => $x['type'] === 'boutons'));
verifier(isset($bt[0]['boutons']['NAISS_OUI']), 'boutons Oui / Pas encore', types($e));
Parcours::recevoir('2250505050505', 'bouton', 'NAISS_OUI', null, 'wamid.M1');
$pm = Parcours::participante($m['id']);
verifier($pm['profil'] === 'mere' && $pm['naissance_enfant'] === '2026-11-12', 'bascule vers le parcours mère');
$canal->vider();
horloge('2026-11-14 10:00:00');
Planificateur::executer();
$c = dernierEnvoi($m['id'], 'contenu');
$code = qv('SELECT code FROM messages WHERE id = ?', [$c['message_id']]);
verifier(in_array($code, ['POSTP-01', 'POSTP-02', 'NNE-03', 'NNE-01', 'NNE-02'], true), "contenu postnatal ({$code})");
$canal->vider();

echo "\n13. Questionnaire à J+10\n";
horloge('2026-11-20 11:00:00');
Parcours::recevoir('2250505050505', 'texte', 'bonjour', null, 'wamid.M2');
$canal->vider();
$bilan = Planificateur::executer();
verifier($bilan['sondages'] === 1, 'questionnaire lancé');
$canal->vider();
foreach (['5', '4', '1', '3', '5'] as $i => $rep) {
    Parcours::recevoir('2250505050505', 'texte', $rep, null, 'wamid.Q' . $i);
}
$rep = q('SELECT question, reponse FROM sondages WHERE participante_id = ? ORDER BY question', [$m['id']])->fetchAll();
verifier(count($rep) === 5 && (int) $rep[0]['reponse'] === 4 && (int) $rep[2]['reponse'] === 1, '5 réponses enregistrées sur une échelle de 1 à 4');
$e = $canal->vider();
verifier(end($e)['titre'] === 'Questionnaire : merci', 'remerciement final');
Parcours::recevoir('2250505050505', 'texte', '2', null, 'wamid.Q9');
verifier((int) qv('SELECT COUNT(*) FROM alertes WHERE participante_id = ?', [$m['id']]) === 1, 'après le questionnaire, 2 redevient l’urgence');
$canal->vider();

echo "\n13 bis. Boutons illustrés et salutations\n";
Parcours::recevoir('2250505050505', 'bouton', 'CENTRE', null, 'wamid.B1');
$e = $canal->vider();
verifier(count($e) >= 1 && !in_array('boutons', array_column($e, 'type'), true), 'bouton 🏥 Mon centre compris (pas de menu renvoyé)', types($e));
$avant = (int) qv('SELECT COUNT(*) FROM alertes WHERE participante_id = ?', [$m['id']]);
Parcours::recevoir('2250505050505', 'bouton', 'URGENCE', null, 'wamid.B2');
verifier((int) qv('SELECT COUNT(*) FROM alertes WHERE participante_id = ?', [$m['id']]) === $avant + 1, 'bouton 🆘 Urgence = alerte');
$canal->vider();
verifier(Questions::estSalutation('anni sɔgɔma') && Questions::estSalutation('I ni ce, i ka kɛnɛ wa') && Questions::estSalutation('bonjour tantie'), 'salutations reconnues');
verifier(!Questions::estSalutation('i ni sɔgɔma, ne b’a fɛ k’a lɔn muso bɛ jiginni kɛ cogo di') && !Questions::estSalutation('ne kɔnɔ bɛ ka dimi'), 'une question après la salutation reste une question');
verifier(Questions::sansSalutation('i ni sɔgɔma ne kɔnɔ bɛ ka dimi') === 'ne kono be ka dimi', 'salutation retirée avant la recherche');

echo "\n14. Sécurité des contenus (EF-10)\n";
q("UPDATE messages SET statut = 'brouillon' WHERE evenement = 'menu'");
Parcours::recevoir('2250505050505', 'texte', 'bonjour', null, 'wamid.M3');
$e = $canal->vider();
verifier(count($e) === 1 && $e[0]['type'] !== 'audio', 'message non validé jamais diffusé');
q("UPDATE messages SET statut = 'valide' WHERE evenement = 'menu'");

echo "\n15. Changement de numéro (EF-12)\n";
Parcours::changerNumero($m['id'], '0102030405', 1);
verifier(Parcours::numero($m['id']) === '2250102030405', 'nouveau numéro actif');
Parcours::recevoir('2250505050505', 'texte', '1', null, 'wamid.M4');
$e = $canal->vider();
verifier(isset($e[0]['texte']) && str_contains($e[0]['texte'], 'pas encore inscrit'), 'ancien numéro désactivé');

echo "\n16. Indicateurs publics\n";
$s = Indicateurs::publics();
verifier($s['inscrites'] === '< 10', 'aucun chiffre sous le seuil de 10 personnes');
verifier(isset($s['messages_envoyes']), 'indicateurs calculés');

echo "\n17. Mode manuel (repli)\n";
$GLOBALS['MK_CANAL'] = new CanalManuel();
Parcours::envoyer($m['id'], 'texte', 'test_manuel', ['texte' => 'bonjour']);
$t = q1('SELECT * FROM taches_manuelles ORDER BY id DESC LIMIT 1');
verifier($t && str_contains($t['consigne'], 'bonjour'), 'tâche manuelle créée');
verifier(dernierEnvoi($m['id'], 'test_manuel')['statut'] === 'a_faire', 'envoi en attente de l’équipe');


echo "\n18. Questions vocales à réponses vérifiées\n";
$GLOBALS['MK_CANAL'] = $canal;
horloge('2026-11-21 10:00:00');
verifier(Installation::semerQuestions(1) === 116, '116 questions fréquentes chargées');
Installation::semerLexique();
$qid = static fn(string $c) => (int) qv('SELECT id FROM questions WHERE code = ?', [$c]);
// Formulations en dioula (exemples de test) et validation des audios de deux questions
Questions::ajouterVariante($qid('Q026'), 'muso kɔnɔma ka kan ka mun dun', 'linguiste', 1);
Questions::ajouterVariante($qid('Q026'), 'n kɔnɔma lo, n bɛ se ka mun dun', 'linguiste', 1);
Questions::ajouterVariante($qid('Q048'), 'mun na an ka kan ka sunɔgɔ sanfɛ finiw kɔrɔ', 'linguiste', 1);
Questions::ajouterVariante($qid('Q013'), 'joli bɛ bɔ n na', 'linguiste', 1);
verifier(!Questions::ajouterVariante($qid('Q026'), 'Muso konoma ka kan ka mun dun', 'linguiste', 1), 'formulation en double refusée');
q("UPDATE messages SET statut = 'valide', fichier_audio = lower(code) || '.ogg' WHERE code IN ('FAQ-Q026-C', 'FAQ-Q026-R', 'FAQ-Q048-C', 'FAQ-Q048-R')");
q("UPDATE lexique SET termes = 'farigan|fariman', valide = 1 WHERE concept_fr = 'fièvre'");
$ind = Questions::indicateurs();
verifier($ind['pretes'] === 3, 'seules les questions avec audios validés et formulations sont utilisées (' . $ind['pretes'] . ')');

$f = Parcours::inscrire(['numero' => '0707070707', 'profil' => 'enceinte', 'semaine' => 16, 'consent_service' => true], 1);
$fid = $f['id'];
Parcours::recevoir('2250707070707', 'texte', 'bonjour', null, 'wamid.F0');
$canal->vider();
$poser = static function (string $id, string $transcription, float $score = 0.9) use ($fid): array {
    Parcours::recevoir('2250707070707', 'audio', null, 'MEDIA-' . $id, 'wamid.F' . $id);
    $v = q1('SELECT * FROM vocaux WHERE participante_id = ? ORDER BY id DESC LIMIT 1', [$fid]);
    @unlink(MK_ROOT . '/storage/vocaux/' . $v['fichier']);
    return ['vocal' => (int) $v['id'], 'decision' => Questions::analyser((int) $v['id'], $transcription, $score, [])];
};
$canal->vider();
$r = $poser('SAL', 'anni sɔgɔma');
$e = $canal->vider();
verifier($r['decision']['decision'] === 'salutation', 'vocal « anni sɔgɔma » : salutation reconnue');
verifier(q1('SELECT statut FROM vocaux WHERE id = ?', [$r['vocal']])['statut'] === 'auto', 'salutation non transmise à l’équipe');
verifier(in_array('audio:Réponse à une salutation : « I ni ce, posez votre question, je vous écoute »', types($e), true) && end($e)['type'] === 'boutons', 'réponse : audio de salutation puis boutons', types($e));

// a) question reconnue malgré une orthographe différente de celle des linguistes
$r = $poser('1', 'muso konoma ka kan ka mun dun wa');
$e = $canal->vider();
verifier($r['decision']['decision'] === 'confirmation' && $r['decision']['question'] === 'Q026', 'question reconnue : Q026 (score ' . ($r['decision']['score'] ?? '?') . ')', $r['decision']);
$bt = array_values(array_filter($e, static fn($x) => $x['type'] === 'boutons'));
verifier(in_array('audio:Confirmation : Que dois-je manger pendant la grossesse ?', types($e), true) && $bt && str_starts_with(array_key_first($bt[0]['boutons']), 'FAQ_OUI_'), 'reformulation et boutons Oui / Non', types($e));
$qp = q1('SELECT * FROM questions_posees ORDER BY id DESC LIMIT 1');
Parcours::recevoir('2250707070707', 'bouton', 'FAQ_OUI_' . $qp['id'], null, 'wamid.F1b');
$e = $canal->vider();
verifier(types($e)[0] === 'audio:Réponse : Que dois-je manger pendant la grossesse ?', 'réponse validée envoyée après confirmation', types($e));
verifier(q1('SELECT statut FROM questions_posees WHERE id = ?', [$qp['id']])['statut'] === 'repondue', 'question marquée « répondue »');
verifier(qv('SELECT statut FROM vocaux WHERE id = ?', [$r['vocal']]) === 'auto', 'réponse automatique à vérifier par l’équipe');

// b) confirmation par la voix du clavier : « awɔ »
$r = $poser('2', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
Parcours::recevoir('2250707070707', 'texte', 'Awɔ', null, 'wamid.F2b');
$e = $canal->vider();
verifier(isset($e[0]) && $e[0]['type'] === 'audio' && str_starts_with($e[0]['titre'], 'Réponse : Pourquoi dormir'), 'confirmation « awɔ » acceptée', types($e));

// b2) dioula de Côte d'Ivoire : « ɔnhɔn » (oui), avec tons saisis, puis « ɔn'ɔn » (non), puis « on » seul ignoré
$r = $poser('2c', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
Parcours::recevoir('2250707070707', 'texte', 'Ɔ́nhɔ̀n', null, 'wamid.T2c');
$e = $canal->vider();
verifier(isset($e[0]) && $e[0]['type'] === 'audio' && str_starts_with($e[0]['titre'], 'Réponse : Pourquoi dormir'), 'confirmation « ɔnhɔn » (dioula, avec tons) acceptée', types($e));

$r = $poser('2d', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$qp = q1('SELECT * FROM questions_posees ORDER BY id DESC LIMIT 1');
Parcours::recevoir('2250707070707', 'texte', "Ɔn'ɔn", null, 'wamid.T2d');
$canal->vider();
verifier(q1('SELECT statut FROM questions_posees WHERE id = ?', [$qp['id']])['statut'] === 'refusee', 'refus « ɔn\'ɔn » (dioula) : transfert à l’équipe');

$r = $poser('2e', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$qp = q1('SELECT * FROM questions_posees ORDER BY id DESC LIMIT 1');
Parcours::recevoir('2250707070707', 'texte', 'On', null, 'wamid.T2e');
$e = $canal->vider();
$aucuneReponse = !array_filter($e, static fn($x) => $x['type'] === 'audio' && str_starts_with((string) $x['titre'], 'Réponse :'));
verifier($aucuneReponse && q1('SELECT statut FROM questions_posees WHERE id = ?', [$qp['id']])['statut'] !== 'repondue', '« on » seul, ambigu : aucune réponse envoyée', types($e));
Parcours::recevoir('2250707070707', 'texte', 'ayi', null, 'wamid.T2f');
$canal->vider();
verifier(q1('SELECT statut FROM questions_posees WHERE id = ?', [$qp['id']])['statut'] === 'refusee', 'refus « ayi » (bambara) accepté');

// b3) confirmation À VOIX HAUTE : les femmes parlent, elles ne tapent pas.
$accuseEcoute = static fn(array $e): bool => (bool) array_filter($e, static fn($x) => ($x['texte'] ?? null) === cfg('textes.faq_ecoute') || str_starts_with((string) ($x['titre'] ?? ''), 'Question reçue'));
$statutQp = static fn(int $id): string => (string) q1('SELECT statut FROM questions_posees WHERE id = ?', [$id])['statut'];
$derniereQp = static fn(): array => q1('SELECT * FROM questions_posees ORDER BY id DESC LIMIT 1');
$reponseEnvoyee = static fn(array $e): bool => (bool) array_filter($e, static fn($x) => $x['type'] === 'audio' && str_starts_with((string) $x['titre'], 'Réponse :'));

$poser('V1', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$qp = $derniereQp();
$r = $poser('V1r', 'ɔnhɔn');
$e = $canal->vider();
verifier($r['decision']['decision'] === 'confirmation_oui' && $reponseEnvoyee($e) && $statutQp((int) $qp['id']) === 'repondue', 'oral « ɔnhɔn » : réponse validée envoyée', $r['decision']);
verifier(!$accuseEcoute($e), 'oral : pas d’accusé « je cherche » pour une simple confirmation', types($e));

$poser('V2', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$qp = $derniereQp();
$r = $poser('V2r', "ɔn'ɔn");
$e = $canal->vider();
verifier($r['decision']['decision'] === 'confirmation_non' && !$reponseEnvoyee($e) && $statutQp((int) $qp['id']) === 'refusee', 'oral « ɔn\'ɔn » : pas de réponse, transfert à l’équipe', $r['decision']);

$poser('V3', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$qp = $derniereQp();
$r = $poser('V3r', 'awɔ');
$e = $canal->vider();
verifier($r['decision']['decision'] === 'confirmation_oui' && $reponseEnvoyee($e), 'oral « awɔ » (bambara) : réponse envoyée', $r['decision']);

$poser('V4', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$qp = $derniereQp();
$r = $poser('V4r', 'on');
$e = $canal->vider();
verifier($r['decision']['decision'] === 'confirmation_incertaine' && !$reponseEnvoyee($e) && $statutQp((int) $qp['id']) === 'sans_reponse', 'oral « on » seul, ambigu : aucune réponse, transfert', $r['decision']);

$poser('V5', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$qp = $derniereQp();
$r = $poser('V5r', 'ɔnhɔn', 0.50);
$e = $canal->vider();
verifier($r['decision']['decision'] === 'confirmation_incertaine' && !$reponseEnvoyee($e), 'oral « ɔnhɔn » mal entendu (confiance 0,50) : aucune réponse', $r['decision']);

$poser('V6', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$qp = $derniereQp();
$r = $poser('V6r', 'muso konoma ka kan ka mun dun wa');
$e = $canal->vider();
verifier($statutQp((int) $qp['id']) === 'refusee' && $r['decision']['decision'] === 'confirmation' && $r['decision']['question'] === 'Q026' && $accuseEcoute($e), 'nouvelle question à la place d’un oui : la précédente part à l’équipe, la nouvelle est traitée', [$statutQp((int) $qp['id']), types($e)]);
$r = $poser('V6s', "ɔn'ɔn");
$canal->vider();
verifier($r['decision']['decision'] === 'confirmation_non', 'la nouvelle question attend bien sa propre confirmation');

$poser('V7', 'mun na an ka kan ka sunogo sanfe finiw koro');
$canal->vider();
$avant = (int) qv('SELECT COUNT(*) FROM alertes');
$r = $poser('V7r', "ɔn'ɔn joli be bo n na");
$canal->vider();
verifier($r['decision']['decision'] === 'danger' && (int) qv('SELECT COUNT(*) FROM alertes') === $avant + 1, 'signe de danger dit pendant la confirmation : urgence d’abord', $r['decision']);
Parcours::recevoir('2250707070707', 'bouton', 'FAQ_NON_' . $derniereQp()['id'], null, 'wamid.T7z');
q("UPDATE participantes SET attente_type = NULL, attente_ref = NULL, attente_expire_le = NULL WHERE id = ?", [$fid]);
$canal->vider();

verifier(Questions::lireOuiNon('Ɔ́nhɔ̀n ɔnhɔn') === 'oui' && Questions::lireOuiNon('ayi ayi') === 'non' && Questions::lireOuiNon('ɔnhɔn ayi') === 'incertain' && Questions::lireOuiNon('n ka kan ka mun dun') === null, 'lecture oui / non : répétitions, contradictions, messages longs');

// c) refus : « Non » → équipe
$r = $poser('3', 'muso konoma ka kan ka mun dun');
$canal->vider();
$qp = q1('SELECT * FROM questions_posees ORDER BY id DESC LIMIT 1');
Parcours::recevoir('2250707070707', 'bouton', 'FAQ_NON_' . $qp['id'], null, 'wamid.F3b');
$e = $canal->vider();
verifier(q1('SELECT statut FROM questions_posees WHERE id = ?', [$qp['id']])['statut'] === 'refusee' && str_starts_with(types($e)[0], 'audio:Pas encore de réponse'), 'question mal comprise : pas de réponse, transfert à l’équipe');
verifier(qv('SELECT statut FROM vocaux WHERE id = ?', [$r['vocal']]) === 'a_traiter', 'vocal remis dans la file de l’équipe');

// d) question de danger : consigne d'urgence immédiate, jamais de confirmation
$avant = (int) qv('SELECT COUNT(*) FROM alertes');
$r = $poser('4', 'joli be bo n na');
$e = $canal->vider();
verifier($r['decision']['decision'] === 'danger' && (int) qv('SELECT COUNT(*) FROM alertes') === $avant + 1, 'question de danger : alerte ouverte');
verifier(types($e)[1] === 'audio:Signes de danger pendant la grossesse (message permanent du menu)' && str_contains($e[2]['texte'], '185'), 'consigne d’urgence immédiate, après l’accusé', types($e));

// e) signe de danger repéré par le lexique, même dans une question inconnue
$r = $poser('5', 'n ka farigan ka bon kosebe');
$canal->vider();
verifier($r['decision']['decision'] === 'danger', 'lexique validé : fièvre détectée');

// f) question inconnue
$r = $poser('6', 'sankolo ye bulaman ye bi');
$e = $canal->vider();
verifier($r['decision']['decision'] === 'sans_reponse' && str_starts_with(types($e)[1], 'audio:Pas encore de réponse'), 'question inconnue : réponse honnête et transfert', [$r['decision'], types($e)]);

// g) audio peu clair
$r = $poser('7', 'muso konoma ka kan ka mun dun', 0.2);
$canal->vider();
verifier($r['decision']['decision'] === 'sans_reponse', 'transcription peu fiable : on ne devine pas');

// h) délais : analyse jamais revenue, confirmation jamais donnée
Parcours::recevoir('2250707070707', 'audio', null, 'MEDIA-8', 'wamid.F8');
$v8 = q1('SELECT * FROM vocaux WHERE participante_id = ? ORDER BY id DESC LIMIT 1', [$fid]);
@unlink(MK_ROOT . '/storage/vocaux/' . $v8['fichier']);
$canal->vider();
horloge('2026-11-21 10:10:00');
verifier(Questions::expirer() >= 1 && q1('SELECT statut FROM questions_posees WHERE vocal_id = ?', [$v8['id']])['statut'] === 'sans_reponse', 'service IA arrêté : la question part à l’équipe');
$canal->vider();
$r = $poser('9', 'muso konoma ka kan ka mun dun');
$canal->vider();
horloge('2026-11-21 12:30:00');
Questions::expirer();
verifier(q1('SELECT statut FROM questions_posees WHERE vocal_id = ?', [$r['vocal']])['statut'] === 'expiree', 'confirmation non donnée : transfert à l’équipe');

// i) l'urgence reste prioritaire pendant une confirmation
$r = $poser('10', 'muso konoma ka kan ka mun dun');
$canal->vider();
$nAlertes = (int) qv('SELECT COUNT(*) FROM alertes');
Parcours::recevoir('2250707070707', 'texte', '2', null, 'wamid.F10b');
verifier((int) qv('SELECT COUNT(*) FROM alertes') === $nAlertes + 1, 'touche 2 toujours prioritaire');
$canal->vider();

// j) indicateurs
$ind = Questions::indicateurs();
verifier($ind['repondues'] === 5 && $ind['danger'] === 3, 'indicateurs : 5 réponses, 3 dangers', $ind);
$nbOrales = (int) qv("SELECT COUNT(*) FROM questions_posees WHERE statut = 'confirmation'");
$nbTotal = (int) qv("SELECT COUNT(*) FROM questions_posees WHERE statut NOT IN ('en_analyse', 'ignoree')");
verifier($nbOrales > 0 && $ind['posees'] === $nbTotal - $nbOrales, 'les réponses orales ɔnhɔn / ɔn\'ɔn ne sont pas comptées comme des questions (' . $nbOrales . ')');
verifier($ind['couverture'] !== null && $ind['couverture'] < 1, 'taux de couverture calculé (' . round(100 * $ind['couverture']) . ' %)');
q('UPDATE questions_posees SET verifie_correct = 1 WHERE statut = ?', ['repondue']);
verifier(Questions::indicateurs()['taux_erreur'] === 0.0, 'taux d’erreur après vérification par l’équipe');

// k) apprentissage : la question inconnue devient une nouvelle formulation
$inconnue = q1("SELECT * FROM questions_posees WHERE motif = 'question inconnue' LIMIT 1");
verifier(Questions::ajouterVariante($qid('Q048'), (string) $inconnue['transcription'], 'pilote', 1), 'question reçue ajoutée comme formulation');

echo "\nRésultat : {$ok} vérifications réussies, {$ko} en échec.\n";
@unlink($base);
@rmdir($dossierTest);
exit($ko ? 1 : 0);
