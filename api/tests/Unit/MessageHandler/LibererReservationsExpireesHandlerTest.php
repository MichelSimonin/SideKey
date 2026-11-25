<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\LibererReservationsExpirees;
use App\MessageHandler\LibererReservationsExpireesHandler;
use App\Schedule;
use App\Service\Reservation\ReservationCles;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class LibererReservationsExpireesHandlerTest extends TestCase
{
    public function testAppelleLaFonctionDeLiberation(): void
    {
        $reservations = $this->createMock(ReservationCles::class);
        $reservations->expects(self::once())->method('libererReservationsExpirees')->willReturn(3);

        $handler = new LibererReservationsExpireesHandler($reservations, new NullLogger());

        self::assertSame(3, $handler(new LibererReservationsExpirees()));
    }

    public function testLaLiberationEstPlanifieeChaqueMinute(): void
    {
        $messages = (new Schedule(new ArrayAdapter()))->getSchedule()->getRecurringMessages();

        self::assertCount(1, $messages);
        self::assertStringContainsString('every 1 minute', (string) $messages[0]->getTrigger());
    }
}
