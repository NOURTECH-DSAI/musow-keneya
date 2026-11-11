<?php
declare(strict_types=1);

/** Indicateurs du tableau de bord (EF-09) et de la page publique (EF-14). */
final class Indicateurs
{
    public const SEUIL = 10;

    public static function equipe(?string $depuis = null): array
    {
        $depuis ??= '2000-01-01 00:00:00';
        $i = [];
        $i['inscrites'] = (int) qv('SELECT COUNT(*) FROM participantes WHERE inscrite_le >= ?', [$depuis]);
        $i['actives'] = (int) qv("SELECT COUNT(*) FROM participantes WHERE statut = 'active'");
        $i['enceintes'] = (int) qv("SELECT COUNT(*) FROM participantes WHERE statut = 'active' AND profil = 'enceinte'");
        $i['meres'] = (int) qv("SELECT COUNT(*) FROM participantes WHERE statut = 'active' AND profil = 'mere'");
        $i['arrets'] = (int) qv("SELECT COUNT(*) FROM participantes WHERE statut = 'stoppee' AND motif_fin = 'demande'");

        // Notifications (proposition d'un message) et écoutes demandées
        $i['notifs'] = (int) qv("SELECT COUNT(*) FROM envois WHERE nature = 'notif' AND statut NOT IN ('annule', 'a_faire') AND cree_le >= ?", [$depuis]);
        $i['notifs_livrees'] = (int) qv("SELECT COUNT(*) FROM envois WHERE nature = 'notif' AND livre_le IS NOT NULL AND cree_le >= ?", [$depuis]);
        $i['ecoutes_demandees'] = (int) qv("SELECT COUNT(*) FROM envois WHERE nature = 'notif' AND demande_le IS NOT NULL AND cree_le >= ?", [$depuis]);

        // Audios de santé (hors menu et accusés)
        $motifsSante = "('contenu', 'rappel', 'danger', 'bienvenue', 'reecoute', 'naissance')";
        $i['audios_envoyes'] = (int) qv("SELECT COUNT(*) FROM envois WHERE nature = 'audio' AND motif IN {$motifsSante} AND statut IN ('envoye', 'livre', 'lu') AND cree_le >= ?", [$depuis]);
        $i['audios_livres'] = (int) qv("SELECT COUNT(*) FROM envois WHERE nature = 'audio' AND motif IN {$motifsSante} AND livre_le IS NOT NULL AND cree_le >= ?", [$depuis]);
        $i['audios_lus'] = (int) qv("SELECT COUNT(*) FROM envois WHERE nature = 'audio' AND motif IN {$motifsSante} AND lu_le IS NOT NULL AND cree_le >= ?", [$depuis]);
        $i['echecs'] = (int) qv("SELECT COUNT(*) FROM envois WHERE statut = 'echec' AND cree_le >= ?", [$depuis]);
        $i['total_envois'] = (int) qv("SELECT COUNT(*) FROM envois WHERE statut NOT IN ('annule', 'a_faire') AND cree_le >= ?", [$depuis]);

        $i['reponses'] = (int) qv('SELECT COUNT(*) FROM entrants WHERE participante_id IS NOT NULL AND recu_le >= ?', [$depuis]);
        $i['participantes_repondantes'] = (int) qv('SELECT COUNT(DISTINCT participante_id) FROM entrants WHERE participante_id IS NOT NULL AND recu_le >= ?', [$depuis]);
        $i['vocaux'] = (int) qv('SELECT COUNT(*) FROM vocaux WHERE recu_le >= ?', [$depuis]);
        $i['alertes'] = (int) qv('SELECT COUNT(*) FROM alertes WHERE cree_le >= ?', [$depuis]);
        $i['alertes_ouvertes'] = (int) qv("SELECT COUNT(*) FROM alertes WHERE statut = 'ouverte'");

        $delais = q('SELECT cree_le, rappel_le FROM alertes WHERE rappel_le IS NOT NULL AND cree_le >= ?', [$depuis])->fetchAll();
        $i['delai_rappel_median_s'] = self::mediane(array_map(static fn($a) => ts($a['rappel_le']) - ts($a['cree_le']), $delais));
        $prises = q('SELECT recu_le, pris_en_charge_le FROM vocaux WHERE pris_en_charge_le IS NOT NULL AND recu_le >= ?', [$depuis])->fetchAll();
        $i['delai_vocal_median_s'] = self::mediane(array_map(static fn($v) => ts($v['pris_en_charge_le']) - ts($v['recu_le']), $prises));

        $i['rdv_rappeles'] = (int) qv('SELECT COUNT(*) FROM rendez_vous WHERE (rappel_j2_le IS NOT NULL OR rappel_j1_le IS NOT NULL) AND cree_le >= ?', [$depuis]);
        $i['rdv_confirmes'] = (int) qv('SELECT COUNT(*) FROM rendez_vous WHERE reponse = 1 AND cree_le >= ?', [$depuis]);
        $i['rdv_empeches'] = (int) qv('SELECT COUNT(*) FROM rendez_vous WHERE reponse = 2 AND cree_le >= ?', [$depuis]);
        $i['rdv_honores'] = (int) qv('SELECT COUNT(*) FROM rendez_vous WHERE honore = 1 AND cree_le >= ?', [$depuis]);
        $i['rdv_passes'] = (int) qv('SELECT COUNT(*) FROM rendez_vous WHERE date_rdv < ? AND cree_le >= ?', [aujourdhui(), $depuis]);

        $i['ia'] = self::ia();
        $i['questions'] = Questions::indicateurs();
        $i['sondage'] = q('SELECT question, AVG(reponse) AS moyenne, COUNT(*) AS n FROM sondages GROUP BY question ORDER BY question')->fetchAll();
        return $i;
    }

    /** Rappel et précision de l'IA sur les vocaux annotés par l'équipe (7.4). */
    public static function ia(): array
    {
        $lignes = q('SELECT ia_alerte, annotation_alerte FROM vocaux WHERE ia_alerte IS NOT NULL AND annotation_alerte IS NOT NULL')->fetchAll();
        $vp = $fp = $fn = 0;
        foreach ($lignes as $l) {
            $ia = (int) $l['ia_alerte'] === 1;
            $vrai = (int) $l['annotation_alerte'] === 1;
            if ($ia && $vrai) {
                $vp++;
            } elseif ($ia && !$vrai) {
                $fp++;
            } elseif (!$ia && $vrai) {
                $fn++;
            }
        }
        return [
            'annotes' => count($lignes),
            'alertes_reelles' => $vp + $fn,
            'rappel' => ($vp + $fn) > 0 ? $vp / ($vp + $fn) : null,
            'precision' => ($vp + $fp) > 0 ? $vp / ($vp + $fp) : null,
            'transcrits' => (int) qv("SELECT COUNT(*) FROM vocaux WHERE ia_statut = 'fait'"),
        ];
    }

    /** Page publique : agrégats seulement, rien sous 10 personnes (section 12.3). */
    public static function publics(): array
    {
        $i = self::equipe();
        $masque = static fn(int $n) => $n < self::SEUIL ? '< ' . self::SEUIL : (string) $n;
        $suffisant = $i['inscrites'] >= self::SEUIL;
        return [
            'inscrites' => $masque($i['inscrites']),
            'actives' => $masque($i['actives']),
            'enceintes' => $suffisant ? $masque($i['enceintes']) : '–',
            'meres' => $suffisant ? $masque($i['meres']) : '–',
            'messages_envoyes' => $suffisant ? (string) $i['audios_envoyes'] : '–',
            'messages_livres' => $suffisant ? (string) $i['audios_livres'] : '–',
            'messages_ecoutes' => $suffisant ? (string) $i['audios_lus'] : '–',
            'taux_ecoute' => $suffisant && $i['notifs'] > 0 ? round(100 * $i['ecoutes_demandees'] / $i['notifs']) . ' %' : '–',
            'taux_confirmation' => $suffisant && $i['rdv_rappeles'] >= self::SEUIL ? round(100 * $i['rdv_confirmes'] / $i['rdv_rappeles']) . ' %' : '–',
            'alertes' => $suffisant ? $masque($i['alertes']) : '–',
            'delai_rappel' => $suffisant && $i['alertes'] >= self::SEUIL ? View::duree($i['delai_rappel_median_s']) : '–',
            'ia_rappel' => $i['ia']['alertes_reelles'] >= self::SEUIL && $i['ia']['rappel'] !== null ? round(100 * $i['ia']['rappel']) . ' %' : '–',
            'ia_precision' => $i['ia']['annotes'] >= self::SEUIL && $i['ia']['precision'] !== null ? round(100 * $i['ia']['precision']) . ' %' : '–',
            'questions_posees' => $suffisant ? $masque($i['questions']['posees']) : '–',
            'couverture' => $suffisant && $i['questions']['posees'] >= self::SEUIL && $i['questions']['couverture'] !== null ? round(100 * $i['questions']['couverture']) . ' %' : '–',
            'taux_erreur' => $i['questions']['verifiees'] >= self::SEUIL && $i['questions']['taux_erreur'] !== null ? round(100 * $i['questions']['taux_erreur'], 1) . ' %' : '–',
            'questions_bibliotheque' => (string) $i['questions']['pretes'],
            'mis_a_jour' => maintenant(),
        ];
    }

    private static function mediane(array $valeurs): ?int
    {
        $valeurs = array_values(array_filter($valeurs, static fn($v) => $v >= 0));
        if (!$valeurs) {
            return null;
        }
        sort($valeurs);
        $n = count($valeurs);
        return $n % 2 ? $valeurs[intdiv($n, 2)] : intdiv($valeurs[$n / 2 - 1] + $valeurs[$n / 2], 2);
    }
}
