<?php
declare(strict_types=1);

/**
 * Questions vocales en dioula, à réponses vérifiées.
 *
 * La femme pose sa question par message vocal. Le service IA la transcrit ;
 * ce module la rapproche des questions de la bibliothèque. Il ne fabrique
 * jamais de réponse :
 *  - signe de danger (lexique validé ou question marquée « alerte »)
 *      → consigne d'urgence immédiate et alerte à l'équipe ;
 *  - question reconnue avec certitude
 *      → il reformule la question comprise, la femme confirme à voix haute
 *        (ɔnhɔn / ɔn'ɔn, ou awɔ / ayi, ou oui / non) ou par les boutons,
 *        puis il envoie la réponse enregistrée et validée par la médecin ;
 *  - doute, question inconnue, refus ou absence de confirmation
 *      → message honnête « pas encore de réponse » et transfert à une sage-femme.
 * Chaque question sans réponse peut ensuite enrichir la bibliothèque.
 */
final class Questions
{
    public const THEMES = [
        'consultations' => 'Consultations et examens',
        'danger' => 'Signes inquiétants',
        'alimentation' => 'Alimentation et interdits',
        'medicaments' => 'Médicaments et plantes',
        'paludisme' => 'Paludisme',
        'quotidien' => 'Vie quotidienne et petits maux',
        'accouchement' => 'Accouchement',
        'postpartum' => 'Après l’accouchement et contraception',
        'bebe' => 'Le bébé',
        'vaccination' => 'Vaccinations',
        'service' => 'Le service',
        'autre' => 'Autres',
    ];

    public static function actives(): bool
    {
        return (bool) cfg('questions.actives', true) && Canal::mode() !== 'manuel';
    }

    // ------------------------------------------------------------------
    // Bibliothèque des questions
    // ------------------------------------------------------------------

    /** Crée une question et ses deux fiches message (confirmation, réponse), en brouillon. */
    public static function creer(string $code, string $theme, string $intitule, bool $alerte, string $profil, ?int $uid): int
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $code)) {
            throw new InvalidArgumentException('Code de question invalide (lettres, chiffres et tirets).');
        }
        if (qv('SELECT COUNT(*) FROM questions WHERE code = ?', [$code])) {
            throw new InvalidArgumentException('Le code ' . $code . ' existe déjà.');
        }
        $intitule = trim($intitule);
        if ($intitule === '') {
            throw new InvalidArgumentException('Intitulé obligatoire.');
        }
        $theme = isset(self::THEMES[$theme]) ? $theme : 'autre';
        $profil = in_array($profil, ['enceinte', 'mere', 'toutes'], true) ? $profil : 'toutes';
        $now = maintenant();
        q(
            'INSERT INTO questions (code, theme, intitule_fr, alerte, profil, statut, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$code, $theme, $intitule, $alerte ? 1 : 0, $profil, 'actif', $now]
        );
        $id = (int) db()->lastInsertId();
        $fiches = [
            ['C', 'faq_confirmation', 'Confirmation : ' . $intitule, 'Vous voulez savoir : ' . $intitule . ' Si c\'est votre question, dites « ɔnhɔn ». Sinon, dites « ɔn\'ɔn ».', 'Reformuler la question comprise, puis inviter la femme à répondre à voix haute : ɔnhɔn (oui) ou ɔn\'ɔn (non).'],
            ['R', 'faq_reponse', 'Réponse : ' . $intitule, null, 'Répondre à la question, simplement, et orienter vers le centre si besoin.'],
        ];
        foreach ($fiches as [$suffixe, $module, $titre, $texte, $objectif]) {
            $codeMsg = 'FAQ-' . $code . '-' . $suffixe;
            if (!qv('SELECT COUNT(*) FROM messages WHERE code = ?', [$codeMsg])) {
                q(
                    'INSERT INTO messages (code, version, titre, module, evenement, langue, profil, objectif, texte_fr, statut, ordre, cree_le)
                     VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$codeMsg, mb_substr($titre, 0, 160), $module, 'faq', (string) cfg('langue_defaut', 'dyu'), 'toutes', $objectif, $texte, 'brouillon', 100, $now]
                );
            }
        }
        journal('u:' . ($uid ?? 0), 'question_creee', ['code' => $code, 'alerte' => $alerte]);
        return $id;
    }

    /** Import CSV : code;theme;intitule_fr;alerte;profil (séparateur « ; »). */
    public static function importerQuestions(string $chemin, ?int $uid): array
    {
        $crees = 0;
        $ignores = 0;
        foreach (self::lireCsv($chemin) as $l) {
            if (empty($l['code']) || empty($l['intitule_fr'])) {
                $ignores++;
                continue;
            }
            try {
                self::creer($l['code'], $l['theme'] ?? 'autre', $l['intitule_fr'], ($l['alerte'] ?? '0') === '1', $l['profil'] ?? 'toutes', $uid);
                $crees++;
            } catch (InvalidArgumentException) {
                $ignores++;
            }
        }
        return ['crees' => $crees, 'ignores' => $ignores];
    }

    /** Import CSV : code;variante (une formulation en dioula par ligne). */
    public static function importerVariantes(string $chemin, ?int $uid): array
    {
        $ajoutees = 0;
        $ignorees = 0;
        foreach (self::lireCsv($chemin) as $l) {
            $qid = qv('SELECT id FROM questions WHERE code = ?', [strtoupper(trim((string) ($l['code'] ?? '')))]);
            $texte = trim((string) ($l['variante'] ?? $l['texte'] ?? ''));
            if (!$qid || $texte === '' || !self::ajouterVariante((int) $qid, $texte, 'import', $uid)) {
                $ignorees++;
                continue;
            }
            $ajoutees++;
        }
        return ['ajoutees' => $ajoutees, 'ignorees' => $ignorees];
    }

    public static function ajouterVariante(int $qid, string $texte, string $source, ?int $uid): bool
    {
        $texte = trim(preg_replace('/\s+/u', ' ', $texte) ?? '');
        if ($texte === '' || mb_strlen($texte) > 500) {
            return false;
        }
        $norm = self::normaliser($texte);
        foreach (q('SELECT texte FROM variantes WHERE question_id = ?', [$qid])->fetchAll(PDO::FETCH_COLUMN) as $existante) {
            if (self::normaliser((string) $existante) === $norm) {
                return false; // doublon
            }
        }
        q('INSERT INTO variantes (question_id, texte, source, cree_par, cree_le) VALUES (?, ?, ?, ?, ?)', [$qid, $texte, $source, $uid, maintenant()]);
        return true;
    }

    /** @return array{confirmation:?array, reponse:?array} versions validées en vigueur */
    public static function fiches(array $q): array
    {
        $trouver = static function (string $code): ?array {
            return q1("SELECT * FROM messages WHERE code = ? AND statut = 'valide' AND fichier_audio IS NOT NULL ORDER BY version DESC LIMIT 1", [$code]);
        };
        return ['confirmation' => $trouver('FAQ-' . $q['code'] . '-C'), 'reponse' => $trouver('FAQ-' . $q['code'] . '-R')];
    }

    /** Une question n'est utilisée que si elle est active, a au moins une formulation et ses deux audios validés. */
    public static function prete(array $q): bool
    {
        if ($q['statut'] !== 'actif') {
            return false;
        }
        if (!qv('SELECT COUNT(*) FROM variantes WHERE question_id = ?', [$q['id']])) {
            return false;
        }
        if ((int) $q['alerte'] === 1) {
            return true; // une question de danger déclenche la consigne d'urgence, sans audio propre
        }
        $f = self::fiches($q);
        return $f['confirmation'] !== null && $f['reponse'] !== null;
    }

    // ------------------------------------------------------------------
    // Rapprochement d'une transcription avec les questions connues
    // ------------------------------------------------------------------

    /** Écriture simplifiée : tolère les différences d'orthographe entre la transcription automatique et les linguistes. */
    public static function normaliser(string $t): string
    {
        $t = mb_strtolower($t, 'UTF-8');
        $t = strtr($t, ['ɛ' => 'e', 'ɔ' => 'o', 'ɲ' => 'ny', 'ŋ' => 'ng', 'ƒ' => 'f', 'ʋ' => 'v', '’' => "'", 'ç' => 'c']);
        if (class_exists('Normalizer')) {
            $t = (string) Normalizer::normalize($t, Normalizer::FORM_D);
            $t = preg_replace('/\p{Mn}+/u', '', $t) ?? $t; // accents et tons
        } else {
            $t = strtr($t, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u']);
        }
        $t = preg_replace('/[^a-z0-9 ]+/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    /** Trigrammes de caractères (robustes aux erreurs de transcription) et mots entiers. */
    private static function traits(string $texteNormalise): array
    {
        $traits = [];
        foreach (explode(' ', $texteNormalise) as $mot) {
            if ($mot === '') {
                continue;
            }
            $m = ' ' . $mot . ' ';
            $n = strlen($m);
            for ($i = 0; $i <= $n - 3; $i++) {
                $g = 'c:' . substr($m, $i, 3);
                $traits[$g] = ($traits[$g] ?? 0) + 1;
            }
            if (strlen($mot) >= 3) {
                $traits['m:' . $mot] = ($traits['m:' . $mot] ?? 0) + 2;
            }
        }
        return $traits;
    }

    /** Index des formulations des questions prêtes (quelques centaines : calcul instantané). */
    private static function index(?string $profil): array
    {
        $questions = q("SELECT * FROM questions WHERE statut = 'actif'")->fetchAll();
        $pretes = [];
        foreach ($questions as $qq) {
            if (($profil === null || $qq['profil'] === 'toutes' || $qq['profil'] === $profil) && self::prete($qq)) {
                $pretes[(int) $qq['id']] = $qq;
            }
        }
        $docs = [];
        $df = [];
        if ($pretes) {
            $ids = implode(',', array_keys($pretes));
            foreach (q("SELECT question_id, texte FROM variantes WHERE question_id IN ({$ids})")->fetchAll() as $v) {
                $t = self::traits(self::normaliser((string) $v['texte']));
                if (!$t) {
                    continue;
                }
                $docs[] = ['q' => (int) $v['question_id'], 't' => $t];
                foreach ($t as $g => $_) {
                    $df[$g] = ($df[$g] ?? 0) + 1;
                }
            }
        }
        $n = count($docs);
        $idf = [];
        foreach ($df as $g => $d) {
            $idf[$g] = log(($n + 1) / ($d + 1)) + 1;
        }
        foreach ($docs as &$doc) {
            $doc['v'] = self::ponderer($doc['t'], $idf);
            unset($doc['t']);
        }
        unset($doc);
        return ['docs' => $docs, 'idf' => $idf, 'questions' => $pretes];
    }

    /** Vecteur TF-IDF normalisé. */
    private static function ponderer(array $traits, array $idf): array
    {
        $v = [];
        $norme = 0.0;
        foreach ($traits as $g => $tf) {
            $w = (1 + log($tf)) * ($idf[$g] ?? (log(2) + 1));
            $v[$g] = $w;
            $norme += $w * $w;
        }
        $norme = sqrt($norme) ?: 1.0;
        foreach ($v as $g => $w) {
            $v[$g] = $w / $norme;
        }
        return $v;
    }

    /**
     * Questions les plus proches d'une transcription, de la plus probable à la moins probable.
     * @return list<array{question_id:int, code:string, intitule:string, score:float}>
     */
    public static function apparier(string $transcription, ?string $profil = null, int $limite = 3): array
    {
        $idx = self::index($profil);
        $requete = self::traits(self::normaliser($transcription));
        if (!$requete || !$idx['docs']) {
            return [];
        }
        $vq = self::ponderer($requete, $idx['idf']);
        $meilleurs = [];
        foreach ($idx['docs'] as $doc) {
            $s = 0.0;
            foreach ($vq as $g => $w) {
                if (isset($doc['v'][$g])) {
                    $s += $w * $doc['v'][$g];
                }
            }
            if ($s > ($meilleurs[$doc['q']] ?? 0.0)) {
                $meilleurs[$doc['q']] = $s;
            }
        }
        arsort($meilleurs);
        $res = [];
        foreach (array_slice($meilleurs, 0, $limite, true) as $qid => $s) {
            $qq = $idx['questions'][$qid];
            $res[] = ['question_id' => $qid, 'code' => $qq['code'], 'intitule' => $qq['intitule_fr'], 'score' => round($s, 4)];
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Parcours d'une question posée
    // ------------------------------------------------------------------

    /** Concepts du lexique validé trouvés dans une transcription. */
    public static function termesDanger(string $transcription): array
    {
        $texte = ' ' . self::normaliser($transcription) . ' ';
        $trouves = [];
        foreach (q('SELECT concept_fr, termes FROM lexique WHERE valide = 1')->fetchAll() as $l) {
            foreach (explode('|', (string) $l['termes']) as $terme) {
                $t = self::normaliser($terme);
                if ($t !== '' && (str_contains($texte, ' ' . $t . ' ') || (strlen($t) >= 7 && str_contains($texte, $t)))) {
                    $trouves[] = $l['concept_fr'];
                    break;
                }
            }
        }
        return array_values(array_unique($trouves));
    }

    /** À la réception d'un vocal : on accuse réception et on attend la transcription. */
    public static function ouvrir(array $p, int $vocalId): int
    {
        q(
            'INSERT INTO questions_posees (participante_id, vocal_id, statut, cree_le) VALUES (?, ?, ?, ?)',
            [$p['id'], $vocalId, 'en_analyse', maintenant()]
        );
        $id = (int) db()->lastInsertId();
        q("UPDATE vocaux SET statut = 'ia' WHERE id = ?", [$vocalId]);
        if (self::confirmationEnAttente($p)) {
            // Ce vocal est sans doute la réponse orale à « Vous voulez savoir… ? » :
            // pas d'accusé « je cherche la réponse » ; analyser() tranchera.
            return $id;
        }
        self::accuser($p);
        return $id;
    }

    /** Question en attente de confirmation (statut « a_confirmer »), si le délai court encore. */
    private static function confirmationEnAttente(array $p): ?array
    {
        if ($p['attente_type'] !== 'faq' || !$p['attente_expire_le'] || $p['attente_expire_le'] <= maintenant()) {
            return null;
        }
        return q1("SELECT * FROM questions_posees WHERE id = ? AND participante_id = ? AND statut = 'a_confirmer'", [(int) $p['attente_ref'], $p['id']]);
    }

    private static function accuser(array $p): void
    {
        $msg = Parcours::messageEvenement('faq_ecoute', $p);
        if ($msg) {
            Parcours::envoyerMessage((int) $p['id'], $msg, 'faq');
        } else {
            Parcours::envoyer((int) $p['id'], 'texte', 'faq', ['texte' => (string) cfg('textes.faq_ecoute')]);
        }
    }

    /**
     * Décision après transcription (appelée par api/ia.php).
     * @param list<string> $termesDanger concepts du lexique des signes de danger trouvés dans la transcription
     * @return array{decision:string, question?:string, score?:float}
     */
    public static function analyser(int $vocalId, string $transcription, ?float $scoreAsr, array $termesDanger): array
    {
        $qp = q1("SELECT * FROM questions_posees WHERE vocal_id = ? AND statut = 'en_analyse'", [$vocalId]);
        if (!$qp) {
            return ['decision' => 'aucune'];
        }
        $p = Parcours::participante((int) $qp['participante_id']);
        q(
            'UPDATE questions_posees SET transcription = ?, score_asr = ?, termes = ?, analyse_le = ? WHERE id = ?',
            [mb_substr($transcription, 0, 5000), $scoreAsr, $termesDanger ? mb_substr(implode(', ', $termesDanger), 0, 255) : null, maintenant(), $qp['id']]
        );

        // 1. Les signes de danger passent avant tout (lexique validé, côté serveur).
        $termesDanger = array_values(array_unique(array_merge($termesDanger, self::termesDanger($transcription))));
        if ($termesDanger) {
            q('UPDATE questions_posees SET termes = ? WHERE id = ?', [mb_substr(implode(', ', $termesDanger), 0, 255), $qp['id']]);
            return self::danger($qp, $p, 'lexique : ' . implode(', ', $termesDanger));
        }
        if ($p['statut'] !== 'active') {
            q("UPDATE questions_posees SET statut = 'ignoree', motif = 'participante inactive' WHERE id = ?", [$qp['id']]);
            return ['decision' => 'ignoree'];
        }
        // 1 bis. Simple salutation (« i ni sɔgɔma ») : on salue en retour, sans déranger l'équipe.
        if (self::estSalutation($transcription)) {
            return self::saluer($qp, $p);
        }
        // 2. Réponse orale à « Vous voulez savoir… ? » (les femmes parlent, elles ne tapent pas).
        $enAttente = self::confirmationEnAttente($p);
        if ($enAttente) {
            $decision = self::confirmationOrale($qp, $enAttente, $p, $transcription, $scoreAsr);
            if ($decision !== null) {
                return $decision;
            }
            // Message long : c'est une nouvelle question. La précédente part à l'équipe.
            q("UPDATE questions_posees SET statut = 'refusee', motif = 'nouvelle question posée' WHERE id = ?", [$enAttente['id']]);
            q("UPDATE participantes SET attente_type = NULL, attente_ref = NULL, attente_expire_le = NULL WHERE id = ?", [$p['id']]);
            self::versEquipe($enAttente, $p, 'nouvelle question posée avant confirmation');
            $p = Parcours::participante((int) $p['id']);
            self::accuser($p);
        }
        // 3. Audio trop peu clair : on ne devine pas.
        if ($scoreAsr !== null && $scoreAsr < (float) cfg('questions.seuil_audio', 0.45)) {
            return self::sansReponse($qp, $p, 'audio peu clair');
        }
        if (trim($transcription) === '') {
            return self::sansReponse($qp, $p, 'transcription vide');
        }
        // 4. Rapprochement avec les questions validées.
        $cands = self::apparier(self::sansSalutation($transcription), $p['profil']);
        $premier = $cands[0] ?? null;
        $second = $cands[1] ?? null;
        q(
            'UPDATE questions_posees SET question_id = ?, score = ?, second_id = ?, second_score = ? WHERE id = ?',
            [$premier['question_id'] ?? null, $premier['score'] ?? null, $second['question_id'] ?? null, $second['score'] ?? null, $qp['id']]
        );
        $seuil = (float) cfg('questions.seuil_reconnaissance', 0.55);
        $marge = (float) cfg('questions.marge', 0.06);
        if (!$premier || $premier['score'] < $seuil) {
            return self::sansReponse($qp, $p, 'question inconnue');
        }
        if ($second && $premier['score'] - $second['score'] < $marge) {
            return self::sansReponse($qp, $p, 'deux questions possibles');
        }
        $question = q1('SELECT * FROM questions WHERE id = ?', [$premier['question_id']]);
        if ((int) $question['alerte'] === 1) {
            return self::danger($qp, $p, 'question de danger : ' . $question['code']);
        }
        // 5. Reformulation et demande de confirmation (réponse à voix haute, ou boutons).
        $fiches = self::fiches($question);
        Parcours::envoyerMessage((int) $p['id'], $fiches['confirmation'], 'faq');
        Parcours::envoyer((int) $p['id'], 'boutons', 'faq', [
            'texte' => (string) cfg('textes.faq_confirmation'),
            'boutons' => ['FAQ_OUI_' . $qp['id'] => '✅ Ɔnhɔn · Oui', 'FAQ_NON_' . $qp['id'] => '❌ Ɔn\'ɔn · Non'],
        ]);
        q("UPDATE questions_posees SET statut = 'a_confirmer', confirmation_le = ? WHERE id = ?", [maintenant(), $qp['id']]);
        q(
            "UPDATE participantes SET attente_type = 'faq', attente_ref = ?, attente_expire_le = ? WHERE id = ?",
            [$qp['id'], plus(maintenant(), '+' . (int) cfg('questions.delai_confirmation_h', 2) . ' hours'), $p['id']]
        );
        return ['decision' => 'confirmation', 'question' => $question['code'], 'score' => $premier['score']];
    }

    // ------------------------------------------------------------------
    // Confirmation à voix haute
    // ------------------------------------------------------------------

    /** Formes reconnues, après normaliser() puis suppression des espaces. Réglables dans config.php. */
    private const OUI_DEFAUT = [
        'onhon',                         // dioula de Côte d'Ivoire : ɔnhɔn
        'awo', 'owo',                    // bambara : awɔ
        'oui',                           // français
    ];
    private const NON_DEFAUT = [
        'onon',                          // dioula de Côte d'Ivoire : ɔn'ɔn
        'ayi', 'ai',                     // bambara : ayi
        'non',                           // français
    ];
    // « on » seul n'y figure pas : il peut se lire oui comme non. Les graphies réellement
    // produites par le modèle de reconnaissance seront ajoutées après les tests (ia/evaluer.py).

    /**
     * Lit oui / non dans la transcription d'une réponse courte.
     * @return 'oui'|'non'|'incertain'|null  null = message long, donc une nouvelle question
     */
    public static function lireOuiNon(string $transcription): ?string
    {
        $norm = self::normaliser($transcription);
        if ($norm === '') {
            return 'incertain';
        }
        $mots = explode(' ', $norm);
        if (count($mots) > (int) cfg('questions.confirmation_mots_max', 3)) {
            return null;
        }
        $oui = array_map([self::class, 'compacter'], (array) cfg('questions.mots_oui', self::OUI_DEFAUT));
        $non = array_map([self::class, 'compacter'], (array) cfg('questions.mots_non', self::NON_DEFAUT));
        $toutColle = self::compacter($norm);
        // Le message entier, ou chacun de ses mots (« ɔnhɔn ɔnhɔn », « awɔ dɛ »).
        $vuOui = in_array($toutColle, $oui, true);
        $vuNon = in_array($toutColle, $non, true);
        foreach ($mots as $m) {
            $vuOui = $vuOui || in_array($m, $oui, true);
            $vuNon = $vuNon || in_array($m, $non, true);
        }
        if ($vuOui === $vuNon) {
            return 'incertain'; // ni l'un ni l'autre, ou les deux
        }
        return $vuOui ? 'oui' : 'non';
    }

    private static function compacter(string $t): string
    {
        return str_replace(' ', '', self::normaliser($t));
    }

    /**
     * Applique une réponse orale. Un oui n'est accepté que s'il est net (confiance audio plus
     * exigeante) : dans le doute, la femme n'obtient pas de réponse automatique et une
     * sage-femme la rappelle. Se tromper vers le « non » ne coûte qu'un rappel.
     */
    private static function confirmationOrale(array $qp, array $enAttente, array $p, string $transcription, ?float $scoreAsr): ?array
    {
        $lu = self::lireOuiNon($transcription);
        if ($lu === null) {
            return null;
        }
        $seuilOui = (float) cfg('questions.seuil_audio_oui', 0.60);
        if ($lu === 'oui' && $scoreAsr !== null && $scoreAsr < $seuilOui) {
            $lu = 'incertain';
        }
        // Ce vocal n'est pas une question : on le garde pour la vérification, hors indicateurs.
        q("UPDATE questions_posees SET statut = 'confirmation', motif = ? WHERE id = ?", ['réponse orale : ' . $lu, $qp['id']]);
        q("UPDATE vocaux SET statut = 'auto' WHERE id = ? AND statut = 'ia'", [$qp['vocal_id']]);
        journal('systeme', 'confirmation_orale', ['participante' => $p['pseudo'], 'question_posee' => $enAttente['id'], 'lu' => $lu, 'transcription' => mb_substr($transcription, 0, 80)]);
        if ($lu === 'oui' || $lu === 'non') {
            self::confirmer($p, (int) $enAttente['id'], $lu === 'oui');
            return ['decision' => 'confirmation_' . $lu];
        }
        q("UPDATE participantes SET attente_type = NULL, attente_ref = NULL, attente_expire_le = NULL WHERE id = ?", [$p['id']]);
        q("UPDATE questions_posees SET statut = 'sans_reponse', motif = 'confirmation incertaine' WHERE id = ?", [$enAttente['id']]);
        self::messageSansReponse($p);
        self::versEquipe($enAttente, $p, 'confirmation orale incertaine');
        return ['decision' => 'confirmation_incertaine'];
    }

    /** Réponse de la femme à « Vous voulez savoir… ? ». */
    public static function confirmer(array $p, int $qpId, bool $oui): void
    {
        $qp = q1("SELECT * FROM questions_posees WHERE id = ? AND participante_id = ? AND statut = 'a_confirmer'", [$qpId, $p['id']]);
        q("UPDATE participantes SET attente_type = NULL, attente_ref = NULL, attente_expire_le = NULL WHERE id = ? AND attente_type = 'faq'", [$p['id']]);
        if (!$qp) {
            Parcours::envoyerMenu((int) $p['id']);
            return;
        }
        if (!$oui) {
            q("UPDATE questions_posees SET statut = 'refusee', motif = 'la femme n’a pas confirmé' WHERE id = ?", [$qpId]);
            self::messageSansReponse($p);
            self::versEquipe($qp, $p, 'question mal comprise');
            return;
        }
        $question = q1('SELECT * FROM questions WHERE id = ?', [$qp['question_id']]);
        $fiches = $question ? self::fiches($question) : ['reponse' => null];
        if (!$fiches['reponse']) {
            // La réponse a été retirée entre-temps : on ne répond pas au hasard.
            q("UPDATE questions_posees SET statut = 'sans_reponse', motif = 'réponse retirée' WHERE id = ?", [$qpId]);
            self::messageSansReponse($p);
            self::versEquipe($qp, $p, 'réponse indisponible');
            return;
        }
        Parcours::envoyerMessage((int) $p['id'], $fiches['reponse'], 'faq_reponse');
        $autre = Parcours::messageEvenement('faq_autre', $p);
        if ($autre) {
            Parcours::envoyerMessage((int) $p['id'], $autre, 'faq');
        }
        q("UPDATE questions_posees SET statut = 'repondue', repondu_le = ? WHERE id = ?", [maintenant(), $qpId]);
        // Le vocal reste visible pour que l'équipe vérifie la réponse automatique.
        q("UPDATE vocaux SET statut = 'auto' WHERE id = ?", [$qp['vocal_id']]);
        journal('systeme', 'question_repondue', ['participante' => $p['pseudo'], 'question' => $question['code']]);
    }

    private static function danger(array $qp, array $p, string $motif): array
    {
        q("UPDATE questions_posees SET statut = 'danger', danger = 1, motif = ? WHERE id = ?", [mb_substr($motif, 0, 40), $qp['id']]);
        q("UPDATE vocaux SET statut = 'a_traiter', priorite = 1 WHERE id = ?", [$qp['vocal_id']]);
        Parcours::danger($p, 'ia', (int) $qp['vocal_id']);
        return ['decision' => 'danger'];
    }

    private static function sansReponse(array $qp, array $p, string $motif): array
    {
        q("UPDATE questions_posees SET statut = 'sans_reponse', motif = ? WHERE id = ?", [$motif, $qp['id']]);
        self::messageSansReponse($p);
        self::versEquipe($qp, $p, $motif);
        return ['decision' => 'sans_reponse'];
    }

    private static function messageSansReponse(array $p): void
    {
        $msg = Parcours::messageEvenement('faq_sans_reponse', $p);
        if ($msg) {
            Parcours::envoyerMessage((int) $p['id'], $msg, 'faq');
        }
        Parcours::envoyer((int) $p['id'], 'texte', 'faq', ['texte' => (string) cfg('textes.faq_sans_reponse')]);
    }

    // ------------------------------------------------------------------
    // Salutations
    // ------------------------------------------------------------------

    /**
     * Formules de salutation, sur le texte normalisé (ɛ→e, ɔ→o, sans tons).
     * Dioula et bambara d'abord, puis les formes françaises et arabes courantes.
     * À compléter par les linguistes au vu des transcriptions réelles.
     */
    private const SALUTATIONS = [
        '(?:a|i)?\s?n+i\s?(?:so\s?go\s?ma|so\s?ro\s?ma|so\s?gu\s?ma|ti\s?le|wu\s?la|w\s?la|su|ce|che|baara|kene\s?ma)',
        'i\s?ka\s?kene(?:\s?wa)?', 'kene\s?wa', 'here\s?(?:dama|sira)?(?:\s?wa)?', 'too?ro\s?(?:si\s?)?te',
        'baraka', 'a?s?\s?salamu?\s?(?:a\s?)?le(?:i|y)?\s?kum', 'salam(?:alekum|aleikum)?', 'ale(?:i|y)?kum\s?salam',
        'bon\s?jour', 'bon\s?soir', 'bonne\s?nuit', 'allo', 'hello',
    ];

    /** Mots qui accompagnent une salutation sans en faire une question. */
    private const MOTS_POLITESSE = [
        'a', 'i', 'e', 'o', 'n', 'm', 'ah', 'eh', 'oh', 'wa', 'ka', 'ne', 'na', 'ba', 'nba', 'se', 'nse', 'ye', 'yo',
        'dama', 'sa', 'si', 'te', 'ni', 'ko', 'muso', 'kene', 'here', 'ce', 'merci', 'madame', 'tantie', 'maman',
    ];

    /** La transcription ne contient qu'une salutation, sans question. */
    public static function estSalutation(string $transcription): bool
    {
        $n = self::normaliser($transcription);
        if ($n === '') {
            return false;
        }
        $reste = $n;
        $trouvee = false;
        foreach (self::SALUTATIONS as $motif) {
            $reste = (string) preg_replace('/\b' . $motif . '\b/', ' ', $reste, -1, $nb);
            $trouvee = $trouvee || $nb > 0;
        }
        if (!$trouvee) {
            return false;
        }
        foreach (explode(' ', trim(preg_replace('/\s+/', ' ', $reste) ?? '')) as $mot) {
            if ($mot !== '' && !in_array($mot, self::MOTS_POLITESSE, true)) {
                return false;
            }
        }
        return true;
    }

    /** Retire les salutations avant de chercher la question (« i ni sɔgɔma, ne b'a fɛ k'a lɔn… »). */
    public static function sansSalutation(string $transcription): string
    {
        $n = self::normaliser($transcription);
        foreach (self::SALUTATIONS as $motif) {
            $n = (string) preg_replace('/\b' . $motif . '\b/', ' ', $n);
        }
        $n = trim(preg_replace('/\s+/', ' ', $n) ?? '');
        return $n !== '' ? $n : $transcription;
    }

    private static function saluer(array $qp, array $p): array
    {
        q("UPDATE questions_posees SET statut = 'salutation', motif = 'salutation' WHERE id = ?", [$qp['id']]);
        q("UPDATE vocaux SET statut = 'auto' WHERE id = ? AND statut = 'ia'", [$qp['vocal_id']]);
        $pid = (int) $p['id'];
        $msg = Parcours::messageEvenement('salutation', $p);
        if ($msg) {
            Parcours::envoyerMessage($pid, $msg, 'faq');
            Parcours::boutonsMenu($pid);
        } else {
            Parcours::boutonsMenu($pid, (string) cfg('textes.salutation', 'I ni ce ! Bienvenue à Musow Kènèya. Posez votre question en envoyant un message vocal, je vous écoute. Vous pouvez aussi toucher un bouton.'));
        }
        return ['decision' => 'salutation'];
    }

    private static function versEquipe(array $qp, array $p, string $motif): void
    {
        q("UPDATE vocaux SET statut = 'a_traiter' WHERE id = ? AND statut IN ('ia', 'auto')", [$qp['vocal_id']]);
        Parcours::prevenirEquipe('Question à rappeler ' . $p['pseudo'], "Question de {$p['pseudo']} sans réponse automatique ({$motif}). Écoutez-la dans la file de traitement.");
    }

    /** Appelé par le planificateur et par le service IA : rien ne reste en suspens. */
    public static function expirer(): int
    {
        $n = 0;
        $limiteAnalyse = plus(maintenant(), '-' . (int) cfg('questions.delai_analyse_min', 5) . ' minutes');
        foreach (q("SELECT * FROM questions_posees WHERE statut = 'en_analyse' AND cree_le < ?", [$limiteAnalyse])->fetchAll() as $qp) {
            $p = Parcours::participante((int) $qp['participante_id']);
            self::sansReponse($qp, $p, 'analyse trop longue');
            $n++;
        }
        $limiteConfirmation = plus(maintenant(), '-' . (int) cfg('questions.delai_confirmation_h', 2) . ' hours');
        foreach (q("SELECT * FROM questions_posees WHERE statut = 'a_confirmer' AND confirmation_le < ?", [$limiteConfirmation])->fetchAll() as $qp) {
            q("UPDATE questions_posees SET statut = 'expiree', motif = 'pas de confirmation' WHERE id = ?", [$qp['id']]);
            self::versEquipe($qp, Parcours::participante((int) $qp['participante_id']), 'pas de confirmation');
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Indicateurs (couverture et sécurité)
    // ------------------------------------------------------------------

    public static function indicateurs(): array
    {
        $compte = static fn(string $where, array $p = []) => (int) qv('SELECT COUNT(*) FROM questions_posees WHERE ' . $where, $p);
        $posees = $compte("statut NOT IN ('en_analyse', 'ignoree', 'confirmation')");
        $repondues = $compte("statut = 'repondue'");
        $verifiees = $compte('verifie_correct IS NOT NULL');
        $erreurs = $compte('verifie_correct = 0');
        $delais = q(
            "SELECT qp.repondu_le, v.recu_le FROM questions_posees qp JOIN vocaux v ON v.id = qp.vocal_id WHERE qp.statut = 'repondue'"
        )->fetchAll();
        $d = array_map(static fn($x) => ts($x['repondu_le']) - ts($x['recu_le']), $delais);
        sort($d);
        $pretes = 0;
        foreach (q("SELECT * FROM questions WHERE statut = 'actif'")->fetchAll() as $qq) {
            $pretes += self::prete($qq) ? 1 : 0;
        }
        return [
            'bibliotheque' => (int) qv('SELECT COUNT(*) FROM questions'),
            'pretes' => $pretes,
            'variantes' => (int) qv('SELECT COUNT(*) FROM variantes'),
            'posees' => $posees,
            'repondues' => $repondues,
            'couverture' => $posees ? (float) $repondues / $posees : null,
            'a_confirmer' => $compte("statut = 'a_confirmer'"),
            'refusees' => $compte("statut = 'refusee'"),
            'sans_reponse' => $compte("statut IN ('sans_reponse', 'expiree')"),
            'danger' => $compte("statut = 'danger'"),
            'verifiees' => $verifiees,
            'erreurs' => $erreurs,
            'taux_erreur' => $verifiees ? (float) $erreurs / $verifiees : null,
            'delai_median_s' => $d ? $d[intdiv(count($d), 2)] : null,
        ];
    }

    private static function lireCsv(string $chemin): array
    {
        $contenu = (string) file_get_contents($chemin);
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu) ?? $contenu;
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }
        $lignes = preg_split('/\r\n|\r|\n/', trim($contenu)) ?: [];
        if (!$lignes) {
            return [];
        }
        $sep = substr_count($lignes[0], ';') >= substr_count($lignes[0], ',') ? ';' : ',';
        $entetes = array_map(static fn($h) => mb_strtolower(trim($h)), str_getcsv(array_shift($lignes), $sep));
        $res = [];
        foreach ($lignes as $l) {
            if (trim($l) === '') {
                continue;
            }
            $champs = str_getcsv($l, $sep);
            $res[] = array_combine($entetes, array_pad(array_slice($champs, 0, count($entetes)), count($entetes), ''));
        }
        return $res;
    }
}
