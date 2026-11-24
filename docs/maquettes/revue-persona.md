# SideKey — Grille de revue persona des maquettes

> Issue **C-02**, critère « chaque écran est relié aux personas qui le justifient ». À faire **à deux**, sur le fichier Figma.
> Méthode : un membre joue le persona et déroule le scénario écran par écran ; l'autre note. Pour chaque étape : le besoin est-il couvert ? Sinon, quelle correction ?
> Statut : ✅ OK · ⚠️ à corriger · ❌ manquant.

Date de la revue : ____ · Participants : ____ · Version Figma : ____

## P1 — Léa, mobile (BX-01 à BX-04)

| # | Étape | Écran | Attendu | Statut | Remarque / correction |
|---|---|---|---|---|---|
| 1 | Cherche « hollow » depuis l'accueil | E01 → E02 | Résultat trouvé, nombre de résultats affiché | | |
| 2 | Filtre « PC » | E02 mobile | Panneau filtres utilisable au pouce, retour à la liste | | |
| 3 | Ouvre la fiche, choisit l'offre Steam | E03 mobile | Prix, stock et PEGI lisibles sans zoom | | |
| 4 | Ajoute au panier, commande | E04 mobile | Total clair, bouton Commander toujours visible | | |
| 5 | Se connecte | E05 | Retour direct au tunnel après connexion | | |
| 6 | Voit la réservation de 15 min | E06 | Compte à rebours compréhensible | | |
| 7 | Paiement refusé puis accepté | E07 mobile | Erreur claire, clés toujours réservées, nouvel essai possible | | |
| 8 | Révèle sa clé | E08 → E09 | Clé visible en 1 action, bouton Copier | | |

## P2 — Marcus, anglophone (BX-05 à BX-07)

| # | Étape | Écran | Attendu | Statut | Remarque / correction |
|---|---|---|---|---|---|
| 1 | Passe en EN depuis l'accueil | E01 EN | Sélecteur visible, langue courante indiquée sans la couleur seule | | |
| 2 | Parcourt E01 → E08 en EN | E01–E08 EN | Aucun texte resté en français, prix au format €39.99 | | |
| 3 | Active l'A2F | E10 | QR code + clé texte, codes de secours | | |

## P3 — Sophie, clavier et lecteur d'écran (BX-08 à BX-10)

| # | Étape | Écran | Attendu | Statut | Remarque / correction |
|---|---|---|---|---|---|
| 1 | Atteint le contenu au clavier | Tous | Lien « Aller au contenu », ordre de tabulation logique, focus visible | | |
| 2 | Filtre PEGI 7 | E02 | Filtres en `fieldset` + `legend`, résultat annoncé | | |
| 3 | Vérifie l'âge et la plateforme | E03 | PEGI et plateforme écrits en texte | | |
| 4 | Fait une erreur de saisie | E05 / E07 | Erreur sous le champ, liée par `aria-describedby`, résumé en haut | | |
| 5 | Vérifie les contrastes | Tous | Texte ≥ 4,5:1, composants ≥ 3:1 (mesure avec le plugin Figma ou l'outil Contrast) | | |

## P4 — Karim, gestionnaire (BX-11 à BX-14)

| # | Étape | Écran | Attendu | Statut | Remarque / correction |
|---|---|---|---|---|---|
| 1 | Importe un CSV avec doublons | E13 | Rapport acceptées / doublons / erreurs, aucune clé en clair | | |
| 2 | Modifie un prix en même temps qu'un collègue | E12 | Message 409 compréhensible, action proposée | | |
| 3 | Repère un stock bas | E11 / E12 | Seuil visible et modifiable | | |

## P5 — Nathalie, administratrice (BX-15 à BX-19)

| # | Étape | Écran | Attendu | Statut | Remarque / correction |
|---|---|---|---|---|---|
| 1 | Se connecte au back-office | Connexion + A2F | A2F obligatoire | | |
| 2 | Traite une commande « à vérifier » | E14 | Motif anti-fraude explicite, confirmation avant action | | |
| 3 | Consulte le journal d'audit | E16 | Filtres, lecture seule, aucune clé en clair | | |

## P6 — Thomas, modérateur (BX-20)

| # | Étape | Écran | Attendu | Statut | Remarque / correction |
|---|---|---|---|---|---|
| 1 | Refuse un avis | E15 | Motif obligatoire | | |
| 2 | Vérifie ses accès | E15 | Ni commandes ni clés visibles (menu filtré) | | |

## Synthèse

| Correction à faire | Écran | Responsable | Faite |
|---|---|---|---|
| | | | |
