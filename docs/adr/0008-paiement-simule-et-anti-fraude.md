# ADR-0008 — Paiement simulé et anti-fraude de niveau Équilibré

| Élément                        | Valeur                                                                                                                                                                                                         |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Statut                         | Accepté                                                                                                                                                                                                        |
| Date                           | 2026-11-23                                                                                                                                                                                                     |
| Décideurs                      | Binôme SideKey (M1, M2)                                                                                                                                                                                        |
| Décision du cahier des charges | F12 (§0.1) — paiement simulé, à justifier ; R3 (§0.3) — anti-fraude niveau Équilibré                                                                                                                           |
| Portée                         | Encaissement (PSP simulé, idempotence, absence de données de carte) et règles anti-fraude appliquées à la commande (§6.3, RG-14). Ne couvre ni la réservation des clés (ADR-0002) ni le chiffrement (ADR-0004) |
| Tickets liés                   | #4 (C-03) · F-07, F-06, F-10, P-02, P-03                                                                                                                                                                       |

## Contexte

SideKey vend des clés de licence, bien unique et immédiatement consommable. Léa (P1) attend une confirmation et sa clé sans délai (BX-02) et un tunnel court (BX-04). Nathalie (P5) craint la fraude et le vol de clés : une clé volée est de l'argent perdu (BX-15). Ces deux besoins s'opposent : plus de contrôles, plus de friction pour Léa ; moins de contrôles, plus de risque pour Nathalie.

Le binôme a retenu un **paiement simulé** (F12), justifié au §11.6 : l'objectif pédagogique porte sur la conception, la sécurité et l'accès aux données, pas sur l'intégration d'un prestataire bancaire ; manipuler de vraies données de carte imposerait la norme PCI DSS, hors de portée d'un projet de formation ; les réponses déterministes rendent les cas testables en CI ; et le patron **Strategy** (`PaymentProviderInterface`) permettra de brancher un vrai prestataire en v2 sans toucher à la logique métier (§8.5).

Le binôme a comparé trois niveaux d'anti-fraude et retenu **Équilibré** (R3, §6.3) : il couvre les schémas typiques des boutiques de clés (compte créé à la volée, gros panier payé par carte volée) sans pénaliser un usage normal. Les seuils sont paramétrables dans la configuration Symfony, donc ajustables sans modification du code.

Le contexte technique impose deux règles. Le montant est calculé côté serveur, jamais transmis par le client (RG-11, §11.2 A08). Et le PSP est appelé **hors transaction**, pour ne pas garder de verrous pendant un appel externe (§10.3).

Le critère qui tranche est la **sécurité**, arbitrée par l'expérience utilisateur : un contrôle qui bloque une première commande légitime coûte un panier abandonné, donc une vente ; un contrôle absent coûte des clés. Le niveau Équilibré est le point d'équilibre documenté au §6.3, et il est repris tel quel plutôt que rediscuté.

## Options étudiées

| Option                                                                 | Avantages                                                                                   | Inconvénients                                                                                                             | Verdict                                           |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| PSP simulé derrière `PaymentProviderInterface` + anti-fraude Équilibré | Aucune donnée de carte, cas déterministes et testables, remplaçable en v2, friction limitée | Ne prouve pas une intégration réelle ; les seuils restent déclaratifs                                                     | **Retenue** — couvre BX-02, BX-04, BX-15, F12, R3 |
| Prestataire de paiement réel en mode test                              | Parcours plus réaliste, 3-D Secure, webhooks                                                | PCI DSS, contrat, gestion des clés d'API, données de carte ; hors délai pour 2 à 3 semaines                               | Écartée — F12, §11.6 (coût et conformité)         |
| Aucun contrôle anti-fraude en v1                                       | Zéro friction, aucun code supplémentaire                                                    | Rien n'arrête un compte jetable ni un gros panier payé par carte volée : BX-15 n'est pas couvert                          | Écartée — BX-15                                   |
| Anti-fraude niveau Souple                                              | Très peu de friction                                                                        | 10 clés par offre, 30 clés sur 24 h : risque de revente massive de clés volées (§6.3)                                     | Écartée — risque (P5)                             |
| Anti-fraude niveau Strict                                              | Risque résiduel très faible                                                                 | Friction fréquente sur toute première commande > 50 €, abandon de panier probable pour Léa, charge de vérification élevée | Écartée — BX-04, charge admin                     |
| Contrôles anti-fraude évalués dans la SPA                              | Retour immédiat à l'utilisateur, moins de requêtes                                          | Contournables en modifiant la requête ; le montant et les compteurs doivent être serveur (RG-11, A08)                     | Écartée — sécurité                                |

## Décision

Le paiement passe par une interface unique, implémentée en v1 par un **PSP simulé** déterministe : `4242 4242 4242 4242` accepté, `4000 0000 0000 0002` refusé, `4000 0000 0000 9995` fonds insuffisants, `4000 0000 0000 0119` délai dépassé. **Aucune donnée de carte n'est stockée** : seuls les 4 derniers chiffres et le code retour sont conservés. Le bandeau « Paiement fictif — projet de formation » est affiché en français et en anglais.

La requête est **idempotente** : une clé d'idempotence identifie chaque tentative, avec `idem:{cle}` dans Redis pendant **24 h** (§9.8) ; un rejeu ne crée pas de second paiement. Le PSP est appelé **hors transaction** (§10.3).

L'anti-fraude applique le niveau **Équilibré** (RG-14) :
- **5 clés** au maximum par offre et par commande ;
- **10 clés** au maximum par client sur **24 h glissantes** ;
- **3 échecs** de paiement entraînent un **blocage d'1 h** ;
- la commande passe en **`A_VERIFIER`** si le compte a **moins de 24 h** et que le montant dépasse **150 €**, ou si la **première commande dépasse 200 €** ;
- l'e-mail doit être vérifié avant tout achat ; l'A2F reste facultative pour les clients.

Les seuils sont lus dans la configuration Symfony, les compteurs dans Redis (`fraud:keys24h:{userId}`, TTL **24 h**), et une commande `A_VERIFIER` garde ses clés réservées sans les rendre révélables jusqu'à la décision du back-office (§6.4, US-56).

## Conséquences

### Positives

- Léa obtient sa clé immédiatement après paiement (BX-02) : aucune étape bancaire réelle ne s'intercale dans le tunnel.
- Nathalie dispose des garde-fous qui couvrent les schémas courants (BX-15) : compte jetable, gros panier, échecs répétés.
- L'absence de données de carte supprime le risque PCI DSS et allège le RGPD : la minimisation du §12.1 s'applique sans effort.
- Les réponses déterministes rendent les cas accepté, refusé, fonds insuffisants, délai dépassé et rejeu testables en CI (§11.6, §14.4), ce qui sert la cible ENF-09.
- Le patron Strategy isole le fournisseur : un vrai prestataire se branche en v2 sans modifier les services métier ni les contrôleurs (§8.5).
- Les seuils paramétrables permettent d'ajuster après une démonstration sans redéploiement de code, ce qui est utile pendant les sprints journaliers.
- Le journal d'audit et l'historique des statuts conservent la trace de chaque décision, y compris une annulation administrative avec libération des clés (BX-16, RG-21).

### Négatives et risques

- **Le PSP simulé ne prouve rien d'une intégration réelle** : pas de webhook, pas de 3-D Secure, pas de remboursement effectif, pas de réconciliation. La soutenance doit présenter cela comme une perspective v2 assumée, pas comme un paiement opérationnel.
- **Les règles sont déclaratives et contournables** par multiplication de comptes : 10 clés par 24 h et par compte ne bornent pas un fraudeur patient. La détection est a posteriori, par la file « à vérifier ».
- **Charge de vérification manuelle** : chaque commande d'un compte de moins de 24 h au-dessus de 150 €, et chaque première commande au-dessus de 200 €, atterrit dans la file. Sur une démonstration, cela peut produire plusieurs commandes à traiter, ce qui mobilise un administrateur (P5).
- **Faux positifs** : un client légitime qui crée son compte le jour même et commande pour 180 € passe en vérification, alors que son comportement ne diffère pas de celui de Léa. Le refus administratif déclenche un remboursement, donc un traitement supplémentaire (RG-20, §6.4).
- **Dépendance à Redis pour les compteurs** : une perte de Redis remet la fenêtre de 24 h à zéro et les échecs de paiement à zéro. Le comportement en cas d'échec du compteur doit être tranché explicitement — refuser la commande (Fail Securely) plutôt que la laisser passer sans contrôle.
- **Idempotence bornée à 24 h** : au-delà, un rejeu serait traité comme un nouveau paiement. C'est acceptable pour une commande dont le TTL de réservation est de 15 min, mais cela doit être écrit.
- **Configuration sensible** : un seuil mis à zéro par erreur désactive une protection sans erreur visible. Les valeurs doivent être validées au démarrage (bornes minimales) et couvertes par un test.
- **Deux notions de temps différentes** : « compte de moins de 24 h » se mesure depuis la création du compte, alors que l'e-mail vérifié est une condition distincte. Les confondre produirait une règle plus permissive que celle du §6.3.
- **Un paiement accepté n'est pas une commande payée** : entre la réponse du PSP et la validation atomique, la commande peut rester `EN_ATTENTE_PAIEMENT` ou basculer en `A_VERIFIER`. L'écran de confirmation doit refléter ces issues sans promettre une clé qui n'est pas encore attribuée.

### Actions induites

- F-07 : `Infrastructure/Payment` (interface, implémentation simulée), service de paiement, idempotence Redis, règles RG-14, compteurs `fraud:*`.
- F-06 : réservation de 15 min conservée en cas d'échec de paiement (US-36), transitions de statut contrôlées par `trg_commande_transition`.
- F-10 : file « à vérifier », validation ou annulation avec libération des clés (US-56), écriture du journal d'audit.
- Configuration : seuils anti-fraude externalisés, validés au démarrage, documentés dans le dossier de projet avec la justification du niveau Équilibré (§6.3).
- Tests : matrice des quatre numéros de test, rejeu de la même clé d'idempotence, déclenchement de chaque seuil, blocage après 3 échecs (§14.1, §14.4).
- Diagramme `seq-paiement` et `etats-commande` (voir [conventions UML](../uml/README.md)).
- Dossier de projet : reprendre le comparatif des trois niveaux du §6.3 pour justifier le niveau Équilibré, et le rappeler dans le diaporama au titre de la sécurité.

## Mise en œuvre (F-07, 26/11/2026)

La décision est appliquée telle quelle ; la mise en œuvre précise six points que l'ADR laissait ouverts.

1. **Un seul paiement actif par commande, garanti par la base.** L'ADR appelle le prestataire hors transaction et protège le rejeu d'une même clé ; il ne dit pas ce qui arrête deux requêtes simultanées avec deux clés différentes. Un index unique partiel (`uq_paiement_commande_actif`) l'impose à l'insertion.
2. **Idempotence durable.** La clé stockée est l'empreinte de « client + clé ». `idem:{empreinte}` (Redis, 24 h) est le verrou rapide ; la colonne unique `paiement.cle_idempotence` est la mémoire durable : un rejeu après 24 h renvoie encore la même réponse, au lieu d'être traité comme un nouveau paiement (conséquence négative levée).
3. **Où se contrôlent les seuils.** Le plafond de 10 clés et le blocage sont vérifiés **au paiement, avant tout débit** ; les clés ne sont comptées qu'après acceptation. La règle « à vérifier » s'applique après l'acceptation (paiement accepté, commande `A_VERIFIER`).
4. **Fenêtre glissante.** Les clés payées sont un ensemble trié horodaté (`fraud:keys24h`), pas un compteur à TTL. Les échecs sont comptés sur la durée du blocage, et le blocage est posé par le script Lua qui compte (`fraud:fails`, `fraud:block`).
5. **Redis injoignable : refus** (503), avant tout débit, comme l'ADR le demandait ; après le débit, la base fait foi et l'incident est journalisé.
6. **Seuils validés au déploiement.** `ConfigurationFraude` refuse toute valeur hors bornes et le réchauffage du cache la construit : un seuil à 0 fait échouer `cache:warmup` (conséquence négative « configuration sensible » levée).

**Reste à faire, hors périmètre de F-07** : la file « à vérifier » et la décision du back-office (F-10), l'e-mail de confirmation, les écrans du tunnel (E07, E08). Le diagramme [seq-paiement](../uml/exports/seq-paiement.svg) reflète la mise en œuvre.

## Références

- [Cahier des charges v1.2](../cahier_des_charges_v1.2_SideKey.md) — §0.1 (F12), §0.3 (R3), §5.4 (US-30 à US-36), §6.2 à §6.4, §8.5, §9.8, §10.3, §11.2, §11.6.
- [Index des ADR](README.md) — ADR-0002 (compteurs et idempotence Redis), ADR-0004 (clés de licence), ADR-0006 (couches et transactions).
- [Conventions des diagrammes UML](../uml/README.md) — `seq-paiement`, `etats-commande`.
