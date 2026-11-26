<?php

declare(strict_types=1);

namespace App\Service\Commande;

use App\Exception\CommandeIntrouvableException;
use App\Exception\CompteNonVerifieException;
use App\Exception\MajoriteNonConfirmeeException;
use App\Exception\OffreIndisponibleException;
use App\Exception\PanierModifieException;
use App\Exception\PanierVideException;
use App\Service\Auth\Compte;
use App\Service\Catalogue\CatalogueCache;
use App\Service\Catalogue\Langue;
use App\Service\Panier\LignePanier;
use App\Service\Panier\PanierId;
use App\Service\Panier\PanierService;
use App\Service\Reservation\MinuteurReservation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Passage et suivi de commande (US-30, US-31, US-33, US-35). Couche métier.
 *
 * Règles appliquées avant toute écriture : compte vérifié (RG-10), panier non vide et identique à ce que
 * le client a vu (sinon il doit le revoir), majorité déclarée pour un jeu PEGI 18 (RG-13). Le prix et le
 * montant sont relus en base dans la transaction (RG-05, RG-11) : le client n'envoie que son panier.
 *
 * La base est la référence : commande, lignes et réservation des clés sont validées ensemble ou pas du
 * tout. Les effets dans Redis (minuteur, panier, cache) viennent après la validation : s'ils échouent,
 * la commande reste valable et l'erreur est seulement journalisée.
 */
final class CommandeService
{
    public const HISTORIQUE_MAX = 50;

    public function __construct(
        private readonly Commandes $commandes,
        private readonly PanierService $paniers,
        private readonly MinuteurReservation $minuteur,
        private readonly CatalogueCache $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function passer(Compte $compte, ?PanierId $panierId, bool $confirmeMajeur, Langue $langue): CommandeDetail
    {
        if (!$compte->emailVerifie) {
            throw new CompteNonVerifieException();
        }
        $panier = null === $panierId ? null : $this->paniers->consulter($panierId);
        if (null === $panier || [] === $panier->lignes) {
            // Un panier vidé par la relecture (offres retirées) doit être revu, pas annoncé « vide »
            throw null !== $panier && [] !== $panier->offresRetirees ? new PanierModifieException() : new PanierVideException();
        }
        $ajuste = [] !== array_filter($panier->lignes, static fn (LignePanier $l): bool => $l->quantiteAjustee);
        if ($ajuste || [] !== $panier->offresRetirees) {
            throw new PanierModifieException();
        }

        $lignes = array_map(static fn (LignePanier $l): LigneACommander => new LigneACommander($l->offre->offreId, $l->quantite), $panier->lignes);
        $offres = $this->commandes->offres(array_map(static fn (LigneACommander $l): string => $l->offreId, $lignes));
        foreach ($lignes as $ligne) {
            $offre = $offres[$ligne->offreId] ?? null;
            if (null === $offre || !$offre->active) {
                throw new OffreIndisponibleException();
            }
            if ($offre->exigeMajorite() && !$confirmeMajeur) {
                throw new MajoriteNonConfirmeeException();
            }
        }

        $creee = $this->commandes->creer($compte->id, $langue, $confirmeMajeur, $lignes, MinuteurReservation::DUREE_SECONDES);

        $this->apresValidation('démarrage du minuteur', fn () => $this->minuteur->demarrer($creee->id, $creee->expireLe));
        $this->apresValidation('vidage du panier', fn () => $this->paniers->vider($panierId));
        $this->invaliderCatalogue($creee->slugs);

        return $this->consulter($creee->reference, $compte->id);
    }

    public function consulter(string $reference, string $utilisateurId): CommandeDetail
    {
        $commande = $this->commandes->detail($reference, $utilisateurId);
        if (null === $commande) {
            throw new CommandeIntrouvableException();
        }
        if (!$commande->enAttenteDePaiement()) {
            return $commande;
        }
        // Minuteur Redis pour l'affichage ; à défaut, l'échéance en base fait foi
        $secondes = null;
        $this->apresValidation('lecture du minuteur', function () use ($commande, &$secondes): void {
            $secondes = $this->minuteur->secondesRestantes($commande->id);
        });
        $secondes ??= max(0, $commande->expireLe->getTimestamp() - time());

        return $commande->avecSecondesRestantes($secondes);
    }

    /** @return list<CommandeResume> */
    public function lister(string $utilisateurId): array
    {
        return $this->commandes->lister($utilisateurId, self::HISTORIQUE_MAX);
    }

    public function annuler(string $reference, string $utilisateurId): CommandeDetail
    {
        $annulee = $this->commandes->annuler($reference, $utilisateurId);

        $this->apresValidation('arrêt du minuteur', fn () => $this->minuteur->arreter($annulee->id));
        $this->invaliderCatalogue($annulee->slugs);

        return $this->consulter($reference, $utilisateurId);
    }

    /** @param list<string> $slugs */
    private function invaliderCatalogue(array $slugs): void
    {
        // Le stock disponible figure dans les fiches et les listes du catalogue (F-01)
        foreach (array_unique($slugs) as $slug) {
            $this->cache->invaliderJeu($slug);
        }
        $this->cache->invaliderListes();
    }

    private function apresValidation(string $etape, callable $effet): void
    {
        try {
            $effet();
        } catch (\RedisException $e) {
            $this->logger->warning('Commande : {etape} impossible, Redis indisponible ({message})', ['etape' => $etape, 'message' => $e->getMessage()]);
        }
    }
}
