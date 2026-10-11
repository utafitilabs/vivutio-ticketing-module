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

namespace Vivutio\Ticketing\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Vivutio\Ticketing\Enum\CarrierKindEnum;
use Vivutio\Ticketing\Repository\CarrierRepository;

/**
 * An airline, ferry, coach or railway the agency buys tickets from, kept by
 * the agency: an airline's two-letter code and its three-digit ticket
 * prefix, and how the agency buys from it.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: CarrierRepository::class)]
#[ORM\Table(name: 'ticketing_carrier')]
class Carrier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\Column(length: 120, unique: true)]
    private string $name;

    #[ORM\Column(length: 8, enumType: CarrierKindEnum::class)]
    private CarrierKindEnum $kind;

    #[ORM\Column(length: 2)]
    private string $code = '';

    /** The digits every ticket number it issues starts with. */
    #[ORM\Column(length: 3)]
    private string $prefix = '';

    /** The account, portal or office the agency buys through. */
    #[ORM\Column(length: 160)]
    private string $howWeBuy = '';

    public function __construct(string $name, CarrierKindEnum $kind)
    {
        $this->uuid = Uuid::v7();
        $this->name = $name;
        $this->kind = $kind;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): CarrierKindEnum
    {
        return $this->kind;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function getHowWeBuy(): string
    {
        return $this->howWeBuy;
    }

    public function setHowWeBuy(string $howWeBuy): static
    {
        $this->howWeBuy = $howWeBuy;

        return $this;
    }
}
