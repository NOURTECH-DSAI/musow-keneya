<?php
declare(strict_types=1);

final class Installation
{
    public static function creerTables(): void
    {
        $pilote = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (Schema::ddl($pilote) as $sql) {
            try {
                db()->exec($sql);
            } catch (PDOException $e) {
                // Index déjà présent lors d'une réinstallation : sans gravité
                if (!str_starts_with($sql, 'CREATE') || !str_contains($sql, 'INDEX')) {
                    throw $e;
                }
            }
        }
    }

    /** Concepts du lexique des signes de danger (termes dioula à fournir par les linguistes). */
    public static function semerLexique(): int
    {
        if (qv('SELECT COUNT(*) FROM lexique')) {
            return 0;
        }
        $concepts = [
            'saignement pendant la grossesse', 'saignement abondant après l’accouchement', 'fièvre', 'convulsions',
            'maux de tête violents', 'vision trouble', 'gonflement du visage ou des mains', 'perte des eaux',
            'le bébé ne bouge plus', 'fortes douleurs au ventre', 'difficulté à respirer', 'évanouissement',
            'bébé qui ne tète pas', 'bébé chaud ou très froid', 'bébé qui respire vite', 'cordon rouge ou qui sent mauvais',
            'peau ou yeux jaunes chez le bébé', 'diarrhée du bébé',
        ];
        foreach ($concepts as $c) {
            q('INSERT INTO lexique (concept_fr, termes, valide) VALUES (?, NULL, 0)', [$c]);
        }
        return count($concepts);
    }

    /** Liste initiale de questions fréquentes (contenus/questions_initiales.csv). */
    public static function semerQuestions(?int $uid): int
    {
        $csv = MK_ROOT . '/contenus/questions_initiales.csv';
        if (qv('SELECT COUNT(*) FROM questions') || !is_file($csv)) {
            return 0;
        }
        return Questions::importerQuestions($csv, $uid)['crees'];
    }

    /** Crée les fiches de la bibliothèque (brouillons) si elles n'existent pas. */
    public static function semerBibliotheque(?int $uid): int
    {
        $n = 0;
        foreach (Schema::bibliotheque() as [$code, $titre, $module, $evt, $profil, $debut, $fin, $rep, $ordre]) {
            if (qv('SELECT COUNT(*) FROM messages WHERE code = ?', [$code])) {
                continue;
            }
            q(
                'INSERT INTO messages (code, version, titre, module, evenement, langue, profil, debut, fin, repetition_jours, ordre, statut, cree_le)
                 VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$code, $titre, $module, $evt, 'dyu', $profil, $debut, $fin, $rep, $ordre, 'brouillon', maintenant()]
            );
            $n++;
        }
        if ($n) {
            journal('u:' . ($uid ?? 0), 'bibliotheque_initialisee', ['messages' => $n]);
        }
        return $n;
    }
}
