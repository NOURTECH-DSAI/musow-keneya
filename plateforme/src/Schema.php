<?php
declare(strict_types=1);

/**
 * Schéma de la base. Écrit en MySQL ; converti automatiquement pour SQLite
 * (tests). Toutes les dates sont stockées en heure d'Abidjan (UTC+0).
 */
final class Schema
{
    public static function tables(): array
    {
        return [
            'utilisateurs' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL UNIQUE,
                nom VARCHAR(120) NOT NULL,
                role VARCHAR(20) NOT NULL,
                mot_de_passe VARCHAR(255) NOT NULL,
                totp_secret VARCHAR(64) NULL,
                actif TINYINT NOT NULL DEFAULT 1,
                cree_le DATETIME NOT NULL,
                derniere_connexion DATETIME NULL",

            'centres' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(160) NOT NULL,
                commune VARCHAR(80) NULL,
                telephone VARCHAR(40) NULL,
                actif TINYINT NOT NULL DEFAULT 1",

            'participantes' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pseudo VARCHAR(20) NOT NULL UNIQUE,
                profil VARCHAR(10) NOT NULL,
                dpa DATE NULL,
                naissance_enfant DATE NULL,
                centre_id INT UNSIGNED NULL,
                langue VARCHAR(10) NOT NULL DEFAULT 'dyu',
                statut VARCHAR(12) NOT NULL DEFAULT 'active',
                consent_service_le DATETIME NOT NULL,
                consent_ia TINYINT NOT NULL DEFAULT 0,
                consent_ia_le DATETIME NULL,
                inscrit_par INT UNSIGNED NULL,
                inscrite_le DATETIME NOT NULL,
                dernier_entrant_le DATETIME NULL,
                bienvenue_envoyee TINYINT NOT NULL DEFAULT 0,
                attente_type VARCHAR(20) NULL,
                attente_ref INT UNSIGNED NULL,
                attente_expire_le DATETIME NULL,
                dernier_contenu_id INT UNSIGNED NULL,
                naissance_demandee_le DATETIME NULL,
                sondage_etape TINYINT NOT NULL DEFAULT 0,
                sondage_lance_le DATETIME NULL,
                arretee_le DATETIME NULL,
                motif_fin VARCHAR(30) NULL",

            'correspondances' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                participante_id INT UNSIGNED NOT NULL,
                numero_chiffre TEXT NOT NULL,
                numero_index CHAR(64) NOT NULL,
                actif TINYINT NULL DEFAULT 1,
                cree_le DATETIME NOT NULL,
                desactive_le DATETIME NULL",

            'rendez_vous' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                participante_id INT UNSIGNED NOT NULL,
                type VARCHAR(20) NOT NULL,
                date_rdv DATE NOT NULL,
                rappel_j2_le DATETIME NULL,
                rappel_j1_le DATETIME NULL,
                reponse TINYINT NULL,
                reponse_le DATETIME NULL,
                honore TINYINT NULL,
                cree_par INT UNSIGNED NULL,
                cree_le DATETIME NOT NULL",

            'messages' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                titre VARCHAR(160) NOT NULL,
                module VARCHAR(40) NOT NULL,
                evenement VARCHAR(30) NULL,
                langue VARCHAR(10) NOT NULL DEFAULT 'dyu',
                profil VARCHAR(10) NOT NULL DEFAULT 'toutes',
                debut INT NULL,
                fin INT NULL,
                repetition_jours INT NOT NULL DEFAULT 0,
                ordre INT NOT NULL DEFAULT 100,
                objectif TEXT NULL,
                reference TEXT NULL,
                texte_fr TEXT NULL,
                texte_dyu TEXT NULL,
                contre_traduction TEXT NULL,
                fichier_audio VARCHAR(120) NULL,
                duree_s INT NULL,
                statut VARCHAR(12) NOT NULL DEFAULT 'brouillon',
                valide_par INT UNSIGNED NULL,
                valide_le DATETIME NULL,
                remplace_id INT UNSIGNED NULL,
                cree_le DATETIME NOT NULL,
                modifie_le DATETIME NULL",

            'envois' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                participante_id INT UNSIGNED NOT NULL,
                message_id INT UNSIGNED NULL,
                nature VARCHAR(12) NOT NULL,
                motif VARCHAR(20) NOT NULL,
                rdv_id INT UNSIGNED NULL,
                parent_id INT UNSIGNED NULL,
                canal VARCHAR(10) NOT NULL,
                wa_id VARCHAR(120) NULL,
                statut VARCHAR(12) NOT NULL DEFAULT 'a_envoyer',
                erreur TEXT NULL,
                tentatives INT NOT NULL DEFAULT 0,
                charge TEXT NULL,
                cree_le DATETIME NOT NULL,
                envoye_le DATETIME NULL,
                livre_le DATETIME NULL,
                lu_le DATETIME NULL,
                demande_le DATETIME NULL",

            'entrants' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                participante_id INT UNSIGNED NULL,
                wa_id VARCHAR(120) NULL,
                type VARCHAR(12) NOT NULL,
                contenu VARCHAR(255) NULL,
                recu_le DATETIME NOT NULL",

            'vocaux' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                participante_id INT UNSIGNED NOT NULL,
                entrant_id INT UNSIGNED NULL,
                media_id VARCHAR(120) NULL,
                fichier VARCHAR(160) NULL,
                duree_s INT NULL,
                recu_le DATETIME NOT NULL,
                consent_ia TINYINT NOT NULL DEFAULT 0,
                ia_statut VARCHAR(12) NOT NULL DEFAULT 'non',
                ia_transcription TEXT NULL,
                ia_score DECIMAL(5,4) NULL,
                ia_alerte TINYINT NULL,
                ia_termes VARCHAR(255) NULL,
                ia_modele VARCHAR(80) NULL,
                ia_traite_le DATETIME NULL,
                priorite TINYINT NOT NULL DEFAULT 0,
                statut VARCHAR(12) NOT NULL DEFAULT 'a_traiter',
                pris_en_charge_le DATETIME NULL,
                traite_par INT UNSIGNED NULL,
                annotation_alerte TINYINT NULL,
                transcription_humaine TEXT NULL,
                note TEXT NULL",

            'alertes' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                participante_id INT UNSIGNED NOT NULL,
                origine VARCHAR(12) NOT NULL,
                vocal_id INT UNSIGNED NULL,
                cree_le DATETIME NOT NULL,
                consigne_envoyee_le DATETIME NULL,
                rappel_le DATETIME NULL,
                issue VARCHAR(30) NULL,
                note TEXT NULL,
                traite_par INT UNSIGNED NULL,
                statut VARCHAR(12) NOT NULL DEFAULT 'ouverte'",

            'taches_manuelles' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                envoi_id INT UNSIGNED NULL,
                participante_id INT UNSIGNED NOT NULL,
                nature VARCHAR(12) NOT NULL,
                consigne TEXT NOT NULL,
                fichier_audio VARCHAR(120) NULL,
                cree_le DATETIME NOT NULL,
                fait_le DATETIME NULL,
                fait_par INT UNSIGNED NULL",

            'sondages' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                participante_id INT UNSIGNED NOT NULL,
                question TINYINT NOT NULL,
                reponse TINYINT NOT NULL,
                repondu_le DATETIME NOT NULL",

            // Questions fréquentes : chaque question a une confirmation et une réponse
            // enregistrées, qui passent par la validation médicale de la bibliothèque.
            'questions' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) NOT NULL UNIQUE,
                theme VARCHAR(40) NOT NULL,
                intitule_fr VARCHAR(255) NOT NULL,
                alerte TINYINT NOT NULL DEFAULT 0,
                profil VARCHAR(10) NOT NULL DEFAULT 'toutes',
                statut VARCHAR(10) NOT NULL DEFAULT 'actif',
                ordre INT NOT NULL DEFAULT 100,
                cree_le DATETIME NOT NULL,
                modifie_le DATETIME NULL",

            // Formulations en dioula (écrites par les linguistes ou issues de vraies questions)
            'variantes' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                question_id INT UNSIGNED NOT NULL,
                texte VARCHAR(500) NOT NULL,
                source VARCHAR(12) NOT NULL DEFAULT 'linguiste',
                cree_par INT UNSIGNED NULL,
                cree_le DATETIME NOT NULL",

            // Chaque question posée par une femme, et ce que le service en a fait
            'questions_posees' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                participante_id INT UNSIGNED NOT NULL,
                vocal_id INT UNSIGNED NOT NULL,
                transcription TEXT NULL,
                score_asr DECIMAL(5,4) NULL,
                question_id INT UNSIGNED NULL,
                score DECIMAL(5,4) NULL,
                second_id INT UNSIGNED NULL,
                second_score DECIMAL(5,4) NULL,
                danger TINYINT NOT NULL DEFAULT 0,
                termes VARCHAR(255) NULL,
                statut VARCHAR(16) NOT NULL DEFAULT 'en_analyse',
                motif VARCHAR(40) NULL,
                cree_le DATETIME NOT NULL,
                analyse_le DATETIME NULL,
                confirmation_le DATETIME NULL,
                repondu_le DATETIME NULL,
                verifie_correct TINYINT NULL,
                verifie_par INT UNSIGNED NULL,
                verifie_le DATETIME NULL",

            // Lexique des signes de danger en dioula (validé par la direction médicale)
            'lexique' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                concept_fr VARCHAR(160) NOT NULL,
                termes TEXT NULL,
                valide TINYINT NOT NULL DEFAULT 0,
                valide_par INT UNSIGNED NULL,
                valide_le DATETIME NULL,
                modifie_le DATETIME NULL",

            'journal' => "
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                acteur VARCHAR(80) NOT NULL,
                action VARCHAR(60) NOT NULL,
                details TEXT NULL,
                cree_le DATETIME NOT NULL",

            'parametres' => "
                cle VARCHAR(60) NOT NULL PRIMARY KEY,
                valeur TEXT NULL",
        ];
    }

    public static function index(): array
    {
        return [
            'CREATE UNIQUE INDEX ux_corr_index ON correspondances (numero_index, actif)',
            'CREATE INDEX ix_corr_part ON correspondances (participante_id)',
            'CREATE INDEX ix_part_statut ON participantes (statut)',
            'CREATE INDEX ix_rdv_date ON rendez_vous (date_rdv)',
            'CREATE INDEX ix_rdv_part ON rendez_vous (participante_id)',
            'CREATE INDEX ix_msg_code ON messages (code, statut)',
            'CREATE INDEX ix_env_part ON envois (participante_id, cree_le)',
            'CREATE INDEX ix_env_wa ON envois (wa_id)',
            'CREATE INDEX ix_env_statut ON envois (statut)',
            'CREATE INDEX ix_ent_wa ON entrants (wa_id)',
            'CREATE INDEX ix_voc_statut ON vocaux (statut, priorite)',
            'CREATE INDEX ix_voc_ia ON vocaux (ia_statut)',
            'CREATE INDEX ix_alerte_statut ON alertes (statut)',
            'CREATE INDEX ix_tache_fait ON taches_manuelles (fait_le)',
            'CREATE INDEX ix_journal_date ON journal (cree_le)',
            'CREATE INDEX ix_var_question ON variantes (question_id)',
            'CREATE INDEX ix_qp_statut ON questions_posees (statut)',
            'CREATE INDEX ix_qp_vocal ON questions_posees (vocal_id)',
            'CREATE INDEX ix_qp_part ON questions_posees (participante_id)',
        ];
    }

    /** Instructions DDL pour le pilote PDO courant. */
    public static function ddl(string $pilote): array
    {
        $sql = [];
        foreach (self::tables() as $nom => $colonnes) {
            if ($pilote === 'sqlite') {
                $colonnes = str_replace(
                    ['INT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INT UNSIGNED', 'DECIMAL(5,4)'],
                    ['INTEGER PRIMARY KEY AUTOINCREMENT', 'INTEGER', 'REAL'],
                    $colonnes
                );
                $sql[] = "CREATE TABLE IF NOT EXISTS {$nom} ({$colonnes})";
            } else {
                $sql[] = "CREATE TABLE IF NOT EXISTS {$nom} ({$colonnes}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            }
        }
        foreach (self::index() as $ix) {
            $sql[] = $ix;
        }
        return $sql;
    }

    /**
     * Bibliothèque initiale : les 25 messages de la section 10.2 du cahier des
     * charges, plus les messages techniques du parcours. Tous en brouillon :
     * aucun ne peut être diffusé avant validation par la directrice médicale.
     * debut/fin : semaines de grossesse (profil enceinte) ou jours de vie de
     * l'enfant (profil mère).
     */
    public static function bibliotheque(): array
    {
        return [
            // code, titre, module, evenement, profil, debut, fin, repetition_jours, ordre
            ['BIENV-01', 'Bienvenue et présentation de la voix du service', 'bienvenue', 'bienvenue', 'toutes', null, null, 0, 1],
            ['BIENV-02', "Mode d'emploi : les chiffres du menu", 'bienvenue', 'menu', 'toutes', null, null, 0, 2],
            ['PRENAT-01', 'Pourquoi suivre ses consultations prénatales', 'prenatal', null, 'enceinte', 1, 13, 0, 10],
            ['PRENAT-02', 'Les contacts du deuxième trimestre', 'prenatal', null, 'enceinte', 14, 27, 0, 10],
            ['PRENAT-03', 'Les contacts du troisième trimestre', 'prenatal', null, 'enceinte', 28, 35, 0, 10],
            ['PRENAT-04', 'Les dernières semaines avant le terme', 'prenatal', null, 'enceinte', 36, 42, 0, 10],
            ['DANGER-01', 'Signes de danger pendant la grossesse (message permanent du menu)', 'danger', 'danger', 'enceinte', null, null, 0, 5],
            ['DANGER-02', 'Signes de danger : saignements, maux de tête, vision trouble', 'danger', null, 'enceinte', 1, 42, 30, 20],
            ['DANGER-03', "Signes de danger : fièvre, pertes d'eau, le bébé ne bouge plus", 'danger', null, 'enceinte', 20, 42, 30, 20],
            ['NUTRI-01', 'Bien manger pendant la grossesse', 'nutrition', null, 'enceinte', 1, 27, 0, 30],
            ['NUTRI-02', 'Repos et activité', 'nutrition', null, 'enceinte', 1, 27, 0, 30],
            ['NUTRI-03', 'Dormir chaque nuit sous moustiquaire imprégnée', 'nutrition', null, 'enceinte', 1, 27, 0, 25],
            ['ACCOUCH-01', "Préparer l'accouchement : le plan et le sac", 'accouchement', null, 'enceinte', 32, 42, 0, 15],
            ['ACCOUCH-02', 'Quand partir au centre de santé', 'accouchement', null, 'enceinte', 34, 42, 0, 15],
            ['POSTP-01', "Après l'accouchement : la consultation postnatale", 'postpartum', null, 'mere', 0, 7, 0, 10],
            ['POSTP-02', 'Signes de danger chez la mère après la naissance', 'postpartum', 'danger_mere', 'mere', 0, 42, 0, 10],
            ['NNE-01', "L'allaitement dès la première heure", 'nouveau_ne', null, 'mere', 0, 30, 0, 12],
            ['NNE-02', 'Soins du cordon et chaleur du bébé', 'nouveau_ne', null, 'mere', 0, 30, 0, 12],
            ['NNE-03', 'Signes de danger chez le nouveau-né', 'nouveau_ne', null, 'mere', 0, 30, 0, 11],
            ['VACC-01', 'Le calendrier des vaccinations', 'vaccination', null, 'mere', 0, 365, 0, 14],
            ['VACC-02', 'Avant la vaccination : ce qu’il faut apporter', 'vaccination', 'rappel_vaccin', 'mere', null, null, 0, 14],
            ['SERV-01', 'Rappel de rendez-vous (consultation)', 'service', 'rappel_rdv', 'toutes', null, null, 0, 1],
            ['SERV-02', 'Message de soutien après la perte d’une grossesse', 'service', 'perte', 'toutes', null, null, 0, 1],
            ['SERV-03', 'Fin du parcours et remerciements', 'service', 'fin', 'toutes', null, null, 0, 1],
            ['SERV-04', 'Confirmation d’arrêt du service', 'service', 'stop', 'toutes', null, null, 0, 1],
            // Messages techniques du parcours (non comptés dans les 25)
            ['TECH-01', 'Accusé de réception d’un message vocal et consigne de sécurité', 'technique', 'accuse_vocal', 'toutes', null, null, 0, 1],
            ['TECH-02', 'Question : votre bébé est-il né ?', 'technique', 'naissance', 'enceinte', null, null, 0, 1],
            ['TECH-03', 'Coordonnées du centre de santé', 'technique', 'centre', 'toutes', null, null, 0, 1],
            ['TECH-04', 'Question reçue : « je vous écoute, je vous réponds dans un instant »', 'technique', 'faq_ecoute', 'toutes', null, null, 0, 1],
            ['TECH-05', 'Pas encore de réponse : une sage-femme va écouter et rappeler (avec consigne de sécurité)', 'technique', 'faq_sans_reponse', 'toutes', null, null, 0, 1],
            ['TECH-06', 'Après une réponse : « avez-vous une autre question ? »', 'technique', 'faq_autre', 'toutes', null, null, 0, 1],
            ['TECH-07', 'Réponse à une salutation : « I ni ce, posez votre question, je vous écoute »', 'technique', 'salutation', 'toutes', null, null, 0, 1],
            ['SOND-01', 'Questionnaire, question 1 : les messages sont-ils faciles à comprendre ?', 'sondage', 'sondage_1', 'toutes', null, null, 0, 1],
            ['SOND-02', 'Questionnaire, question 2 : les messages vous sont-ils utiles ?', 'sondage', 'sondage_2', 'toutes', null, null, 0, 1],
            ['SOND-03', 'Questionnaire, question 3 : la voix et la langue vous conviennent-elles ?', 'sondage', 'sondage_3', 'toutes', null, null, 0, 1],
            ['SOND-04', 'Questionnaire, question 4 : le nombre de messages vous convient-il ?', 'sondage', 'sondage_4', 'toutes', null, null, 0, 1],
            ['SOND-05', 'Questionnaire, question 5 : recommanderiez-vous le service à une amie ?', 'sondage', 'sondage_5', 'toutes', null, null, 0, 1],
            ['SOND-06', 'Questionnaire : merci', 'sondage', 'sondage_fin', 'toutes', null, null, 0, 1],
        ];
    }
}
