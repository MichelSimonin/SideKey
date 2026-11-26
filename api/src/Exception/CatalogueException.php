<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Erreurs de gestion du catalogue (F-09), traduites en codes HTTP par ApiExceptionSubscriber :
 *  - introuvable (404) : même réponse pour un identifiant inconnu et pour une ressource d'un autre périmètre ;
 *  - doublon (409) : éditeur, plateforme, slug ou offre active déjà existants (la base fait foi : index uniques) ;
 *  - utilisée (409) : suppression refusée parce que la ressource est référencée (clé étrangère RESTRICT) ;
 *  - invalide (422) : valeur acceptée par le format mais refusée par le métier (éditeur ou genre inconnu).
 * Les messages sont génériques et traduits : aucune valeur saisie, aucun détail SQL.
 */
final class CatalogueException extends ExceptionMetier
{
    private function __construct(string $cleMessage, private readonly int $statut, /** @var array<string, string> */ private readonly array $erreurs = [])
    {
        parent::__construct($cleMessage);
    }

    public static function introuvable(): self
    {
        return new self('error.catalogue_introuvable', 404);
    }

    /** @param string $cleMessage error.editeur_doublon, error.plateforme_doublon, error.slug_doublon ou error.offre_doublon */
    public static function doublon(string $cleMessage): self
    {
        return new self($cleMessage, 409);
    }

    public static function utilisee(): self
    {
        return new self('error.catalogue_utilisee', 409);
    }

    /** @param array<string, string> $erreurs champ → clé de message traduite (domaine « errors ») */
    public static function invalide(array $erreurs): self
    {
        return new self('error.unprocessable', 422, $erreurs);
    }

    public function codeHttp(): int
    {
        return $this->statut;
    }

    public function erreursChamps(): array
    {
        return $this->erreurs;
    }
}
