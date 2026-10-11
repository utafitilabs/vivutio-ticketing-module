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

namespace Vivutio\Ticketing\Partner;

use Vivutio\Contracts\Partner\Charge;
use Vivutio\Contracts\Partner\ChargeSourceInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Ticketing\Controller\TicketController;
use Vivutio\Ticketing\Entity\Ticket;
use Vivutio\Ticketing\Enum\TicketStatusEnum;
use Vivutio\Ticketing\Repository\TicketRepository;
use Vivutio\Ticketing\Service\TicketService;

/**
 * What tickets are owed, as charges, from the day each was issued: an issued
 * ticket what it charged, a refunded one what the agency kept, a voided one
 * nothing; due in the client's days to pay, or that day from the traveller
 * who pays. A held ticket, or one let go, is owed nothing.
 */
final readonly class TicketCharges implements ChargeSourceInterface
{
    public const string KEY = 'ticket';

    public function __construct(
        private TicketRepository $tickets,
        private PartnerDirectoryInterface $partners,
    ) {
    }

    public function charges(): iterable
    {
        foreach ($this->tickets->findBy([], ['reference' => 'ASC']) as $ticket) {
            $issued = $ticket->getIssuedAt();
            if (null === $issued || \in_array($ticket->getStatus(), [TicketStatusEnum::Held, TicketStatusEnum::Released], true)) {
                continue;
            }
            $raised = $issued->setTime(0, 0);
            $client = null === $ticket->getBilledTo() ? null : $this->partners->find($ticket->getBilledTo());
            yield new Charge(
                self::KEY.':'.$ticket->getUuid(),
                $ticket->getReference(),
                'Ticket',
                $this->about($ticket),
                $ticket->getBilledTo(),
                $client?->getName() ?? $ticket->getTravellers()[0]['name'],
                $ticket->getCurrency(),
                TicketService::charged($ticket),
                $raised,
                $raised->modify(\sprintf('+%d days', $client?->getCreditDays() ?? 0)),
                TicketController::SHOW,
                ['uuid' => (string) $ticket->getUuid()],
            );
        }
    }

    /** "Kilima Air, DAR → ZNZ → DAR, 14 – 16 Nov 2026", and what became of it. */
    private function about(Ticket $ticket): string
    {
        $became = match ($ticket->getStatus()) {
            TicketStatusEnum::Refunded => ', refunded',
            TicketStatusEnum::Voided => ', voided',
            default => '',
        };

        return $ticket->getCarrier()->getName().', '.TicketService::trip($ticket).', '.TicketService::dates($ticket).$became;
    }
}
