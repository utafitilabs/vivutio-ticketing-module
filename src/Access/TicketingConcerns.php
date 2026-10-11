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

namespace Vivutio\Ticketing\Access;

use Vivutio\Contracts\Access\Concern;
use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Access\Scope;
use Vivutio\Contracts\Access\Verb;

/**
 * What a position may be granted over tickets: reading them and the
 * carriers, recording a new one, and issuing, changing, refunding, voiding
 * and keeping the carriers.
 */
final readonly class TicketingConcerns implements ConcernSourceInterface
{
    public const string TICKETS = 'tickets';

    public function declaredBy(): string
    {
        return 'Ticketing';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::TICKETS,
            label: 'Tickets',
            description: 'Tickets bought from airlines, ferries, coaches and railways for travellers: reading them, recording a new one, and issuing, changing, refunding or voiding one and keeping the carriers.',
            verbs: [Verb::Read, Verb::Record, Verb::Manage],
            scopes: [Scope::ORGANIZATION],
            moduleSlug: 'ticketing',
        );
    }
}
