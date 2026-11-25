# ADR-0005 — Hébergement dans l'Union européenne

| Élément                        | Valeur                                                                                                                                                                                                       |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Statut                         | Remplacé par ADR-0009                                                                                                                                                                                                |
| Date                           | 2026-11-23                                                                                                                                                                                                   |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                                                                      |
| Décision du cahier des charges | R4 (§0.3) — hébergement dans l'UE ; précisions R10 (§19) et §8.3                                                                                                                                             |
| Portée                         | Localisation et nature de l'infrastructure de recette et de production : type d'offre, modalités de déploiement, sauvegardes, conformité. Le fournisseur et sa localisation exacte sont arrêtés au J3 (T-04) |
| Tickets liés                   | #4 (C-03) · T-04, T-01, L-02                                                                                                                                                                                 |

## Contexte

Nathalie (P5) est responsable des données de ses clients et veut respecter le RGPD en hébergeant les données dans l'Union européenne (BX-17). Hugo (P7) veut des déploiements automatisés et réversibles (BX-23). Les clients sont en France et à La Réunion (P1) ; les personas n'imposent pas de latence particulière au-delà des cibles du §13.

Le RGPD (règlement UE 2016/679) encadre les transferts hors UE ; le §12.1 en tire une conséquence explicite : « Hébergement dans l'UE (BX-17), donc pas de transfert hors UE. » Le cahier des charges précise le choix au §8.3 : « VPS chez un hébergeur situé dans l'UE (fournisseur choisi au J3) », avec deux justifications — données dans l'UE et déploiement Docker simple — et une alternative écartée : « PaaS hors UE : transferts de données à encadrer ».

Le point R10 du §19 laisse volontairement le fournisseur ouvert : il sera choisi au J3 sur trois critères (prix, données dans l'UE, Docker possible). L'architecture impose par ailleurs un déploiement par `docker compose pull && up -d` en SSH sur la recette, puis en production après approbation manuelle (§15.3), derrière Caddy pour TLS et les en-têtes (§8.3). Les cibles ENF-13 (déploiement en recette < 10 min) et ENF-14 (RPO 24 h, RTO 1 h) s'appliquent à cette infrastructure.

La décision porte donc sur le **modèle d'hébergement**, pas sur une marque : le choix du prestataire relève d'un ADR distinct, ce qui permet de comparer trois offres au J3 sans réécrire le principe retenu ici.

Le critère qui tranche est la **localisation des données** : un hébergement hors UE est disqualifié même s'il est moins cher. Viennent ensuite le délai et le coût, puisque la recette doit être en ligne dès le J3 (T-04). La puissance de la machine et le support ne sont pas des critères de choix à ce stade.

## Options étudiées

| Option                                | Avantages                                                                                                                      | Inconvénients                                                                                                                   | Verdict                                             |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------- |
| VPS Linux chez un hébergeur de l'UE   | Données et sauvegardes dans l'UE ; Docker et SSH disponibles ; prix maîtrisé ; déploiement identique au poste de développement | Exploitation à la charge du binôme ; aucune redondance                                                                          | **Retenue** — couvre BX-17, BX-23, R4, R10          |
| PaaS hors UE                          | Mise en service immédiate, TLS et montée en charge gérés                                                                       | Transferts de données hors UE à encadrer, donc charge juridique ; contredit R4 et le §12.1                                      | Écartée — RGPD et R4                                |
| Cloud public avec région UE           | Conforme sur le papier, services managés disponibles                                                                           | Coût, IAM, quotas, multiples objets à configurer pour trois semaines ; aucun service managé n'est requis par l'architecture     | Écartée — coût et délai (R5)                        |
| VPS hors UE avec clauses de transfert | Prix parfois plus bas, offre plus large                                                                                        | Transfert hors UE à documenter et à justifier (chapitre V du RGPD), alors que le §12.1 exclut tout transfert ; contredit R4     | Écartée — BX-17, R4                                 |
| PaaS européen managé                  | Exploitation allégée, TLS automatique                                                                                          | Le déploiement prévu au §15.3 (`docker compose pull` par SSH) devrait être réécrit ; contrôle réduit sur l'hôte et sur les logs | Écartée — T-04, attendu DevOps de l'activité type 3 |
| Hébergement sur un poste du binôme    | Coût nul, accès immédiat                                                                                                       | Pas d'URL publique stable : ni recette permanente, ni ZAP, ni k6, ni démonstration en soutenance                                | Écartée — §15.1, L-02                               |

## Décision

La recette et la production tournent sur un **VPS Linux** loué chez un **hébergeur dont le siège et les centres de données sont situés dans l'Union européenne**. Le fournisseur et la localisation exacte sont arrêtés au **J3** (R10, T-04), sur quatre critères : localisation UE des données **et des sauvegardes**, prix, possibilité d'exécuter Docker, accès SSH.

Le déploiement suit le §15.3 : publication des images vers GHCR depuis `main`, puis `docker compose pull && up -d` par SSH en recette ; en production, mêmes images, tag `vX.Y.Z` et approbation manuelle. Chaque environnement a ses propres secrets (§15.4). Caddy assure HTTPS, HSTS et la limitation de taille des requêtes (§8.2). La sauvegarde est un `pg_dump -Fc` horodaté avant chaque migration (§9.7), restauration par `pg_restore --clean` testée avant la livraison (BX-19, L-02).

Le fournisseur retenu fera l'objet d'un **nouvel ADR**, pas d'une réécriture de celui-ci.

## Conséquences

### Positives

- Les données personnelles et les clés de licence restent dans l'UE : la promesse faite à Nathalie (BX-17) et le §12.1 sont tenus sans clause de transfert à rédiger.
- L'infrastructure est la même que celle du poste de développement (Docker Compose), donc un défaut vu en recette se reproduit localement : c'est directement la cible ENF-06 et le besoin BX-21 de Hugo.
- Le déploiement par SSH et Compose est simple à expliquer et à démontrer au jury, ce qui sert la partie DevOps de l'activité type 3 (§2, F5).
- Le coût reste compatible avec un projet de formation, facturé en euros, et la réversibilité est bonne : les images et le `compose.yaml` se réinstallent chez un autre hébergeur UE.
- Les sauvegardes restent sur un stockage choisi dans l'UE, ce qui évite de recréer un transfert hors UE par la bande.
- L'infrastructure reste modeste et lisible : un serveur, un proxy, deux bases, quatre conteneurs. C'est aussi ce qui rend la démonstration de soutenance reproductible en direct.

### Négatives et risques

- **Charge d'exploitation** : mises à jour du système, durcissement SSH, renouvellement TLS, rotation des secrets, surveillance des disques et des sauvegardes reposent sur deux personnes qui développent en parallèle. C'est du temps pris sur les features.
- **Aucun service managé** : pas de base de données managée, pas de montée en charge automatique, pas de bascule automatique. Une panne du VPS arrête recette et production, et la cible de disponibilité 2/4 du DICP reste tenue par la sauvegarde, pas par la redondance.
- **Point unique de défaillance** : un seul VPS pour les deux environnements (ou deux VPS, donc deux fois le coût et deux fois l'exploitation) ; le RTO d'1 h (ENF-14) suppose que la procédure de restauration et de redéploiement soit réellement chronométrée, pas seulement écrite.
- **Conformité à documenter** : l'hébergeur devient sous-traitant au sens de l'article 28 du RGPD. Il faut la clause, la localisation des serveurs, la mention dans le registre des traitements simplifié (§12.1) et l'information des personnes.
- **Sauvegardes à sortir du VPS** : une sauvegarde stockée sur le même disque ne protège pas d'une perte du serveur. Le lieu de stockage doit être dans l'UE, sinon le problème de transfert revient.
- **Dépendance au fournisseur** : prix, conditions et qualité réseau peuvent changer ; la réversibilité repose sur la conteneurisation, à condition de conserver les scripts et les secrets hors du serveur.
- **Deux environnements à exposer** : la recette et la production ont chacune leur nom et leur certificat, avec les limites de débit de Let's Encrypt en cas d'essais répétés.
- **Le choix du fournisseur reste ouvert** : tant que R10 n'est pas tranché, la recette ne peut pas être livrée au J3, ce qui bloque la démonstration quotidienne du §16.1.
- **Aucune haute disponibilité** : le DICP place la disponibilité à 2/4, ce qui est cohérent avec un serveur unique, mais il faut l'assumer devant le jury plutôt que laisser croire à une redondance inexistante.

### Actions induites

- T-04 : comparaison de deux ou trois hébergeurs UE (prix, localisation, Docker, SSH), création du VPS, durcissement minimal, secrets `staging` et `production`, workflow de production sur tag.
- Nouvel ADR au J3 documentant le fournisseur retenu et sa localisation, plutôt qu'une modification de celui-ci.
- `infra/` : `Caddyfile`, `docker-compose.prod.yml`, `backup.sh`, `restore.sh`, procédure de retour arrière (§15.3).
- RGPD : clause de sous-traitance, mention du sous-traitant et de la localisation dans le registre des traitements (§12.1).
- Supervision : route `/api/health`, contrôles de santé des conteneurs, notification en cas d'échec de déploiement (§15.5).
- Documentation : diagramme `deploiement` (voir [conventions UML](../uml/README.md)).
- L-02 : chronométrer la restauration et le redéploiement, et consigner la mesure pour prouver le RTO d'1 h (ENF-14).

## Références

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §0.3 (R4), §8.3, §9.7, §11.1, §12.1, §13 (ENF-13, ENF-14), §15.1 à §15.5, §19 (R10).
- [Index des ADR](README.md) — ADR-0001 (application conteneurisée), ADR-0002 (bases de données), ADR-0008 (paiement simulé, aucun prestataire externe).
- [README du dépôt](../../README.md) — environnement Docker de développement, utilisé à l'identique en recette.

Cette décision est remplacée par [ADR-0009](0009-hebergement-sur-un-poste-du-binome.md) depuis le 2026-11-25.
