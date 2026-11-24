-- ============================================================================
-- SideKey — Contraintes d'intégrité et index (MPD, modele-donnees.md §4.3).
-- Suit 001_schema.sql. Toutes les violations sont testées dans
-- api/tests/Database/02_contraintes_test.sql.
--
-- Règles de nommage (CDC §9.1) : uq_ unicité · ck_ CHECK · ix_ index.
-- Les statuts sont des varchar + CHECK (et non des types énumérés) : ajouter une valeur ne bloque
-- pas la migration, et l'enum PHP se mappe directement.
-- Garde-fous du MCD : empreinte de clé unique (RG-03), une clé VENDUE a une ligne (RG-04),
-- une seule offre active par jeu et plateforme (RG-01), quantité 1 à 10, avis 1 à 5 (RG-30).
-- ============================================================================


-- ---------------------------------------------------------------------------
-- Référentiels et catalogue
-- ---------------------------------------------------------------------------
ALTER TABLE langue ADD CONSTRAINT ck_langue_code CHECK (code ~ '^[a-z]{2}$');
ALTER TABLE editeur ADD CONSTRAINT uq_editeur_nom UNIQUE (nom);
ALTER TABLE plateforme ADD CONSTRAINT uq_plateforme_code UNIQUE (code);
ALTER TABLE plateforme ADD CONSTRAINT ck_plateforme_code CHECK (code ~ '^[A-Z0-9_]+$');
ALTER TABLE genre ADD CONSTRAINT uq_genre_slug UNIQUE (slug);
ALTER TABLE role ADD CONSTRAINT ck_role_code CHECK (code IN ('CLIENT','GESTIONNAIRE','MODERATEUR','ADMIN'));
ALTER TABLE jeu ADD CONSTRAINT uq_jeu_slug UNIQUE (slug);
ALTER TABLE jeu ADD CONSTRAINT ck_jeu_pegi CHECK (pegi IN (3, 7, 12, 16, 18));
ALTER TABLE jeu_traduction ADD CONSTRAINT ck_jt_description CHECK (char_length(description) BETWEEN 10 AND 4000);
ALTER TABLE offre ADD CONSTRAINT ck_offre_prix CHECK (prix_ttc > 0);
ALTER TABLE offre ADD CONSTRAINT ck_offre_seuil CHECK (seuil_alerte >= 0);
ALTER TABLE offre ADD CONSTRAINT ck_offre_version CHECK (version >= 1);
CREATE UNIQUE INDEX uq_offre_active ON offre (jeu_id, plateforme_id) WHERE actif;

-- ---------------------------------------------------------------------------
-- Clés de licence
-- ---------------------------------------------------------------------------
ALTER TABLE import_cles ADD CONSTRAINT ck_import_statut CHECK (statut IN ('EN_COURS','TERMINE','ECHEC'));
ALTER TABLE import_cles ADD CONSTRAINT ck_import_compteurs CHECK (nb_lignes >= 0 AND nb_acceptees >= 0 AND nb_doublons >= 0 AND nb_erreurs >= 0 AND nb_acceptees + nb_doublons + nb_erreurs <= nb_lignes);
ALTER TABLE cle_licence ADD CONSTRAINT uq_cle_empreinte UNIQUE (empreinte);
ALTER TABLE cle_licence ADD CONSTRAINT ck_cle_empreinte CHECK (octet_length(empreinte) = 32);
ALTER TABLE cle_licence ADD CONSTRAINT ck_cle_chiffree CHECK (octet_length(cle_chiffree) > 0);
ALTER TABLE cle_licence ADD CONSTRAINT ck_cle_statut CHECK (statut IN ('DISPONIBLE','RESERVEE','VENDUE'));
ALTER TABLE cle_licence ADD CONSTRAINT ck_cle_vendue_ligne CHECK (statut <> 'VENDUE' OR ligne_commande_id IS NOT NULL);
CREATE INDEX ix_cle_stock ON cle_licence (offre_id, importee_le) WHERE statut = 'DISPONIBLE';

-- ---------------------------------------------------------------------------
-- Comptes
-- ---------------------------------------------------------------------------
CREATE UNIQUE INDEX uq_utilisateur_email ON utilisateur (lower(email));
ALTER TABLE utilisateur ADD CONSTRAINT ck_utilisateur_email CHECK (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$');
ALTER TABLE utilisateur ADD CONSTRAINT ck_utilisateur_a2f CHECK (NOT a2f_active OR totp_secret_chiffre IS NOT NULL);

-- ---------------------------------------------------------------------------
-- Commande, paiement et contenus
-- ---------------------------------------------------------------------------
ALTER TABLE commande ADD CONSTRAINT uq_commande_reference UNIQUE (reference);
ALTER TABLE commande ADD CONSTRAINT ck_commande_statut CHECK (statut IN ('EN_ATTENTE_PAIEMENT','PAYEE','A_VERIFIER','EXPIREE','ANNULEE','REMBOURSEE'));
ALTER TABLE commande ADD CONSTRAINT ck_commande_montant CHECK (montant_total >= 0);
ALTER TABLE commande ADD CONSTRAINT ck_commande_expire CHECK (expire_le > cree_le);
CREATE INDEX ix_commande_client_statut ON commande (utilisateur_id, statut);
ALTER TABLE ligne_commande ADD CONSTRAINT uq_ligne_offre UNIQUE (commande_id, offre_id);
ALTER TABLE ligne_commande ADD CONSTRAINT ck_ligne_quantite CHECK (quantite BETWEEN 1 AND 10);
ALTER TABLE ligne_commande ADD CONSTRAINT ck_ligne_prix CHECK (prix_unitaire > 0);
ALTER TABLE paiement ADD CONSTRAINT uq_paiement_idempotence UNIQUE (cle_idempotence);
ALTER TABLE paiement ADD CONSTRAINT ck_paiement_montant CHECK (montant > 0);
ALTER TABLE paiement ADD CONSTRAINT ck_paiement_statut CHECK (statut IN ('EN_ATTENTE','ACCEPTE','REFUSE','ERREUR'));
ALTER TABLE paiement ADD CONSTRAINT ck_paiement_carte CHECK (derniers_chiffres ~ '^[0-9]{4}$');
ALTER TABLE commande_historique ADD CONSTRAINT ck_hist_statut CHECK (nouveau_statut IN ('EN_ATTENTE_PAIEMENT','PAYEE','A_VERIFIER','EXPIREE','ANNULEE','REMBOURSEE') AND (ancien_statut IS NULL OR ancien_statut IN ('EN_ATTENTE_PAIEMENT','PAYEE','A_VERIFIER','EXPIREE','ANNULEE','REMBOURSEE')));
ALTER TABLE avis ADD CONSTRAINT uq_avis_utilisateur_jeu UNIQUE (utilisateur_id, jeu_id);
ALTER TABLE avis ADD CONSTRAINT ck_avis_note CHECK (note BETWEEN 1 AND 5);
ALTER TABLE avis ADD CONSTRAINT ck_avis_longueur CHECK (char_length(commentaire) BETWEEN 10 AND 1000);
ALTER TABLE avis ADD CONSTRAINT ck_avis_statut CHECK (statut_moderation IN ('EN_ATTENTE','PUBLIE','REFUSE'));
ALTER TABLE avis ADD CONSTRAINT ck_avis_motif CHECK (statut_moderation <> 'REFUSE' OR motif_refus IS NOT NULL);
ALTER TABLE journal_audit ADD CONSTRAINT ck_audit_ip CHECK (ip_hachee IS NULL OR octet_length(ip_hachee) = 32);
CREATE INDEX ix_audit_ressource ON journal_audit (ressource, ressource_id);
