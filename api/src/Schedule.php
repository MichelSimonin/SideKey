<?php

declare(strict_types=1);

namespace App;

use App\Message\LibererReservationsExpirees;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tâches planifiées de SideKey, exécutées par le worker (messenger:consume scheduler_default).
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)            // une exécution manquée (worker redémarré) est rattrapée
            ->processOnlyLastMissedRun(true)    // ... une seule fois
            // RG-12 : chaque minute, les commandes dont la réservation de 15 min est dépassée
            // passent en EXPIREE et leurs clés reviennent en stock (fn_liberer_reservations_expirees).
            ->add(RecurringMessage::every('1 minute', new LibererReservationsExpirees()));
    }
}
