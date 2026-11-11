<?php
declare(strict_types=1);

/**
 * Logique du parcours d'une participante : inscription, réception des
 * messages, menu à chiffres, livraison des contenus, alertes.
 *
 * Touches du menu (une touche = une seule fonction, pour éviter toute
 * confusion entre un rappel et une urgence) :
 *   1 écouter le message en attente · 2 signes de danger (urgence)
 *   3 coordonnées du centre · 4 je viendrai au rendez-vous
 *   5 je ne peux pas venir · 9 arrêter le service · 0 réécouter le menu
 */
final class Parcours
{
    /** Fenêtre de service WhatsApp (24 h), avec une marge de sécurité. */
    private const FENETRE_S = 23 * 3600 + 30 * 60;

    private const MOTS_STOP = ['9', 'stop', 'arret', 'arreter', 'arrete', 'fin'];

    // ------------------------------------------------------------------
    // Inscription (EF-01)
    // ------------------------------------------------------------------

    /**
     * @param array{numero:string, profil:string, dpa?:?string, semaine?:?int,
     *   naissance_enfant?:?string, centre_id?:?int, consent_service:bool,
     *   consent_ia?:bool, rdv_type?:?string, rdv_date?:?string} $d
     * @return array{id:int, pseudo:string}
     */
    public static function inscrire(array $d, ?int $uid): array
    {
        $numero = Crypto::normaliser((string) ($d['numero'] ?? ''));
        if ($numero === null) {
            throw new InvalidArgumentException('Numéro WhatsApp invalide. Exemple : 07 01 02 03 04.');
        }
        if (empty($d['consent_service'])) {
            throw new InvalidArgumentException('Le consentement à la participation est obligatoire.');
        }
        $profil = $d['profil'] ?? '';
        $dpa = null;
        $naissance = null;
        if ($profil === 'enceinte') {
            if (!empty($d['dpa'])) {
                $dpa = self::dateValide((string) $d['dpa']);
            } elseif (!empty($d['semaine'])) {
                $sem = (int) $d['semaine'];
                if ($sem < 4 || $sem > 42) {
                    throw new InvalidArgumentException('La semaine de grossesse doit être comprise entre 4 et 42.');
                }
                $dpa = date('Y-m-d', strtotime('+' . ((40 - $sem) * 7) . ' days', ts(aujourdhui())));
            }
            if (!$dpa) {
                throw new InvalidArgumentException('Indiquez la date prévue d’accouchement ou la semaine de grossesse.');
            }
            $ecart = (ts($dpa) - ts(aujourdhui())) / 86400;
            if ($ecart < -14 || $ecart > 280) {
                throw new InvalidArgumentException('La date prévue d’accouchement semble incorrecte.');
            }
        } elseif ($profil === 'mere') {
            $naissance = self::dateValide((string) ($d['naissance_enfant'] ?? ''));
            if (!$naissance || $naissance > aujourdhui()) {
                throw new InvalidArgumentException('Indiquez la date de naissance de l’enfant.');
            }
            if ((ts(aujourdhui()) - ts($naissance)) / 86400 > 365) {
                throw new InvalidArgumentException('Le service concerne les enfants de moins de 12 mois.');
            }
        } else {
            throw new InvalidArgumentException('Choisissez le profil : enceinte ou mère.');
        }

        $index = Crypto::index($numero);
        $existe = q1(
            'SELECT p.pseudo FROM correspondances c JOIN participantes p ON p.id = c.participante_id
             WHERE c.numero_index = ? AND c.actif = 1',
            [$index]
        );
        if ($existe) {
            throw new InvalidArgumentException('Ce numéro est déjà inscrit (' . $existe['pseudo'] . ').');
        }

        $rdvType = $d['rdv_type'] ?? null;
        $rdvDate = !empty($d['rdv_date']) ? self::dateValide((string) $d['rdv_date']) : null;
        if ($rdvDate && $rdvDate < aujourdhui()) {
            throw new InvalidArgumentException('La date du prochain rendez-vous est passée.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $now = maintenant();
            q(
                'INSERT INTO participantes (pseudo, profil, dpa, naissance_enfant, centre_id, langue, statut,
                    consent_service_le, consent_ia, consent_ia_le, inscrit_par, inscrite_le)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    'tmp-' . bin2hex(random_bytes(6)), $profil, $dpa, $naissance,
                    !empty($d['centre_id']) ? (int) $d['centre_id'] : null,
                    (string) cfg('langue_defaut', 'dyu'), 'active', $now,
                    !empty($d['consent_ia']) ? 1 : 0, !empty($d['consent_ia']) ? $now : null,
                    $uid, $now,
                ]
            );
            $id = (int) $pdo->lastInsertId();
            $pseudo = 'MK-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
            q('UPDATE participantes SET pseudo = ? WHERE id = ?', [$pseudo, $id]);
            q(
                'INSERT INTO correspondances (participante_id, numero_chiffre, numero_index, actif, cree_le) VALUES (?, ?, ?, 1, ?)',
                [$id, Crypto::chiffrer($numero), $index, $now]
            );
            if ($rdvDate) {
                self::ajouterRdv($id, $rdvType ?: ($profil === 'mere' ? 'vaccination' : 'prenatal'), $rdvDate, $uid);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        journal('u:' . ($uid ?? 0), 'inscription', ['participante' => $pseudo, 'profil' => $profil, 'consent_ia' => !empty($d['consent_ia'])]);
        self::accueillir($id);
        return ['id' => $id, 'pseudo' => $pseudo];
    }

    public static function ajouterRdv(int $pid, string $type, string $date, ?int $uid): int
    {
        $types = ['prenatal', 'postnatal', 'vaccination', 'autre'];
        if (!in_array($type, $types, true)) {
            $type = 'autre';
        }
        q(
            'INSERT INTO rendez_vous (participante_id, type, date_rdv, cree_par, cree_le) VALUES (?, ?, ?, ?, ?)',
            [$pid, $type, $date, $uid, maintenant()]
        );
        return (int) db()->lastInsertId();
    }

    /** Message de bienvenue : envoyé directement si la femme a déjà écrit, sinon notification. */
    public static function accueillir(int $pid): void
    {
        $p = self::participante($pid);
        $msg = self::messageEvenement('bienvenue', $p);
        if (!$msg) {
            log_tech('parcours', "Aucun message de bienvenue validé : {$p['pseudo']} attendra sa validation.");
            return;
        }
        if (self::fenetreOuverte($p)) {
            self::livrerBienvenue($p);
        } else {
            self::proposer($pid, $msg, 'bienvenue');
        }
    }

    private static function livrerBienvenue(array $p): void
    {
        $pid = (int) $p['id'];
        $msg = self::messageEvenement('bienvenue', $p);
        if (!$msg) {
            // Pas encore d'audio validé (phase de test) : on répond quand même par écrit,
            // sinon la participante qui écrit ne reçoit rien.
            log_tech('parcours', "Bienvenue sans audio validé : version écrite envoyée à {$p['pseudo']}.");
            q('UPDATE participantes SET bienvenue_envoyee = 1 WHERE id = ?', [$pid]);
            self::envoyer($pid, 'texte', 'bienvenue', ['texte' => (string) cfg('textes.bienvenue', 'Bienvenue à Musow Kènèya. Vous recevrez ici des messages en dioula sur la grossesse et la santé de votre bébé.')]);
            self::envoyerMenu($pid);
            return;
        }
        q('UPDATE participantes SET bienvenue_envoyee = 1 WHERE id = ?', [$pid]);
        // Une notification de bienvenue en attente est considérée comme traitée
        q("UPDATE envois SET demande_le = ? WHERE participante_id = ? AND motif = 'bienvenue' AND nature = 'notif' AND demande_le IS NULL", [maintenant(), $pid]);
        self::envoyerMessage($pid, $msg, 'bienvenue');
        self::envoyerMenu($pid);
    }

    // ------------------------------------------------------------------
    // Réception (webhook ou saisie manuelle)
    // ------------------------------------------------------------------

    /**
     * Point d'entrée du webhook.
     * @param string $type texte | bouton | audio | autre
     */
    public static function recevoir(string $numeroBrut, string $type, ?string $contenu, ?string $mediaId, ?string $waId): void
    {
        if ($waId && qv('SELECT id FROM entrants WHERE wa_id = ?', [$waId])) {
            return; // Meta renvoie parfois la même notification : on l'ignore.
        }
        $numero = Crypto::normaliser($numeroBrut);
        if ($numero === null) {
            return;
        }
        $pid = null;
        foreach (Crypto::variantes($numero) as $forme) {
            $pid = qv(
                'SELECT participante_id FROM correspondances WHERE numero_index = ? AND actif = 1',
                [Crypto::index($forme)]
            );
            if ($pid) {
                break;
            }
        }
        if (!$pid) {
            log_tech('parcours', 'Message d’un numéro non inscrit : ' . Crypto::masquer($numero) . ' (' . strlen($numero) . ' chiffres).');
            self::numeroInconnu($numero, $waId, $type);
            return;
        }
        self::traiter((int) $pid, $type, $contenu, $mediaId, $waId);
    }

    public static function traiter(int $pid, string $type, ?string $contenu, ?string $mediaId, ?string $waId): void
    {
        $now = maintenant();
        q(
            'INSERT INTO entrants (participante_id, wa_id, type, contenu, recu_le) VALUES (?, ?, ?, ?, ?)',
            [$pid, $waId, $type, $type === 'audio' ? null : mb_substr((string) $contenu, 0, 255), $now]
        );
        $entrantId = (int) db()->lastInsertId();
        q('UPDATE participantes SET dernier_entrant_le = ? WHERE id = ?', [$now, $pid]);
        $p = self::participante($pid);

        $cle = self::cle($type, $contenu);

        // Participante qui a arrêté ou terminé : on ne la relance pas.
        if ($p['statut'] !== 'active') {
            // Elle peut encore écouter un dernier message de service en attente.
            if (in_array($cle, ['1', 'ECOUTER'], true) || str_starts_with((string) $cle, 'ECOUTER_')) {
                self::livrerEnAttente($p, null);
                return;
            }
            if (!qv("SELECT COUNT(*) FROM envois WHERE participante_id = ? AND motif = 'inactive' AND cree_le > ?", [$pid, plus($now, '-1 day')])) {
                self::envoyer($pid, 'texte', 'inactive', ['texte' => (string) cfg('textes.inactive')]);
            }
            return;
        }

        if ($type === 'audio') {
            if (!$p['bienvenue_envoyee']) {
                self::livrerBienvenue($p);
            }
            self::recevoirVocal($p, $mediaId, $entrantId);
            return;
        }

        if (in_array($cle, self::MOTS_STOP, true)) {
            self::arreter($pid, 'demande');
            return;
        }

        // Urgence (touche 2 ou bouton 🆘) : toujours prioritaire, même pendant un questionnaire.
        if ($cle === '2' || $cle === 'URGENCE') {
            if (!$p['bienvenue_envoyee']) {
                q('UPDATE participantes SET bienvenue_envoyee = 1 WHERE id = ?', [$pid]);
            }
            self::danger($p, 'menu');
            return;
        }

        if (!$p['bienvenue_envoyee']) {
            self::livrerBienvenue($p);
            return;
        }

        // Questionnaire en cours (EF-13) : les chiffres 1, 3, 4 et 5 sont des réponses.
        $sondageActif = $p['attente_type'] === 'sondage' && $p['attente_expire_le'] && $p['attente_expire_le'] > $now;
        if ($sondageActif && in_array($cle, ['1', '3', '4', '5'], true)) {
            self::repondreSondage($p, (int) $cle);
            return;
        }

        // Confirmation d'une question comprise (« Vous voulez savoir… ? »)
        if (is_string($cle) && preg_match('/^FAQ_(OUI|NON)_(\d+)$/', $cle, $m)) {
            Questions::confirmer($p, (int) $m[2], $m[1] === 'OUI');
            return;
        }
        $faqActive = $p['attente_type'] === 'faq' && $p['attente_expire_le'] && $p['attente_expire_le'] > $now;
        // Réponse écrite (par une proche, par exemple) : même règle que la réponse orale.
        $lu = ($faqActive && $type === 'texte') ? Questions::lireOuiNon((string) $contenu) : null;
        if ($lu === 'oui' || $lu === 'non') {
            Questions::confirmer($p, (int) $p['attente_ref'], $lu === 'oui');
            return;
        }

        if (is_string($cle) && str_starts_with($cle, 'ECOUTER_')) {
            self::livrerEnAttente($p, (int) substr($cle, 8));
            return;
        }
        if (is_string($cle) && preg_match('/^RDV([12])_(\d+)$/', $cle, $m)) {
            self::repondreRdv($p, (int) $m[2], $m[1] === '1');
            return;
        }
        if ($cle === 'NAISS_OUI' || $cle === 'NAISS_NON') {
            self::repondreNaissance($p, $cle === 'NAISS_OUI');
            return;
        }

        switch ($cle) {
            case '1':
            case 'ECOUTER':
                self::livrerEnAttente($p, null);
                return;
            case '3':
            case 'CENTRE':
                self::centre($p);
                return;
            case '4':
            case '5':
                $rdv = q1(
                    'SELECT id FROM rendez_vous WHERE participante_id = ? AND date_rdv >= ? AND date_rdv <= ? ORDER BY date_rdv LIMIT 1',
                    [$pid, aujourdhui(), date('Y-m-d', strtotime('+7 days', ts(aujourdhui())))]
                );
                if ($rdv) {
                    self::repondreRdv($p, (int) $rdv['id'], $cle === '4');
                } else {
                    self::envoyerMenu($pid);
                }
                return;
            default:
                self::envoyerMenu($pid);
        }
    }

    /** Ramène une saisie à une clé : chiffre, mot-clé ou identifiant de bouton. */
    private static function cle(string $type, ?string $contenu): ?string
    {
        $c = trim((string) $contenu);
        if ($type === 'bouton') {
            return $c;
        }
        if ($type !== 'texte') {
            return null;
        }
        $c = mb_strtolower($c);
        if (class_exists('Normalizer')) {
            // Tons et nasalisation saisis au clavier (ɔ́, ɔ̃, ó…) : on garde la lettre, on retire la marque.
            $c = (string) Normalizer::normalize($c, Normalizer::FORM_D);
            $c = preg_replace('/\p{Mn}+/u', '', $c) ?? $c;
        }
        $c = strtr($c, ['à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ɛ' => 'e', 'ɔ' => 'o']);
        $c = preg_replace('/[^a-z0-9 ]/', '', $c) ?? '';
        $c = trim($c);
        if (preg_match('/^\d$/', $c)) {
            return $c;
        }
        if (preg_match('/^\d/', $c)) {
            return $c[0]; // « 1 merci » → 1
        }
        return $c;
    }

    private static function numeroInconnu(string $numero, ?string $waId, string $type): void
    {
        $empreinte = 'inconnu:' . substr(Crypto::index($numero), 0, 16);
        $deja = qv('SELECT COUNT(*) FROM entrants WHERE participante_id IS NULL AND contenu = ? AND recu_le > ?', [$empreinte, plus(maintenant(), '-1 day')]);
        q(
            'INSERT INTO entrants (participante_id, wa_id, type, contenu, recu_le) VALUES (NULL, ?, ?, ?, ?)',
            [$waId, $type, $empreinte, maintenant()]
        );
        if (!$deja && Canal::mode() !== 'manuel') {
            Canal::instance()->texte($numero, (string) cfg('textes.inconnue'), ['participante_id' => 0]);
        }
    }

    // ------------------------------------------------------------------
    // Fonctions du menu
    // ------------------------------------------------------------------

    /** Signe de danger (EF-05, protocole d'alerte 11.2). */
    public static function danger(array $p, string $origine, ?int $vocalId = null): int
    {
        $pid = (int) $p['id'];
        $now = maintenant();
        q(
            'INSERT INTO alertes (participante_id, origine, vocal_id, cree_le, statut) VALUES (?, ?, ?, ?, ?)',
            [$pid, $origine, $vocalId, $now, 'ouverte']
        );
        $alerteId = (int) db()->lastInsertId();
        if (in_array($origine, ['menu', 'ia'], true)) {
            $msg = self::messageEvenement($p['profil'] === 'mere' ? 'danger_mere' : 'danger', $p)
                ?? self::messageEvenement('danger', $p);
            if ($msg) {
                self::envoyerMessage($pid, $msg, 'danger');
            }
            self::envoyer($pid, 'texte', 'danger', ['texte' => self::texteUrgence($p)]);
            q('UPDATE alertes SET consigne_envoyee_le = ? WHERE id = ?', [maintenant(), $alerteId]);
        }
        journal('systeme', 'alerte', ['participante' => $p['pseudo'], 'origine' => $origine, 'alerte' => $alerteId]);
        self::prevenirEquipe('Alerte ' . $p['pseudo'], "Une participante ({$p['pseudo']}) a déclenché une alerte ({$origine}) le " . View::date($now) . ".\nOuvrez la file de traitement pour la rappeler.");
        return $alerteId;
    }

    private static function texteUrgence(array $p): string
    {
        $texte = (string) cfg('textes.urgence');
        $tel = $p['centre_id'] ? qv('SELECT telephone FROM centres WHERE id = ?', [$p['centre_id']]) : null;
        if ($tel) {
            $texte .= "\n" . str_replace('{tel}', (string) $tel, (string) cfg('textes.urgence_centre', 'Centre : {tel}'));
        }
        return $texte;
    }

    private static function centre(array $p): void
    {
        $pid = (int) $p['id'];
        $msg = self::messageEvenement('centre', $p);
        if ($msg) {
            self::envoyerMessage($pid, $msg, 'centre');
        }
        $c = $p['centre_id'] ? q1('SELECT nom, telephone FROM centres WHERE id = ?', [$p['centre_id']]) : null;
        if ($c) {
            self::envoyer($pid, 'texte', 'centre', ['texte' => $c['nom'] . ($c['telephone'] ? "\n" . $c['telephone'] : '')]);
        }
    }

    /** Message vocal d'une femme (EF-07). */
    private static function recevoirVocal(array $p, ?string $mediaId, int $entrantId): void
    {
        $pid = (int) $p['id'];
        $fichier = null;
        if ($mediaId) {
            $media = Canal::instance()->telechargerMedia($mediaId);
            if ($media) {
                $nom = $p['pseudo'] . '-' . date('Ymd-His', ts(maintenant())) . '-' . bin2hex(random_bytes(3)) . '.ogg';
                $dossier = MK_ROOT . '/storage/vocaux';
                if (@file_put_contents($dossier . '/' . $nom, $media['octets']) !== false) {
                    $fichier = $nom;
                }
            }
        }
        // Questions vocales actives : la transcription sert à répondre (service).
        // Le consentement IA ne concerne que l'usage des vocaux pour entraîner les modèles.
        $questions = Questions::actives() && $fichier !== null;
        q(
            'INSERT INTO vocaux (participante_id, entrant_id, media_id, fichier, recu_le, consent_ia, ia_statut, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$pid, $entrantId, $mediaId, $fichier, maintenant(), (int) $p['consent_ia'], ($questions || ($p['consent_ia'] && $fichier)) ? 'a_faire' : 'non', 'a_traiter']
        );
        $vocalId = (int) db()->lastInsertId();
        if ($questions) {
            Questions::ouvrir($p, $vocalId);
            journal('systeme', 'question_recue', ['participante' => $p['pseudo'], 'vocal' => $vocalId]);
            return;
        }
        $msg = self::messageEvenement('accuse_vocal', $p);
        if ($msg) {
            self::envoyerMessage($pid, $msg, 'accuse');
        }
        self::envoyer($pid, 'texte', 'accuse', ['texte' => (string) cfg('textes.accuse')]);
        self::prevenirEquipe('Message vocal ' . $p['pseudo'], "Nouveau message vocal de {$p['pseudo']} à écouter dans la file de traitement.");
        journal('systeme', 'vocal_recu', ['participante' => $p['pseudo'], 'vocal' => $vocalId]);
    }

    public static function arreter(int $pid, string $motif, ?int $uid = null): void
    {
        $p = self::participante($pid);
        if ($p['statut'] === 'stoppee') {
            return;
        }
        q(
            "UPDATE participantes SET statut = 'stoppee', arretee_le = ?, motif_fin = ?, attente_type = NULL, attente_ref = NULL WHERE id = ?",
            [maintenant(), $motif, $pid]
        );
        self::annulerEnAttente($pid);
        $msg = self::messageEvenement('stop', $p);
        if ($msg && self::fenetreOuverte(self::participante($pid))) {
            self::envoyerMessage($pid, $msg, 'stop');
        } else {
            self::envoyer($pid, 'texte', 'stop', ['texte' => (string) cfg('textes.stop')]);
        }
        journal($uid ? 'u:' . $uid : 'participante', 'arret', ['participante' => $p['pseudo'], 'motif' => $motif]);
    }

    private static function repondreRdv(array $p, int $rdvId, bool $vient): void
    {
        $rdv = q1('SELECT * FROM rendez_vous WHERE id = ? AND participante_id = ?', [$rdvId, $p['id']]);
        if (!$rdv) {
            self::envoyerMenu((int) $p['id']);
            return;
        }
        q('UPDATE rendez_vous SET reponse = ?, reponse_le = ? WHERE id = ?', [$vient ? 1 : 2, maintenant(), $rdvId]);
        self::envoyer((int) $p['id'], 'texte', 'rdv_reponse', [
            'texte' => (string) cfg($vient ? 'textes.rdv_oui' : 'textes.rdv_non'),
        ]);
        journal('participante', 'rdv_reponse', ['participante' => $p['pseudo'], 'rdv' => $rdvId, 'vient' => $vient]);
    }

    private static function repondreNaissance(array $p, bool $nee): void
    {
        $pid = (int) $p['id'];
        q('UPDATE participantes SET attente_type = NULL, attente_ref = NULL WHERE id = ?', [$pid]);
        if ($nee && $p['profil'] === 'enceinte') {
            q("UPDATE participantes SET profil = 'mere', naissance_enfant = ? WHERE id = ?", [aujourdhui(), $pid]);
            journal('participante', 'naissance_declaree', ['participante' => $p['pseudo']]);
            self::envoyer($pid, 'texte', 'naissance', ['texte' => (string) cfg('textes.naissance_oui')]);
        } else {
            self::envoyer($pid, 'texte', 'naissance', ['texte' => (string) cfg('textes.naissance_non')]);
        }
    }

    /** Déclarations faites par l'équipe. */
    public static function declarerNaissance(int $pid, string $date, int $uid): void
    {
        $date = self::dateValide($date) ?? aujourdhui();
        q("UPDATE participantes SET profil = 'mere', naissance_enfant = ? WHERE id = ? AND statut = 'active'", [$date, $pid]);
        journal('u:' . $uid, 'naissance_declaree', ['participante' => self::participante($pid)['pseudo'], 'date' => $date]);
    }

    public static function declarerPerte(int $pid, int $uid): void
    {
        $p = self::participante($pid);
        q("UPDATE participantes SET statut = 'terminee', motif_fin = 'perte', arretee_le = ?, attente_type = NULL WHERE id = ?", [maintenant(), $pid]);
        self::annulerEnAttente($pid);
        $msg = self::messageEvenement('perte', $p);
        if ($msg) {
            self::proposer($pid, $msg, 'perte', null, true);
        }
        journal('u:' . $uid, 'perte_declaree', ['participante' => $p['pseudo']]);
    }

    /** Changement de numéro (EF-12). */
    public static function changerNumero(int $pid, string $nouveau, int $uid): void
    {
        $numero = Crypto::normaliser($nouveau);
        if ($numero === null) {
            throw new InvalidArgumentException('Nouveau numéro invalide.');
        }
        $index = Crypto::index($numero);
        if (qv('SELECT COUNT(*) FROM correspondances WHERE numero_index = ? AND actif = 1', [$index])) {
            throw new InvalidArgumentException('Ce numéro est déjà utilisé par une autre inscription.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        q('UPDATE correspondances SET actif = NULL, desactive_le = ? WHERE participante_id = ? AND actif = 1', [maintenant(), $pid]);
        q(
            'INSERT INTO correspondances (participante_id, numero_chiffre, numero_index, actif, cree_le) VALUES (?, ?, ?, 1, ?)',
            [$pid, Crypto::chiffrer($numero), $index, maintenant()]
        );
        // Le nouveau numéro n'a pas encore écrit : la fenêtre de 24 h est fermée.
        q('UPDATE participantes SET dernier_entrant_le = NULL WHERE id = ?', [$pid]);
        $pdo->commit();
        journal('u:' . $uid, 'numero_change', ['participante' => self::participante($pid)['pseudo']]);
    }

    // ------------------------------------------------------------------
    // Questionnaire J+10 (EF-13)
    // ------------------------------------------------------------------

    private static function demarrerSondage(array $p): void
    {
        q(
            "UPDATE participantes SET attente_type = 'sondage', sondage_etape = 1, attente_expire_le = ? WHERE id = ?",
            [plus(maintenant(), '+2 hours'), $p['id']]
        );
        self::envoyer((int) $p['id'], 'texte', 'sondage', ['texte' => (string) cfg('textes.sondage_consigne')]);
    }

    private static function repondreSondage(array $p, int $note): void
    {
        $pid = (int) $p['id'];
        $etape = (int) $p['sondage_etape'];
        $echelle = [1 => 1, 3 => 2, 4 => 3, 5 => 4]; // touches 1·3·4·5 → note de 1 à 4
        q(
            'INSERT INTO sondages (participante_id, question, reponse, repondu_le) VALUES (?, ?, ?, ?)',
            [$pid, $etape, $echelle[$note] ?? 0, maintenant()]
        );
        $suivante = $etape + 1;
        if ($suivante <= 5) {
            $msg = self::messageEvenement('sondage_' . $suivante, $p);
            q('UPDATE participantes SET sondage_etape = ?, attente_expire_le = ? WHERE id = ?', [$suivante, plus(maintenant(), '+2 hours'), $pid]);
            if ($msg) {
                self::envoyerMessage($pid, $msg, 'sondage');
            }
            return;
        }
        q('UPDATE participantes SET sondage_etape = 6, attente_type = NULL, attente_expire_le = NULL WHERE id = ?', [$pid]);
        $fin = self::messageEvenement('sondage_fin', $p);
        if ($fin) {
            self::envoyerMessage($pid, $fin, 'sondage');
        }
    }

    // ------------------------------------------------------------------
    // Proposition et livraison des contenus
    // ------------------------------------------------------------------

    /**
     * Propose un message : envoi direct si la fenêtre de 24 h est ouverte,
     * sinon notification neutre « un message vous attend » (modèle Meta).
     * La réponse 1 ou le bouton « Écouter » déclenche l'envoi de l'audio,
     * ce qui mesure directement l'engagement.
     */
    public static function proposer(int $pid, array $msg, string $motif, ?int $rdvId = null, bool $memeInactive = false): ?int
    {
        $p = self::participante($pid);
        if ($p['statut'] !== 'active' && !$memeInactive) {
            return null;
        }
        if (self::fenetreOuverte($p)) {
            $id = self::envoyerMessage($pid, $msg, $motif, null, $rdvId);
            self::apresLivraison($p, $msg, $motif, $rdvId);
            return $id;
        }
        return self::envoyer($pid, 'notif', $motif, [
            'message_id' => (int) $msg['id'],
            'rdv_id' => $rdvId,
            'modele' => (string) cfg('whatsapp.modele_notification', 'musow_nouveau_message'),
            'payloads' => ['ECOUTER_{ENVOI}'],
            'titre' => $msg['titre'],
        ]);
    }

    /** Livre les messages en attente (notifications non encore écoutées, 7 jours au plus). */
    private static function livrerEnAttente(array $p, ?int $envoiId): void
    {
        $pid = (int) $p['id'];
        $attente = q(
            "SELECT * FROM envois WHERE participante_id = ? AND nature = 'notif' AND demande_le IS NULL
               AND statut NOT IN ('echec', 'annule') AND cree_le > ? ORDER BY CASE WHEN motif = 'rappel' THEN 0 ELSE 1 END, id LIMIT 3",
            [$pid, plus(maintenant(), '-7 days')]
        )->fetchAll();

        if (!$attente) {
            // Rien en attente : on réécoute le dernier contenu, ou on renvoie le menu.
            if ($p['dernier_contenu_id'] && $p['statut'] === 'active') {
                $msg = self::messageActuel((int) $p['dernier_contenu_id']);
                if ($msg) {
                    self::envoyerMessage($pid, $msg, 'reecoute');
                    return;
                }
            }
            if ($p['statut'] === 'active') {
                self::envoyerMenu($pid);
            }
            return;
        }
        foreach ($attente as $notif) {
            q('UPDATE envois SET demande_le = ? WHERE id = ?', [maintenant(), $notif['id']]);
            $msg = self::messageActuel((int) $notif['message_id']);
            if (!$msg) {
                continue;
            }
            if ($notif['motif'] === 'bienvenue') {
                q('UPDATE participantes SET bienvenue_envoyee = 1 WHERE id = ?', [$pid]);
            }
            self::envoyerMessage($pid, $msg, $notif['motif'], (int) $notif['id'], $notif['rdv_id'] ? (int) $notif['rdv_id'] : null);
            self::apresLivraison(self::participante($pid), $msg, $notif['motif'], $notif['rdv_id'] ? (int) $notif['rdv_id'] : null);
        }
    }

    /** Suites d'une livraison selon le motif. */
    private static function apresLivraison(array $p, array $msg, string $motif, ?int $rdvId): void
    {
        $pid = (int) $p['id'];
        switch ($motif) {
            case 'bienvenue':
                q('UPDATE participantes SET bienvenue_envoyee = 1 WHERE id = ?', [$pid]);
                self::envoyerMenu($pid);
                break;
            case 'contenu':
                q('UPDATE participantes SET dernier_contenu_id = ? WHERE id = ?', [$msg['id'], $pid]);
                break;
            case 'rappel':
                $rdv = $rdvId ? q1('SELECT * FROM rendez_vous WHERE id = ?', [$rdvId]) : null;
                if ($rdv) {
                    $texte = str_replace('{date}', self::dateLongue($rdv['date_rdv']), (string) cfg('textes.rappel'));
                    self::envoyer($pid, 'boutons', 'rappel', [
                        'texte' => $texte,
                        'boutons' => ['RDV1_' . $rdvId => '✅ Je viens', 'RDV2_' . $rdvId => '❌ Je ne peux pas'],
                        'rdv_id' => $rdvId,
                    ]);
                }
                break;
            case 'naissance':
                q("UPDATE participantes SET attente_type = 'naissance' WHERE id = ?", [$pid]);
                self::envoyer($pid, 'boutons', 'naissance', [
                    'texte' => (string) cfg('textes.naissance_question'),
                    'boutons' => ['NAISS_OUI' => '👶 Oui', 'NAISS_NON' => '⏳ Pas encore'],
                ]);
                break;
            case 'sondage':
                if ((int) $p['sondage_etape'] === 0) {
                    self::demarrerSondage($p);
                }
                break;
        }
    }

    private static function annulerEnAttente(int $pid): void
    {
        q("UPDATE envois SET statut = 'annule' WHERE participante_id = ? AND nature = 'notif' AND demande_le IS NULL AND motif NOT IN ('perte', 'fin', 'stop')", [$pid]);
    }

    public static function envoyerMenu(int $pid): void
    {
        $p = self::participante($pid);
        $msg = self::messageEvenement('menu', $p);
        if ($msg) {
            self::envoyerMessage($pid, $msg, 'menu');
        }
        self::boutonsMenu($pid);
    }

    /**
     * Menu pour des femmes qui ne lisent pas : trois boutons illustrés à toucher
     * (🔊 écouter, 🆘 urgence, 🏥 centre). Les chiffres restent compris en secours.
     * Hors de la fenêtre de 24 h, WhatsApp refuse les boutons : on envoie alors le texte.
     */
    public static function boutonsMenu(int $pid, ?string $texte = null): void
    {
        $p = self::participante($pid);
        if (!self::fenetreOuverte($p)) {
            self::envoyer($pid, 'texte', 'menu', ['texte' => (string) cfg('textes.menu')]);
            return;
        }
        self::envoyer($pid, 'boutons', 'menu', [
            'texte' => $texte ?? (string) cfg('textes.menu_boutons', 'Touchez un bouton, ou envoyez un message vocal pour poser votre question.'),
            'boutons' => [
                'ECOUTER' => (string) cfg('textes.bouton_ecouter', '🔊 Écouter'),
                'URGENCE' => (string) cfg('textes.bouton_urgence', '🆘 Urgence'),
                'CENTRE' => (string) cfg('textes.bouton_centre', '🏥 Mon centre'),
            ],
        ]);
    }

    /** Envoie l'audio d'un message validé. */
    public static function envoyerMessage(int $pid, array $msg, string $motif, ?int $parent = null, ?int $rdvId = null): ?int
    {
        if ($msg['statut'] !== 'valide' || !$msg['fichier_audio']) {
            log_tech('parcours', "Message {$msg['code']} non validé ou sans audio : envoi bloqué.");
            return null;
        }
        return self::envoyer($pid, 'audio', $motif, [
            'message_id' => (int) $msg['id'],
            'url' => url_publique('audio/' . $msg['fichier_audio']),
            'titre' => $msg['titre'],
            'parent_id' => $parent,
            'rdv_id' => $rdvId,
        ]);
    }

    /**
     * Enregistre puis réalise un envoi. Toute tentative est tracée (ENF-07)
     * et relancée par le planificateur en cas d'échec (ENF-02).
     */
    public static function envoyer(int $pid, string $nature, string $motif, array $o): ?int
    {
        $canal = Canal::instance();
        q(
            'INSERT INTO envois (participante_id, message_id, nature, motif, rdv_id, parent_id, canal, statut, charge, cree_le)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pid, $o['message_id'] ?? null, $nature, $motif, $o['rdv_id'] ?? null, $o['parent_id'] ?? null,
                $canal->nom(), 'a_envoyer', json_encode($o, JSON_UNESCAPED_UNICODE), maintenant(),
            ]
        );
        $envoiId = (int) db()->lastInsertId();
        self::realiser($envoiId);
        return $envoiId;
    }

    /** Tente (ou retente) un envoi enregistré. */
    public static function realiser(int $envoiId): bool
    {
        $e = q1('SELECT * FROM envois WHERE id = ?', [$envoiId]);
        if (!$e) {
            return false;
        }
        $numero = self::numero((int) $e['participante_id']);
        if ($numero === null) {
            q("UPDATE envois SET statut = 'echec', erreur = 'aucun numéro actif' WHERE id = ?", [$envoiId]);
            return false;
        }
        $o = json_decode((string) $e['charge'], true) ?: [];
        $ctx = ['participante_id' => (int) $e['participante_id'], 'envoi_id' => $envoiId, 'titre' => $o['titre'] ?? null];
        $canal = Canal::instance();
        $r = match ($e['nature']) {
            'audio' => $canal->audio($numero, (string) $o['url'], $ctx),
            'texte' => $canal->texte($numero, (string) $o['texte'], $ctx),
            'boutons' => $canal->boutons($numero, (string) $o['texte'], (array) $o['boutons'], $ctx),
            'notif' => $canal->modele(
                $numero,
                (string) $o['modele'],
                [],
                array_map(static fn($p) => str_replace('{ENVOI}', (string) $envoiId, $p), (array) ($o['payloads'] ?? [])),
                $ctx
            ),
            default => ['ok' => false, 'wa_id' => null, 'erreur' => 'nature inconnue'],
        };
        if ($r['ok']) {
            // En mode manuel, l'envoi n'est réel que lorsque l'équipe coche la tâche.
            $statut = $canal->nom() === 'manuel' ? 'a_faire' : 'envoye';
            q(
                'UPDATE envois SET statut = ?, wa_id = ?, envoye_le = ?, tentatives = tentatives + 1, erreur = NULL WHERE id = ?',
                [$statut, $r['wa_id'], $statut === 'envoye' ? maintenant() : null, $envoiId]
            );
            return true;
        }
        q(
            "UPDATE envois SET statut = 'echec', erreur = ?, tentatives = tentatives + 1 WHERE id = ?",
            [mb_substr((string) $r['erreur'], 0, 500), $envoiId]
        );
        return false;
    }

    /** Accusés de WhatsApp : sent, delivered, read, failed (ENF-04). */
    public static function statut(string $waId, string $statut, ?string $erreur = null): void
    {
        $now = maintenant();
        switch ($statut) {
            case 'sent':
                q("UPDATE envois SET envoye_le = COALESCE(envoye_le, ?), statut = CASE WHEN statut IN ('a_envoyer', 'echec') THEN 'envoye' ELSE statut END WHERE wa_id = ?", [$now, $waId]);
                break;
            case 'delivered':
                q("UPDATE envois SET livre_le = COALESCE(livre_le, ?), statut = CASE WHEN statut = 'lu' THEN statut ELSE 'livre' END WHERE wa_id = ?", [$now, $waId]);
                break;
            case 'read':
                q("UPDATE envois SET lu_le = COALESCE(lu_le, ?), livre_le = COALESCE(livre_le, ?), statut = 'lu' WHERE wa_id = ?", [$now, $now, $waId]);
                break;
            case 'failed':
                q("UPDATE envois SET statut = 'echec', erreur = ? WHERE wa_id = ?", [mb_substr((string) $erreur, 0, 500), $waId]);
                break;
        }
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    public static function participante(int $pid): array
    {
        $p = q1('SELECT * FROM participantes WHERE id = ?', [$pid]);
        if (!$p) {
            throw new RuntimeException('Participante introuvable');
        }
        return $p;
    }

    public static function numero(int $pid): ?string
    {
        $c = q1('SELECT numero_chiffre FROM correspondances WHERE participante_id = ? AND actif = 1', [$pid]);
        return $c ? Crypto::dechiffrer($c['numero_chiffre']) : null;
    }

    public static function fenetreOuverte(array $p): bool
    {
        return $p['dernier_entrant_le'] && ts(maintenant()) - ts($p['dernier_entrant_le']) < self::FENETRE_S;
    }

    /** Dernière version validée d'un message lié à un événement du parcours. */
    public static function messageEvenement(string $evenement, array $p): ?array
    {
        return q1(
            "SELECT * FROM messages WHERE evenement = ? AND statut = 'valide' AND fichier_audio IS NOT NULL
               AND langue = ? AND profil IN (?, 'toutes')
             ORDER BY CASE WHEN profil = ? THEN 0 ELSE 1 END, version DESC LIMIT 1",
            [$evenement, $p['langue'], $p['profil'], $p['profil']]
        );
    }

    /** Version validée la plus récente du même code (un contenu corrigé remplace l'ancien). */
    public static function messageActuel(int $messageId): ?array
    {
        $code = qv('SELECT code FROM messages WHERE id = ?', [$messageId]);
        if (!$code) {
            return null;
        }
        return q1(
            "SELECT * FROM messages WHERE code = ? AND statut = 'valide' AND fichier_audio IS NOT NULL ORDER BY version DESC LIMIT 1",
            [$code]
        );
    }

    /** Stade : semaine de grossesse ou âge de l'enfant en jours. */
    public static function stade(array $p): ?int
    {
        if ($p['profil'] === 'enceinte' && $p['dpa']) {
            $jours = (int) round((ts($p['dpa']) - ts(aujourdhui())) / 86400);
            return max(1, 40 - (int) ceil(max($jours, -28) / 7));
        }
        if ($p['profil'] === 'mere' && $p['naissance_enfant']) {
            return (int) round((ts(aujourdhui()) - ts($p['naissance_enfant'])) / 86400);
        }
        return null;
    }

    public static function libelleStade(array $p): string
    {
        $s = self::stade($p);
        if ($s === null) {
            return '–';
        }
        if ($p['profil'] === 'enceinte') {
            return $s . 'e semaine';
        }
        return $s < 60 ? $s . ' jours' : intdiv($s, 30) . ' mois';
    }

    private static function dateValide(string $d): ?string
    {
        $d = trim($d);
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $d, $m)) {
            $d = "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }
        [$a, $mo, $j] = array_map('intval', explode('-', $d));
        return checkdate($mo, $j, $a) ? $d : null;
    }

    public static function dateLongue(string $d): string
    {
        $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
        return $jours[(int) date('w', ts($d))] . ' ' . date('d/m', ts($d));
    }

    public static function prevenirEquipe(string $sujet, string $texte): void
    {
        $dest = (string) cfg('alertes.emails', '');
        if ($dest === '' || Canal::mode() === 'test') {
            return;
        }
        $lien = url_publique('admin/file.php');
        @mail(
            $dest,
            '=?UTF-8?B?' . base64_encode('[Musow Kènèya] ' . $sujet) . '?=',
            $texte . "\n\n" . $lien,
            "Content-Type: text/plain; charset=UTF-8\r\nFrom: " . cfg('alertes.expediteur', 'no-reply@localhost')
        );
    }
}
