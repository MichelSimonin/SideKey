-- Annulation de 02_privileges.sql : retire tous les droits accordés aux comptes applicatifs.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM sk_app, sk_reporting;
ALTER DEFAULT PRIVILEGES FOR ROLE sk_owner REVOKE EXECUTE ON FUNCTIONS FROM sk_app;
ALTER DEFAULT PRIVILEGES FOR ROLE sk_owner GRANT EXECUTE ON FUNCTIONS TO PUBLIC;
