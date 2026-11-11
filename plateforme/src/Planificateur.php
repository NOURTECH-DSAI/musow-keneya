<?php
declare(strict_types=1);

/**
 * Planificateur (EF-03, EF-04, EF-11, EF-13), lancé toutes les 15 minutes
 * par une tâche cron. Les envois programmés ne partent qu'entre 8 h et 20 h ;
 * les réponses aux femmes, elles, partent à toute heure.
 */
final class Planificateur
{
    /** @return array<string,int> compteurs de l'exécution */
    public static function executer(): array
    {
        $bilan = ['questions_expirees' => 0, 'relances' => 0, 'rappels' => 0, 'contenus' => 0, 'naissances' => 0, 'fins' => 0, 'sondages' => 0];
        parametre('dernier_cron', maintenant());
        $bilan['relances'] = self::relancerEchecs();
        self::expirerAttentes();
        $bilan['questions_expirees'] = Questions::expirer();

        $heure = (int) date('G', ts(maintenant()));
        $debut = (int) cfg('rythme.heure_debut', 8);
        $fin = (int) cfg('rythme.heure_fin', 20);
        if ($heure < $debut || $heure >= $fin) {
            return $bilan;
        }
        $bilan['rappels'] = self::rappels();
        $bilan['naissances'] = self::naissances();
        $bilan['fins'] = self::fins();
        $bilan['sondages'] = self::sondages();
        $bilan['contenus'] = self::contenus();
        return $bilan;
    }

    /** ENF-02 : jusqu'à 3 tentatives, espacées d'au moins 15 minutes. */
    private static function relancerEchecs(): int
    {
        $n = 0;
        $echecs = q(
            "SELECT id FROM envois WHERE statut = 'echec' AND tentatives < 3 AND cree_le > ? AND canal <> 'manuel'",
            [plus(maintenant(), '-1 day')]
        )->fetchAll();
        foreach ($echecs as $e) {
            if (Parcours::realiser((int) $e['id'])) {
                $n++;
            }
        }
        return $n;
    }

    private static function expirerAttentes(): void
    {
        q(
            "UPDATE participantes SET attente_type = NULL, attente_expire_le = NULL
             WHERE attente_type = 'sondage' AND attente_expire_le IS NOT NULL AND attente_expire_le < ?",
            [maintenant()]
        );
    }

    /** Rappels à J-2 et J-1 (EF-04). */
    private static function rappels(): int
    {
        $n = 0;
        $j1 = date('Y-m-d', strtotime('+1 day', ts(aujourdhui())));
        $j2 = date('Y-m-d', strtotime('+2 days', ts(aujourdhui())));
        $rdvs = q(
            "SELECT r.*, p.statut FROM rendez_vous r JOIN participantes p ON p.id = r.participante_id
             WHERE p.statut = 'active' AND r.reponse IS NULL AND (
                (r.date_rdv = ? AND r.rappel_j2_le IS NULL) OR (r.date_rdv = ? AND r.rappel_j1_le IS NULL))",
            [$j2, $j1]
        )->fetchAll();
        foreach ($rdvs as $r) {
            $p = Parcours::participante((int) $r['participante_id']);
            $evt = $r['type'] === 'vaccination' ? 'rappel_vaccin' : 'rappel_rdv';
            $msg = Parcours::messageEvenement($evt, $p) ?? Parcours::messageEvenement('rappel_rdv', $p);
            if (!$msg) {
                continue;
            }
            $colonne = $r['date_rdv'] === $j2 ? 'rappel_j2_le' : 'rappel_j1_le';
            q("UPDATE rendez_vous SET {$colonne} = ?" . ($colonne === 'rappel_j1_le' ? ', rappel_j2_le = COALESCE(rappel_j2_le, ?)' : '') . ' WHERE id = ?',
                $colonne === 'rappel_j1_le' ? [maintenant(), maintenant(), $r['id']] : [maintenant(), $r['id']]);
            Parcours::proposer((int) $r['participante_id'], $msg, 'rappel', (int) $r['id']);
            $n++;
        }
        return $n;
    }

    /** À la date prévue d'accouchement, on demande si l'enfant est né (EF-11). */
    private static function naissances(): int
    {
        $n = 0;
        $liste = q(
            "SELECT * FROM participantes WHERE statut = 'active' AND profil = 'enceinte' AND dpa <= ?
               AND (naissance_demandee_le IS NULL OR naissance_demandee_le < ?)",
            [aujourdhui(), plus(maintenant(), '-7 days')]
        )->fetchAll();
        foreach ($liste as $p) {
            $deja = (int) qv("SELECT COUNT(*) FROM envois WHERE participante_id = ? AND motif = 'naissance' AND nature IN ('notif', 'audio')", [$p['id']]);
            if ($deja >= 4) {
                continue; // au-delà, l'équipe prend le relais au centre
            }
            $msg = Parcours::messageEvenement('naissance', $p);
            if (!$msg) {
                continue;
            }
            q('UPDATE participantes SET naissance_demandee_le = ? WHERE id = ?', [maintenant(), $p['id']]);
            Parcours::proposer((int) $p['id'], $msg, 'naissance');
            $n++;
        }
        return $n;
    }

    /** Fin du parcours au premier anniversaire de l'enfant. */
    private static function fins(): int
    {
        $n = 0;
        $liste = q(
            "SELECT * FROM participantes WHERE statut = 'active' AND profil = 'mere' AND naissance_enfant <= ?",
            [date('Y-m-d', strtotime('-365 days', ts(aujourdhui())))]
        )->fetchAll();
        foreach ($liste as $p) {
            q("UPDATE participantes SET statut = 'terminee', motif_fin = 'un_an', arretee_le = ? WHERE id = ?", [maintenant(), $p['id']]);
            $msg = Parcours::messageEvenement('fin', $p);
            if ($msg) {
                Parcours::proposer((int) $p['id'], $msg, 'fin', null, true);
            }
            journal('systeme', 'fin_parcours', ['participante' => $p['pseudo']]);
            $n++;
        }
        return $n;
    }

    /** Questionnaire de satisfaction à J+10 (EF-13). */
    private static function sondages(): int
    {
        $jours = (int) cfg('rythme.sondage_jours', 10);
        if ($jours <= 0) {
            return 0;
        }
        $n = 0;
        $liste = q(
            "SELECT * FROM participantes WHERE statut = 'active' AND bienvenue_envoyee = 1 AND sondage_lance_le IS NULL AND inscrite_le <= ?",
            [plus(maintenant(), "-{$jours} days")]
        )->fetchAll();
        foreach ($liste as $p) {
            $msg = Parcours::messageEvenement('sondage_1', $p);
            if (!$msg) {
                continue;
            }
            q('UPDATE participantes SET sondage_lance_le = ? WHERE id = ?', [maintenant(), $p['id']]);
            Parcours::proposer((int) $p['id'], $msg, 'sondage');
            $n++;
        }
        return $n;
    }

    /** Contenus adaptés au stade, 2 à 3 par semaine (EF-03). */
    private static function contenus(): int
    {
        $n = 0;
        $intervalle = (int) cfg('rythme.intervalle_jours', 2);
        $maxSemaine = (int) cfg('rythme.max_semaine', 3);
        $liste = q("SELECT * FROM participantes WHERE statut = 'active' AND bienvenue_envoyee = 1")->fetchAll();
        foreach ($liste as $p) {
            $pid = (int) $p['id'];
            // Pas plus d'un contenu tous les deux jours, ni plus de trois par semaine.
            $dernier = qv(
                "SELECT MAX(cree_le) FROM envois WHERE participante_id = ? AND motif = 'contenu' AND nature IN ('notif', 'audio') AND parent_id IS NULL",
                [$pid]
            );
            if ($dernier && ts(maintenant()) - ts((string) $dernier) < $intervalle * 86400 - 3600) {
                continue;
            }
            $semaine = (int) qv(
                "SELECT COUNT(*) FROM envois WHERE participante_id = ? AND motif = 'contenu' AND nature IN ('notif', 'audio') AND parent_id IS NULL AND cree_le > ?",
                [$pid, plus(maintenant(), '-7 days')]
            );
            if ($semaine >= $maxSemaine) {
                continue;
            }
            // Pas d'empilement : au plus deux notifications non écoutées.
            $nonEcoutees = (int) qv(
                "SELECT COUNT(*) FROM envois WHERE participante_id = ? AND nature = 'notif' AND demande_le IS NULL AND statut NOT IN ('echec', 'annule') AND cree_le > ?",
                [$pid, plus(maintenant(), '-7 days')]
            );
            if ($nonEcoutees >= 2) {
                continue;
            }
            $msg = self::prochainContenu($p);
            if ($msg) {
                Parcours::proposer($pid, $msg, 'contenu');
                $n++;
            }
        }
        return $n;
    }

    /** Premier message validé, adapté au stade, pas encore reçu (ou dont la répétition est due). */
    public static function prochainContenu(array $p): ?array
    {
        $stade = Parcours::stade($p);
        if ($stade === null) {
            return null;
        }
        $candidats = q(
            "SELECT * FROM messages m WHERE m.statut = 'valide' AND m.evenement IS NULL AND m.fichier_audio IS NOT NULL
               AND m.langue = ? AND m.profil = ? AND m.debut <= ? AND m.fin >= ?
               AND m.version = (SELECT MAX(m2.version) FROM messages m2 WHERE m2.code = m.code AND m2.statut = 'valide')
             ORDER BY m.ordre, m.debut, m.code",
            [$p['langue'], $p['profil'], $stade, $stade]
        )->fetchAll();
        foreach ($candidats as $m) {
            $dernierEnvoi = qv(
                "SELECT MAX(e.cree_le) FROM envois e JOIN messages mm ON mm.id = e.message_id
                 WHERE e.participante_id = ? AND mm.code = ? AND e.motif = 'contenu' AND e.statut <> 'annule' AND e.parent_id IS NULL",
                [$p['id'], $m['code']]
            );
            if (!$dernierEnvoi) {
                return $m;
            }
            $rep = (int) $m['repetition_jours'];
            if ($rep > 0 && ts(maintenant()) - ts((string) $dernierEnvoi) >= $rep * 86400) {
                return $m;
            }
        }
        return null;
    }
}
