-- ============================================================================
-- SideKey — F-08 : 4 derniers caractères de la clé de licence, en clair (CDC §9.4, BX-13).
--
-- Le back-office n'affiche qu'une forme masquée « ••••ABCD ». Ces 4 caractères ne se déduisent pas du
-- chiffré AES-GCM : ils sont calculés à l'import et rangés ici, ce qui évite un second point de
-- déchiffrement (seul LicenceService::reveler() déchiffre). Choix du binôme : 4 caractères en clair sur
-- 8 à 64 réduisent très peu la confidentialité, et une clé du jeu d'essai antérieure n'en a pas (NULL).
-- ============================================================================
ALTER TABLE cle_licence
    ADD COLUMN suffixe char(4),
    ADD CONSTRAINT ck_cle_suffixe CHECK (suffixe IS NULL OR suffixe ~ '^[A-Z0-9]{4}$');

COMMENT ON COLUMN cle_licence.suffixe IS '4 derniers caractères de la clé normalisée, seuls affichés au back-office (F-08)';
