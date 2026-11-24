# Modèle de données — MCD, MLD et MPD

Ce document est le **commentaire** des trois modèles ; les diagrammes font foi et se
trouvent dans `docs/uml/src/` :

| Niveau | Source PlantUML | Export | Rôle |
|---|---|---|---|
| **MCD** — conceptuel | [`mcd.puml`](../uml/src/mcd.puml) | [PNG](../uml/exports/mcd.png) · [SVG](../uml/exports/mcd.svg) | Entités, associations, cardinalités ; aucun identifiant technique, aucune clé étrangère |
| **MLD** — logique | [`mld.puml`](../uml/src/mld.puml) | [PNG](../uml/exports/mld.png) · [SVG](../uml/exports/mld.svg) | Tables, clés primaires et étrangères, types logiques |
| **MPD** — physique | [`mpd.puml`](../uml/src/mpd.puml) | [PNG](../uml/exports/mpd.png) · [SVG](../uml/exports/mpd.svg) | Types PostgreSQL, valeurs par défaut, contraintes, index, triggers |

Référence : `docs/cahier_des_charges_v1.2_SideKey.md` §6 (règles de gestion), §9
(données), §10 (concurrence).

---

## 1. Démarche de conception

1. **MCD** à partir des besoins fonctionnels (§5) et des règles de gestion (§6) : une
   entité par objet métier durable, une association dès qu'une règle relie deux objets.
2. **MLD** par application des règles de transformation (§3.1) : chaque association
   `(0,N)`–`(1,1)` devient une clé étrangère, chaque association `(0,N)`–`(0,N)` devient
   une table de jointure, chaque association porteuse d'attributs devient une table.
3. **MPD** : choix des types PostgreSQL, des contraintes, des index et des objets
   PL/pgSQL (les migrations Doctrine de T-01 reprennent ce fichier à la lettre).

Le modèle est en **3ᵉ forme normale** (§4), avec deux dénormalisations assumées et
documentées.

---

## 2. MCD — entités et associations

### 2.1 Entités

| Entité | Rôle | Attributs (domaines conceptuels) | Règle |
|---|---|---|---|
| `LANGUE` | Référentiel des langues (fr, en) | code, libelle | F10 (FR/EN uniquement) |
| `EDITEUR` | Éditeur d'un jeu | id, nom | — |
| `PLATEFORME` | Store : STEAM, PSN, XBOX, ESHOP | id, code, libelle | — |
| `GENRE` | Genre de jeu (i18n) | id, slug | — |
| `GENRE_TRADUCTION` | Libellé d'un genre par langue | genre, langue, libelle | F10 |
| `ROLE` | Rôle applicatif | code, libelle | §11.5 |
| `JEU` | Fiche d'un jeu | id, titre, slug, date_sortie, pegi, jaquette, actif | §5.1 |
| `JEU_TRADUCTION` | Description d'un jeu par langue | jeu, langue, description | F10 |
| `OFFRE` | Couple jeu × plateforme, avec un prix | id, prix_ttc, seuil_alerte, actif, version | RG-01, BX-14 |
| `CLE_LICENCE` | Clé de licence unique et sensible | id, cle_chiffree, empreinte, statut, importee_le, reservee_le, revelee_le | RG-03, RG-04, §9.4 |
| `IMPORT_CLES` | Rapport d'un import CSV | id, fichier_nom, compteurs, statut | US-52 |
| `UTILISATEUR` | Compte client ou back-office | id, email, hash, prénom, nom, langue, A2F, état | §5.3, §11.4 |
| `UTILISATEUR_ROLE` | Attribution d'un rôle | utilisateur, role, attribue_le | §11.5 |
| `CODE_SECOURS` | Code de secours A2F | id, code_hash, utilise_le | BX-07 |
| `COMMANDE` | Commande d'un client | id, reference, statut, montant_total, confirme_majeur, expire_le | RG-10 à RG-14, §6.4 |
| `LIGNE_COMMANDE` | Ligne de commande (une offre, une quantité, un prix figé) | id, quantite, prix_unitaire | RG-05 |
| `PAIEMENT` | Tentative de paiement | id, montant, statut, cle_idempotence, code_retour, derniers_chiffres | US-32, §11.6 |
| `COMMANDE_HISTORIQUE` | Historique des changements de statut | id, ancien_statut, nouveau_statut, cree_le | RG-21 |
| `AVIS` | Avis modéré d'un client | id, note, commentaire, statut_moderation, motif_refus | §5.5 |
| `JOURNAL_AUDIT` | Trace immuable des actions sensibles | id, action, ressource, ressource_id, ip_hachee | BX-16, ENF-12 |

### 2.2 Associations et cardinalités

Lecture : `(min,max)` de l'entité **pour une occurrence de l'autre**.

| Entité A | Card. A | Entité B | Card. B | Signification | Règle |
|---|:--:|---|:--:|---|---|
| EDITEUR | (1,1) | JEU | (0,N) | un jeu a un éditeur ; un éditeur publie plusieurs jeux | — |
| JEU | (1,1) | JEU_TRADUCTION | (1,2) | un jeu a une traduction par langue parlée | F10 |
| LANGUE | (1,1) | JEU_TRADUCTION | (0,N) | une traduction porte sur une langue | F10 |
| JEU | (0,N) | GENRE | (0,N) | un jeu appartient à plusieurs genres | US-02 |
| JEU | (1,1) | OFFRE | (0,N) | une offre concerne un seul jeu | RG-01 |
| PLATEFORME | (1,1) | OFFRE | (0,N) | une offre concerne une seule plateforme | RG-01 |
| OFFRE | (1,1) | CLE_LICENCE | (0,N) | une clé appartient à une seule offre | RG-02 |
| IMPORT_CLES | (0,1) | CLE_LICENCE | (0,N) | une clé est créée par un import (ou par le jeu d'essai) | US-52 |
| UTILISATEUR | (1,1) | IMPORT_CLES | (0,N) | un import est réalisé par un gestionnaire | BX-11 |
| UTILISATEUR | (1,1) | UTILISATEUR_ROLE | (0,N) | un rôle est attribué à un utilisateur | §11.5 |
| UTILISATEUR | (1,1) | CODE_SECOURS | (0,N) | un code de secours appartient à un utilisateur | BX-07 |
| UTILISATEUR | (1,1) | COMMANDE | (0,N) | une commande appartient à un client | RG-10 |
| COMMANDE | (1,1) | LIGNE_COMMANDE | (1,N) | une commande contient au moins une ligne | §6.2 |
| OFFRE | (1,1) | LIGNE_COMMANDE | (0,N) | une ligne référence une offre | RG-05 |
| LIGNE_COMMANDE | (1,1) | CLE_LICENCE | (0,1) | une clé est rattachée au plus à une ligne | RG-04 |
| COMMANDE | (1,1) | PAIEMENT | (0,N) | plusieurs tentatives de paiement possibles | US-32, US-36 |
| COMMANDE | (1,1) | COMMANDE_HISTORIQUE | (0,N) | chaque transition est historisée | RG-21 |
| LANGUE | (1,1) | COMMANDE | (0,N) | la commande est libellée dans la langue du client | F10 |
| LANGUE | (1,1) | UTILISATEUR | (0,N) | langue préférée du compte | BX-06 |
| UTILISATEUR | (1,1) | AVIS | (0,N) | un avis a un auteur | RG-31 |
| JEU | (1,1) | AVIS | (0,N) | un avis porte sur un jeu | RG-31 |
| UTILISATEUR | (0,1) | JOURNAL_AUDIT | (0,N) | une action peut être anonyme (échec de connexion) | BX-16 |

---

## 3. MLD — du conceptuel au relationnel

### 3.1 Règles de transformation appliquées

| Règle | Cas dans SideKey | Résultat |
|---|---|---|
| Entité → table | toutes | 20 tables d'entités |
| Association `(1,1)`–`(0,N)` → clé étrangère côté « plusieurs » | 25 associations | `editeur_id`, `offre_id`, `commande_id`… |
| Association `(0,N)`–`(0,N)` → table de jointure | JEU × GENRE | `jeu_genre` (clé primaire composée, aucun attribut) |
| Association porteuse d'attributs → entité de jointure | JEU_TRADUCTION, GENRE_TRADUCTION, UTILISATEUR_ROLE | clé primaire composée + attributs (`description`, `libelle`, `attribue_le`) |
| Association `(0,1)` → clé étrangère **nullable** | `cle_licence.ligne_commande_id`, `journal_audit.utilisateur_id`, `commande_historique.auteur_id` | `NULL` autorisé, contrainte d'intégrité référentielle conservée |

### 3.2 Tables (21)

| Table | Clé primaire | Clés étrangères | Remarque |
|---|---|---|---|
| `langue` | `code` | — | référentiel, 2 lignes (fr, en) |
| `editeur` | `id` | — | `nom` unique |
| `plateforme` | `id` | — | `code` unique |
| `genre` | `id` | — | `slug` unique |
| `genre_traduction` | (`genre_id`, `langue_code`) | `genre_id`, `langue_code` | 1 libellé par langue |
| `role` | `code` | — | 4 valeurs, contrainte `CHECK` |
| `jeu` | `id` | `editeur_id` | `slug` unique, `pegi` contrôlé |
| `jeu_traduction` | (`jeu_id`, `langue_code`) | `jeu_id`, `langue_code` | description non vide |
| `jeu_genre` | (`jeu_id`, `genre_id`) | `jeu_id`, `genre_id` | table de jointure |
| `offre` | `id` | `jeu_id`, `plateforme_id` | unique **partiel** si `actif` |
| `cle_licence` | `id` | `offre_id`, `ligne_commande_id`, `import_cles_id` | `empreinte` unique |
| `import_cles` | `id` | `utilisateur_id` | rapport d'import |
| `utilisateur` | `id` | `langue_code` | `email` unique (insensible à la casse) |
| `utilisateur_role` | (`utilisateur_id`, `role_code`) | `utilisateur_id`, `role_code` | table de jointure |
| `code_secours` | `id` | `utilisateur_id` | codes A2F hachés |
| `commande` | `id` | `utilisateur_id`, `langue_code` | `reference` unique, exposée |
| `ligne_commande` | `id` | `commande_id`, `offre_id` | unique (`commande_id`, `offre_id`) |
| `paiement` | `id` | `commande_id` | `cle_idempotence` unique |
| `commande_historique` | `id` | `commande_id`, `auteur_id` | rempli par trigger |
| `avis` | `id` | `utilisateur_id`, `jeu_id`, `langue_code` | unique (`utilisateur_id`, `jeu_id`) |
| `journal_audit` | `id` | `utilisateur_id` | immuable |

### 3.3 Contraintes d'intégrité du MLD

| Contrainte | Table | Portée |
|---|---|---|
| `RG-03` unicité de l'empreinte | `cle_licence` | `UNIQUE (empreinte)`, en base et à l'import |
| `RG-04` statut de clé | `cle_licence` | `CHECK statut IN ('DISPONIBLE','RESERVEE','VENDUE')` |
| cohérence statut / ligne | `cle_licence` | une clé `VENDUE` a obligatoirement une `ligne_commande_id` |
| `RG-05` prix figé | `ligne_commande` | `prix_unitaire` recopié depuis `offre` à la création |
| `RG-11` montant serveur | `commande` | `montant_total` recalculé, jamais reçu du client |
| `RG-12` réservation | `commande` | `expire_le = cree_le + 15 min` (valeur posée par le service) |
| `RG-30` avis | `avis` | `CHECK note BETWEEN 1 AND 5`, `CHECK length(commentaire) BETWEEN 10 AND 1000` |
| `RG-31` un avis par client et par jeu | `avis` | `UNIQUE (utilisateur_id, jeu_id)` |
| `RG-01` une offre active par couple | `offre` | index unique **partiel** `WHERE actif` |
| `BX-14` verrou optimiste | `offre` | colonne `version`, incrémentée par Doctrine |

---

## 4. MPD — choix physiques PostgreSQL 17

### 4.1 Correspondance des domaines

| Domaine conceptuel | Type logique | Type PostgreSQL | Justification |
|---|---|---|---|
| identifiant | uuid | `uuid` | non énumérable (anti-IDOR), généré par `gen_random_uuid()` |
| chaîne(n) | varchar(n) | `varchar(n)` | borné, contrôle de taille en base |
| code(n) | char/varchar | `char(2)` (langue), `varchar(20)` (rôle, statut) | référentiels courts |
| texte | text | `text` | descriptions et commentaires |
| entier | smallint / integer | `smallint`, `integer` | bornes vérifiées par `CHECK` |
| montant | numeric(10,2) | `numeric(10,2)` | **jamais** de `float` pour de l'argent |
| booléen | boolean | `boolean` | — |
| date | date | `date` | date de sortie |
| horodatage | timestamptz | `timestamptz` | UTC, indépendant du fuseau du serveur |
| binaire | bytea | `bytea` | chiffré AES-GCM et empreintes HMAC |
| énumération | varchar(n) + CHECK | `varchar(n) + CHECK` | un `CREATE TYPE` ne peut pas être modifié dans une transaction (migrations) |

### 4.2 Conventions de nommage (CDC §9.1)

`snake_case` au singulier, `id` pour la clé primaire, `<table>_id` pour une clé
étrangère (exception documentée : `langue_code` et `role_code`, la clé cible étant un
code), préfixes `pk_`, `fk_`, `uq_`, `ck_`, `ix_`, `trg_`, `fn_`, colonnes `cree_le` et
`modifie_le`.

### 4.3 Contraintes et index

| Objet | Définition | Pourquoi |
|---|---|---|
| `pk_*` | `PRIMARY KEY (id)` ou clé composée | intégrité |
| `fk_*` | `FOREIGN KEY … ON DELETE RESTRICT` | aucune suppression en cascade sur des données de vente |
| `uq_cle_empreinte` | `UNIQUE (empreinte)` | RG-03, une clé ne peut pas entrer deux fois |
| `uq_utilisateur_email` | `UNIQUE (lower(email))` | e-mail unique, insensible à la casse, sans extension `citext` |
| `uq_offre_active` | `UNIQUE (jeu_id, plateforme_id) WHERE actif` | RG-01 sans empêcher l'historique des offres désactivées |
| `uq_ligne_offre` | `UNIQUE (commande_id, offre_id)` | une seule ligne par offre dans une commande |
| `uq_paiement_idempotence` | `UNIQUE (cle_idempotence)` | US-32, un seul débit par clé d'idempotence |
| `ck_cle_statut` | `statut IN ('DISPONIBLE','RESERVEE','VENDUE')` | RG-04 |
| `ck_cle_vendue` | `statut <> 'VENDUE' OR ligne_commande_id IS NOT NULL` | une clé vendue est rattachée à une commande |
| `ck_commande_statut` | 6 statuts de §6.4 | RG-20 |
| `ck_commande_expire` | `expire_le > cree_le` | RG-12 |
| `ck_offre_prix` | `prix_ttc > 0` | US-51 |
| `ck_offre_version` | `version >= 1` | verrou optimiste |
| `ck_ligne_quantite` | `quantite BETWEEN 1 AND 10` | §9.2 |
| `ck_avis_note` / `ck_avis_longueur` | 1..5 / 10..1000 | RG-30 |
| `ck_avis_motif` | `statut_moderation <> 'REFUSE' OR motif_refus IS NOT NULL` | US-42 |
| `ck_utilisateur_a2f` | `NOT a2f_active OR totp_secret_chiffre IS NOT NULL` | §11.4 |
| `ck_paiement_carte` | `derniers_chiffres ~ '^[0-9]{4}$'` | §11.6 |
| `ix_cle_stock` | `(offre_id, importee_le) WHERE statut = 'DISPONIBLE'` | index de `FOR UPDATE SKIP LOCKED` (§10.1) |
| `ix_commande_client_statut` | `(utilisateur_id, statut)` | écran « Mes commandes », file « à vérifier » |
| `ix_audit_ressource` | `(ressource, ressource_id)` | consultation du journal |

### 4.4 Objets PL/pgSQL (livrés en T-02)

| Objet | Rôle | Interaction avec le modèle |
|---|---|---|
| `fn_reserver_cles(offre, quantite, ligne)` | `FOR UPDATE SKIP LOCKED`, exception si stock insuffisant | passe `cle_licence` de `DISPONIBLE` à `RESERVEE` |
| `fn_valider_commande(commande)` | validation atomique du paiement | passe les clés à `VENDUE` et la commande à `PAYEE` |
| `fn_liberer_reservations_expirees()` | balayage minute (Symfony Scheduler) | clés `RESERVEE` → `DISPONIBLE`, commande `EXPIREE` |
| `trg_commande_historique` | historise chaque transition | alimente `commande_historique` |
| `trg_commande_transition` | refuse une transition non prévue | applique §6.4 en base |
| `trg_cle_licence_protection` | interdit `UPDATE`/`DELETE` d'une clé `VENDUE` | RG-04 |
| `trg_journal_audit_immuable` | interdit `UPDATE` et `DELETE` | BX-16, ENF-12 |

### 4.5 Rôles PostgreSQL (CDC §9.5)

`sk_owner` (DDL, migrations) · `sk_app` (DML, sans `DELETE` sur `cle_licence`,
`commande`, `paiement`, `journal_audit`) · `sk_reporting` (`SELECT` sur vues) ·
`sk_backup` (`pg_read_all_data`). Authentification `scram-sha-256`, réseau Docker
interne uniquement.

---

## 5. Normalisation et écarts assumés

Le modèle est en **3ᵉ forme normale** : aucune valeur multivaluée (les genres et les
rôles passent par des tables de jointure), aucun attribut calculable stocké sans
raison, toutes les dépendances fonctionnelles partent de la clé.

Deux dénormalisations sont **volontaires** et justifiées :

| Écart | Pourquoi | Conséquence maîtrisée |
|---|---|---|
| `commande.montant_total` est dérivable des lignes | le montant doit être **figé** au moment de la commande (prix et total historiques, RG-05, RG-11) | recalculé à chaque modification de ligne, contrôle en service |
| `cle_licence.statut` et `ligne_commande_id` sont redondants avec la ligne | le statut est la donnée de concurrence : il est lu et écrit sous verrou, sans jointure | triggers et `CHECK` empêchent les états incohérents |

Trois choix de modélisation méritent d'être explicites :

1. **`langue` est une table**, pas un `enum` PHP seul : la clé étrangère garantit qu'une
   commande ou une traduction ne référence pas une langue inexistante. Côté PHP,
   l'entité `Langue` est en lecture seule.
2. **Les statuts sont des `varchar` + `CHECK`**, pas des types énumérés PostgreSQL : une
   valeur s'ajoute sans migration bloquante, et Doctrine mappe directement l'`enum` PHP.
3. **`cle_licence.ligne_commande_id` est nullable** : une clé existe avant d'être
   vendue. La contrainte `ck_cle_vendue` rattache l'obligation au statut.

---

## 6. Cohérence MCD → MLD → MPD → code

| Entité (MCD) | Table (MLD/MPD) | Classe PHP (`api/src/Entity`) | Repository (`api/src/Repository`) | Route principale |
|---|---|---|---|---|
| JEU | `jeu` | `Jeu` | `JeuRepository` | `GET /api/jeux`, `GET /api/jeux/{slug}` |
| EDITEUR | `editeur` | `Editeur` | `EditeurRepository` | `GET /api/editeurs` |
| PLATEFORME | `plateforme` | `Plateforme` | `PlateformeRepository` | `GET /api/plateformes` |
| GENRE | `genre` | `Genre` | `GenreRepository` | `GET /api/genres` |
| OFFRE | `offre` | `Offre` | `OffreRepository` | `GET /api/jeux/{slug}/offres` |
| CLE_LICENCE | `cle_licence` | `CleLicence` | `CleLicenceRepository` | `GET /api/commandes/{reference}/cles` |
| IMPORT_CLES | `import_cles` | `ImportCles` | `ImportClesRepository` | `POST /api/admin/cles/import` |
| UTILISATEUR | `utilisateur` | `Utilisateur` | `UtilisateurRepository` | `GET /api/moi` |
| UTILISATEUR_ROLE | `utilisateur_role` | `UtilisateurRole` (entité de jointure) | `UtilisateurRepository` | `POST /api/admin/utilisateurs` |
| CODE_SECOURS | `code_secours` | `CodeSecours` | `CodeSecoursRepository` | `POST /api/2fa/secours` |
| COMMANDE | `commande` | `Commande` | `CommandeRepository` | `POST /api/commandes` |
| LIGNE_COMMANDE | `ligne_commande` | `LigneCommande` | (via `Commande`) | `POST /api/commandes` |
| PAIEMENT | `paiement` | `Paiement` | `PaiementRepository` | `POST /api/paiements` |
| COMMANDE_HISTORIQUE | `commande_historique` | `CommandeHistorique` | `CommandeHistoriqueRepository` | `GET /api/admin/commandes/{reference}` |
| AVIS | `avis` | `Avis` | `AvisRepository` | `POST /api/jeux/{slug}/avis` |
| JOURNAL_AUDIT | `journal_audit` | `JournalAudit` | `JournalAuditRepository` | `GET /api/admin/journal` |

Une seule table n'a pas de classe PHP dédiée en v1 :

- `jeu_genre` est une **table de jointure** pilotée par l'association Doctrine
  `ManyToMany` `Jeu::$genres` (aucun attribut, donc aucune entité de jointure).

`utilisateur_role`, `jeu_traduction` et `genre_traduction` portent des attributs : ce
sont des entités de jointure, avec une classe PHP explicite.

Les vues de reporting (`v_ventes_jour`, `v_stock_disponible`) sont créées en T-01 pour
le rôle `sk_reporting` et ne sont pas des tables : elles n'apparaissent ni au MLD ni au
MPD.

---

## 7. Décisions de revue (24/11/2026)

Les quatre points restés ouverts après la revue de conception ont été tranchés par le binôme.
Aucun ne modifie la structure des tables : MCD, MLD et MPD restent valables.

| # | Point | Décision | Mise en œuvre |
|---|---|---|---|
| 1 | Langue d'un `avis` | **Détectée automatiquement** à partir du texte du commentaire, côté serveur, à la création. Si la détection n'est pas assez fiable (texte court, langue autre que FR / EN), on retient `utilisateur.langue_code`. La langue est figée ensuite et affichée au modérateur | P-01 : service de détection exécuté localement (aucun envoi du commentaire à un service externe, RGPD), limité aux langues de la table `langue` |
| 2 | `commande.langue_code` alors que `utilisateur.langue_code` existe | **Conservée** : c'est la langue au moment de la commande (e-mail de confirmation, preuve de la vente), qui ne doit pas changer si le client change de langue ensuite. Ce n'est pas une redondance : la valeur peut légitimement différer de celle du profil | F-06 : copiée de la langue de la requête à la création de la commande |
| 3 | Purge du `journal_audit` | Durée de conservation fixée à **12 mois** (CDC §12.1 : 6 mois à 1 an, recommandation CNIL). **Purge automatisée en v2**, hors périmètre v1 | v2 : le trigger `trg_journal_audit_immuable` interdit aujourd'hui tout DELETE ; la purge devra être conçue avec lui (par exemple partitionnement mensuel et suppression des partitions anciennes par `sk_owner`). `sk_app` reste sans droit DELETE |
| 4 | `code_secours` | **10 codes générés en bloc à chaque activation de l'A2F** ; les anciens codes de l'utilisateur sont supprimés dans la même transaction. Usage unique, stockés hachés | F-04 |
