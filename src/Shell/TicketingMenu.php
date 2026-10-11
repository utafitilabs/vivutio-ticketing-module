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

use Vivutio\Contracts\Shell\MenuEntry;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Ticketing\Controller\TicketController;

/**
 * Tickets in the menu, with lucide's ticket; the carriers are reached
 * from the tickets' page.
 */
final readonly class TicketingMenu implements MenuSourceInterface
{
    public function entries(): iterable
    {
        yield new MenuEntry(
            TicketController::REGISTER,
            'Tickets',
            TicketController::READ,
            '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
            'tickets',
        );
    }
}
