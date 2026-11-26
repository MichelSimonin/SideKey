-- Annulation de 004_cle_suffixe.sql
ALTER TABLE cle_licence DROP CONSTRAINT IF EXISTS ck_cle_suffixe, DROP COLUMN IF EXISTS suffixe;
