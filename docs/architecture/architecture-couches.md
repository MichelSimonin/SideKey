# Architecture en couches

Vue d'ensemble : [`archi-couches.puml`](../uml/src/archi-couches.puml)
([PNG](../uml/exports/archi-couches.png) · [SVG](../uml/exports/archi-couches.svg)).
Décision : [ADR-0006](../adr/0006-architecture-en-couches.md). Référence :
`docs/cahier_des_charges_v1.2_SideKey.md` §8.1 et §8.2.

L'application est une **SPA React** qui consomme une **API REST Symfony**. L'API est
elle-même découpée en trois couches dont les dépendances ne vont que dans un sens :
**présentation → métier → accès aux données → SGBD**. Aucune couche ne connaît celle
qui l'appelle.

```text
Navigateur (SPA React) ──HTTPS/JSON + JWT──▶ Proxy ──▶ Présentation ──▶ Métier ──▶ Données ──▶ PostgreSQL
                                                                              └──▶ Redis
```

---

## 1. Responsabilités par couche

| Couche | Dossier | Responsabilités | Ce qu'elle ne fait **pas** | Contrôles de sécurité |
|---|---|---|---|---|
| **SPA React** | `front/src/` | Affichage, routage, i18n FR/EN, accessibilité, appels HTTP | Aucun calcul de prix, aucune règle métier, aucun secret | Échappement JSX natif ; jeton d'accès **en mémoire** ; aucun secret dans le bundle |
| **Proxy** | `docker/nginx/`, Caddy en recette | TLS, HSTS, CSP, limitation de taille des requêtes | Aucune logique applicative | HTTPS forcé, en-têtes de sécurité |
| **Présentation** | `api/src/Controller/Api`, `Controller/Admin` | Routes, désérialisation, validation des DTO, codes HTTP, sérialisation des réponses | Aucune règle métier, aucun accès direct à un repository, aucune requête SQL | Authentification JWT, validation (`Symfony Validator`), `RateLimiter`, gestionnaire d'erreurs sans fuite technique |
| **Métier** | `api/src/Service`, `Security/Voter` | Règles de gestion §6, transactions, anti-fraude, autorisations, audit | Aucune connaissance de HTTP (pas de `Request`, pas de code de statut) | Voters (RBAC + propriété anti-IDOR), transactions, journal d'audit |
| **Accès aux données** | `api/src/Repository`, `Infrastructure/Redis`, `Infrastructure/Crypto` | Requêtes SQL paramétrées, PL/pgSQL, Redis, chiffrement / déchiffrement | Aucune règle métier, aucune décision d'autorisation | Requêtes préparées, compte SGBD à moindre privilège, AES-GCM + HMAC, clés Redis construites depuis des identifiants validés |

### 1.1 Règles de dépendance vérifiées en revue

1. Un contrôleur **n'injecte jamais** un `EntityManager` ni un repository : il appelle un
   service. *(Exception documentée : les contrôleurs de lecture du catalogue appellent un
   `CatalogueService`, jamais `JeuRepository` directement.)*
2. Un service **ne reçoit jamais** d'objet HTTP (`Request`, `Response`) : il reçoit des
   DTO ou des entités.
3. Une entité Doctrine **n'est jamais sérialisée** : chaque réponse passe par un DTO de
   sortie (`Dto/Response`). Cela évite de fuiter `motDePasseHash`, `totpSecretChiffre` ou
   `cleChiffree`.
4. Une transaction est ouverte **dans la couche métier** uniquement, jamais dans un
   contrôleur ni dans un repository.
5. La couche d'accès aux données ne prend **aucune décision d'autorisation** : elle
   exécute une requête déjà autorisée par le Voter appelé dans le service.

---

## 2. Ce que contient chaque dossier

```text
api/src/
├── Controller/Api/          routes publiques et client (JSON, JWT)
├── Controller/Admin/        routes du back-office (rôles + A2F obligatoire)
├── Dto/Request/             contrats d'entrée validés (jamais des entités)
├── Dto/Response/            contrats de sortie (jamais des entités)
├── Service/                 règles de gestion, transactions, orchestration
├── Repository/              accès SQL (Doctrine + PL/pgSQL), requêtes paramétrées
├── Entity/  Enum/           modèle de domaine
├── Infrastructure/Redis/    panier, réservations, révocation, compteurs, cache
├── Infrastructure/Crypto/   AES-256-GCM (clés) et HMAC-SHA256 (empreintes)
├── Infrastructure/Payment/  PSP simulé derrière PaymentProviderInterface
├── Security/                utilisateur, fournisseur, permissions (matrice RBAC), jetons JWT, cookies et CSRF
├── Security/Voter/          autorisations (rôle + propriété)
├── EventSubscriber/         en-têtes de sécurité, exceptions JSON
└── Exception/               exceptions métier traduites en codes HTTP
```

---

## 3. Cycle de vie d'une requête

### 3.1 Lecture `GET /api/jeux/{slug}` (cache Redis)

1. **Proxy** : TLS, en-tête `Accept-Language` transmis.
2. **Présentation** : `JeuController::fiche(string $slug)`, paramètre `slug` contraint par la
   route puis revérifié par le service ; route publique.
3. **Métier** : `CatalogueService::fiche($slug, Langue $langue)`.
4. **Données** : lecture de `cache:jeu:{slug}:{langue}` dans Redis (TTL 5 min) ; en cas de
   défaut, `JeuRepository` interroge PostgreSQL puis le service alimente le cache.
5. **Réponse** : `JeuFicheResponse` (DTO), `Cache-Control` adapté ; aucune entité exposée.

### 3.2 Écriture `POST /api/commandes` (transaction)

1. **Présentation** : `CommandeController::create`, JWT obligatoire, DTO
   `CreerCommandeRequest` validé (offre, quantité 1..5).
2. **Métier** : `CommandeService::creer()` ouvre la transaction, appelle
   `AntiFraudeService`, puis `ReservationService` qui exécute `fn_reserver_cles`
   (`FOR UPDATE SKIP LOCKED`), et enfin journalise.
3. **Données** : `CleLicenceRepository` et `CommandeRepository` écrivent dans la même
   transaction ; Redis reçoit `resa:{commandeId}` **après** le commit.
4. **Réponse** : `201 Created` + `reference`, ou `409` si le stock est insuffisant.

Le détail concurrent est dans [`seq-achat-concurrent`](../uml/src/seq-achat-concurrent.puml).

---

## 4. Contrats et erreurs

| Situation | Code | Corps | Journalisation |
|---|---|---|---|
| Succès | 200 / 201 / 204 | DTO de sortie | non |
| Validation (champ invalide) | 422 | `{ "erreurs": { "champ": "message" } }` | non |
| Authentification absente ou expirée | 401 | message générique | oui (compteur) |
| Autorisation refusée (rôle, propriété, A2F) | 403 | message générique (pas d'énumération) | oui |
| Ressource d'un autre client | 404 | « introuvable » | oui (IDOR tenté) |
| Conflit métier (stock épuisé, verrou optimiste) | 409 | message fonctionnel | oui |
| Paiement refusé | 402 | message fonctionnel + statut | oui |
| Limitation de débit | 429 | `Retry-After` | oui |
| Erreur interne | 500 | `{ "message": "erreur interne", "correlationId": … }` | oui, avec trace **uniquement** côté serveur |

`EventSubscriber/ApiExceptionSubscriber` traduit les exceptions métier
(`ExceptionMetier` : `StockInsuffisantException`, `IdentifiantsInvalidesException`,
`SessionInvalideException`, `TropDeTentativesException`…) en codes HTTP, messages traduits selon
`Accept-Language` et, quand l'erreur en porte, en-têtes (`Retry-After` d'un 429) : aucun
contrôleur ne construit un code d'erreur à la main, aucune trace technique ne quitte le serveur.
Les échecs d'authentification JWT (absent, falsifié, expiré, révoqué) passent par
`Security/Jwt/JwtSubscriber` et produisent le même 401 générique.

---

## 5. Front React

| Dossier | Contenu |
|---|---|
| `front/src/api/` | client HTTP : jeton d'accès **en mémoire**, en-tête `Authorization`, rafraîchissement silencieux |
| `front/src/features/` | un dossier par feature : `auth`, `catalogue`, `panier`, `commande`, `compte`, `admin` |
| `front/src/components/ui` | composants accessibles (React Aria ou équivalent) : modales, formulaires, focus |
| `front/src/i18n/` | `locales/fr` et `locales/en`, aucune chaîne en dur |
| `front/src/routes/`, `pages/` | routage et écrans E01 à E16 |

Le front ne contient **aucune règle de gestion** : le total du panier affiché est celui
retourné par l'API (`US-13`), jamais un calcul local.

---

## 6. Correspondance avec le REAC

| Critère de la compétence 6 | Où il se démontre |
|---|---|
| Architecture multicouche répartie sécurisée | Ce document, `archi-couches`, §1 à §3 |
| Rôle de chaque couche selon la stratégie de sécurité | §1 (colonne « contrôles de sécurité ») et `docs/securite/dicp.md` |
| Frameworks et ORM identifiés et justifiés | `docs/adr/0001`, `docs/adr/0002` |
| Design patterns et security patterns | `docs/securite/security-patterns.md` |
| DICP et recommandations ANSSI | `docs/securite/dicp.md` |

## 7. Limites assumées en v1

- Pas de CQRS ni de bus d'événements : un service, une transaction.
- Le worker Messenger traite l'import lourd et les e-mails ; **pas** de saga distribuée.
- Le cache Redis du catalogue (F-01) est **invalidé explicitement par toute écriture du
  catalogue** et, en filet de sécurité, par son TTL de 5 min (voir §7.1). Tant qu'une écriture
  n'appelle pas `CatalogueCache`, un prix modifié peut mettre jusqu'à 5 minutes à apparaître.
- La disponibilité affichée dans le catalogue est **indicative** (au plus 5 min de retard) :
  la vérité est relue en base à l'ajout au panier et à la réservation (RG-02, RG-04).

### 7.1 Cache du catalogue : clés, invalidation, panne

| Clé | Contenu | TTL |
|---|---|---|
| `cache:jeu:{slug}:{langue}` | fiche d'un jeu (JSON) | 5 min |
| `cache:jeux:v{n}:{langue}:{empreinte}` | une liste filtrée ; `{empreinte}` = SHA-256 des critères normalisés | 5 min |
| `cache:filtres:v{n}:{langue}` | plateformes, genres et fourchette de prix des filtres | 5 min |
| `cache:jeux:version` | compteur `{n}` incrémenté par `invaliderListes()` | aucun |

- **Qui invalide.** `Service/Catalogue/CatalogueCache` expose `invaliderJeu(slug)` (la fiche, dans
  les deux langues) et `invaliderListes()` (toutes les listes et les valeurs de filtres, en O(1)
  par changement de version ; les anciennes entrées expirent seules). **Toute écriture qui modifie
  le catalogue appelle les deux** : jeu, traduction, genre, offre ou prix (F-09), import de clés
  (F-08), réservation et vente de clés (F-06), modération d'un avis (note moyenne). Le
  back-office F-09 s'y branche ; le TTL n'est qu'un filet.
- **Pourquoi une version.** Supprimer « toutes les listes » demanderait un `SCAN` ou un index de
  clés. Une version dans la clé rend les anciennes listes inatteignables sans rien supprimer.
- **Panne de Redis.** Le cache n'est qu'une optimisation de données publiques : en cas
  d'indisponibilité, les lectures retombent sur PostgreSQL et l'incident est journalisé. Contraste
  voulu avec l'authentification (§4, ADR-0003), où l'indisponibilité de Redis *refuse*.
- **Ce qui n'est jamais mis en cache.** Les 404 (un client ne peut pas inonder Redis de clés) et
  toute valeur non validée : seuls un slug conforme, la langue de l'énumération et une empreinte
  entrent dans une clé.
