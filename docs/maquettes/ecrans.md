# SideKey — Inventaire des écrans et revue persona

> Issue **C-02**. Source : cahier des charges v1.2, §3 (personas), §5 (user stories), §7 (écrans), §11.5 (RBAC), §12 (RGAA, éco-conception, multilingue).
> Maquettes : fichier Figma [« SideKey — Maquettes »](https://www.figma.com/design/NPqan8uaxM4EM3PyAtf405) (dossier MSP). Pages : **Composants**, **Front-office** (E01–E10), **Back-office** (E11–E16). Enchaînement : [enchainement-ecrans.svg](enchainement-ecrans.svg) (source PlantUML : [enchainement-ecrans.puml](enchainement-ecrans.puml)).

## 1. Règles communes à tous les écrans

| Règle | Détail | Justification |
|---|---|---|
| Bilingue FR / EN | Sélecteur de langue dans l'en-tête de **chaque** écran, choix mémorisé ; aucun texte en dur ; `lang` de la page mis à jour | BX-05, BX-06 (Marcus, P2) |
| Mobile d'abord | Maquettes mobile (375 px) pour E02, E03, E04, E07 ; desktop (1440 px) pour tous | BX-01 (Léa, P1) |
| Contrastes AA | Texte normal ≥ 4,5:1, gros texte et composants ≥ 3:1 ; l'information n'est jamais portée par la couleur seule | BX-08 (Sophie, P3), RGAA 3.2 / 3.3 |
| Clavier et lecteur d'écran | Ordre de tabulation logique, focus visible (contour 2 px), lien « Aller au contenu », titres hiérarchisés, landmarks (`header`, `nav`, `main`, `footer`) | BX-08, RGAA 7, 9, 12 |
| Formulaires | Label visible sur chaque champ, erreur affichée sous le champ et reliée (`aria-describedby`), résumé des erreurs en haut | BX-10 (Sophie, P3), RGAA 11 |
| Confiance | Pied de page : sécurité, hébergement UE, mentions légales, données personnelles | BX-03 (Léa), BX-17 (Nathalie, P5) |
| Éco-conception | Images WebP/AVIF en différé, une seule police web, pas de vidéo auto, pagination | RGESN, cible EcoIndex B |
| Back-office | Accès seulement après connexion + **A2F** ; menu filtré selon le rôle (§11.5) | BX-18 (Nathalie), RBAC |

## 2. Front-office

| Code | Écran | Acteurs | Personas | Besoins | User stories | Contenu clé | Points RGAA / éco |
|---|---|---|---|---|---|---|---|
| **E01** | Accueil | Tous | P1, P2, P3 | BX-01, BX-03, BX-06 | US-01, US-03 | Recherche, nouveautés, meilleures ventes, réassurance (clé immédiate, aucune clé en double) | Une seule image « héros » légère ; recherche avec label |
| **E02** | Catalogue | Tous | P1, P3 | BX-01, BX-08, BX-09 | US-01, US-02, US-03, US-05 | Grille paginée (20), filtres plateforme / genre / prix / PEGI reflétés dans l'URL, tri, nombre de résultats, message si aucun résultat | Filtres en `fieldset` + `legend` ; résultat annoncé (`aria-live`) ; jaquettes en différé |
| **E03** | Fiche jeu | Tous | P1, P3 | BX-03, BX-09 | US-04, US-10, US-40 | Jaquette, description FR/EN, éditeur, **PEGI** bien visible, offres par plateforme avec prix et stock, note moyenne, avis validés, « Ajouter au panier » | PEGI en texte, pas seulement en pictogramme ; stock en texte (« 12 clés disponibles ») |
| **E04** | Panier | Tous | P1 | BX-03, BX-04 | US-10, US-11, US-12, US-13 | Lignes (offre, quantité, prix), total calculé côté serveur, limites anti-fraude expliquées (5 clés par offre), « Commander » | Boutons quantité avec nom accessible ; total annoncé à chaque changement |
| **E05** | Connexion / inscription / A2F | Visiteur | P1, P2, P3 | BX-03, BX-07, BX-10 | US-20, US-21, US-22, US-23 | Onglets connexion / inscription, règles du mot de passe affichées, consentement, saisie du code A2F, mot de passe oublié | `autocomplete` correct ; erreurs reliées aux champs ; pas de limite de temps sur la saisie |
| **E06** | Tunnel 1 : récapitulatif | Client | P1 | BX-03, BX-04 | US-30, US-31 | Étape 1/3, articles, total, compte à rebours de réservation **15 min** (RG-12), confirmation de majorité si **PEGI 18** (RG-13) | Indicateur d'étapes lisible au lecteur d'écran ; compte à rebours annoncé avec parcimonie |
| **E07** | Tunnel 2 : paiement simulé | Client | P1, P3 | BX-04, BX-10 | US-32, US-36 | Étape 2/3, bandeau **« Paiement fictif — projet de formation »**, formulaire carte, cartes de test, message de refus et nouvel essai | Bandeau en texte ; champs carte avec labels et format attendu |
| **E08** | Tunnel 3 : confirmation | Client | P1, P2 | BX-02, BX-05 | US-33 | Étape 3/3, n° de commande, statut, accès direct aux clés, e-mail envoyé dans la langue du client | Message de succès en tête, focus placé dessus |
| **E09** | Mes commandes et mes clés | Client | P1 | BX-02, BX-16 | US-34, US-35, US-40 | Historique, détail, statut, « Révéler la clé » (masquée par défaut), copier, « Donner un avis » | Clé lisible en police à chasse fixe ; bouton « Copier » avec retour annoncé |
| **E10** | Profil, langue, sécurité, RGPD | Client | P2, P5 | BX-06, BX-07, BX-17 | US-22, US-24, US-25 | Profil, langue préférée, activation A2F (QR + codes de secours), export JSON, suppression du compte | Alternative textuelle au QR code (clé de configuration) |

## 3. Back-office

| Code | Écran | Rôles (§11.5) | Personas | Besoins | User stories | Contenu clé | Points RGAA / sécurité |
|---|---|---|---|---|---|---|---|
| **E11** | Tableau de bord (C) | Gestionnaire, Admin | P4, P5 | BX-12 | US-58 | CA, commandes du jour, offres en stock bas, commandes à vérifier | Chiffres en texte, graphiques avec tableau équivalent |
| **E12** | Jeux et offres | Gestionnaire, Admin | P4 | BX-12, BX-14 | US-50, US-51, US-53 | Liste des jeux, formulaire FR/EN, offres jeu × plateforme, prix, seuil de stock bas, message de conflit **409** si un collègue a modifié la fiche | Tableaux avec en-têtes ; message de conflit explicite avec action proposée |
| **E13** | Import de clés | Gestionnaire, Admin | P4 | BX-11, BX-13 | US-52 | Choix de l'offre, dépôt du CSV (≤ 1 000 lignes), rapport acceptées / doublons / erreurs ; **aucune clé affichée en clair** | Zone de dépôt aussi utilisable au clavier (bouton « Parcourir ») |
| **E14** | Commandes et file « à vérifier » | Gestionnaire (lecture), Admin | P4, P5 | BX-12, BX-15 | US-54, US-56 | Liste filtrable par statut, détail et historique, file « à vérifier » avec motif anti-fraude, valider / annuler (admin seulement) | Confirmation avant action irréversible |
| **E15** | Modération des avis | Modérateur, Admin | P6 | BX-20 | US-42 | File d'avis en attente, publier / refuser avec **motif obligatoire** ; aucun accès aux commandes ni aux clés | Raccourcis clavier documentés |
| **E16** | Utilisateurs, rôles, audit | Admin | P5 | BX-16, BX-18 | US-55, US-57 | Comptes, rôles, état de l'A2F, journal d'audit filtrable en lecture seule | Journal sans clé en clair ; filtres avec labels |

## 4. Couverture des besoins

Tous les besoins utilisateurs du §3 sont couverts par au moins un écran. BX-19 (restauration) et BX-21 à BX-24 (Hugo, P7, persona technique) relèvent de l'exploitation et du DevOps : ils n'ont pas d'écran.

| Persona | Besoins couverts par un écran |
|---|---|
| P1 Léa | BX-01 (E01, E02), BX-02 (E08, E09), BX-03 (E01, E03, E04, E05), BX-04 (E04, E06, E07) |
| P2 Marcus | BX-05 (tous, E08), BX-06 (tous, E10), BX-07 (E05, E10) |
| P3 Sophie | BX-08 (tous, E02), BX-09 (E02, E03), BX-10 (E05, E07) |
| P4 Karim | BX-11 (E13), BX-12 (E11, E12, E14), BX-13 (E13), BX-14 (E12) |
| P5 Nathalie | BX-15 (E14), BX-16 (E09, E16), BX-17 (E10, pied de page), BX-18 (E16, connexion back-office) |
| P6 Thomas | BX-20 (E15) |

## 5. Revue persona (à faire à deux sur les maquettes)

Parcourir les maquettes avec le scénario de chaque persona et cocher.

- [ ] **Léa (P1), mobile** : accueil → recherche d'un jeu Steam → fiche → panier → connexion → récapitulatif → paiement refusé puis accepté → révélation de la clé.
- [ ] **Marcus (P2)** : passe en anglais depuis l'accueil, le choix est conservé sur tous les écrans, y compris le tunnel et la confirmation ; active l'A2F dans son profil.
- [ ] **Sophie (P3), clavier seul** : filtre le catalogue par PEGI 7, ouvre une fiche, voit le PEGI et la plateforme en texte, ajoute au panier, comprend une erreur de saisie.
- [ ] **Karim (P4)** : importe un CSV avec doublons et lit le rapport ; modifie un prix pendant qu'un collègue édite la même offre (message 409).
- [ ] **Nathalie (P5)** : se connecte avec A2F, traite une commande « à vérifier », consulte le journal d'audit.
- [ ] **Thomas (P6)** : refuse un avis avec motif ; vérifie qu'il ne voit ni commandes ni clés.

## 6. Version anglaise et mobile

La déclinaison **EN** porte sur le parcours d'achat complet (E01 → E08), qui est aussi celui de la démonstration en anglais (décision F10).

## 7. Avancement des maquettes Figma

| Livrable | État |
|---|---|
| Composants SideKey (en-tête FR/EN, pied de page FR/EN, carte jeu, badge PEGI, étapes du tunnel, bandeau paiement fictif FR/EN, menu admin) sur les tokens du Simple Design System | ✅ fait |
| E01–E10 desktop FR | ✅ fait |
| E11–E16 desktop FR | ✅ fait |
| Mobile 375 px : E02, E03, E04, E07 | ⏳ à faire |
| Parcours EN E01 → E08 (dupliquer les écrans FR, passer l'en-tête, le pied de page et le bandeau en variante `Langue=EN`, traduire les textes) | ⏳ à faire |
| Revue persona (§5) | ⏳ à faire à deux |

Choix de conception :
- Les composants SDS (bouton, champ, liste déroulante, case à cocher, recherche) et ses tokens de couleur et de typographie (Inter) sont réutilisés, pour avoir des contrastes déjà vérifiés et rester cohérent.
- Le plan Figma Starter limite le fichier à 3 pages : la version mobile et la version EN iront dans des sections de la page Front-office.
