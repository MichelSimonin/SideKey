-- ============================================================================
-- SideKey — Vues de reporting pour le compte sk_reporting (CDC §9.5, modele-donnees.md §6)
--
-- Ces vues n'exposent ni donnée personnelle (e-mail, nom, adresse IP) ni clé de licence :
-- c'est la seule porte d'entrée de sk_reporting (qui n'a aucun droit sur les tables).
-- Elles s'exécutent avec les droits de leur propriétaire (sk_owner).
-- ============================================================================

-- Ventes par jour, par jeu et par plateforme (commandes PAYEE uniquement)
CREATE VIEW v_ventes_jour AS
SELECT (c.payee_le AT TIME ZONE 'UTC')::date          AS jour,
       j.id                                           AS jeu_id,
       j.titre                                        AS titre,
       p.code                                         AS plateforme,
       count(DISTINCT c.id)::integer                  AS nb_commandes,
       sum(l.quantite)::integer                       AS cles_vendues,
       sum(l.quantite * l.prix_unitaire)::numeric(12,2) AS chiffre_affaires_ttc
  FROM commande c
  JOIN ligne_commande l ON l.commande_id = c.id
  JOIN offre o          ON o.id = l.offre_id
  JOIN jeu j            ON j.id = o.jeu_id
  JOIN plateforme p     ON p.id = o.plateforme_id
 WHERE c.statut = 'PAYEE' AND c.payee_le IS NOT NULL
 GROUP BY 1, 2, 3, 4;

COMMENT ON VIEW v_ventes_jour IS 'Ventes par jour, jeu et plateforme (commandes PAYEE). Sans donnée personnelle.';

-- Stock de clés par offre active, avec l'alerte de stock bas (seuil de l'offre)
CREATE VIEW v_stock_disponible AS
SELECT o.id                                                        AS offre_id,
       j.titre                                                     AS titre,
       p.code                                                      AS plateforme,
       count(k.id) FILTER (WHERE k.statut = 'DISPONIBLE')::integer AS cles_disponibles,
       count(k.id) FILTER (WHERE k.statut = 'RESERVEE')::integer   AS cles_reservees,
       count(k.id) FILTER (WHERE k.statut = 'VENDUE')::integer     AS cles_vendues,
       o.seuil_alerte                                              AS seuil_alerte,
       (count(k.id) FILTER (WHERE k.statut = 'DISPONIBLE') <= o.seuil_alerte) AS stock_bas
  FROM offre o
  JOIN jeu j        ON j.id = o.jeu_id
  JOIN plateforme p ON p.id = o.plateforme_id
  LEFT JOIN cle_licence k ON k.offre_id = o.id
 WHERE o.actif
 GROUP BY o.id, j.titre, p.code, o.seuil_alerte;

COMMENT ON VIEW v_stock_disponible IS 'Nombre de clés par statut et offre active. Aucune clé, chiffrée ou non.';
