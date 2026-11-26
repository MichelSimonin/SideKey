<?php

declare(strict_types=1);

namespace App\Service\Paiement;

use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonPayableException;
use App\Exception\IdempotenceConflitException;
use App\Exception\LimiteClesException;
use App\Exception\PaiementBloqueException;
use App\Exception\PaiementEnCoursException;
use App\Exception\PaiementIndisponibleException;
use App\Exception\PaiementRefuseException;
use App\Service\Audit\JournalAudit;
use App\Service\Auth\Compte;
use App\Service\Auth\LimiteurDebit;
use App\Service\Commande\CommandeService;
use App\Service\Reservation\MinuteurReservation;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Paiement d'une commande (US-32, US-33, US-36) et anti-fraude « Équilibré » (RG-14). Couche métier.
 *
 * Déroulé d'un paiement, dans cet ordre :
 *   1. format de la clé d'idempotence et de la carte (422, sans rien compter) ;
 *   2. REJEU : une clé déjà enregistrée renvoie la même réponse, sans rappeler le prestataire ;
 *   3. contrôles avant tout débit : limitation de débit, blocage après échecs, commande à payer et non expirante,
 *      plafond de clés sur 24 h glissantes. Redis injoignable : refus (Fail Securely) ;
 *   4. réservation de la clé (Redis) puis ouverture d'une tentative EN_ATTENTE (base) : l'index unique empêche deux
 *      paiements simultanés de la même commande d'atteindre le prestataire ;
 *   5. appel du prestataire HORS transaction, aucun verrou de base n'étant tenu pendant un appel externe ;
 *   6. issue : accepté → PAYEE, ou « à vérifier » (règle anti-fraude), clés vendues ou conservées, en UNE transaction ;
 *      refusé ou en erreur → échec compté, blocage au 3e, commande et réservation inchangées (réessai possible, US-36).
 *
 * Le montant n'est jamais lu dans la requête : il vient de la commande en base (RG-11). La carte n'est ni stockée, ni
 * journalisée : seuls les quatre derniers chiffres et le code de retour sont conservés.
 */
final class PaiementService
{
    /** Une commande qui expire dans moins de 30 s n'est plus payée : la validation échouerait après le débit. */
    public const MARGE_EXPIRATION_SECONDES = 30;
    /** Tentatives de paiement par client et par heure (CDC §9.8 : rl:pay:{userId}), tous résultats confondus. */
    public const TENTATIVES_PAR_HEURE = 10;

    public function __construct(
        private readonly Paiements $paiements,
        private readonly FournisseurPaiement $fournisseur,
        private readonly CompteursFraude $compteurs,
        private readonly Idempotence $idempotence,
        private readonly PolitiqueFraude $politique,
        private readonly LimiteurDebit $limiteur,
        private readonly CommandeService $commandes,
        private readonly MinuteurReservation $minuteur,
        private readonly JournalAudit $audit,
        private readonly ClockInterface $horloge,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @throws \App\Exception\PaiementInvalideException    format de la carte ou de la clé
     * @throws CommandeIntrouvableException                inexistante ou d'un autre client (404 identique)
     * @throws CommandeNonPayableException                 plus en attente de paiement, ou sur le point d'expirer
     * @throws PaiementEnCoursException                    un autre paiement de cette commande est en cours
     * @throws IdempotenceConflitException                 clé déjà utilisée pour une autre commande
     * @throws PaiementBloqueException                     trop d'échecs récents
     * @throws LimiteClesException                         plafond de clés sur 24 h
     * @throws PaiementRefuseException                     refusé ou erreur du prestataire
     * @throws PaiementIndisponibleException               compteurs injoignables
     * @throws \App\Exception\TropDeTentativesException    trop de tentatives sur l'heure
     */
    public function payer(
        Compte $compte,
        string $reference,
        #[\SensitiveParameter] string $numeroCarte,
        #[\SensitiveParameter] string $expiration,
        #[\SensitiveParameter] string $cryptogramme,
        ?string $cleBrute,
        ?string $adresseIp,
    ): PaiementEffectue {
        $reference = strtolower($reference);
        $cle = CleIdempotence::depuis($cleBrute, $compte->id);
        $maintenant = $this->horloge->now();
        $carte = CartePaiement::depuis($numeroCarte, $expiration, $cryptogramme, $maintenant);

        $existant = $this->paiements->parCle($cle->empreinte, $compte->id);
        if (null !== $existant) {
            return $this->rejouer($existant, $reference, $compte);
        }

        $contexte = $this->controlerAvantDebit($compte, $reference, $maintenant);

        try {
            $reservee = $this->idempotence->reserver($cle->empreinte);
        } catch (\RedisException $e) {
            throw new PaiementIndisponibleException($e);
        }
        if (!$reservee) {
            throw new PaiementEnCoursException();   // même clé, requête simultanée
        }

        try {
            $ouvert = $this->paiements->ouvrir($reference, $compte->id, $cle->empreinte, self::MARGE_EXPIRATION_SECONDES);
        } catch (\Throwable $e) {
            $this->libererCle($cle);   // rien n'est écrit : la clé reste utilisable
            throw $e;
        }

        $resultat = $this->interroger($contexte, $carte, $cle);
        if (!$resultat->estAccepte()) {
            $this->echouer($compte, $ouvert, $resultat, $carte, $adresseIp);
        }

        return $this->conclure($compte, $contexte, $ouvert, $resultat, $carte, $adresseIp, $maintenant);
    }

    private function controlerAvantDebit(Compte $compte, string $reference, \DateTimeImmutable $maintenant): ContextePaiement
    {
        try {
            $this->limiteur->enregistrerTentative('rl:pay:'.$compte->id, self::TENTATIVES_PAR_HEURE, 3600);
            $blocage = $this->compteurs->secondesDeBlocage($compte->id);
            if (null !== $blocage) {
                throw new PaiementBloqueException($blocage);
            }

            $contexte = $this->paiements->contexte($reference, $compte->id) ?? throw new CommandeIntrouvableException();
            if ('EN_ATTENTE_PAIEMENT' !== $contexte->statut || $contexte->expireLe <= $maintenant->modify('+'.self::MARGE_EXPIRATION_SECONDES.' seconds')) {
                throw new CommandeNonPayableException();
            }

            if ($this->politique->limiteClesAtteinte($this->compteurs->clesAcheteesSur24h($compte->id), $contexte->nombreCles)) {
                throw new LimiteClesException($this->politique->plafondCles());
            }

            return $contexte;
        } catch (\RedisException $e) {
            throw new PaiementIndisponibleException($e);   // Fail Securely : pas de paiement sans contrôle
        }
    }

    /** Appelle le prestataire ; une exception vaut « pas de réponse », jamais « accepté ». */
    private function interroger(ContextePaiement $contexte, CartePaiement $carte, CleIdempotence $cle): ResultatPaiement
    {
        try {
            return $this->fournisseur->autoriser(new DemandeAutorisation($contexte->montantCentimes, $carte, $cle->empreinte));
        } catch (\Throwable $e) {
            // La classe seule : le message d'un prestataire pourrait citer la requête, donc la carte
            $this->logger->error('Paiement : le prestataire a échoué ({classe})', ['classe' => $e::class]);

            return ResultatPaiement::erreur('erreur_fournisseur');
        }
    }

    private function echouer(Compte $compte, PaiementOuvert $ouvert, ResultatPaiement $resultat, CartePaiement $carte, ?string $ip): never
    {
        $this->paiements->terminer($ouvert->paiementId, $resultat->statut, $resultat->codeRetour, $carte->derniersChiffres());
        $this->audit->tracer(JournalAudit::PAIEMENT_REFUSE, 'paiement', $ouvert->paiementId, $compte->id, $ip);

        $restantes = null;
        try {
            $restantes = $this->compteurs->enregistrerEchec($compte->id);
        } catch (\RedisException $e) {
            // Le débit n'a pas eu lieu : on ne peut plus « refuser par précaution », le compteur est seulement perdu
            $this->logger->warning('Paiement : échec non compté, Redis indisponible ({message})', ['message' => $e->getMessage()]);
        }
        if (0 === $restantes) {
            $this->audit->tracer(JournalAudit::PAIEMENT_BLOQUE, 'utilisateur', $compte->id, $compte->id, $ip);
        }

        throw new PaiementRefuseException($resultat->codeRetour, $restantes);
    }

    private function conclure(
        Compte $compte,
        ContextePaiement $contexte,
        PaiementOuvert $ouvert,
        ResultatPaiement $resultat,
        CartePaiement $carte,
        ?string $ip,
        \DateTimeImmutable $maintenant,
    ): PaiementEffectue {
        $suspecte = $this->politique->commandeSuspecte($contexte, $maintenant);
        try {
            $statut = $this->paiements->accepter($ouvert->paiementId, $resultat->codeRetour, $carte->derniersChiffres(), $suspecte, $compte->id);
        } catch (CommandeNonPayableException $e) {
            // Accepté par le prestataire, mais la réservation a expiré pendant l'appel : à rembourser. Le prestataire simulé
            // ne débite rien ; un vrai prestataire exigerait ici une annulation de l'autorisation.
            $this->paiements->terminer($ouvert->paiementId, 'ERREUR', 'accepte_non_valide', $carte->derniersChiffres());
            $this->audit->tracer(JournalAudit::PAIEMENT_A_REMBOURSER, 'paiement', $ouvert->paiementId, $compte->id, $ip);
            $this->logger->critical('Paiement accepté sur une commande qui ne peut plus être validée : remboursement à faire', ['paiement' => $ouvert->paiementId]);

            throw $e;
        }

        $this->audit->tracer(JournalAudit::PAIEMENT_ACCEPTE, 'paiement', $ouvert->paiementId, $compte->id, $ip);
        if ('A_VERIFIER' === $statut) {
            $this->audit->tracer(JournalAudit::COMMANDE_A_VERIFIER, 'commande', $contexte->commandeId, $compte->id, $ip);
        }

        // Effets Redis APRÈS la validation : la base fait foi, ils ne peuvent plus défaire un paiement accepté
        $this->apresValidation('compteurs anti-fraude', function () use ($compte, $contexte): void {
            $this->compteurs->enregistrerClesAchetees($compte->id, $contexte->commandeId, $contexte->nombreCles);
            $this->compteurs->reinitialiserEchecs($compte->id);
        });
        $this->apresValidation('arrêt du minuteur', fn () => $this->minuteur->arreter($contexte->commandeId));

        return new PaiementEffectue($this->commandes->consulter($contexte->reference, $compte->id), $carte->derniersChiffres(), false);
    }

    /** Même clé : même réponse, sans rappeler le prestataire ni compter un nouvel essai. */
    private function rejouer(PaiementExistant $existant, string $reference, Compte $compte): PaiementEffectue
    {
        if ($existant->commandeReference !== $reference) {
            throw new IdempotenceConflitException();
        }

        return match ($existant->statut) {
            'ACCEPTE' => new PaiementEffectue($this->commandes->consulter($reference, $compte->id), $existant->derniersChiffres ?? '', true),
            'EN_ATTENTE' => throw new PaiementEnCoursException(),
            default => throw new PaiementRefuseException($existant->codeRetour ?? 'erreur_fournisseur'),
        };
    }

    private function libererCle(CleIdempotence $cle): void
    {
        $this->apresValidation('libération de la clé d\'idempotence', fn () => $this->idempotence->liberer($cle->empreinte));
    }

    private function apresValidation(string $etape, callable $effet): void
    {
        try {
            $effet();
        } catch (\RedisException $e) {
            $this->logger->warning('Paiement : {etape} impossible, Redis indisponible ({message})', ['etape' => $etape, 'message' => $e->getMessage()]);
        }
    }
}
