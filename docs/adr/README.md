# ADR — Architecture Decision Records de SideKey

Un **ADR** (*Architecture Decision Record*) est une fiche courte qui trace une décision d'architecture : le contexte qui l'a rendue nécessaire, les options examinées, la décision retenue et ses conséquences. Le dossier de projet (livrable L1, §17) s'appuie sur ces fiches pour démontrer la compétence 6 du REAC, et le §8.5 du cahier des charges impose qu'« chaque choix d'architecture important fasse l'objet d'un ADR ».

Ces fiches ne racontent pas le code : elles expliquent **pourquoi** il est écrit ainsi, à une date donnée, et ce que cela coûte.

## Index

| N°       | Titre                                                                                                           | Statut  | Décision source       | Date       |
| -------- | --------------------------------------------------------------------------------------------------------------- | ------- | --------------------- | ---------- |
| ADR-0001 | [Symfony (API) et React (SPA)](0001-symfony-react.md)                                                           | Accepté | B1 (§0.2)             | 2026-11-23 |
| ADR-0002 | [PostgreSQL (relationnel) et Redis (NoSQL clé/valeur)](0002-postgresql-redis.md)                                | Accepté | R1 (§0.3)             | 2026-11-23 |
| ADR-0003 | [JWT d'accès court et jeton de rafraîchissement rotatif](0003-jwt-et-jeton-de-rafraichissement.md)              | Accepté | B2, B3 (§0.2)         | 2026-11-23 |
| ADR-0004 | [Chiffrement AES-256-GCM et empreinte HMAC-SHA256 des clés de licence](0004-chiffrement-des-cles-de-licence.md) | Accepté | §9.4 (RG-03)          | 2026-11-23 |
| ADR-0005 | [Hébergement dans l'Union européenne](0005-hebergement-dans-l-ue.md)                                            | Accepté | R4 (§0.3)             | 2026-11-23 |
| ADR-0006 | [Architecture en couches de l'API](0006-architecture-en-couches.md)                                             | Accepté | §8.1, §8.2 (REAC C6)  | 2026-11-23 |
| ADR-0007 | [PlantUML pour tous les diagrammes](0007-modelisation-plantuml.md)                                              | Accepté | B5 (§0.2)             | 2026-11-23 |
| ADR-0008 | [Paiement simulé et anti-fraude de niveau Équilibré](0008-paiement-simule-et-anti-fraude.md)                    | Accepté | F12 (§0.1), R3 (§0.3) | 2026-11-23 |

Les huit fiches sont produites par le ticket **#4 (C-03)** « Modélisation PlantUML et ADR ». Les décisions B1, B2, B3, B5, R1, R3 et R4, ainsi que F12, y sont traduites ; les tickets de mise en œuvre sont rappelés dans chaque fiche.

## Quand écrire un ADR

- Pour une décision **structurante**, difficile à défaire, qui engage plusieurs tickets ou plusieurs couches : choix de framework, de base de données, de mécanisme de sécurité, de mode de déploiement, de contrat entre l'API et la SPA.
- Pas pour un détail d'implémentation local (nom d'une méthode, choix d'une petite bibliothèque) : cela relève de la revue de PR.
- **Au moment de la décision**, pas après. La fiche est rédigée dans la PR qui introduit le choix, ou dans le ticket de conception quand la décision précède le code — c'est le cas des huit fiches présentes, produites par C-03.
- Une décision déjà tranchée par le cahier des charges (B*, R*, F*) n'est pas re-décidée : l'ADR explique comment le binôme la met en œuvre et ce qu'elle coûte.
- Un point restant à valider (§19) devient un ADR dès qu'il est tranché, pas avant.

## Format

- Fichier `docs/adr/NNNN-titre-court-en-francais.md`, numéro sur **4 chiffres**, jamais réutilisé, jamais renuméroté.
- En-tête en tableau, toujours avec les six lignes : **Statut**, **Date** (`AAAA-MM-JJ`), **Décideurs**, **Décision du cahier des charges**, **Portée**, **Tickets liés**.
- Sections imposées, dans cet ordre : `Contexte`, `Options étudiées`, `Décision`, `Conséquences` (sous-sections `Positives`, `Négatives et risques`, `Actions induites`), `Références`.
- **Options étudiées** : un tableau *Option / Avantages / Inconvénients / Verdict*, avec **au moins deux options écartées** et le **critère qui tranche** — sécurité, coût, compétences du binôme, attendu du REAC, délai.
- **Décision** : au présent, courte et vérifiable. Les durées et les seuils sont ceux du cahier des charges, à l'identique : 15 min, 7 j, 5 min, 12 caractères, 5 clés par offre et par commande, 10 clés sur 24 h, 3 échecs puis blocage d'1 h, compte de moins de 24 h et montant supérieur à 150 €, première commande supérieure à 200 €.
- **Conséquences** : les inconvénients sont écrits honnêtement, y compris ceux qui restent non tranchés. Un ADR qui ne coûte rien n'est pas un ADR.
- **Ancrage obligatoire** : chaque fiche cite les besoins persona (`BX-nn`), les sections du cahier des charges et les tickets concernés.
- Longueur de **70 à 130 lignes**, en français, ton professionnel, sans emphase commerciale. Aucun tableau vide, aucune section laissée en attente : une fiche est complète ou elle n'est pas acceptée.
- **Liens relatifs** uniquement, vers des fichiers réellement présents : `../cahier_des_charges_v1.2_SideKey.md`, `../uml/README.md`, le [README du dépôt](../../README.md), ou une autre fiche de ce dossier.
- Terminologie imposée : *jeton d'accès* / *jeton de rafraîchissement*, *offre* (jeu × plateforme), *clé de licence*, *commande*, *panier*, *Voter*, *PSP simulé*.

## Statuts

| Statut                | Sens                                                                                                 |
| --------------------- | ---------------------------------------------------------------------------------------------------- |
| Proposé               | Fiche rédigée, en attente de la relecture croisée ; aucune mise en œuvre avant acceptation           |
| Accepté               | Décision en vigueur ; le code peut s'y appuyer                                                       |
| Remplacé par ADR-00xx | Décision abandonnée ; la fiche reste en place pour l'historique et pointe vers celle qui la remplace |
| Rejeté                | Option proposée puis écartée par le binôme ; conservée parce qu'elle a été examinée                  |

Une fiche `Accepté` peut laisser un point explicitement non tranché dans ses conséquences : c'est le cas de l'affichage des 4 derniers caractères d'une clé dans ADR-0004. Ce point est signalé, pas masqué.

## Un ADR ne se réécrit pas

Une fiche acceptée **ne se modifie plus** : ni la décision, ni les options, ni les conséquences. Seules les corrections de forme (orthographe, lien mort cassé par un déplacement de fichier) sont admises.

Autrement dit : **un ADR ne se réécrit pas, il se remplace par un nouvel ADR** qui porte le numéro suivant. La fiche remplacée reçoit alors le statut `Remplacé par ADR-00xx`, ce qui ne représente **qu'une seule ligne modifiée** :

```markdown
| Statut | Remplacé par ADR-0009 |
```

et, si l'on veut être explicite, une phrase en fin de fiche : « Cette décision est remplacée par ADR-0009 depuis le AAAA-MM-JJ. » Le reste du contenu reste intact.

Pourquoi : une fiche réécrite efface le raisonnement d'origine, et l'on ne sait plus ce qui était vrai au moment du choix. En soutenance, pouvoir dire « nous avions décidé cela le 23/11, puis nous l'avons remplacé parce que… » vaut mieux qu'une fiche toujours à jour qui ne raconte rien.

Exemple concret : ADR-0005 pose le principe d'un hébergement dans l'UE, laissant le fournisseur au J3 (R10). Le fournisseur retenu sera consigné dans un **ADR-0009**, et ADR-0005 restera `Accepté` — il n'a pas été remplacé, seulement complété.

## Qui décide

| Décision                                                                      | Qui                                    | Comment                                                                               |
| ----------------------------------------------------------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------- |
| Architecture, technologies, mécanismes                                        | Binôme SideKey (M1, M2), à l'unanimité | Fiche rédigée puis relue en PR ; 1 approbation obligatoire par l'autre membre (§16.5) |
| Décision déjà arbitrée par le cahier des charges (F*, B*, R*)                 | Le binôme applique                     | L'ADR traduit la décision et expose ses conséquences, sans la remettre en cause       |
| Points restant à valider (§19)                                                | Le binôme                             | Décision consignée dans un nouvel ADR et dans le compte rendu du jour (§16.1)         |
| Priorisation, périmètre, critères d'acceptation                               | Le binôme dans son rôle de PO (F8)     | Ne relève pas d'un ADR, mais du backlog et du compte rendu                            |

Le binôme porte le rôle de PO (F8) : il tranche les arbitrages de périmètre, dans le cadre des décisions de cadrage (F*) rappelées au §0.1, prises selon le REAC. Un désaccord entre les deux membres se règle par la discussion, puis par la modification du cahier des charges **avant** de toucher à un ADR.

## Lien avec les tickets

- Chaque fiche porte la ligne **Tickets liés** : le ticket qui l'a produite (`#4` pour C-03) et les features qui l'appliquent (`F-03`, `F-08`…).
- Un ticket qui contredit un ADR ne passe pas la revue : soit l'ADR est remplacé, soit le ticket est corrigé. La règle vaut aussi pour une PR isolée.
- La Définition de terminé du backlog demande un « dossier de projet complété » : la fiche est citée dans la PR et reprise dans le dossier, ce qui évite de rédiger les choix à la fin.
- Un ADR ne décrit pas de tâche. Les actions induites sont listées comme conséquences, mais elles se suivent dans GitHub Projects, pas ici.
- Les critères DICP et les *security patterns* sont documentés dans le dossier de sécurité et réutilisés par ADR-0003 (jetons), ADR-0004 (chiffrement) et ADR-0006 (couches).

## Décisions qui mériteraient un ADR en v2

| Sujet                                                            | Pourquoi un ADR                                                                                                                 | Déclencheur                        |
| ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Fournisseur d'hébergement et localisation exacte                 | ADR-0005 fixe le principe de l'UE, pas le prestataire ; prix, réversibilité et sous-traitance RGPD doivent être tracés          | J3, T-04 (R10)                     |
| Rotation et versionnement de la clé maître AES et du secret HMAC | ADR-0004 signale que la rotation n'est pas outillée : sans version de clé, une clé compromise est irremplaçable                 | Première clé à changer             |
| Intégration d'un prestataire de paiement réel                    | F12 et le §11.6 annoncent un branchement v2 grâce au patron Strategy ; PCI DSS et 3-D Secure à cadrer                           | Ouverture commerciale              |
| Externalisation des sauvegardes et objectifs RPO/RTO             | ENF-14 impose RPO 24 h et RTO 1 h, alors que la sauvegarde vit sur le même VPS                                                  | L-02 (test de restauration)        |
| Import de clés asynchrone (transport Messenger)                  | P-13 et le §9.8 laissent le choix entre transport Redis et Doctrine, avec des conséquences différentes sur la perte de messages | Au-delà de 1 000 lignes par import |
| Vérification des mots de passe compromis                         | R7 reste à trancher entre liste locale et API HIBP avec k-anonymat, avec un impact RGPD et réseau                               | P-04 ou durcissement               |
| A2F généralisée, voire WebAuthn côté client                      | P-06 rend l'A2F facultative ; le niveau Strict cité au §6.3 ouvrirait la voie à une obligation                                  | Après P-06                         |
| Supervision et centralisation des journaux                       | Le §15.5 décrit un minimum (contrôles de santé, logs JSON, alerte GitHub) qui ne suffirait pas en exploitation réelle           | Après la v1                        |
| Application mobile native consommant l'API                       | Le §18 la repousse, mais l'ADR-0003 et ADR-0001 l'anticipent : support de l'authentification et des versions d'API à figer      | v2                                 |
| Multilingue au-delà de FR / EN                                   | F10 limite à deux langues ; l'ajout d'une langue touche les tables `*_traduction`, les e-mails et les tests i18n                | v2                                 |
| Avis stockés en base document                                    | R1 a écarté MongoDB faute de besoin ; la croissance des avis pourrait le justifier plus tard                                    | Volume d'avis significatif         |

## Documents liés

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — source de vérité des décisions B*, R*, F* et des besoins `BX-nn`.
- [Conventions des diagrammes UML](../uml/README.md) — mêmes principes de versionnement pour les sources `.puml` et leurs exports.
- [README du dépôt](../../README.md) — structure du monorepo, dont ce dossier `docs/adr/`.
