<?php

declare(strict_types=1);

namespace App\Service\Cles;

/** Clé déchiffrée pour son acheteur : ne quitte LicenceService que vers la réponse HTTP. */
final class CleRevelee
{
    public function __construct(
        public readonly string $id,
        #[\SensitiveParameter] public readonly string $cle,
        public readonly \DateTimeImmutable $reveleeLe,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id];
    }
}
