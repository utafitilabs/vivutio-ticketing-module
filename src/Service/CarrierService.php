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
use Vivutio\Ticketing\Entity\Carrier;
use Vivutio\Ticketing\Entity\Ticket;
use Vivutio\Ticketing\Enum\CarrierKindEnum;
use Vivutio\Ticketing\Exception\InvalidTicketException;
use Vivutio\Ticketing\Repository\CarrierRepository;

/**
 * The carriers the agency buys from, kept by the agency itself: no list is
 * shipped, so none goes stale.
 */
final readonly class CarrierService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CarrierRepository $carriers,
    ) {
    }

    /**
     * Every carrier, by kind and then by name.
     *
     * @return array<string, list<Carrier>> by the kind's label
     */
    public function byKind(): array
    {
        $kinds = [];
        foreach (CarrierKindEnum::cases() as $kind) {
            $kept = $this->carriers->findBy(['kind' => $kind], ['name' => 'ASC']);
            if ([] !== $kept) {
                $kinds[$kind->label()] = $kept;
            }
        }

        return $kinds;
    }

    /**
     * Every carrier, by name, with the tickets bought from it.
     *
     * @return list<array{carrier: Carrier, tickets: int}>
     */
    public function register(): array
    {
        $counts = [];
        foreach ($this->entityManager->getRepository(Ticket::class)->findAll() as $ticket) {
            $id = (int) $ticket->getCarrier()->getId();
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return array_map(static fn (Carrier $carrier): array => ['carrier' => $carrier, 'tickets' => $counts[(int) $carrier->getId()] ?? 0], $this->carriers->findBy([], ['name' => 'ASC']));
    }

    /**
     * @throws InvalidTicketException
     */
    public function add(string $name, string $kind, string $code, string $prefix, string $how): Carrier
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw new InvalidTicketException('name', 'Its name, as the carrier writes it.');
        }
        if (null !== $this->carriers->findOneBy(['name' => $name])) {
            throw new InvalidTicketException('name', \sprintf('%s is kept already.', $name));
        }
        $kind = CarrierKindEnum::tryFrom($kind) ?? throw new InvalidTicketException('kind', 'Air, ferry, bus or rail.');
        $code = strtoupper(trim($code));
        if ('' !== $code && 1 !== preg_match('{^[A-Z0-9]{2}$}D', $code)) {
            throw new InvalidTicketException('code', 'An airline’s code is its two letters or digits: KL.');
        }
        $prefix = trim($prefix);
        if ('' !== $prefix && 1 !== preg_match('{^\d{3}$}D', $prefix)) {
            throw new InvalidTicketException('prefix', 'A ticket prefix is three digits: 998.');
        }
        if (mb_strlen(trim($how)) > 160) {
            throw new InvalidTicketException('how', 'At most 160 characters.');
        }

        $carrier = (new Carrier($name, $kind))->setCode($code)->setPrefix($prefix)->setHowWeBuy(trim($how));
        $this->entityManager->persist($carrier);
        $this->entityManager->flush();

        return $carrier;
    }
}
