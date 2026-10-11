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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Vivutio\Ticketing\Enum\TicketStatusEnum;
use Vivutio\Ticketing\Repository\TicketRepository;

/**
 * A ticket bought from a carrier for travellers: the carrier's reference,
 * the legs, the travellers with a ticket number each, the fares and the
 * agency's fee, who it is billed to, where it stands, and each step taken.
 * Amounts are kept as typed, to the cent, in the ticket's currency.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'ticketing_ticket')]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    /** "TK-0001", the agency's own. */
    #[ORM\Column(length: 16, unique: true)]
    private string $reference;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Carrier $carrier;

    /** The carrier's booking reference, a PNR. */
    #[ORM\Column(length: 40)]
    private string $carrierReference = '';

    #[ORM\Column(length: 40)]
    private string $travelClass = '';

    #[ORM\Column(length: 40)]
    private string $baggage = '';

    /** @var list<array{number: string, from: string, to: string, day: string, leaves: string, arrives: string}> in the order travelled */
    #[ORM\Column(type: Types::JSON)]
    private array $legs;

    /** @var list<array{name: string, fare_kind: string, fare: string, number: string, seat: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $travellers;

    #[ORM\Column(length: 3)]
    private string $currency;

    /** The agency's fee, a traveller. */
    #[ORM\Column(type: Types::DECIMAL, precision: 11, scale: 2)]
    private string $fee = '0.00';

    /** The taxes in the fares, for the receipt. */
    #[ORM\Column(type: Types::DECIMAL, precision: 11, scale: 2)]
    private string $taxes = '0.00';

    /** What the carrier charged for every change, in all. */
    #[ORM\Column(type: Types::DECIMAL, precision: 11, scale: 2)]
    private string $changeCharges = '0.00';

    /** The client billed, by the id the core's partners are known by; none when the first traveller pays. */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $billedTo = null;

    #[ORM\Column(length: 160)]
    private string $askedFor = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $notes = '';

    #[ORM\Column(length: 12, enumType: TicketStatusEnum::class)]
    private TicketStatusEnum $status = TicketStatusEnum::Held;

    /** The carrier's time limit for a held ticket. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $heldUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(length: 120)]
    private string $recordedBy = '';

    #[ORM\Column(length: 120)]
    private string $issuedBy = '';

    /** On a refund: what the carrier returns. */
    #[ORM\Column(type: Types::DECIMAL, precision: 11, scale: 2, nullable: true)]
    private ?string $returned = null;

    /** On a refund: what the agency keeps. */
    #[ORM\Column(type: Types::DECIMAL, precision: 11, scale: 2, nullable: true)]
    private ?string $kept = null;

    /** @var list<array{at: string, by: string, said: string}> each step, oldest first */
    #[ORM\Column(type: Types::JSON)]
    private array $history = [];

    /**
     * @param list<array{number: string, from: string, to: string, day: string, leaves: string, arrives: string}> $legs
     * @param list<array{name: string, fare_kind: string, fare: string, number: string, seat: string}>            $travellers
     */
    public function __construct(string $reference, Carrier $carrier, array $legs, array $travellers, string $currency)
    {
        $this->uuid = Uuid::v7();
        $this->reference = $reference;
        $this->carrier = $carrier;
        $this->legs = $legs;
        $this->travellers = $travellers;
        $this->currency = $currency;
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

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getCarrier(): Carrier
    {
        return $this->carrier;
    }

    public function getCarrierReference(): string
    {
        return $this->carrierReference;
    }

    public function setCarrierReference(string $carrierReference): static
    {
        $this->carrierReference = $carrierReference;

        return $this;
    }

    public function getTravelClass(): string
    {
        return $this->travelClass;
    }

    public function setTravelClass(string $travelClass): static
    {
        $this->travelClass = $travelClass;

        return $this;
    }

    public function getBaggage(): string
    {
        return $this->baggage;
    }

    public function setBaggage(string $baggage): static
    {
        $this->baggage = $baggage;

        return $this;
    }

    /**
     * @return list<array{number: string, from: string, to: string, day: string, leaves: string, arrives: string}>
     */
    public function getLegs(): array
    {
        return $this->legs;
    }

    /**
     * @param list<array{number: string, from: string, to: string, day: string, leaves: string, arrives: string}> $legs
     */
    public function setLegs(array $legs): static
    {
        $this->legs = $legs;

        return $this;
    }

    /**
     * @return list<array{name: string, fare_kind: string, fare: string, number: string, seat: string}>
     */
    public function getTravellers(): array
    {
        return $this->travellers;
    }

    /**
     * @param list<array{name: string, fare_kind: string, fare: string, number: string, seat: string}> $travellers
     */
    public function setTravellers(array $travellers): static
    {
        $this->travellers = $travellers;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getFee(): string
    {
        return $this->fee;
    }

    public function setFee(string $fee): static
    {
        $this->fee = $fee;

        return $this;
    }

    public function getTaxes(): string
    {
        return $this->taxes;
    }

    public function setTaxes(string $taxes): static
    {
        $this->taxes = $taxes;

        return $this;
    }

    public function getChangeCharges(): string
    {
        return $this->changeCharges;
    }

    public function setChangeCharges(string $changeCharges): static
    {
        $this->changeCharges = $changeCharges;

        return $this;
    }

    public function getBilledTo(): ?string
    {
        return $this->billedTo;
    }

    public function setBilledTo(?string $billedTo): static
    {
        $this->billedTo = $billedTo;

        return $this;
    }

    public function getAskedFor(): string
    {
        return $this->askedFor;
    }

    public function setAskedFor(string $askedFor): static
    {
        $this->askedFor = $askedFor;

        return $this;
    }

    public function getNotes(): string
    {
        return $this->notes;
    }

    public function setNotes(string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getStatus(): TicketStatusEnum
    {
        return $this->status;
    }

    public function setStatus(TicketStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getHeldUntil(): ?\DateTimeImmutable
    {
        return $this->heldUntil;
    }

    public function setHeldUntil(?\DateTimeImmutable $heldUntil): static
    {
        $this->heldUntil = $heldUntil;

        return $this;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): static
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getRecordedBy(): string
    {
        return $this->recordedBy;
    }

    public function setRecordedBy(string $recordedBy): static
    {
        $this->recordedBy = $recordedBy;

        return $this;
    }

    public function getIssuedBy(): string
    {
        return $this->issuedBy;
    }

    public function setIssuedBy(string $issuedBy): static
    {
        $this->issuedBy = $issuedBy;

        return $this;
    }

    public function getReturned(): ?string
    {
        return $this->returned;
    }

    public function setReturned(?string $returned): static
    {
        $this->returned = $returned;

        return $this;
    }

    public function getKept(): ?string
    {
        return $this->kept;
    }

    public function setKept(?string $kept): static
    {
        $this->kept = $kept;

        return $this;
    }

    /**
     * @return list<array{at: string, by: string, said: string}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function record(\DateTimeImmutable $at, string $by, string $said): static
    {
        $this->history[] = ['at' => $at->format(\DATE_ATOM), 'by' => $by, 'said' => $said];

        return $this;
    }
}
