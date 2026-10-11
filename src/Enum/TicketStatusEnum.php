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

namespace Vivutio\Ticketing\Enum;

/**
 * Where a ticket stands: held to the carrier's time limit, issued, and then
 * refunded or voided; a held ticket not issued is released.
 */
enum TicketStatusEnum: string
{
    case Held = 'held';
    case Issued = 'issued';
    case Refunded = 'refunded';
    case Voided = 'voided';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Held',
            self::Issued => 'Issued',
            self::Refunded => 'Refunded',
            self::Voided => 'Voided',
            self::Released => 'Released',
        };
    }
}
