-- ============================================================================
-- SideKey — Comptes PostgreSQL (CDC §9.5, DICP, security-patterns « Least Privilege »)
--
-- À exécuter par un super-utilisateur, base cible déjà créée, AVANT les migrations :
--   psql -v owner_pw=... -v app_pw=... -v reporting_pw=... -v backup_pw=... -f 01_roles.sql
-- (le script api/sql/bin/db-apply.sh le fait ; en recette et en production, c'est le job de déploiement)
--
-- Idempotent : rejouable sans erreur ; les mots de passe sont remis à jour à chaque exécution.
-- Les rôles sont globaux au serveur PostgreSQL ; les droits sur les tables sont dans 02_privileges.sql.
--
--   sk_owner      migrations (DDL) : propriétaire du schéma et des objets ; jamais utilisé par l'API
--   sk_app        API et worker : DML limité, pas de DDL, pas de DELETE sur les tables sensibles
--   sk_reporting  tableau de bord : SELECT sur des vues sans donnée personnelle
--   sk_backup     sauvegardes : lecture de toutes les données (pg_read_all_data), rien d'autre
-- ============================================================================
\set ON_ERROR_STOP on

-- Mots de passe stockés en SCRAM-SHA-256 (jamais en MD5)
SET password_encryption = 'scram-sha-256';

SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', r)
  FROM (VALUES ('sk_owner'), ('sk_app'), ('sk_reporting'), ('sk_backup')) AS t(r)
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r)
\gexec

SELECT format('ALTER ROLE %I PASSWORD %L', r, pw)
  FROM (VALUES ('sk_owner',     :'owner_pw'),
               ('sk_app',       :'app_pw'),
               ('sk_reporting', :'reporting_pw'),
               ('sk_backup',    :'backup_pw')) AS t(r, pw)
\gexec

-- Lecture de toutes les tables pour pg_dump, sans droit d'écriture
GRANT pg_read_all_data TO sk_backup;

-- Garde-fous contre les requêtes qui s'éternisent ou les transactions oubliées
ALTER ROLE sk_app       SET statement_timeout = '30s';
ALTER ROLE sk_app       SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE sk_reporting SET statement_timeout = '30s';
ALTER ROLE sk_reporting SET default_transaction_read_only = on;

-- Accès à la base : personne par défaut, puis les quatre comptes
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database()) \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO sk_owner, sk_app, sk_reporting, sk_backup', current_database()) \gexec

-- Schéma public : propriété de sk_owner, lecture seule pour les autres, rien pour PUBLIC
ALTER SCHEMA public OWNER TO sk_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO sk_owner;
GRANT USAGE ON SCHEMA public TO sk_app, sk_reporting, sk_backup;
