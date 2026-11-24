-- ============================================================================
-- SideKey — Schéma relationnel PostgreSQL (MPD) : tables, clés primaires,
-- clés étrangères, types et valeurs par défaut, données de référence.
--
-- Source   : docs/uml/src/mpd.puml, docs/architecture/modele-donnees.md §3-4,
--            cahier des charges v1.2 §9.1 à §9.2
-- Suite    : 002_contraintes.sql (CHECK, unicités, index)
-- Nommage  : snake_case au singulier, pk_/fk_/uq_/ck_/ix_, cree_le / modifie_le
-- Choix    : identifiants uuid non énumérables (anti-IDOR), montants en numeric,
--            horodatages en timestamptz, statuts en varchar + CHECK (002),
--            ON DELETE RESTRICT partout : aucune suppression en cascade.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Référentiels et catalogue
-- ---------------------------------------------------------------------------
CREATE TABLE langue (
    code        char(2)      NOT NULL,
    libelle     varchar(40)  NOT NULL,
    CONSTRAINT pk_langue PRIMARY KEY (code)
);

CREATE TABLE editeur (
    id          uuid         NOT NULL DEFAULT gen_random_uuid(),
    nom         varchar(120) NOT NULL,
    cree_le     timestamptz  NOT NULL DEFAULT now(),
    modifie_le  timestamptz,
    CONSTRAINT pk_editeur PRIMARY KEY (id)
);

CREATE TABLE plateforme (
    id          uuid         NOT NULL DEFAULT gen_random_uuid(),
    code        varchar(12)  NOT NULL,
    libelle     varchar(60)  NOT NULL,
    cree_le     timestamptz  NOT NULL DEFAULT now(),
    modifie_le  timestamptz,
    CONSTRAINT pk_plateforme PRIMARY KEY (id)
);

CREATE TABLE genre (
    id          uuid         NOT NULL DEFAULT gen_random_uuid(),
    slug        varchar(80)  NOT NULL,
    cree_le     timestamptz  NOT NULL DEFAULT now(),
    modifie_le  timestamptz,
    CONSTRAINT pk_genre PRIMARY KEY (id)
);

CREATE TABLE genre_traduction (
    genre_id    uuid         NOT NULL,
    langue_code char(2)      NOT NULL,
    libelle     varchar(80)  NOT NULL,
    CONSTRAINT pk_genre_traduction PRIMARY KEY (genre_id, langue_code),
    CONSTRAINT fk_gt_genre  FOREIGN KEY (genre_id)    REFERENCES genre (id)    ON DELETE RESTRICT,
    CONSTRAINT fk_gt_langue FOREIGN KEY (langue_code) REFERENCES langue (code) ON DELETE RESTRICT
);

CREATE TABLE role (
    code        varchar(20)  NOT NULL,
    libelle     varchar(60)  NOT NULL,
    CONSTRAINT pk_role PRIMARY KEY (code)
);

CREATE TABLE jeu (
    id          uuid         NOT NULL DEFAULT gen_random_uuid(),
    titre       varchar(200) NOT NULL,
    slug        varchar(200) NOT NULL,
    date_sortie date,
    pegi        smallint     NOT NULL,
    jaquette    varchar(255),
    actif       boolean      NOT NULL DEFAULT true,
    editeur_id  uuid         NOT NULL,
    cree_le     timestamptz  NOT NULL DEFAULT now(),
    modifie_le  timestamptz,
    CONSTRAINT pk_jeu PRIMARY KEY (id),
    CONSTRAINT fk_jeu_editeur FOREIGN KEY (editeur_id) REFERENCES editeur (id) ON DELETE RESTRICT
);

CREATE TABLE jeu_traduction (
    jeu_id      uuid         NOT NULL,
    langue_code char(2)      NOT NULL,
    description text         NOT NULL,
    CONSTRAINT pk_jeu_traduction PRIMARY KEY (jeu_id, langue_code),
    CONSTRAINT fk_jt_jeu    FOREIGN KEY (jeu_id)      REFERENCES jeu (id)      ON DELETE RESTRICT,
    CONSTRAINT fk_jt_langue FOREIGN KEY (langue_code) REFERENCES langue (code) ON DELETE RESTRICT
);

CREATE TABLE jeu_genre (
    jeu_id      uuid         NOT NULL,
    genre_id    uuid         NOT NULL,
    CONSTRAINT pk_jeu_genre PRIMARY KEY (jeu_id, genre_id),
    CONSTRAINT fk_jg_jeu   FOREIGN KEY (jeu_id)   REFERENCES jeu (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_jg_genre FOREIGN KEY (genre_id) REFERENCES genre (id) ON DELETE RESTRICT
);

CREATE TABLE offre (
    id            uuid          NOT NULL DEFAULT gen_random_uuid(),
    prix_ttc      numeric(10,2) NOT NULL,
    seuil_alerte  smallint      NOT NULL DEFAULT 5,
    actif         boolean       NOT NULL DEFAULT true,
    version       integer       NOT NULL DEFAULT 1,   -- verrou optimiste (BX-14)
    jeu_id        uuid          NOT NULL,
    plateforme_id uuid          NOT NULL,
    cree_le       timestamptz   NOT NULL DEFAULT now(),
    modifie_le    timestamptz,
    CONSTRAINT pk_offre PRIMARY KEY (id),
    CONSTRAINT fk_offre_jeu        FOREIGN KEY (jeu_id)        REFERENCES jeu (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_offre_plateforme FOREIGN KEY (plateforme_id) REFERENCES plateforme (id) ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------------
-- Comptes
-- ---------------------------------------------------------------------------
CREATE TABLE utilisateur (
    id                  uuid         NOT NULL DEFAULT gen_random_uuid(),
    email               varchar(254) NOT NULL,
    mot_de_passe_hash   varchar(255) NOT NULL,
    prenom              varchar(80)  NOT NULL,
    nom                 varchar(80)  NOT NULL,
    email_verifie       boolean      NOT NULL DEFAULT false,
    totp_secret_chiffre bytea,                          -- AES-256-GCM (CDC §9.4)
    a2f_active          boolean      NOT NULL DEFAULT false,
    actif               boolean      NOT NULL DEFAULT true,
    derniere_connexion  timestamptz,
    anonymise_le        timestamptz,
    langue_code         char(2)      NOT NULL DEFAULT 'fr',
    cree_le             timestamptz  NOT NULL DEFAULT now(),
    modifie_le          timestamptz,
    CONSTRAINT pk_utilisateur PRIMARY KEY (id),
    CONSTRAINT fk_utilisateur_langue FOREIGN KEY (langue_code) REFERENCES langue (code) ON DELETE RESTRICT
);

CREATE TABLE utilisateur_role (
    utilisateur_id uuid        NOT NULL,
    role_code      varchar(20) NOT NULL,
    attribue_le    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT pk_utilisateur_role PRIMARY KEY (utilisateur_id, role_code),
    CONSTRAINT fk_ur_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ur_role        FOREIGN KEY (role_code)      REFERENCES role (code)      ON DELETE RESTRICT
);

CREATE TABLE code_secours (
    id             uuid         NOT NULL DEFAULT gen_random_uuid(),
    code_hash      varchar(255) NOT NULL,
    utilise_le     timestamptz,
    utilisateur_id uuid         NOT NULL,
    cree_le        timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT pk_code_secours PRIMARY KEY (id),
    CONSTRAINT fk_code_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------------
-- Commande, paiement et contenus
-- ---------------------------------------------------------------------------
CREATE TABLE commande (
    id              uuid          NOT NULL DEFAULT gen_random_uuid(),
    reference       uuid          NOT NULL DEFAULT gen_random_uuid(),   -- exposée dans l'API
    statut          varchar(30)   NOT NULL,
    montant_total   numeric(10,2) NOT NULL,
    confirme_majeur boolean       NOT NULL DEFAULT false,
    payee_le        timestamptz,
    expire_le       timestamptz   NOT NULL,
    utilisateur_id  uuid          NOT NULL,
    langue_code     char(2)       NOT NULL,
    cree_le         timestamptz   NOT NULL DEFAULT now(),
    modifie_le      timestamptz,
    CONSTRAINT pk_commande PRIMARY KEY (id),
    CONSTRAINT fk_commande_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT,
    CONSTRAINT fk_commande_langue      FOREIGN KEY (langue_code)    REFERENCES langue (code)    ON DELETE RESTRICT
);

CREATE TABLE ligne_commande (
    id            uuid          NOT NULL DEFAULT gen_random_uuid(),
    quantite      smallint      NOT NULL,
    prix_unitaire numeric(10,2) NOT NULL,                -- prix figé à la commande (RG-05)
    commande_id   uuid          NOT NULL,
    offre_id      uuid          NOT NULL,
    CONSTRAINT pk_ligne_commande PRIMARY KEY (id),
    CONSTRAINT fk_ligne_commande FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ligne_offre    FOREIGN KEY (offre_id)    REFERENCES offre (id)    ON DELETE RESTRICT
);

CREATE TABLE paiement (
    id                uuid          NOT NULL DEFAULT gen_random_uuid(),
    montant           numeric(10,2) NOT NULL,
    statut            varchar(20)   NOT NULL,
    cle_idempotence   varchar(64)   NOT NULL,
    code_retour       varchar(40),
    derniers_chiffres char(4),                           -- jamais de numéro de carte complet
    commande_id       uuid          NOT NULL,
    cree_le           timestamptz   NOT NULL DEFAULT now(),
    modifie_le        timestamptz,
    CONSTRAINT pk_paiement PRIMARY KEY (id),
    CONSTRAINT fk_paiement_commande FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE RESTRICT
);

CREATE TABLE commande_historique (
    id             uuid        NOT NULL DEFAULT gen_random_uuid(),
    ancien_statut  varchar(30),
    nouveau_statut varchar(30) NOT NULL,
    commande_id    uuid        NOT NULL,
    auteur_id      uuid,
    cree_le        timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT pk_commande_historique PRIMARY KEY (id),
    CONSTRAINT fk_hist_commande FOREIGN KEY (commande_id) REFERENCES commande (id)    ON DELETE RESTRICT,
    CONSTRAINT fk_hist_auteur   FOREIGN KEY (auteur_id)   REFERENCES utilisateur (id) ON DELETE RESTRICT
);

CREATE TABLE avis (
    id                uuid         NOT NULL DEFAULT gen_random_uuid(),
    note              smallint     NOT NULL,
    commentaire       text         NOT NULL,
    statut_moderation varchar(20)  NOT NULL DEFAULT 'EN_ATTENTE',
    motif_refus       varchar(255),
    utilisateur_id    uuid         NOT NULL,
    jeu_id            uuid         NOT NULL,
    langue_code       char(2)      NOT NULL,
    cree_le           timestamptz  NOT NULL DEFAULT now(),
    modifie_le        timestamptz,
    CONSTRAINT pk_avis PRIMARY KEY (id),
    CONSTRAINT fk_avis_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT,
    CONSTRAINT fk_avis_jeu         FOREIGN KEY (jeu_id)         REFERENCES jeu (id)         ON DELETE RESTRICT,
    CONSTRAINT fk_avis_langue      FOREIGN KEY (langue_code)    REFERENCES langue (code)    ON DELETE RESTRICT
);

CREATE TABLE journal_audit (
    id             uuid         NOT NULL DEFAULT gen_random_uuid(),
    action         varchar(60)  NOT NULL,
    ressource      varchar(40)  NOT NULL,
    ressource_id   uuid,
    ip_hachee      bytea,                                -- HMAC de l'adresse IP, jamais l'IP en clair
    utilisateur_id uuid,                                 -- NULL : action anonyme (échec de connexion)
    cree_le        timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT pk_journal_audit PRIMARY KEY (id),
    CONSTRAINT fk_audit_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------------
-- Clés de licence (créées après `ligne_commande` et `utilisateur`)
-- ---------------------------------------------------------------------------
CREATE TABLE import_cles (
    id             uuid         NOT NULL DEFAULT gen_random_uuid(),
    fichier_nom    varchar(255) NOT NULL,
    nb_lignes      integer      NOT NULL DEFAULT 0,
    nb_acceptees   integer      NOT NULL DEFAULT 0,
    nb_doublons    integer      NOT NULL DEFAULT 0,
    nb_erreurs     integer      NOT NULL DEFAULT 0,
    statut         varchar(20)  NOT NULL,
    utilisateur_id uuid         NOT NULL,
    cree_le        timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT pk_import_cles PRIMARY KEY (id),
    CONSTRAINT fk_import_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT
);

CREATE TABLE cle_licence (
    id                uuid         NOT NULL DEFAULT gen_random_uuid(),
    cle_chiffree      bytea        NOT NULL,             -- AES-256-GCM : IV + étiquette + chiffré
    empreinte         bytea        NOT NULL,             -- HMAC-SHA256 de la clé normalisée
    statut            varchar(20)  NOT NULL DEFAULT 'DISPONIBLE',
    importee_le       timestamptz  NOT NULL DEFAULT now(),
    reservee_le       timestamptz,
    revelee_le        timestamptz,
    offre_id          uuid         NOT NULL,
    ligne_commande_id uuid,
    import_cles_id    uuid,
    cree_le           timestamptz  NOT NULL DEFAULT now(),
    modifie_le        timestamptz,
    CONSTRAINT pk_cle_licence PRIMARY KEY (id),
    CONSTRAINT fk_cle_offre  FOREIGN KEY (offre_id)          REFERENCES offre (id)          ON DELETE RESTRICT,
    CONSTRAINT fk_cle_ligne  FOREIGN KEY (ligne_commande_id) REFERENCES ligne_commande (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cle_import FOREIGN KEY (import_cles_id)    REFERENCES import_cles (id)    ON DELETE RESTRICT
);

-- ---------------------------------------------------------------------------
-- Données de référence (nécessaires en production comme en test)
-- ---------------------------------------------------------------------------
INSERT INTO langue (code, libelle) VALUES
    ('fr', 'Français'),
    ('en', 'English');

INSERT INTO role (code, libelle) VALUES
    ('CLIENT',      'Client'),
    ('GESTIONNAIRE','Gestionnaire du catalogue et des clés'),
    ('MODERATEUR',  'Modérateur des avis'),
    ('ADMIN',       'Administrateur');

INSERT INTO plateforme (code, libelle) VALUES
    ('STEAM', 'Steam'),
    ('PSN',   'PlayStation Store'),
    ('XBOX',  'Xbox Store'),
    ('ESHOP', 'Nintendo eShop');
