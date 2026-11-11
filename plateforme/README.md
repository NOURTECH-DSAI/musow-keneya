# Musow Kènèya, version 2.1.0

Assistant vocal de santé maternelle en dioula, sur WhatsApp. Développé par NOUR TECH SARL pour le micro-pilote d'Abobo (candidature UNICEF Femtech Ventures 2026). Code sous licence MIT, contenus sous licence CC BY 4.0.

Deux fonctions :
1. **Accompagnement programmé** : messages vocaux en dioula adaptés à la semaine de grossesse ou à l'âge de l'enfant, rappels des vrais rendez-vous, accès permanent aux signes de danger.
2. **Questions vocales à réponses vérifiées** : la femme pose sa question à voix haute en dioula ; l'IA la transcrit et la reconnaît parmi les questions de la bibliothèque ; le service reformule la question, la femme confirme **à voix haute** (« ɔnhɔn » ou « ɔn'ɔn »), et elle reçoit la réponse enregistrée et validée par la directrice médicale. La machine ne rédige jamais de réponse.

## 1. Comment le service répond à une question

| Situation | Ce que fait le service |
|---|---|
| Un terme du lexique des signes de danger est entendu, ou la question est marquée « danger » | Consigne d'urgence immédiate (audio des signes de danger, numéros) et alerte à l'équipe, sans attendre de confirmation |
| La question est reconnue avec certitude | Reformulation « Vous voulez savoir… ? Si c'est votre question, dites ɔnhɔn. Sinon, dites ɔn'ɔn. » |
| La femme dit « ɔnhɔn » (ou « awɔ », « oui ») nettement | Réponse validée, puis « avez-vous une autre question ? » |
| La femme dit « ɔn'ɔn » (ou « ayi », « non ») | Message « pas encore de réponse », transfert à une sage-femme |
| Réponse orale incertaine : « on » seul, oui et non à la fois, oui mal entendu (confiance audio sous 0,60) | Aucune réponse automatique, transfert à une sage-femme |
| Au lieu de confirmer, la femme pose une autre question | La première part à l'équipe, la nouvelle est traitée normalement |
| Question inconnue, deux questions possibles, audio peu clair | Même message honnête et transfert à l'équipe |
| Le service IA ne répond pas en 5 minutes, ou la femme ne confirme pas en 2 heures | Transfert à l'équipe |
| Touche 2 à tout moment | Urgence, toujours prioritaire |

Pendant le pilote, chaque réponse automatique est réécoutée par l'équipe (page « Questions reçues ») : c'est ce qui mesure le taux d'erreur. Chaque question sans réponse peut devenir une nouvelle formulation ou une nouvelle question : le service apprend sous contrôle médical.

Réglages dans `config.php`, section `questions` : seuil de reconnaissance (0,55), écart minimal avec la deuxième question (0,06), confiance minimale de l'audio (0,45), confiance minimale pour accepter un « oui » oral (0,60), longueur au-delà de laquelle un vocal est une nouvelle question (3 mots), listes `mots_oui` et `mots_non`, délais.

**Confirmation à voix haute.** Les femmes ne tapent rien : elles répondent en parlant. « Ɔnhɔn » et « ɔn'ɔn » se ressemblent beaucoup à l'oreille ; la règle est donc dissymétrique. Un « non » pris pour un oui donnerait une réponse à une question que la femme n'a pas posée ; un « oui » pris pour un non ne coûte qu'un rappel. Dans le doute, le service ne répond pas et une sage-femme rappelle. Les graphies réellement produites par le modèle pour ces deux mots doivent être relevées pendant les tests (section 5) et ajoutées aux listes `mots_oui` / `mots_non`. Si le modèle ne distingue pas assez bien les deux mots, la reformulation peut demander une réponse plus facile à séparer (par exemple « ɔnhɔn » pour oui, et reposer sa question sinon). Ajustez le seuil avec l'outil d'évaluation pour viser zéro erreur.

## 2. Installation de la plateforme (environ 20 minutes)

1. Dans hPanel, créez une base MySQL et son utilisateur.
2. Envoyez le dossier `musow-keneya` sur le serveur, par exemple dans `public_html/musow`.
3. Vérifiez que `audio/`, `storage/vocaux/` et `storage/logs/` sont accessibles en écriture (755).
4. Ouvrez `https://<domaine>/musow/install.php` : base de données, premier compte d'administration, centre de santé. L'installation crée les 37 messages de la bibliothèque, les 116 questions fréquentes (`contenus/questions_initiales.csv`) et les 18 concepts du lexique des signes de danger, tous en brouillon.
5. **Supprimez `install.php`.** **Sauvegardez `config.php` hors du serveur** : sans ses clés, les numéros chiffrés sont irrécupérables.
6. Tâche cron toutes les 15 minutes : `/usr/bin/php /home/<compte>/public_html/musow/cron.php`
7. Créez les comptes (menu Équipe) : Dr FOFANA Awa en « Direction médicale », les sages-femmes et animatrices en « Animatrice / sage-femme », les linguistes en « Linguiste ».

| Rôle | Peut |
|---|---|
| Direction médicale | tout voir, **seule à valider** les messages et le lexique, double authentification |
| Administration | gérer l'équipe, les participantes, la bibliothèque, double authentification |
| Animatrice / sage-femme | inscrire, traiter les alertes et les vocaux, vérifier les réponses automatiques |
| Linguiste | traduire, saisir les formulations et le lexique, enrichir la bibliothèque depuis les questions reçues ; aucun accès aux participantes |
| Lecture seule | consulter le tableau de bord et les bibliothèques |

## 3. Service de reconnaissance vocale

La plateforme PHP ne peut pas faire tourner un modèle d'IA. Le service `ia/worker.py` tourne sur une autre machine (un ordinateur du centre IDTDS ou de NOUR TECH allumé pendant le pilote, ou un petit serveur) et interroge la plateforme toutes les 5 secondes.

```
sudo apt install ffmpeg
pip install torch transformers requests numpy
export MK_URL="https://<domaine>/musow"
export MK_JETON_IA="<valeur securite.jeton_ia de config.php>"
export MK_MODELE="facebook/mms-1b-all"
python3 ia/worker.py --boucle
```

Avec MMS sur un processeur ordinaire, compter quelques secondes à une quinzaine de secondes par question ; une carte graphique rend la réponse quasi instantanée. Si le service est arrêté, rien n'est perdu : les questions partent à l'équipe après 5 minutes, et le tableau de bord signale l'arrêt.

**Licence du modèle.** MMS (`facebook/mms-1b-all`) est sous CC BY-NC 4.0 : utilisation non commerciale, adaptée au prototype et à la recherche. Le service exploité par NOUR TECH devra passer à un modèle sous licence permissive, XLS-R (Apache 2.0) affiné sur le corpus MEY-D26 : il suffit de changer `MK_MODELE`.

## 4. Préparer les questions pour le pilote

Pour chaque question retenue (viser 30 à 50 pour le dépôt du 4 novembre) :
1. **Dr FOFANA** relit l'intitulé, garde ou retire le marquage « danger », et rédige les textes source de la confirmation et de la réponse (fiches liées dans la page de la question). La confirmation se termine toujours par la consigne orale : « Si c'est votre question, dites ɔnhɔn. Sinon, dites ɔn'ɔn. »
2. **Linguiste n°1** traduit en dioula ; **linguiste n°2** contre-traduit sans voir le texte source.
3. **Linguistes** : 5 à 10 formulations en dioula par question, comme les femmes la poseraient (page de la question, ou import CSV `code;variante`).
4. **Enregistrement** des deux audios (OGG Opus mono : `ffmpeg -i voix.wav -c:a libopus -b:a 24k -ac 1 -ar 48000 message.ogg`).
5. **Dr FOFANA** valide les deux fiches. La question devient alors « utilisée par le service ».

Remplissez aussi le **lexique des signes de danger** (page Lexique) : les linguistes saisissent les termes, Dr FOFANA les valide. Préférez trop de termes que pas assez.

Messages techniques à valider en priorité : BIENV-01 et 02, DANGER-01, TECH-01, TECH-04, TECH-05, TECH-06, SERV-01, SERV-04.

## 5. Mesurer la fiabilité (chiffres du dossier)

1. Faites enregistrer les questions par 15 à 20 femmes, avec leurs propres mots, plus quelques questions hors bibliothèque, et une dizaine de réponses courtes chacune : « ɔnhɔn » et « ɔn'ɔn » (« awɔ » et « ayi » pour les locutrices du bambara), dans le désordre.
2. Décrivez-les dans un CSV `fichier;code_attendu;locutrice` (code `AUCUNE` pour les questions hors bibliothèque, `OUI` et `NON` pour les réponses courtes). **Ces enregistrements ne doivent pas servir de formulations.**
3. Lancez : `python3 ia/evaluer.py dossier_audios/ etiquettes.csv --profil enceinte --codes-danger Q013,Q014,...`

Résultats : taux de bonne reconnaissance, couverture, réponses inexactes, questions hors bibliothèque à qui on aurait répondu à tort, orientation des signes de danger, oui et non compris à voix haute, **« non » pris pour un oui (à garder à zéro)**, durée de transcription.

## 6. Raccordement à WhatsApp Business Platform

1. Faites vérifier NOUR TECH SARL dans Meta Business Suite, ajoutez le numéro du service à WhatsApp Business Platform, nom affiché neutre (par exemple « Musow »).
2. Application Meta « Business », produit WhatsApp, utilisateur système avec jeton permanent (`whatsapp_business_messaging`, `whatsapp_business_management`).
3. Modèle à soumettre (catégorie Utilitaire, Français) : nom `musow_nouveau_message`, corps `Bonjour, un nouveau message vocal vous attend. Appuyez sur « Écouter ».`, bouton de réponse rapide `Écouter`.
4. Webhook : `https://<domaine>/musow/webhook.php`, jeton de vérification donné à la fin de l'installation, champ `messages`.
5. `config.php`, section `whatsapp` : `id_numero`, `jeton`, `secret_application`, puis `'mode' => 'cloud'`.

Tant que Meta n'a pas vérifié le compte, le service fonctionne en **mode manuel** (envois depuis l'application WhatsApp Business, menu « Envois manuels »). Les questions vocales automatiques demandent le mode `cloud`.

## 7. Menu de la femme

1 écouter · 2 urgence · 3 contact du centre · 4 / 5 je viens / je ne peux pas venir au rendez-vous · 9 ou « stop » arrêter · message vocal : poser une question. Confirmation d'une question : **à voix haute**, en dioula (« ɔnhɔn » / « ɔn'ɔn »), en bambara pour les locutrices venues du Mali (« awɔ » / « ayi ») ou en français (« oui » / « non »). Les boutons « Ɔnhɔn · Oui » / « Ɔn'ɔn · Non » et la réponse écrite restent possibles, par exemple quand une proche aide la femme. « On » seul n'est pas accepté, car il peut se lire oui comme non.

## 8. Protection des données

Numéros chiffrés (libsodium) et recherchés par empreinte HMAC ; participantes désignées par un code (MK-0001) ; affichage d'un numéro avec motif et trace ; vocaux hors du dossier public, écoute réservée à l'équipe et journalisée ; double authentification pour l'administration et la direction médicale ; exports sans numéro ; page publique `stats.php` sans aucun chiffre sous 10 personnes. La transcription des questions sert à répondre (finalité du service, annoncée à l'inscription) ; seul le consentement facultatif autorise la conservation des vocaux pour entraîner les modèles.

## 9. Tests

```
php tests/run.php
```

100 vérifications sur SQLite : inscription, bienvenue, rappels, urgence, rythme d'envoi, naissance, questionnaire, arrêt, changement de numéro, panne et relance, mode manuel, et le parcours complet des questions vocales (reconnaissance malgré une orthographe différente, confirmation et refus à voix haute ou par bouton, réponse orale ambiguë ou mal entendue, nouvelle question à la place d'une confirmation, danger par question ou par lexique, question inconnue, audio peu clair, délais dépassés, urgence prioritaire, indicateurs, apprentissage).

## 10. Arborescence

```
admin/        espace équipe (questions, questions reçues, lexique, file, bibliothèque audio…)
api/ia.php    interface avec le service de reconnaissance vocale
audio/        audios de santé publiés (CC BY 4.0)
contenus/     liste initiale des 116 questions fréquentes
ia/           worker.py (reconnaissance) et evaluer.py (mesure de fiabilité)
src/          moteur : Parcours, Questions, Planificateur, canaux, sécurité
storage/      vocaux reçus et journaux (privés)
webhook.php   notifications de Meta · cron.php planificateur · stats.php indicateurs publics
```
