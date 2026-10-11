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
 * The fare a traveller travels on.
 */
enum FareKindEnum: string
{
    case Adult = 'adult';
    case Child = 'child';
    case Infant = 'infant';

    public function label(): string
    {
        return match ($this) {
            self::Adult => 'Adult',
            self::Child => 'Child',
            self::Infant => 'Infant',
        };
    }
}
