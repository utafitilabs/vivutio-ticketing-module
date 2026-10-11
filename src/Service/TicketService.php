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

namespace Vivutio\Ticketing\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Partner\PartnerInterface;
use Vivutio\Contracts\Settings\OrganizationIdentitySourceInterface;
use Vivutio\Ticketing\Entity\Carrier;
use Vivutio\Ticketing\Entity\Ticket;
use Vivutio\Ticketing\Enum\FareKindEnum;
use Vivutio\Ticketing\Enum\TicketStatusEnum;
use Vivutio\Ticketing\Exception\InvalidTicketException;
use Vivutio\Ticketing\Repository\CarrierRepository;
use Vivutio\Ticketing\Repository\TicketRepository;

/**
 * Tickets recorded by hand, as the carrier made them: held to the carrier's
 * time limit or issued with a ticket number a traveller; a held ticket issued
 * or let go; an issued one changed, refunded, or voided the day it was
 * issued. Each step is kept on the ticket with who took it. A ticket is
 * billed to the traveller who pays, or to a company or tour operator among
 * the partners traded with now.
 */
final readonly class TicketService
{
    public const int MOST_LEGS = 6;
    public const int MOST_TRAVELLERS = 9;

    /** The kinds of partner a ticket may be billed to. */
    public const array CLIENTS = ['company', 'tour_operator'];

    private const array COUNTS = [1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private TicketRepository $tickets,
        private CarrierRepository $carriers,
        private PartnerDirectoryInterface $partners,
        private OrganizationIdentitySourceInterface $organization,
    ) {
    }

    /**
     * The partners a ticket may be billed to, by name.
     *
     * @return list<PartnerInterface>
     */
    public function clients(): array
    {
        $clients = array_values(array_filter($this->partners->active(), static fn (PartnerInterface $partner): bool => \in_array($partner->getPartnerKind(), self::CLIENTS, true)));
        usort($clients, static fn (PartnerInterface $a, PartnerInterface $b): int => strcmp($a->getName(), $b->getName()));

        return $clients;
    }

    public function clientOf(Ticket $ticket): ?PartnerInterface
    {
        return null === $ticket->getBilledTo() ? null : $this->partners->find($ticket->getBilledTo());
    }

    /**
     * @param array<mixed> $sent
     *
     * @throws InvalidTicketException
     */
    public function record(array $sent, string $by): Ticket
    {
        $text = self::texts($sent);
        $carrier = Uuid::isValid($text('carrier')) ? $this->carriers->findOneBy(['uuid' => Uuid::fromString($text('carrier'))]) : null;
        if (null === $carrier) {
            throw new InvalidTicketException('carrier', 'Choose one of the carriers you keep.');
        }
        $status = TicketStatusEnum::tryFrom($text('status'));
        if (TicketStatusEnum::Held !== $status && TicketStatusEnum::Issued !== $status) {
            throw new InvalidTicketException('status', 'A new ticket is held or issued.');
        }
        $legs = self::legs($sent['legs'] ?? null);
        $travellers = self::travellers($sent['travellers'] ?? null, $carrier, TicketStatusEnum::Issued === $status);
        $currency = strtoupper($text('currency'));
        if (1 !== preg_match('{^[A-Z]{3}$}D', $currency)) {
            throw new InvalidTicketException('currency', 'A currency is its three-letter code: USD, TZS, EUR.');
        }
        $fee = self::amount($text('fee'), 'fee', 'Our fee is an amount to the cent: 20.00.');
        $taxes = self::amount($text('taxes'), 'taxes', 'The taxes are an amount to the cent: 46.00.');
        $billedTo = null;
        if ('' !== $text('billed_to')) {
            $client = $this->partners->find($text('billed_to'));
            if (null === $client || !$client->isActive() || !\in_array($client->getPartnerKind(), self::CLIENTS, true)) {
                throw new InvalidTicketException('billed_to', 'Bill a company or a tour operator you trade with, or the traveller.');
            }
            $billedTo = $client->getPartnerId();
        }
        $heldUntil = null;
        if (TicketStatusEnum::Held === $status) {
            $heldUntil = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text('held_until'), $this->zone());
            if (false === $heldUntil || $heldUntil->format('Y-m-d\TH:i') !== $text('held_until')) {
                throw new InvalidTicketException('held_until', 'The day and time the carrier holds it to.');
            }
        }
        foreach (['their_reference' => 40, 'class' => 40, 'baggage' => 40, 'asked_for' => 160] as $field => $most) {
            if (mb_strlen($text($field)) > $most) {
                throw new InvalidTicketException($field, \sprintf('At most %d characters.', $most));
            }
        }

        $now = $this->clock->now();
        $heldUntil = $heldUntil?->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $ticket = (new Ticket($this->reference(), $carrier, $legs, $travellers, $currency))
            ->setCarrierReference($text('their_reference'))
            ->setTravelClass($text('class'))
            ->setBaggage($text('baggage'))
            ->setFee($fee)
            ->setTaxes($taxes)
            ->setBilledTo($billedTo)
            ->setAskedFor($text('asked_for'))
            ->setNotes($text('notes'))
            ->setStatus($status)
            ->setHeldUntil($heldUntil)
            ->setRecordedBy($by);
        if (null !== $heldUntil) {
            $ticket->record($now, $by, \sprintf('Held to %s, the carrier’s time limit', $heldUntil->setTimezone($this->zone())->format('j M, H:i')));
        } else {
            $this->issued($ticket, $now, $by);
        }
        $this->entityManager->persist($ticket);
        $this->entityManager->flush();

        return $ticket;
    }

    /**
     * A held ticket issued: a ticket number for each traveller.
     *
     * @param array<mixed> $numbers by the traveller's place on the ticket
     *
     * @throws InvalidTicketException
     */
    public function issue(Ticket $ticket, array $numbers, string $by): void
    {
        if (TicketStatusEnum::Held !== $ticket->getStatus()) {
            throw new InvalidTicketException('numbers', 'Only a held ticket is issued.');
        }
        $travellers = $ticket->getTravellers();
        foreach ($travellers as $i => $traveller) {
            $typed = \is_string($numbers[$i] ?? null) ? trim($numbers[$i]) : '';
            $travellers[$i]['number'] = self::number($typed, \sprintf('numbers[%d]', $i), $ticket->getCarrier(), true);
        }
        $ticket->setTravellers($travellers);
        $this->issued($ticket, $this->clock->now(), $by);
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidTicketException
     */
    public function release(Ticket $ticket, string $by): void
    {
        if (TicketStatusEnum::Held !== $ticket->getStatus()) {
            throw new InvalidTicketException('release', 'Only a held ticket is let go.');
        }
        $ticket->setStatus(TicketStatusEnum::Released)->record($this->clock->now(), $by, 'Let go: the carrier releases the seats, nothing is charged');
        $this->entityManager->flush();
    }

    /**
     * An issued ticket changed as the carrier changed it: its legs, its
     * travellers' names, numbers and seats, and what the carrier charged for
     * it. The fares stay as issued; a difference is what the carrier charged.
     *
     * @param array<mixed> $sent
     *
     * @throws InvalidTicketException
     */
    public function change(Ticket $ticket, array $sent, string $by): void
    {
        if (TicketStatusEnum::Issued !== $ticket->getStatus()) {
            throw new InvalidTicketException('legs', 'Only an issued ticket is changed.');
        }
        $text = self::texts($sent);
        $legs = self::legs($sent['legs'] ?? null);
        $typed = self::travellers($sent['travellers'] ?? null, $ticket->getCarrier(), true);
        $before = $ticket->getTravellers();
        if (\count($typed) !== \count($before)) {
            throw new InvalidTicketException('travellers', 'A change keeps its travellers; another traveller is a new ticket.');
        }
        $travellers = [];
        foreach ($typed as $i => $traveller) {
            $travellers[] = [...$traveller, 'fare_kind' => $before[$i]['fare_kind'], 'fare' => $before[$i]['fare']];
        }
        $charged = self::cents(self::amount($text('change_fee'), 'change_fee', 'A change fee is an amount to the cent: 50.00.'))
            + self::cents(self::amount($text('fare_difference'), 'fare_difference', 'A fare difference is an amount to the cent: 0.00.'));

        $what = array_keys(array_filter(['the legs' => $legs !== $ticket->getLegs(), 'the travellers' => $travellers !== $before]));
        if ([] === $what && 0 === $charged) {
            throw new InvalidTicketException('legs', 'Nothing was changed.');
        }
        $ticket->setLegs($legs)->setTravellers($travellers)->setChangeCharges(self::decimal(self::cents($ticket->getChangeCharges()) + $charged));
        $said = [] === $what ? 'Charged by the carrier' : 'Changed '.implode(' and ', $what);
        $ticket->record($this->clock->now(), $by, $said.'; the carrier charged '.(0 === $charged ? 'nothing' : self::money($ticket->getCurrency(), $charged)));
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidTicketException
     */
    public function refund(Ticket $ticket, string $returned, string $kept, string $by): void
    {
        if (TicketStatusEnum::Issued !== $ticket->getStatus()) {
            throw new InvalidTicketException('returned', 'Only an issued ticket is refunded.');
        }
        $returned = self::amount(trim($returned), 'returned', 'What the carrier returns is an amount to the cent: 316.00.');
        $kept = self::amount(trim($kept), 'kept', 'What we keep is an amount to the cent: 40.00.');
        $ticket->setReturned($returned)->setKept($kept)->setStatus(TicketStatusEnum::Refunded)
            ->record($this->clock->now(), $by, \sprintf('Refunded: the carrier returns %s, we keep %s', self::money($ticket->getCurrency(), self::cents($returned)), self::money($ticket->getCurrency(), self::cents($kept))));
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidTicketException
     */
    public function void(Ticket $ticket, string $by): void
    {
        if (!$this->voidable($ticket)) {
            throw new InvalidTicketException('void', 'A ticket is voided only the day it was issued.');
        }
        $ticket->setStatus(TicketStatusEnum::Voided)->record($this->clock->now(), $by, 'Voided: nothing is charged');
        $this->entityManager->flush();
    }

    /** An issued ticket is voided the day it was issued, in the organization's time zone. */
    public function voidable(Ticket $ticket): bool
    {
        $issued = $ticket->getIssuedAt();

        return TicketStatusEnum::Issued === $ticket->getStatus() && null !== $issued
            && $issued->setTimezone($this->zone())->format('Y-m-d') === $this->clock->now()->setTimezone($this->zone())->format('Y-m-d');
    }

    /**
     * The ticket as its pages say it, every line already worded.
     *
     * @return array{trip: string, dates: string, party: string, names: string, leaves: string, chip: string, ok: bool, total_label: string, total: string, held: bool, fares: list<array{string, string}>, legs: list<array{string, string}>, travellers: list<array{string, string}>, carrier: string}
     */
    public function view(Ticket $ticket): array
    {
        $currency = $ticket->getCurrency();
        $money = static fn (int $cents): string => self::money($currency, $cents);
        $travellers = $ticket->getTravellers();
        $n = \count($travellers);
        $fares = array_values(array_unique(array_map(static fn (array $traveller): string => $traveller['fare'], $travellers)));
        $held = TicketStatusEnum::Held === $ticket->getStatus();

        $lines = [[1 === \count($fares) ? \sprintf('Fare, %d × %s', $n, $money(self::cents($fares[0]))) : \sprintf('Fares, %d travellers', $n), $money(self::fares($ticket))]];
        $lines[] = [\sprintf('Our fee, %d × %s', $n, $money(self::cents($ticket->getFee()))), $money(self::fees($ticket))];
        if (self::cents($ticket->getTaxes()) > 0) {
            $lines[] = ['Taxes, in the fare', $money(self::cents($ticket->getTaxes()))];
        }
        if (self::cents($ticket->getChangeCharges()) > 0) {
            $lines[] = ['Changes by the carrier', $money(self::cents($ticket->getChangeCharges()))];
        }
        $nothing = \in_array($ticket->getStatus(), [TicketStatusEnum::Voided, TicketStatusEnum::Released], true);
        $lines[] = [$held ? 'To charge' : 'Charged', $money($nothing ? 0 : self::fares($ticket) + self::fees($ticket) + self::cents($ticket->getChangeCharges()))];
        if (TicketStatusEnum::Refunded === $ticket->getStatus()) {
            $lines[] = ['The carrier returns', $money(self::cents($ticket->getReturned() ?? '0'))];
            $lines[] = ['We keep', $money(self::cents($ticket->getKept() ?? '0'))];
        }

        $carrier = $ticket->getCarrier();
        $legs = array_map(static function (array $leg) use ($ticket): array {
            $about = array_values(array_filter([(new \DateTimeImmutable($leg['day']))->format('D j M Y'), $leg['number'], implode(', ', array_filter([$ticket->getTravelClass(), $ticket->getBaggage()]))]));

            return [implode(' · ', $about), trim($leg['from'].' '.$leg['leaves'].' → '.$leg['to'].' '.$leg['arrives'])];
        }, $ticket->getLegs());
        $people = array_map(static fn (array $traveller): array => [$traveller['name'].' · '.(FareKindEnum::tryFrom($traveller['fare_kind'])?->label() ?? ''), '' === $traveller['number'] ? 'Not issued yet' : $traveller['number']], $travellers);

        $first = $ticket->getLegs()[0];
        $until = $ticket->getHeldUntil()?->setTimezone($this->zone());

        return [
            'trip' => self::trip($ticket),
            'dates' => self::dates($ticket),
            'party' => self::party($ticket),
            'names' => self::names($ticket),
            'leaves' => (new \DateTimeImmutable($first['day']))->format('D j M').' · '.$first['leaves'],
            'chip' => $held && null !== $until ? 'Held to '.$until->format('j M, H:i') : $ticket->getStatus()->label(),
            'ok' => TicketStatusEnum::Issued === $ticket->getStatus(),
            'total_label' => $held ? 'To charge' : 'Charged',
            'total' => $money(self::charged($ticket)),
            'held' => $held,
            'fares' => $lines,
            'legs' => $legs,
            'travellers' => $people,
            'carrier' => $carrier->getName(),
        ];
    }

    /** What the travellers' fares come to, in cents. */
    public static function fares(Ticket $ticket): int
    {
        return array_sum(array_map(static fn (array $traveller): int => self::cents($traveller['fare']), $ticket->getTravellers()));
    }

    /** Our fee for every traveller, in cents. */
    public static function fees(Ticket $ticket): int
    {
        return self::cents($ticket->getFee()) * \count($ticket->getTravellers());
    }

    /** What the ticket comes to as it stands: to charge, charged, what we kept, or nothing. */
    public static function charged(Ticket $ticket): int
    {
        return match ($ticket->getStatus()) {
            TicketStatusEnum::Held, TicketStatusEnum::Issued => self::fares($ticket) + self::fees($ticket) + self::cents($ticket->getChangeCharges()),
            TicketStatusEnum::Refunded => self::cents($ticket->getKept() ?? '0'),
            TicketStatusEnum::Voided, TicketStatusEnum::Released => 0,
        };
    }

    /** "USD 356.00", an amount in cents of a currency. */
    public static function money(string $currency, int $cents): string
    {
        return $currency.' '.number_format($cents / 100, 2);
    }

    /** "DAR → ZNZ → DAR", where it goes as typed: the first leg's start, then each leg's end. */
    public static function trip(Ticket $ticket): string
    {
        $stops = [];
        foreach ($ticket->getLegs() as $leg) {
            if ([] === $stops || end($stops) !== $leg['from']) {
                $stops[] = $leg['from'];
            }
            $stops[] = $leg['to'];
        }

        return implode(' → ', $stops);
    }

    /** "14 – 16 Nov 2026", the first leg's day to the last's. */
    public static function dates(Ticket $ticket): string
    {
        $legs = $ticket->getLegs();
        $first = new \DateTimeImmutable($legs[0]['day']);
        $last = new \DateTimeImmutable($legs[\count($legs) - 1]['day']);

        return match (true) {
            $first == $last => $first->format('j M Y'),
            $first->format('Y-m') === $last->format('Y-m') => $first->format('j').' – '.$last->format('j M Y'),
            $first->format('Y') === $last->format('Y') => $first->format('j M').' – '.$last->format('j M Y'),
            default => $first->format('j M Y').' – '.$last->format('j M Y'),
        };
    }

    /** "2 adults, 1 child". */
    public static function party(Ticket $ticket): string
    {
        $counts = [];
        foreach ($ticket->getTravellers() as $traveller) {
            $counts[$traveller['fare_kind']] = ($counts[$traveller['fare_kind']] ?? 0) + 1;
        }
        $words = [];
        foreach (FareKindEnum::cases() as $kind) {
            $n = $counts[$kind->value] ?? 0;
            if ($n > 0) {
                $words[] = $n.' '.mb_strtolower($kind->label()).(1 === $n ? '' : (FareKindEnum::Child === $kind ? 'ren' : 's'));
            }
        }

        return implode(', ', $words);
    }

    /** "Amina Said, Joseph Mrema", or "Maria Lopes and 2". */
    public static function names(Ticket $ticket): string
    {
        $names = array_map(static fn (array $traveller): string => $traveller['name'], $ticket->getTravellers());

        return \count($names) <= 2 ? implode(', ', $names) : $names[0].' and '.(\count($names) - 1);
    }

    public static function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function issued(Ticket $ticket, \DateTimeImmutable $at, string $by): void
    {
        $n = \count($ticket->getTravellers());
        $ticket->setStatus(TicketStatusEnum::Issued)->setIssuedAt($at)->setIssuedBy($by)
            ->record($at, $by, \sprintf('Issued, %s ticket number%s recorded', self::COUNTS[$n] ?? (string) $n, 1 === $n ? '' : 's'));
    }

    private function zone(): \DateTimeZone
    {
        $zone = $this->organization->identity()?->timeZone;

        return new \DateTimeZone(null !== $zone && \in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : date_default_timezone_get());
    }

    private function reference(): string
    {
        return \sprintf('TK-%04d', $this->tickets->count([]) + 1);
    }

    /**
     * @param array<mixed> $sent
     *
     * @return \Closure(string): string
     */
    private static function texts(array $sent): \Closure
    {
        return static fn (string $key): string => \is_string($sent[$key] ?? null) ? trim($sent[$key]) : '';
    }

    /**
     * The legs typed, a row left empty passed over.
     *
     * @return list<array{number: string, from: string, to: string, day: string, leaves: string, arrives: string}>
     *
     * @throws InvalidTicketException
     */
    private static function legs(mixed $typed): array
    {
        $legs = [];
        foreach (array_values(\is_array($typed) ? $typed : []) as $i => $row) {
            $part = static fn (string $key): string => \is_array($row) && \is_string($row[$key] ?? null) ? trim($row[$key]) : '';
            $leg = ['number' => $part('number'), 'from' => $part('from'), 'to' => $part('to'), 'day' => $part('day'), 'leaves' => $part('leaves'), 'arrives' => $part('arrives')];
            if ('' === implode('', $leg)) {
                continue;
            }
            $field = static fn (string $key): string => \sprintf('legs[%d][%s]', $i, $key);
            foreach (['from' => 'Where it leaves from.', 'to' => 'Where it arrives.'] as $key => $reason) {
                if ('' === $leg[$key] || mb_strlen($leg[$key]) > 40) {
                    throw new InvalidTicketException($field($key), $reason);
                }
            }
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $leg['day']);
            if (false === $day || $day->format('Y-m-d') !== $leg['day']) {
                throw new InvalidTicketException($field('day'), 'A day as 16.11.2026.');
            }
            foreach (['leaves' => true, 'arrives' => false] as $key => $needed) {
                if (($needed || '' !== $leg[$key]) && 1 !== preg_match('{^([01]\d|2[0-3]):[0-5]\d$}D', $leg[$key])) {
                    throw new InvalidTicketException($field($key), 'A time as 07:30.');
                }
            }
            if (mb_strlen($leg['number']) > 12) {
                throw new InvalidTicketException($field('number'), 'At most 12 characters: KL 112.');
            }
            $legs[] = $leg;
        }
        if ([] === $legs) {
            throw new InvalidTicketException('legs', 'At least one leg: where from, where to, the day and the time it leaves.');
        }
        if (\count($legs) > self::MOST_LEGS) {
            throw new InvalidTicketException('legs', 'At most six legs; a longer trip is two tickets.');
        }

        return $legs;
    }

    /**
     * The travellers typed, a row left empty passed over.
     *
     * @return list<array{name: string, fare_kind: string, fare: string, number: string, seat: string}>
     *
     * @throws InvalidTicketException
     */
    private static function travellers(mixed $typed, Carrier $carrier, bool $issued): array
    {
        $travellers = [];
        foreach (array_values(\is_array($typed) ? $typed : []) as $i => $row) {
            $part = static fn (string $key): string => \is_array($row) && \is_string($row[$key] ?? null) ? trim($row[$key]) : '';
            $traveller = ['name' => $part('name'), 'fare_kind' => $part('fare_kind'), 'fare' => $part('fare'), 'number' => $part('number'), 'seat' => $part('seat')];
            if ('' === $traveller['name'].$traveller['fare'].$traveller['number'].$traveller['seat']) {
                continue;
            }
            $field = static fn (string $key): string => \sprintf('travellers[%d][%s]', $i, $key);
            if ('' === $traveller['name'] || mb_strlen($traveller['name']) > 120) {
                throw new InvalidTicketException($field('name'), 'The name on their ID.');
            }
            if (null === FareKindEnum::tryFrom($traveller['fare_kind'])) {
                throw new InvalidTicketException($field('fare_kind'), 'Adult, child or infant.');
            }
            $traveller['fare'] = self::amount($traveller['fare'], $field('fare'), 'A fare is an amount to the cent: 158.00.', required: true);
            $traveller['number'] = self::number($traveller['number'], $field('number'), $carrier, $issued);
            if (mb_strlen($traveller['seat']) > 8) {
                throw new InvalidTicketException($field('seat'), 'A seat as 4C.');
            }
            $travellers[] = $traveller;
        }
        if ([] === $travellers) {
            throw new InvalidTicketException('travellers', 'At least one traveller, by the name on their ID.');
        }
        if (\count($travellers) > self::MOST_TRAVELLERS) {
            throw new InvalidTicketException('travellers', 'At most nine travellers; a larger group is two tickets.');
        }

        return $travellers;
    }

    /**
     * A ticket number as the carrier printed it: digits in groups; one an
     * airline issues starts with its prefix.
     *
     * @throws InvalidTicketException
     */
    private static function number(string $typed, string $field, Carrier $carrier, bool $needed): string
    {
        $number = trim((string) preg_replace('/\s+/', ' ', $typed));
        if ('' === $number) {
            if ($needed) {
                throw new InvalidTicketException($field, 'An issued ticket has its number.');
            }

            return '';
        }
        if (1 !== preg_match('{^[0-9A-Z][0-9A-Z -]{2,23}$}D', $number)) {
            throw new InvalidTicketException($field, 'A ticket number as printed: 998 2400 018 341.');
        }
        if ('' !== $carrier->getPrefix() && !str_starts_with(str_replace([' ', '-'], '', $number), $carrier->getPrefix())) {
            throw new InvalidTicketException($field, \sprintf('%s’s ticket numbers start %s.', $carrier->getName(), $carrier->getPrefix()));
        }

        return $number;
    }

    /**
     * An amount typed to the cent, kept as "158.00"; nothing typed is nothing.
     *
     * @throws InvalidTicketException
     */
    private static function amount(string $typed, string $field, string $reason, bool $required = false): string
    {
        $typed = str_replace(',', '', trim($typed));
        if ('' === $typed && !$required) {
            return '0.00';
        }
        if (1 !== preg_match('{^\d{1,9}(\.\d{1,2})?$}D', $typed)) {
            throw new InvalidTicketException($field, $reason);
        }

        return number_format((float) $typed, 2, '.', '');
    }

    private static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
