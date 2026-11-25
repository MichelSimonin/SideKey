<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Service\Catalogue\CatalogueCache;
use App\Service\Catalogue\CatalogueService;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\JeuFiche;
use App\Service\Catalogue\Langue;
use App\Service\Catalogue\PageJeux;
use App\Service\Catalogue\ValeursFiltres;
use Psr\Log\LoggerInterface;

/**
 * Cache du catalogue dans Redis (CDC §9.8), valeurs en JSON (jamais de serialize() PHP).
 *
 *   cache:jeu:{slug}:{langue}               fiche
 *   cache:jeux:v{n}:{langue}:{empreinte}    liste filtrée, n = cache:jeux:version
 *   cache:filtres:v{n}:{langue}             valeurs des filtres
 *
 * Seules des valeurs validées entrent dans une clé : slug conforme au format, langue de l'énumération, empreinte
 * SHA-256 des critères. Une panne de Redis ne casse jamais le catalogue : lectures à null, écritures ignorées,
 * l'incident est journalisé. Les données mises en cache sont publiques (aucun secret, aucune donnée personnelle).
 */
final class RedisCatalogueCache implements CatalogueCache
{
    private const CLE_VERSION = 'cache:jeux:version';

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = self::TTL,   // tests uniquement : expiration courte
    ) {
    }

    public function fiche(string $slug, Langue $langue): ?JeuFiche
    {
        $donnees = $this->lire($this->cleFiche($slug, $langue));

        return null === $donnees ? null : $this->reconstruire(static fn () => JeuFiche::depuis($donnees));
    }

    public function memoriserFiche(string $slug, Langue $langue, JeuFiche $fiche): void
    {
        $this->ecrire($this->cleFiche($slug, $langue), $fiche->enTableau());
    }

    public function liste(FiltresCatalogue $filtres, Langue $langue): ?PageJeux
    {
        $cle = $this->cleListe($filtres, $langue);
        $donnees = null === $cle ? null : $this->lire($cle);

        return null === $donnees ? null : $this->reconstruire(static fn () => PageJeux::depuis($donnees));
    }

    public function memoriserListe(FiltresCatalogue $filtres, Langue $langue, PageJeux $page): void
    {
        $cle = $this->cleListe($filtres, $langue);
        if (null !== $cle) {
            $this->ecrire($cle, $page->enTableau());
        }
    }

    public function valeursFiltres(Langue $langue): ?ValeursFiltres
    {
        $cle = $this->cleFiltres($langue);
        $donnees = null === $cle ? null : $this->lire($cle);

        return null === $donnees ? null : $this->reconstruire(static fn () => ValeursFiltres::depuis($donnees));
    }

    public function memoriserValeursFiltres(Langue $langue, ValeursFiltres $valeurs): void
    {
        $cle = $this->cleFiltres($langue);
        if (null !== $cle) {
            $this->ecrire($cle, $valeurs->enTableau());
        }
    }

    public function invaliderJeu(string $slug): void
    {
        try {
            $this->redis->del(...array_map(fn (Langue $l): string => $this->cleFiche($slug, $l), Langue::cases()));
        } catch (\RedisException $erreur) {
            $this->logger->error('Invalidation de la fiche impossible : Redis indisponible (le TTL prend le relais)', ['exception' => $erreur]);
        }
    }

    public function invaliderListes(): void
    {
        try {
            $this->redis->incr(self::CLE_VERSION);
        } catch (\RedisException $erreur) {
            $this->logger->error('Invalidation des listes impossible : Redis indisponible (le TTL prend le relais)', ['exception' => $erreur]);
        }
    }

    private function cleFiche(string $slug, Langue $langue): string
    {
        if (\strlen($slug) > CatalogueService::SLUG_MAX || 1 !== preg_match(CatalogueService::SLUG, $slug)) {
            throw new \InvalidArgumentException('Slug de jeu invalide.');
        }

        return 'cache:jeu:'.$slug.':'.$langue->value;
    }

    /** null si Redis ne répond pas : la liste n'est alors ni lue ni écrite. */
    private function cleListe(FiltresCatalogue $filtres, Langue $langue): ?string
    {
        $version = $this->version();

        return null === $version ? null : \sprintf('cache:jeux:v%d:%s:%s', $version, $langue->value, $filtres->empreinte());
    }

    private function cleFiltres(Langue $langue): ?string
    {
        $version = $this->version();

        return null === $version ? null : \sprintf('cache:filtres:v%d:%s', $version, $langue->value);
    }

    private function version(): ?int
    {
        try {
            return (int) $this->redis->get(self::CLE_VERSION);
        } catch (\RedisException $erreur) {
            $this->logger->warning('Cache du catalogue indisponible : lecture directe en base', ['exception' => $erreur]);

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function lire(string $cle): ?array
    {
        try {
            $brut = $this->redis->get($cle);
        } catch (\RedisException $erreur) {
            $this->logger->warning('Cache du catalogue indisponible : lecture directe en base', ['exception' => $erreur]);

            return null;
        }
        if (!\is_string($brut)) {
            return null;
        }
        try {
            $donnees = json_decode($brut, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;   // entrée corrompue : traitée comme absente, la base fait foi
        }

        return \is_array($donnees) ? $donnees : null;
    }

    /** @param array<string, mixed> $donnees */
    private function ecrire(string $cle, array $donnees): void
    {
        try {
            $this->redis->set($cle, json_encode($donnees, \JSON_THROW_ON_ERROR), ['ex' => $this->ttl]);
        } catch (\RedisException $erreur) {
            $this->logger->warning('Cache du catalogue indisponible : écriture ignorée', ['exception' => $erreur]);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $construire
     *
     * @return T|null
     */
    private function reconstruire(callable $construire): mixed
    {
        try {
            return $construire();
        } catch (\Throwable) {
            return null;   // forme inattendue (ancienne version de l'application) : on relit la base
        }
    }
}
