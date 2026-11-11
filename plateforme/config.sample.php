<?php
/**
 * Configuration de Musow Kènèya.
 * install.php crée automatiquement config.php à partir de ce modèle.
 * Ne publiez jamais config.php (il contient les clés de chiffrement).
 */
return [
    // Adresse publique du dossier, sans barre finale (HTTPS obligatoire pour Meta)
    'url_base' => 'https://collecte.idtds.org/musow',

    'db' => [
        'hote' => 'localhost',
        'port' => 3306,
        'base' => 'u000000000_musow',
        'utilisateur' => 'u000000000_musow',
        'mot_de_passe' => '',
    ],

    'securite' => [
        // Générées par install.php : NE JAMAIS LES CHANGER après la première inscription
        'cle_chiffrement' => '',
        'cle_index' => '',
        // Jeton du service IA (api/ia.php)
        'jeton_ia' => '',
    ],

    'whatsapp' => [
        // cloud : API WhatsApp Business Platform · manuel : repli avec l'application WhatsApp Business · test : rien n'est envoyé
        'mode' => 'manuel',
        'version_api' => 'v21.0',
        'id_numero' => '',            // Phone number ID (tableau de bord Meta)
        'jeton' => '',                // Jeton d'accès permanent (utilisateur système)
        'secret_application' => '',   // App secret, pour vérifier la signature des notifications
        'jeton_verification' => '',   // Chaîne libre, à recopier dans Meta lors de l'abonnement au webhook
        'langue_modeles' => 'fr',
        // Modèle « utilitaire » neutre, un bouton de réponse rapide « Écouter »
        'modele_notification' => 'musow_nouveau_message',
        'texte_notification' => 'Bonjour, un nouveau message vocal vous attend. Envoyez 1 pour l’écouter.',
    ],

    'langue_defaut' => 'dyu',

    'rythme' => [
        'heure_debut' => 8,        // aucun envoi programmé avant 8 h
        'heure_fin' => 20,         // ni après 20 h
        'intervalle_jours' => 2,   // au moins deux jours entre deux contenus
        'max_semaine' => 3,        // trois contenus par semaine au plus
        'sondage_jours' => 10,     // questionnaire à J+10 (0 pour désactiver)
    ],

    // Questions vocales en dioula, à réponses vérifiées
    'questions' => [
        'actives' => true,               // nécessite le service IA (ia/worker.py) en marche
        'seuil_reconnaissance' => 0.55,  // certitude minimale pour proposer une question (0 à 1)
        'marge' => 0.06,                 // écart minimal avec la deuxième question possible
        'seuil_audio' => 0.45,           // confiance minimale de la transcription
        'delai_analyse_min' => 5,        // au-delà, la question part à l'équipe
        'delai_confirmation_h' => 2,     // délai laissé à la femme pour confirmer
        // Confirmation à voix haute (« ɔnhɔn » / « ɔn'ɔn », puis « awɔ » / « ayi », puis « oui » / « non »)
        'seuil_audio_oui' => 0.60,       // un « oui » entendu sous ce seuil est traité comme incertain
        'confirmation_mots_max' => 3,    // au-delà, le vocal est une nouvelle question
        // 'mots_oui' => ['onhon', 'awo', 'owo', 'oui'],   // à compléter avec les graphies de la transcription
        // 'mots_non' => ['onon', 'ayi', 'ai', 'non'],
    ],

    'alertes' => [
        // Adresses prévenues à chaque alerte et message vocal (séparées par des virgules)
        'emails' => '',
        'expediteur' => 'musow@nourtech.ci',
    ],

    // Textes courts envoyés avec les audios. Ils restent neutres : aucun mot
    // ne révèle une grossesse ou un enfant sur un téléphone partagé (EF-06).
    // Numéros d'urgence à confirmer par la directrice médicale.
    'textes' => [
        'bienvenue' => 'Bienvenue à Musow Kènèya. Vous recevrez ici des messages en dioula sur la grossesse et la santé de votre bébé.',
        'menu_boutons' => 'Touchez un bouton, ou envoyez un message vocal pour poser votre question.',
        'bouton_ecouter' => '🔊 Écouter',
        'bouton_urgence' => '🆘 Urgence',
        'bouton_centre' => '🏥 Mon centre',
        'salutation' => 'I ni ce ! Bienvenue à Musow Kènèya. Posez votre question en envoyant un message vocal, je vous écoute. Vous pouvez aussi toucher un bouton.',
        'menu' => "1 : écouter\n2 : urgence\n3 : contact\n9 : arrêter",
        'urgence' => "URGENCE : allez tout de suite au centre de santé, ou appelez le SAMU au 185.",
        'urgence_centre' => 'Centre de santé : {tel}',
        'accuse' => "Message bien reçu. Une personne de l’équipe va l’écouter. En cas d’urgence, envoyez 2.",
        'stop' => 'C’est arrêté. Vous ne recevrez plus de messages. Merci.',
        'inactive' => 'Le service est arrêté pour ce numéro. Pour le reprendre, parlez-en à votre centre de santé.',
        'inconnue' => 'Bonjour. Ce numéro n’est pas encore inscrit. L’inscription se fait au centre de santé.',
        'rappel' => 'Rendez-vous : {date}. Envoyez 4 si vous venez, 5 si vous ne pouvez pas venir.',
        'rdv_oui' => 'Merci, c’est noté. À bientôt.',
        'rdv_non' => 'C’est noté. Le centre vous proposera une autre date.',
        'naissance_question' => 'Avez-vous une nouvelle à nous donner ?',
        'naissance_oui' => 'Félicitations ! Les prochains messages seront adaptés.',
        'naissance_non' => 'Merci. Nous vous redemanderons plus tard.',
        'faq_ecoute' => 'Message reçu. Je cherche la réponse, un instant. En cas d’urgence, envoyez 2.',
        'faq_confirmation' => 'Est-ce bien votre question ? Répondez à voix haute : « ɔnhɔn » (oui) ou « ɔn’ɔn » (non).',
        'faq_sans_reponse' => 'Je n’ai pas encore de réponse à cette question. Une personne de l’équipe va l’écouter et vous rappeler. En cas d’urgence, allez au centre de santé ou envoyez 2.',
        'sondage_consigne' => 'Pour répondre : 1 = pas du tout, 3 = un peu, 4 = bien, 5 = très bien.',
    ],
];
