# Backlog SideKey

> Généré depuis `docs/backlog/backlog.json` par `scripts/import_backlog.py --md`. Modifier le JSON, pas ce fichier.

## Vue d'ensemble

| ID | Titre | Attribution | Prio | Est. (j) | Jour | Jalon | Dépend de |
|---|---|---|---|---|---|---|---|
| C-01 | Valider le cahier des charges v1.2 et importer le backlog | Ensemble | M | 0.5 | J1 | S1 · Conception et socle (J1–J5) | — |
| T-00 | Initialiser le dépôt et l'environnement Docker | Ensemble | M | 1 | J1 | S1 · Conception et socle (J1–J5) | — |
| C-02 | Maquettes Figma FR/EN et schéma d'enchaînement des écrans | Ensemble | M | 1 | J1–J2 | S1 · Conception et socle (J1–J5) | C-01 |
| C-03 | Modélisation PlantUML et ADR | Ensemble | M | 1 | J2 | S1 · Conception et socle (J1–J5) | C-01 |
| T-01 | Base de données : schéma, rôles SGBD, jeu d'essai et sauvegarde | M1 | M | 1 | J3 | S1 · Conception et socle (J1–J5) | T-00, C-03 |
| T-04 | Déploiement continu en recette (hébergeur UE) | M2 | M | 1 | J3 | S1 · Conception et socle (J1–J5) | T-00 |
| F-02 | Internationalisation FR/EN | M2 | M | 0.5 | J4 | S1 · Conception et socle (J1–J5) | T-00 |
| T-02 | Procédures stockées, fonctions et triggers défensifs | M2 | M | 1 | J4 | S1 · Conception et socle (J1–J5) | T-01 |
| F-03 | Authentification JWT, refresh token et RBAC | M1 | M | 1 | J4 | S1 · Conception et socle (J1–J5) | T-01 |
| F-04 | A2F obligatoire pour le back-office | M1 | M | 0.5 | J5 | S1 · Conception et socle (J1–J5) | F-03 |
| F-05 | Panier Redis et réservation temporaire de 15 minutes | M2 | M | 1 | J5 | S1 · Conception et socle (J1–J5) | T-01 |
| F-01 | Catalogue, recherche, filtres et cache Redis | M1 | M | 1 | J6 | S2 · Features (J6–J10) | T-01, F-02 |
| F-08 | Gestion des clés : chiffrement AES-GCM et empreinte HMAC | M2 | M | 1.5 | J6 | S2 · Features (J6–J10) | T-02, F-04 |
| T-03 | CI complète : tests, analyse statique et audit | M1 | M | 0.5 | J7 | S2 · Features (J6–J10) | T-00 |
| F-06 | Commande et gestion de la concurrence | M2 | M | 1.5 | J7–J8 | S2 · Features (J6–J10) | F-05, F-08 |
| F-07 | Paiement simulé et anti-fraude « Équilibré » | M1 | M | 1 | J8 | S2 · Features (J6–J10) | F-06 |
| F-09 | Back-office catalogue | M1 | M | 1 | J9 | S2 · Features (J6–J10) | F-01, F-04 |
| F-10 | Back-office commandes et journal d'audit | M2 | M | 0.5 | J9 | S2 · Features (J6–J10) | F-06, F-07 |
| P-01 | Avis clients et modération | Pool | S | 1 | J10+ | Pool | F-06 |
| P-02 | Tests de sécurité automatisés | Pool | S | 1 | J10+ | Pool | T-03 |
| P-03 | Tests de bout en bout | Pool | S | 1 | J10+ | Pool | F-07 |
| P-04 | Réinitialisation du mot de passe | Pool | S | 0.5 | J10+ | Pool | F-03 |
| P-05 | Droits RGPD : export et suppression du compte | Pool | S | 1 | J10+ | Pool | F-03 |
| P-06 | A2F facultative pour les clients | Pool | S | 0.5 | J10+ | Pool | F-04 |
| P-07 | Analyse SonarQube / SonarCloud | Pool | S | 0.5 | J10+ | Pool | T-03 |
| P-08 | Mesure EcoIndex | Pool | S | 0.5 | J10+ | Pool | F-01 |
| P-09 | Alerte de stock de clés bas | Pool | S | 0.5 | J10+ | Pool | F-08 |
| P-10 | Fusion du panier invité et tri du catalogue | Pool | S | 0.5 | J10+ | Pool | F-05, F-01 |
| P-11 | Écran de consultation du journal d'audit | Pool | S | 0.5 | J10+ | Pool | F-10 |
| P-12 | Tableau de bord des ventes | Pool | C | 1 | J10+ | Pool | F-10 |
| P-13 | Import asynchrone des clés | Pool | C | 1 | J10+ | Pool | F-08 |
| L-01 | Tests de sécurité croisés et audit d'accessibilité | Ensemble | M | 1 | J11 | S3 · Livraison (J11–J13) | F-10 |
| L-02 | Version v1.0.0 en production et test de restauration | Ensemble | M | 0.5 | J11 | S3 · Livraison (J11–J13) | L-01, T-04 |
| L-03 | Dossier de projet | Ensemble | M | 1 | J11–J12 | S3 · Livraison (J11–J13) | L-01 |
| L-04 | Diaporama et démonstration | Ensemble | M | 0.5 | J12 | S3 · Livraison (J11–J13) | L-02 |
| L-05 | Répétition de l'oral | Ensemble | M | 0.5 | J13 | S3 · Livraison (J11–J13) | L-03, L-04 |

**Charge estimée** : Ensemble 7 j · M1 6 j · M2 7 j · Pool 9.5 j

## Règles d'attribution du pool

- Le premier membre libre prend la feature du pool la mieux classée.
- Chacun ne mène qu'une feature à la fois.
- Une feature en retard est réattribuée à la revue du soir.
- Aucune nouvelle feature n'est lancée après le J10.

## Définition de terminé (commune à toutes les issues)

- PR relue et approuvée par l'autre membre, titre au format Conventional Commits
- CI verte (tests, analyse statique, audit des dépendances)
- Tests unitaires écrits, et tests de sécurité quand la feature touche à l'auth, aux données ou aux clés
- Textes de l'interface disponibles en FR et en EN
- Validation des entrées et contrôle d'accès vérifiés dans chaque couche (contrôleur, service, repository)
- Déployé en recette et montré lors de la démo du soir
- Dossier de projet complété (choix techniques, captures, extraits de code)

## Détail

### C-01 — Valider le cahier des charges v1.2 et importer le backlog

*Ensemble · priorité M · 0.5 j · J1 · dépend de : aucune*

Relire ensemble le cahier des charges v1.2, confirmer le périmètre (clés uniques uniquement, Redis seul, hébergement UE) et importer ce backlog dans GitHub Projects.

- [ ] Le CDC v1.2 est versionné dans le dépôt (docs/)
- [ ] Les 35 issues sont créées et rattachées au Project avec leurs champs
- [ ] Les colonnes du Status sont renommées : Backlog / Jour J / En cours / En revue / Terminé

### T-00 — Initialiser le dépôt et l'environnement Docker

*Ensemble · priorité M · 1 j · J1 · dépend de : aucune*

Créer la structure du monorepo (api/ Symfony, front/ React), le Docker Compose (PHP-FPM, Nginx, PostgreSQL, Redis, Node) et les fichiers de gouvernance du dépôt.

- [ ] docker compose up démarre l'API, le front, PostgreSQL et Redis
- [ ] README avec la procédure d'installation locale
- [ ] .env.example versionné, tous les .env ignorés
- [ ] CODEOWNERS, modèles d'issue et de PR, CONTRIBUTING.md (Conventional Commits, GitHub Flow, squash merge)
- [ ] Workflows ci.yml (job ci-success) et pr-title.yml en place, puis ruleset de main importé

### C-02 — Maquettes Figma FR/EN et schéma d'enchaînement des écrans

*Ensemble · priorité M · 1 j · J1–J2 · dépend de : C-01*

Wireframes puis maquettes des écrans : catalogue, fiche jeu, panier, tunnel de commande, compte client, back-office. Schéma d'enchaînement des écrans. Prise en compte du RGAA et de l'éco-conception.

- [ ] Maquettes des écrans principaux en FR et au moins une version EN
- [ ] Schéma d'enchaînement des écrans exporté dans docs/
- [ ] Contrastes et navigation clavier pris en compte (RGAA)
- [ ] Chaque écran est relié aux user stories et aux personas qui le justifient

### C-03 — Modélisation PlantUML et ADR

*Ensemble · priorité M · 1 j · J2 · dépend de : C-01*

Diagrammes de cas d'utilisation, de classes et de séquence (dont l'achat concurrent de la dernière clé), MCD/MLD/MPD, architecture en couches. Rédiger les ADR des choix structurants.

- [ ] Sources PlantUML versionnées dans docs/uml/ avec leurs exports
- [ ] MCD, MLD et MPD cohérents (jeu, éditeur, plateforme, client, commande, ligne, clé de licence)
- [ ] Diagramme de séquence de l'achat concurrent
- [ ] ADR : Symfony + React, PostgreSQL + Redis, JWT + refresh token, chiffrement des clés, hébergement UE
- [ ] Critères DICP et security patterns retenus documentés

### T-01 — Base de données : schéma, rôles SGBD, jeu d'essai et sauvegarde

*M1 · priorité M · 1 j · J3 · dépend de : T-00, C-03*

Migrations Doctrine du schéma, contraintes d'intégrité, comptes PostgreSQL avec droits minimaux, base de test avec jeu d'essai, scripts de sauvegarde et de restauration.

- [ ] Migrations appliquées sans erreur sur une base vide
- [ ] Contraintes : clés primaires et étrangères, CHECK, unicité de l'empreinte des clés
- [ ] Comptes sk_owner (migrations), sk_app (CRUD limité), sk_reporting (vues) et sk_backup (sauvegardes) créés par script, avec GRANT/REVOKE
- [ ] Base sidekey_test et jeu d'essai avec cas limites (dernière clé, compte désactivé, commande annulée)
- [ ] Sauvegarde pg_dump et restauration testées et documentées

### T-04 — Déploiement continu en recette (hébergeur UE)

*M2 · priorité M · 1 j · J3 · dépend de : T-00*

Choisir l'hébergeur dans l'UE, préparer l'environnement de recette et le déploiement automatique depuis main. Préparer le déploiement en production sur tag v* avec approbation.

- [ ] Hébergeur UE choisi et justifié (ADR)
- [ ] Chaque merge sur main déploie automatiquement en recette
- [ ] Environnements GitHub staging et production configurés, secrets par environnement
- [ ] Workflow de production déclenché par un tag v*, avec approbation de l'autre membre
- [ ] Procédure de retour arrière documentée

### F-02 — Internationalisation FR/EN

*M2 · priorité M · 0.5 j · J4 · dépend de : T-00*

Mettre en place l'i18n côté React et côté API (messages d'erreur, e-mails), avec sélecteur de langue et langue par défaut selon le navigateur.

- [ ] Sélecteur FR/EN visible sur toutes les pages, choix mémorisé
- [ ] Aucun texte d'interface codé en dur
- [ ] Messages d'erreur de l'API traduits selon Accept-Language
- [ ] Attribut lang de la page mis à jour (RGAA)

### T-02 — Procédures stockées, fonctions et triggers défensifs

*M2 · priorité M · 1 j · J4 · dépend de : T-01*

Écrire en style défensif les routines SQL du métier : attribution d'une clé à une commande, historique des changements de statut, garde-fous d'intégrité.

- [ ] Fonction d'attribution de clé qui valide ses paramètres et lève une erreur explicite
- [ ] Trigger qui historise chaque changement de statut de commande
- [ ] Trigger qui interdit de modifier une clé déjà vendue
- [ ] Droits EXECUTE accordés uniquement au compte sk_app
- [ ] Tests des cas d'erreur sur la base de test

### F-03 — Authentification JWT, refresh token et RBAC

*M1 · priorité M · 1 j · J4 · dépend de : T-01*

Inscription et connexion avec jeton d'accès de 15 minutes gardé en mémoire, refresh token dans un cookie HttpOnly/Secure/SameSite révocable via Redis, rôles client / gestionnaire / admin.

- [ ] Mots de passe hachés en Argon2id, politique conforme ANSSI/CNIL
- [ ] Jeton d'accès de 15 min, refresh token en cookie protégé, rotation et révocation dans Redis
- [ ] Route de rafraîchissement protégée contre le CSRF
- [ ] Limitation des tentatives de connexion par Redis
- [ ] Contrôle d'accès par rôle sur chaque route, testé (accès refusé = 403)

### F-04 — A2F obligatoire pour le back-office

*M1 · priorité M · 0.5 j · J5 · dépend de : F-03*

Authentification à deux facteurs TOTP obligatoire pour les rôles gestionnaire et admin, avec codes de secours.

- [ ] Enrôlement TOTP par QR code
- [ ] Accès au back-office impossible sans second facteur validé
- [ ] Codes de secours à usage unique, stockés hachés
- [ ] Tentatives limitées

### F-05 — Panier Redis et réservation temporaire de 15 minutes

*M2 · priorité M · 1 j · J5 · dépend de : T-01*

Panier stocké dans Redis (clé/valeur) avec TTL, et réservation temporaire des clés pendant 15 minutes au passage en commande.

- [ ] Ajout, retrait et modification de quantité dans le panier
- [ ] Panier expiré automatiquement (TTL)
- [ ] Réservation de 15 min au début du tunnel, libérée à l'expiration
- [ ] Quantité limitée par le stock de clés disponibles
- [ ] Tests du composant d'accès Redis

### F-01 — Catalogue, recherche, filtres et cache Redis

*M1 · priorité M · 1 j · J6 · dépend de : T-01, F-02*

Liste paginée des jeux, recherche, filtres (plateforme, éditeur, prix), fiche jeu avec disponibilité. Mise en cache des listes dans Redis.

- [ ] Liste paginée, recherche et filtres combinables
- [ ] Fiche jeu avec plateforme, éditeur, prix et disponibilité
- [ ] Cache Redis invalidé quand le catalogue change
- [ ] Requêtes paramétrées uniquement, sorties échappées (XSS)
- [ ] Images légères et chargement différé (éco-conception)

### F-08 — Gestion des clés : chiffrement AES-GCM et empreinte HMAC

*M2 · priorité M · 1.5 j · J6 · dépend de : T-02, F-04*

Import des clés par le back-office, chiffrement AES-GCM, empreinte HMAC unique pour détecter les doublons, révélation de la clé au client après paiement.

- [ ] Clés chiffrées en AES-GCM, secret hors du dépôt
- [ ] Empreinte HMAC unique : une clé en double est refusée
- [ ] Import par lot avec rapport d'erreurs
- [ ] Clé visible uniquement par son acheteur, chaque affichage journalisé
- [ ] Tests de sécurité : pas de clé en clair dans la base, les logs ou les réponses de l'API

### T-03 — CI complète : tests, analyse statique et audit

*M1 · priorité M · 0.5 j · J7 · dépend de : T-00*

Compléter la pipeline : PHPUnit, tests front, PHPStan, ESLint, Semgrep pour le PHP, audits Composer et npm, couverture. Tout passe par le job ci-success.

- [ ] PHPUnit et tests front exécutés avec une base PostgreSQL et un Redis de service
- [ ] PHPStan, ESLint et Semgrep bloquants
- [ ] composer audit et npm audit bloquants sur les failles hautes
- [ ] Rapport de couverture publié
- [ ] ci-success dépend de tous les jobs

### F-06 — Commande et gestion de la concurrence

*M2 · priorité M · 1.5 j · J7–J8 · dépend de : F-05, F-08*

Création de la commande en transaction, attribution des clés avec SELECT … FOR UPDATE SKIP LOCKED, statuts de commande. Scénario de référence : deux clients achètent la dernière clé en même temps.

- [ ] Commande, lignes et attribution des clés dans une seule transaction
- [ ] Niveau d'isolation choisi et justifié
- [ ] Test de charge : deux achats simultanés de la dernière clé donnent une seule vente
- [ ] Statuts de commande et transitions contrôlées
- [ ] Aucune clé attribuée deux fois (vérifié par le test)

### F-07 — Paiement simulé et anti-fraude « Équilibré »

*M1 · priorité M · 1 j · J8 · dépend de : F-06*

Faux prestataire de paiement (accepté pour la v1 et justifié dans le dossier) et règles anti-fraude du niveau Équilibré (RG-14).

- [ ] Paiement simulé avec cas succès, refus et erreur
- [ ] Règle RG-14 appliquée : seuils de nombre de clés, de montant et d'échecs de paiement
- [ ] Commande suspecte mise en attente de validation dans le back-office
- [ ] Compteurs anti-fraude dans Redis
- [ ] Aucune donnée de carte stockée

### F-09 — Back-office catalogue

*M1 · priorité M · 1 j · J9 · dépend de : F-01, F-04*

Gestion des jeux, éditeurs, plateformes et prix par les gestionnaires et admins.

- [ ] CRUD jeux, éditeurs et plateformes
- [ ] Validation des formulaires côté front et côté API
- [ ] Téléversement d'images sécurisé (type, taille, nom régénéré)
- [ ] Accès réservé aux rôles autorisés avec A2F

### F-10 — Back-office commandes et journal d'audit

*M2 · priorité M · 0.5 j · J9 · dépend de : F-06, F-07*

Liste et détail des commandes, validation ou annulation des commandes suspectes, journal d'audit des actions sensibles.

- [ ] Liste filtrable des commandes et détail
- [ ] Validation ou annulation des commandes en attente
- [ ] Journal d'audit : qui, quoi, quand, sur quelle ressource
- [ ] Le journal ne contient aucune clé en clair

### P-01 — Avis clients et modération

*Pool · priorité S · 1 j · J10+ · dépend de : F-06*

Avis et note sur un jeu acheté, modération dans le back-office.

- [ ] Avis possible uniquement après achat
- [ ] Contenu échappé (XSS)
- [ ] Modération : publier, masquer

### P-02 — Tests de sécurité automatisés

*Pool · priorité S · 1 j · J10+ · dépend de : T-03*

Tests automatisés des contrôles OWASP principaux et scan dynamique de la recette.

- [ ] Tests d'accès refusé (IDOR, rôles)
- [ ] Tests d'injection et de XSS
- [ ] Scan OWASP ZAP de la recette dans la CI

### P-03 — Tests de bout en bout

*Pool · priorité S · 1 j · J10+ · dépend de : F-07*

Tests E2E du parcours d'achat complet.

- [ ] Parcours catalogue → panier → commande → paiement → clé
- [ ] Exécutés dans la CI

### P-04 — Réinitialisation du mot de passe

*Pool · priorité S · 0.5 j · J10+ · dépend de : F-03*

Lien de réinitialisation par e-mail à usage unique.

- [ ] Jeton à usage unique avec TTL dans Redis
- [ ] Réponse identique que le compte existe ou non
- [ ] Sessions révoquées après changement

### P-05 — Droits RGPD : export et suppression du compte

*Pool · priorité S · 1 j · J10+ · dépend de : F-03*

Export des données personnelles et suppression du compte, en respectant les durées de conservation légales.

- [ ] Export JSON des données du client
- [ ] Suppression ou anonymisation du compte
- [ ] Pièces comptables conservées 10 ans

### P-06 — A2F facultative pour les clients

*Pool · priorité S · 0.5 j · J10+ · dépend de : F-04*

Proposer l'A2F TOTP aux clients qui le souhaitent.

- [ ] Activation et désactivation depuis le compte
- [ ] Codes de secours

### P-07 — Analyse SonarQube / SonarCloud

*Pool · priorité S · 0.5 j · J10+ · dépend de : T-03*

Ajouter Sonar à la CI avec un quality gate.

- [ ] Analyse à chaque PR
- [ ] Quality gate bloquant

### P-08 — Mesure EcoIndex

*Pool · priorité S · 0.5 j · J10+ · dépend de : F-01*

Mesurer l'EcoIndex des pages principales et corriger les points faibles.

- [ ] Mesure des pages catalogue, fiche jeu et panier
- [ ] Résultats et actions dans le dossier

### P-09 — Alerte de stock de clés bas

*Pool · priorité S · 0.5 j · J10+ · dépend de : F-08*

Prévenir le gestionnaire quand le stock de clés d'un jeu passe sous un seuil.

- [ ] Seuil configurable par jeu
- [ ] Alerte visible dans le back-office

### P-10 — Fusion du panier invité et tri du catalogue

*Pool · priorité S · 0.5 j · J10+ · dépend de : F-05, F-01*

Fusionner le panier invité avec le panier du compte à la connexion, ajouter le tri du catalogue.

- [ ] Panier invité conservé après connexion
- [ ] Tri par prix, nouveauté et nom

### P-11 — Écran de consultation du journal d'audit

*Pool · priorité S · 0.5 j · J10+ · dépend de : F-10*

Écran admin pour filtrer et consulter le journal d'audit.

- [ ] Filtres par utilisateur, action et date
- [ ] Accès réservé à l'admin

### P-12 — Tableau de bord des ventes

*Pool · priorité C · 1 j · J10+ · dépend de : F-10*

Indicateurs de ventes, lus avec le compte sk_reporting.

- [ ] Ventes par jour et par jeu
- [ ] Lecture via le compte sk_reporting

### P-13 — Import asynchrone des clés

*Pool · priorité C · 1 j · J10+ · dépend de : F-08*

Traiter les gros imports de clés en tâche de fond (Symfony Messenger avec transport Redis).

- [ ] Import en arrière-plan avec suivi d'avancement
- [ ] Rapport d'erreurs à la fin

### L-01 — Tests de sécurité croisés et audit d'accessibilité

*Ensemble · priorité M · 1 j · J11 · dépend de : F-10*

Chaque membre teste la sécurité des features de l'autre ; audit RGAA des écrans principaux.

- [ ] Grille OWASP Top 10 remplie pour chaque feature
- [ ] Audit RGAA des écrans principaux
- [ ] Corrections faites ou listées

### L-02 — Version v1.0.0 en production et test de restauration

*Ensemble · priorité M · 0.5 j · J11 · dépend de : L-01, T-04*

Taguer la v1.0.0, déployer en production et tester la restauration de la sauvegarde.

- [ ] Tag v1.0.0 et notes de version
- [ ] Production déployée après approbation
- [ ] Restauration de la sauvegarde testée

### L-03 — Dossier de projet

*Ensemble · priorité M · 1 j · J11–J12 · dépend de : L-01*

Finaliser le dossier de projet, avec une partie rédigée en anglais.

- [ ] Chaque critère des compétences 5 à 8 illustré
- [ ] Partie en anglais
- [ ] Relu par les deux membres

### L-04 — Diaporama et démonstration

*Ensemble · priorité M · 0.5 j · J12 · dépend de : L-02*

Préparer le diaporama et le scénario de démonstration, dont l'achat concurrent de la dernière clé.

- [ ] Diaporama terminé
- [ ] Scénario de démo écrit et testé sur la production

### L-05 — Répétition de l'oral

*Ensemble · priorité M · 0.5 j · J13 · dépend de : L-03, L-04*

Répéter l'oral en conditions réelles ; chacun doit pouvoir défendre seul les quatre compétences.

- [ ] Répétition chronométrée
- [ ] Questions du jury préparées
