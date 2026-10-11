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

namespace Vivutio\Ticketing\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Vivutio\Bundle\IdentityBundle\Test\AuthorityTestCase;
use Vivutio\Bundle\IdentityBundle\Test\Probe;
use Vivutio\Ticketing\Controller\CarrierController;
use Vivutio\Ticketing\Controller\TicketController;
use Vivutio\Ticketing\Entity\Carrier;
use Vivutio\Ticketing\Entity\Ticket;
use Vivutio\Ticketing\Enum\CarrierKindEnum;
use Vivutio\Ticketing\Enum\TicketStatusEnum;
use Vivutio\Ticketing\Tests\Application\Kernel;

/**
 * The module held to the core's five proofs, through the base every module's
 * suite extends. Record a reviewed change to the table with:
 *
 *     VIVUTIO_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit --filter TicketingAuthorityTest
 */
final class TicketingAuthorityTest extends AuthorityTestCase
{
    private const string CARRIER_UUID = '0199b1c0-0000-7000-8000-00000000d001';
    private const string HELD_UUID = '0199b1c0-0000-7000-8000-00000000d002';
    private const string ISSUED_UUID = '0199b1c0-0000-7000-8000-00000000d003';
    private const string HELD = '/tickets/'.self::HELD_UUID;
    private const string ISSUED = '/tickets/'.self::ISSUED_UUID;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function probes(): array
    {
        $legs = [['number' => 'KL 112', 'from' => 'DAR', 'to' => 'ZNZ', 'day' => '2099-11-14', 'leaves' => '07:30', 'arrives' => '07:55']];
        $travellers = [['name' => 'Probed traveller', 'fare_kind' => 'adult', 'fare' => '158.00', 'number' => '998 2400 000 001', 'seat' => '']];

        return [
            new Probe(TicketController::REGISTER, 'GET', '/tickets'),
            new Probe(CarrierController::REGISTER, 'GET', '/tickets/carriers'),
            new Probe(TicketController::NEW, 'GET', '/tickets/new'),
            new Probe(TicketController::SHOW, 'GET', self::ISSUED),
            new Probe(TicketController::CHANGE, 'GET', self::ISSUED.'/change'),
            // Each allowed person records a change the carrier charged for.
            new Probe(TicketController::CHANGE, 'POST', self::ISSUED.'/change', ['legs' => $legs, 'travellers' => $travellers, 'change_fee' => '50.00', 'fare_difference' => '0'], formAt: self::ISSUED.'/change'),
            // The first allowed issues the held ticket; the next finds it issued already.
            new Probe(TicketController::ISSUE, 'POST', self::HELD.'/issue', ['numbers' => ['998 2400 000 002']], formAt: self::HELD),
            new Probe(TicketController::RELEASE, 'POST', self::HELD.'/release', [], formAt: self::HELD),
            new Probe(TicketController::VOID, 'POST', self::ISSUED.'/void', [], formAt: self::ISSUED),
            new Probe(TicketController::REFUND, 'POST', self::ISSUED.'/refund', ['returned' => '0', 'kept' => '20.00'], formAt: self::ISSUED),
            new Probe(CarrierController::ADD, 'POST', '/tickets/carriers', ['name' => 'Probed coaches', 'kind' => 'bus', 'code' => '', 'prefix' => '', 'how' => ''], formAt: '/tickets/carriers'),
            new Probe(TicketController::NEW, 'POST', '/tickets/new', ['carrier' => self::CARRIER_UUID, 'their_reference' => 'PROBE', 'class' => 'Economy', 'baggage' => '20 kg', 'legs' => $legs, 'travellers' => $travellers, 'currency' => 'USD', 'fee' => '20', 'taxes' => '', 'billed_to' => '', 'asked_for' => '', 'status' => 'issued', 'held_until' => '', 'notes' => ''], formAt: '/tickets/new'),
        ];
    }

    protected static function packageDirectory(): string
    {
        return \dirname(__DIR__);
    }

    protected static function authorityTable(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected function seedSubjects(EntityManagerInterface $entityManager): void
    {
        $carrier = (new Carrier('Probed air', CarrierKindEnum::Air))->setCode('KL')->setPrefix('998')->setUuid(Uuid::fromString(self::CARRIER_UUID));
        $entityManager->persist($carrier);
        $legs = [['number' => 'KL 112', 'from' => 'DAR', 'to' => 'ZNZ', 'day' => '2099-11-13', 'leaves' => '07:30', 'arrives' => '07:55']];
        $travellers = [['name' => 'Probed traveller', 'fare_kind' => 'adult', 'fare' => '158.00', 'number' => '', 'seat' => '']];
        $entityManager->persist((new Ticket('TK-0001', $carrier, $legs, $travellers, 'USD'))->setUuid(Uuid::fromString(self::HELD_UUID))
            ->setStatus(TicketStatusEnum::Held)->setHeldUntil(new \DateTimeImmutable('2099-11-01 17:00')));
        $travellers[0]['number'] = '998 2400 000 001';
        $entityManager->persist((new Ticket('TK-0002', $carrier, $legs, $travellers, 'USD'))->setUuid(Uuid::fromString(self::ISSUED_UUID))
            ->setStatus(TicketStatusEnum::Issued)->setIssuedAt(new \DateTimeImmutable())->setFee('20.00'));
    }
}
