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
 * What a carrier carries travellers by; a ticket is of its carrier's kind.
 */
enum CarrierKindEnum: string
{
    case Air = 'air';
    case Ferry = 'ferry';
    case Bus = 'bus';
    case Rail = 'rail';

    public function label(): string
    {
        return match ($this) {
            self::Air => 'Air',
            self::Ferry => 'Ferry',
            self::Bus => 'Bus',
            self::Rail => 'Rail',
        };
    }

    /** What one of its legs is called: a flight, a sailing. */
    public function leg(): string
    {
        return match ($this) {
            self::Air => 'Flight',
            self::Ferry => 'Sailing',
            self::Bus => 'Coach',
            self::Rail => 'Train',
        };
    }
}
