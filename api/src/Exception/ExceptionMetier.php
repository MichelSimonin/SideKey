<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Exception métier : porte son code HTTP et une clé de message traduite (domaine « errors »).
 * L'ApiExceptionSubscriber la convertit en réponse JSON ; aucun contrôleur ne fabrique de code
 * d'erreur à la main (architecture-couches.md §4).
 */
abstract class ExceptionMetier extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parametres valeurs à insérer dans le message (%nom%)
     */
    public function __construct(
        private readonly string $cleMessage,
        private readonly array $parametres = [],
        ?\Throwable $precedente = null,
    ) {
        parent::__construct($cleMessage, 0, $precedente);
    }

    abstract public function codeHttp(): int;

    public function cleMessage(): string
    {
        return $this->cleMessage;
    }

    /** @return array<string, string|int> */
    public function parametres(): array
    {
        return $this->parametres;
    }

    /**
     * Données complémentaires renvoyées au client (jamais d'information technique).
     *
     * @return array<string, scalar>
     */
    public function details(): array
    {
        return [];
    }
}
