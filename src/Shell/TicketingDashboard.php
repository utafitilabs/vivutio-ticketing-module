<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio ticketing module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\Ticketing\Shell;

use Psr\Clock\ClockInterface;
use Vivutio\Contracts\Shell\DashboardCard;
use Vivutio\Contracts\Shell\DashboardFigure;
use Vivutio\Contracts\Shell\DashboardRow;
use Vivutio\Contracts\Shell\DashboardSourceInterface;
use Vivutio\Ticketing\Controller\TicketController;
use Vivutio\Ticketing\Entity\Ticket;
use Vivutio\Ticketing\Enum\TicketStatusEnum;
use Vivutio\Ticketing\Repository\TicketRepository;
use Vivutio\Ticketing\Service\TicketService;

/**
 * Tickets on the organization's dashboard, for whoever reads them: how many
 * are held still, how many travel this week and how many were issued this
 * month; the held ones the soonest let go first, and the issued ones with a
 * leg leaving in the next seven days. Days and hours are the organization's.
 */
final readonly class TicketingDashboard implements DashboardSourceInterface
{
    private const int WEEK = 7;

    public function __construct(
        private TicketRepository $tickets,
        private TicketService $service,
        private ClockInterface $clock,
    ) {
    }

    public function figures(): iterable
    {
        $held = $this->held();
        $today = \count(array_filter($held, fn (Ticket $ticket): bool => 0 === $this->daysTo($ticket)));
        $tomorrow = \count(array_filter($held, fn (Ticket $ticket): bool => 1 === $this->daysTo($ticket)));
        yield new DashboardFigure('Held, to issue', (string) \count($held), match (true) {
            $today > 0 => self::lettingGo($today, 'today'),
            $tomorrow > 0 => self::lettingGo($tomorrow, 'tomorrow'),
            default => '',
        }, TicketController::READ, 10);

        $travelling = $this->travelling();
        $travellers = array_sum(array_map(static fn (array $soon): int => \count($soon[0]->getTravellers()), $travelling));
        yield new DashboardFigure('Travelling this week', (string) \count($travelling), self::tickets(\count($travelling)).(0 === $travellers ? '' : \sprintf(', %d traveller%s', $travellers, 1 === $travellers ? '' : 's')), TicketController::READ, 20);

        $month = $this->now()->format('Y-m');
        $issued = array_filter(
            $this->tickets->findBy(['status' => [TicketStatusEnum::Issued, TicketStatusEnum::Refunded]]),
            fn (Ticket $ticket): bool => $ticket->getIssuedAt()?->setTimezone($this->service->zone())->format('Y-m') === $month,
        );
        yield new DashboardFigure('Issued this month', (string) \count($issued), self::tickets(\count($issued)), TicketController::READ, 30);
    }

    public function cards(): iterable
    {
        $held = $this->held();
        yield new DashboardCard(
            'Held, to issue',
            'Tickets',
            'the soonest let go first',
            array_map(fn (Ticket $ticket): DashboardRow => $this->heldRow($ticket), \array_slice($held, 0, DashboardCard::MOST_ROWS)),
            \count($held),
            'Nothing is held',
            'All tickets',
            TicketController::REGISTER,
            TicketController::READ,
            ['status' => TicketStatusEnum::Held->value],
            10,
        );

        $travelling = $this->travelling();
        yield new DashboardCard(
            'Travelling soon',
            'Tickets',
            'issued, the next seven days',
            array_map(fn (array $soon): DashboardRow => new DashboardRow(
                $soon[0]->getReference(),
                $this->who($soon[0]).' · '.$soon[0]->getCarrier()->getName(),
                [self::route($soon[1]), (new \DateTimeImmutable($soon[1]['day']))->format('D j M')],
            ), \array_slice($travelling, 0, DashboardCard::MOST_ROWS)),
            \count($travelling),
            'Nobody travels in the next seven days',
            'All tickets',
            TicketController::REGISTER,
            TicketController::READ,
            [],
            25,
        );
    }

    /**
     * The held tickets whose time limit has not passed, the soonest first.
     *
     * @return list<Ticket>
     */
    private function held(): array
    {
        $now = $this->clock->now();
        $held = array_values(array_filter(
            $this->tickets->findBy(['status' => TicketStatusEnum::Held]),
            static fn (Ticket $ticket): bool => null !== $ticket->getHeldUntil() && $ticket->getHeldUntil() > $now,
        ));
        usort($held, static fn (Ticket $a, Ticket $b): int => [$a->getHeldUntil(), $a->getReference()] <=> [$b->getHeldUntil(), $b->getReference()]);

        return $held;
    }

    /**
     * The issued tickets with a leg leaving from today through the sixth day
     * on, each with the first such leg, the soonest first.
     *
     * @return list<array{Ticket, array{number: string, from: string, to: string, day: string, leaves: string, arrives: string}}>
     */
    private function travelling(): array
    {
        $today = $this->now()->format('Y-m-d');
        $last = $this->now()->modify(\sprintf('+%d days', self::WEEK - 1))->format('Y-m-d');
        $travelling = [];
        foreach ($this->tickets->findBy(['status' => TicketStatusEnum::Issued]) as $ticket) {
            foreach ($ticket->getLegs() as $leg) {
                if ($leg['day'] >= $today && $leg['day'] <= $last) {
                    $travelling[] = [$ticket, $leg];
                    break;
                }
            }
        }
        usort($travelling, static fn (array $a, array $b): int => [$a[1]['day'], $a[1]['leaves'], $a[0]->getReference()] <=> [$b[1]['day'], $b[1]['leaves'], $b[0]->getReference()]);

        return $travelling;
    }

    /** "held to today 17:00", or "held to Tue 13 Oct", with a chip for how soon it lets go. */
    private function heldRow(Ticket $ticket): DashboardRow
    {
        $until = $ticket->getHeldUntil()?->setTimezone($this->service->zone()) ?? $this->now();
        $days = $this->daysTo($ticket);
        $legs = $ticket->getLegs();

        return new DashboardRow(
            $ticket->getReference(),
            $this->who($ticket).' · '.$ticket->getCarrier()->getName().', '.self::route($legs[0]),
            ['held to '.(0 === $days ? 'today '.$until->format('H:i') : $until->format('D j M'))],
            match ($days) {
                0 => 'today',
                1 => 'tomorrow',
                default => \sprintf('in %d days', $days),
            },
            match (true) {
                $days <= 1 => 'danger',
                $days <= 3 => 'warn',
                default => '',
            },
        );
    }

    /** Days from today to the day a held ticket lets go, in the organization's calendar. */
    private function daysTo(Ticket $ticket): int
    {
        $until = $ticket->getHeldUntil()?->setTimezone($this->service->zone()) ?? $this->now();
        $today = new \DateTimeImmutable($this->now()->format('Y-m-d'));

        return (int) $today->diff(new \DateTimeImmutable($until->format('Y-m-d')))->format('%r%a');
    }

    /** "Amina Salim", or "Upendo Schools, 4 travellers": the client billed, else the first traveller. */
    private function who(Ticket $ticket): string
    {
        $n = \count($ticket->getTravellers());
        $who = $this->service->clientOf($ticket)?->getName() ?? $ticket->getTravellers()[0]['name'];

        return $n > 1 ? \sprintf('%s, %d travellers', $who, $n) : $who;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->service->zone());
    }

    /**
     * "DAR–ZNZ".
     *
     * @param array{from: string, to: string} $leg
     */
    private static function route(array $leg): string
    {
        return $leg['from'].'–'.$leg['to'];
    }

    private static function tickets(int $n): string
    {
        return 1 === $n ? 'ticket' : 'tickets';
    }

    /** "1 lets go today", "2 let go tomorrow". */
    private static function lettingGo(int $n, string $when): string
    {
        return \sprintf('%d %s go %s', $n, 1 === $n ? 'lets' : 'let', $when);
    }
}
