# ADR-0009 — Hébergement sur un poste du binôme, exposé par Tailscale Funnel

| Élément                        | Valeur                                                                                                                                                         |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Statut                         | Accepté                                                                                                                                                        |
| Date                           | 2026-11-25                                                                                                                                                     |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                        |
| Décision du cahier des charges | R4 (§0.3) et R10 (§19) — hébergement dans l'UE ; déploiement du §15.3 ; remplace ADR-0005                                                                      |
| Portée                         | Machine, exposition sur Internet et mode de déploiement de la recette et de la production. Ne change ni les images, ni la stack, ni le déroulé du §15.3         |
| Tickets liés                   | T-04 (#6) · T-01 (#5), P-02, L-02                                                                                                                              |

## Contexte

ADR-0005 retenait un VPS loué chez un hébergeur de l'UE et écartait l'hébergement sur un poste du binôme, faute d'URL publique stable : sans elle, ni recette permanente, ni ZAP, ni k6, ni démonstration en soutenance (§15.1, L-02). Au J3, le binôme fait le choix inverse : la recette et la production tournent sur **un PC Windows personnel** (Docker Desktop sur WSL2). Le projet dure trois semaines, le binôme ne veut pas engager d'abonnement, et la machine est disponible tout de suite.

L'objection d'ADR-0005 tombe si l'on obtient une adresse HTTPS publique et stable **sans ouvrir de port sur la box** : un tunnel sortant, initié par le PC, le permet. Les exigences de départ restent les mêmes : données dans l'UE (BX-17, §12.1), déploiement automatisé et réversible (BX-23, §15.3), recette en moins de 10 min (ENF-13), RPO 24 h et RTO 1 h (ENF-14).

Le dépôt est **public** : tout ce qui exécute du code sur le PC à partir de GitHub doit être limité aux déploiements de `main` et des tags, jamais au code d'une PR.

Le critère qui tranche est le **coût nul pour une exposition sans port entrant**, à condition que le contenu des échanges ne soit lisible que sur le PC.

## Options étudiées

| Option                                       | Avantages                                                                                                    | Inconvénients                                                                                                                        | Verdict                                  |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------- |
| PC du binôme + Tailscale Funnel et SSH       | Gratuit ; URL HTTPS fixe `*.ts.net` ; TLS terminé sur le PC ; aucun port ouvert ; SSH limité au tailnet (ACL) | Disponible seulement PC allumé ; métadonnées (IP, nom TLS) vues par Tailscale, société hors UE ; trois ports publics seulement       | **Retenue**                              |
| PC du binôme + Cloudflare Tunnel             | Gratuit, nom de domaine personnalisé, protections anti-DDoS                                                  | TLS terminé chez Cloudflare : le contenu (données personnelles, clés) est déchiffré hors du PC ; domaine à acheter et à déléguer      | Écartée — confidentialité, §12.1         |
| PC du binôme + redirection de ports de la box | Aucun tiers dans la chaîne ; certificat Let's Encrypt par Caddy                                              | PC domestique exposé directement ; IP souvent dynamique (DynDNS) ; box parfois sans redirection possible                             | Écartée — surface d'attaque              |
| Runner GitHub auto-hébergé sur le PC         | Pas de SSH ; déploiement local                                                                              | Dépôt public : un runner auto-hébergé peut exécuter le code d'une PR externe ; GitHub le déconseille                                  | Écartée — sécurité du poste              |
| VPS dans l'UE (ADR-0005)                     | Disponible 24 h/24, URL stable, aucun tiers                                                                  | Abonnement et exploitation d'un serveur de plus pour trois semaines                                                                  | Écartée — coût, choix du binôme          |

## Décision

La recette et la production tournent sur le **PC Windows d'un membre du binôme**, situé en France, dans **WSL2 (Ubuntu)** avec Docker Desktop. Chaque environnement est un projet compose distinct (`~/sidekey/staging`, `~/sidekey/production`), avec ses volumes et son `.env`.

- **Exposition** : Tailscale Funnel publie Caddy en HTTPS sur `https://<machine>.<tailnet>.ts.net` : port **443** pour la production, **8443** pour la recette. Le certificat est sur le PC : Tailscale relaie un flux chiffré qu'il ne peut pas lire. Caddy n'écoute que sur `127.0.0.1`.
- **Déploiement** : le §15.3 est appliqué à l'identique. Les images sont construites une fois depuis `main` et poussées sur GHCR (`sha-<commit>`) ; un runner GitHub hébergé rejoint le tailnet comme **nœud éphémère `tag:ci`** et lance `deploy/deploy.sh` par **Tailscale SSH**. Un tag `vX.Y.Z` promeut les mêmes images en production, après approbation de l'autre membre (environnement GitHub `production`).
- **Secrets** : uniquement dans les environnements GitHub `staging` et `production` ; le `.env` du PC est réécrit à chaque déploiement, en `0600`.
- **Sauvegarde** : `pg_dump -Fc` avant chaque migration, rotation sur 14 fichiers, restauration par `deploy.sh restore`.

ADR-0005 passe au statut « Remplacé par ADR-0009 ». Son principe, des données dans l'UE, reste tenu.

## Conséquences

### Positives

- Coût nul et mise en service immédiate : la recette peut être en ligne au J3 comme prévu au §16.
- Aucun port entrant sur la box, aucun service exposé hors de Caddy ; PostgreSQL, Redis et l'API sont sur un réseau Docker interne, sans accès à Internet.
- Le contenu des échanges reste chiffré de bout en bout jusqu'au PC : données personnelles et clés de licence ne sont jamais lisibles par un tiers.
- La CD est la même que pour un VPS : changer d'hébergeur consiste à changer `DEPLOY_HOST`, rien dans les images ni dans les workflows.
- Le déploiement est répété en CI à chaque PR (registre local, `deploy.sh` de bout en bout) : la recette ne découvre plus les erreurs de migration.

### Négatives et risques

- **Disponibilité** : la recette et la production n'existent que PC allumé et connecté. Démo du soir, ZAP, k6 et soutenance supposent de l'avoir prévu ; la cible 2/4 du DICP est tenue par la sauvegarde, pas par la machine.
- **Métadonnées chez un tiers hors UE** : Tailscale (société nord-américaine) voit les adresses IP des visiteurs et le nom TLS demandé. L'adresse IP est une donnée personnelle : c'est un transfert de métadonnées à documenter (§12.1), même si le contenu reste chiffré.
- **Poste personnel** : la machine sert aussi à autre chose ; une compromission du poste compromet les deux environnements. Les deux environnements partagent aussi la même machine.
- **Sauvegardes sur le même disque** : elles ne protègent pas d'une perte du PC. RPO 24 h suppose une copie hors de la machine.
- **Adresse IP des clients** : derrière Funnel et Caddy, l'API voit l'adresse du relais. La limitation de débit par IP (F-03) devra s'appuyer sur l'en-tête transmis par le relais, et pas sur l'adresse de la connexion.
- **Dépendance à Tailscale** : une panne ou un changement de conditions du service coupe l'accès public et la CD. La réversibilité est bonne : un VPS reprend la même stack.

### Actions induites

- T-04, partie 2 : sur le PC, WSL2, Docker Desktop, utilisateur `deploy`, Tailscale avec Funnel et SSH ; dans GitHub, environnements `staging` et `production`, approbateurs, secrets et client OAuth Tailscale ([guide](../exploitation/deploiement.md)).
- RGPD : vérifier le contrat de sous-traitance (DPA) de Tailscale et son mécanisme de transfert, puis l'inscrire au registre des traitements (§12.1).
- L-02 : copier les sauvegardes de production hors du PC (support chiffré, stockage dans l'UE), chronométrer une restauration complète pour prouver le RTO d'1 h.
- F-03 : prendre l'adresse du client dans l'en-tête du relais pour la limitation de débit.
- Démonstrations : PC allumé et déploiement vérifié avant chaque démo du soir et avant la soutenance.

## Références

- [ADR-0005 — Hébergement dans l'Union européenne](0005-hebergement-dans-l-ue.md), remplacé par cette fiche.
- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §0.3 (R4), §8.3, §9.7, §12.1, §13 (ENF-13, ENF-14), §15.1 à §15.4, §19 (R10).
- [Guide de déploiement et d'exploitation](../exploitation/deploiement.md) — préparation du PC, secrets, retour arrière.
- [README du dépôt](../../README.md) — environnement Docker de développement.
