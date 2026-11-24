# SideKey — Spécification des maquettes mobiles (375 px)

> Issue **C-02**. Écrans retenus pour le mobile : **E02, E03, E04, E07**, le parcours de Léa (P1) sur téléphone avec une connexion moyenne (BX-01).
> Dans Figma : page **Front-office**, section « Mobile FR », cadres de **375 × auto**, marges latérales **16 px**, espacement vertical **16–24 px**. Mêmes composants et tokens que le desktop.

## Règles communes

| Règle | Détail | Justification |
|---|---|---|
| En-tête compact | Logo, bouton menu (☰, nom accessible « Menu »), bouton recherche, panier avec compteur, sélecteur **FR \| EN** dans le menu | BX-05, BX-06 |
| Zones tactiles | 44 × 44 px minimum pour tout élément cliquable | RGAA 13.x / WCAG 2.5.8 |
| Une colonne | Pas de défilement horizontal à 320 px de large | RGAA 10.11 (reflow) |
| Bouton d'action principal | En bas de l'écran, pleine largeur, toujours visible | BX-04 (tunnel court) |
| Poids | Jaquettes 160 px de large en WebP, chargement différé ; pas d'image décorative | RGESN, page < 1 Mo |

## E02 — Catalogue (mobile)

1. En-tête compact.
2. Titre « Catalogue » + « 128 jeux trouvés ».
3. Ligne de 2 boutons : **Filtres (1)** (ouvre un panneau plein écran) et **Trier** (liste déroulante).
4. Liste de cartes jeu en **une colonne** (jaquette à gauche 96 px, texte à droite) : titre, plateformes, prix, badge PEGI, stock en texte.
5. Pagination simplifiée : « ‹ Précédent · Page 1 sur 7 · Suivant › ».

Panneau Filtres (plein écran) : mêmes `fieldset` que le desktop, bouton « Voir 42 résultats » en bas, bouton « Fermer » en haut ; le focus revient sur « Filtres » à la fermeture.

## E03 — Fiche jeu (mobile)

1. Fil d'Ariane réduit : « ‹ Catalogue ».
2. Jaquette pleine largeur (ratio 3:4, hauteur max. 360 px).
3. Titre, éditeur, **badge PEGI + descripteurs en texte**, note moyenne.
4. Offres en liste : plateforme, prix, stock ; choix par boutons radio.
5. Barre fixe en bas : prix de l'offre choisie + **Ajouter au panier** (pleine largeur).
6. Description repliable (« Lire la suite »), puis avis.

## E04 — Panier (mobile)

1. Titre « Mon panier (3) ».
2. Lignes empilées : vignette, titre, plateforme, quantité − 1 + (boutons 44 px, noms « Diminuer » / « Augmenter »), prix, lien « Retirer ».
3. Encadré des limites anti-fraude (RG-14).
4. Récapitulatif : sous-total, total TTC.
5. Barre fixe en bas : total + **Commander**.

## E07 — Paiement simulé (mobile)

1. Bandeau **« Paiement fictif — projet de formation »** sous l'en-tête.
2. Étapes du tunnel réduites : « Étape 2 sur 3 — Paiement ».
3. Message d'erreur (si refus) en haut, relié au formulaire.
4. Champs empilés : nom, numéro de carte (`inputmode="numeric"`, `autocomplete="cc-number"`), expiration, cryptogramme.
5. Cartes de test repliables (« Voir les cartes de test »).
6. Barre fixe en bas : **Payer 109,96 €**.
