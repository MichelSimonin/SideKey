<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Le prestataire a refusé le paiement ou n'a pas répondu à temps (402). La commande reste en attente et ses clés
 * réservées jusqu'à la fin des 15 minutes : le client peut réessayer avec une nouvelle clé d'idempotence (US-36).
 * Le code de retour dit pourquoi (carte refusée, fonds insuffisants, délai dépassé) ; il ne contient aucune donnée de carte.
 */
final class PaiementRefuseException extends ExceptionMetier
{
    public const CODES = ['carte_refusee', 'fonds_insuffisants', 'delai_depasse', 'carte_inconnue', 'erreur_fournisseur'];

    private readonly string $codeRetour;

    /** @param int|null $tentativesRestantes essais avant blocage ; null si inconnu (rejeu d'une réponse déjà donnée) */
    public function __construct(string $codeRetour, private readonly ?int $tentativesRestantes = null)
    {
        $this->codeRetour = \in_array($codeRetour, self::CODES, true) ? $codeRetour : 'erreur_fournisseur';
        parent::__construct('error.paiement_'.$this->codeRetour);
    }

    public function codeHttp(): int
    {
        return 402;
    }

    public function details(): array
    {
        return ['code' => $this->codeRetour] + (null === $this->tentativesRestantes ? [] : ['tentativesRestantes' => $this->tentativesRestantes]);
    }
}
