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

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Organization;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Ticketing\Entity\Carrier;
use Vivutio\Ticketing\Entity\Ticket;
use Vivutio\Ticketing\Enum\CarrierKindEnum;
use Vivutio\Ticketing\Enum\TicketStatusEnum;

/**
 * What tickets put on the organization's dashboard, as drawn for a travel
 * agency (vivutio-designs shell/dashboard-agency.html, picked 12 October
 * 2026): three figures in the band, the held tickets the soonest let go
 * first, and who travels in the next seven days; every day and hour in the
 * organization's time zone.
 */
final class TheDashboardTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;
    private Carrier $air;
    private Carrier $ferry;
    private Carrier $coach;
    private Partner $schools;
    private Partner $logistics;

    protected function setUp(): void
    {
        self::mockTime('2026-10-12 08:42:00');
        $this->browser = static::createClient();
        $this->migrate();
        $this->air = (new Carrier('Kilima Air', CarrierKindEnum::Air))->setCode('KL')->setPrefix('998');
        $this->ferry = new Carrier('Pwani Express', CarrierKindEnum::Ferry);
        $this->coach = new Carrier('Mto Coaches', CarrierKindEnum::Bus);
        $this->schools = new Partner('Upendo Schools', PartnerKindEnum::Company, 'TZ', 'trips@upendo.example');
        $this->logistics = new Partner('Bahari Logistics', PartnerKindEnum::Company, 'TZ', 'travel@bahari.example');
        foreach ([$this->air, $this->ferry, $this->coach, $this->schools, $this->logistics,
            (new Organization())->setName('Vivutio Travel')->setTimeZone('Africa/Dar_es_Salaam'), ] as $kept) {
            $this->em()->persist($kept);
        }
        $this->em()->flush();
    }

    public function testTheBandCountsWhatIsHeldWhoTravelsThisWeekAndWhatWasIssuedThisMonth(): void
    {
        $this->agency();

        self::assertSame([
            'Held, to issue 6 1 lets go today',
            'Travelling this week 6 tickets, 9 travellers',
            'Issued this month 7 tickets',
        ], $this->figures($this->dashboard($this->person('Rehema', TierEnum::Admin))));
    }

    /** The soonest let go first, today's with its hour, a chip for how soon; five of them, out of all still held. */
    public function testTheHeldCardListsTheSoonestLetGoFirst(): void
    {
        $this->agency();
        $card = $this->card($this->dashboard($this->person('Rehema', TierEnum::Admin)), 'Held, to issue');

        self::assertSame('Tickets · the soonest let go first', $this->text($card->filter('.panel-head p')));
        self::assertSame([
            'TK-0041 | Amina Salim · Kilima Air, DAR–ZNZ | held to today 17:00 | today danger',
            'TK-0043 | Upendo Schools, 4 travellers · Pwani Express, DAR–ZNZ | held to Tue 13 Oct | tomorrow danger',
            'TK-0046 | Mwajuma Ali · Kilima Air, DAR–MWZ | held to Wed 14 Oct | in 2 days warn',
            'TK-0044 | Juma Rashidi · Kilima Air, DAR–JRO | held to Thu 15 Oct | in 3 days warn',
            'TK-0048 | Saidi Mbwana, 2 travellers · Mto Coaches, DAR–ARK | held to Fri 16 Oct | in 4 days',
        ], $this->rows($card));
        self::assertSame('5 of 6', $this->text($card->filter('.panel-foot .state')));
        self::assertSame('All tickets', $this->text($card->filter('.panel-foot a.more')));
        self::assertSame('/tickets?status=held', $card->filter('.panel-foot a.more')->attr('href'));
    }

    /** An issued ticket with a leg leaving in the next seven days, by that leg: a return homeward reads its own route. */
    public function testTheTravellingCardListsWhoLeavesInTheNextSevenDays(): void
    {
        $this->agency();
        $card = $this->card($this->dashboard($this->person('Rehema', TierEnum::Admin)), 'Travelling soon');

        self::assertSame('Tickets · issued, the next seven days', $this->text($card->filter('.panel-head p')));
        self::assertSame([
            'TK-0033 | Halima Bakari · Kilima Air | DAR–ZNZ | Mon 12 Oct',
            'TK-0038 | Neema Kweka · Kilima Air | DAR–MWZ | Tue 13 Oct',
            'TK-0039 | Bahari Logistics, 2 travellers · Pwani Express | DAR–ZNZ | Wed 14 Oct',
            'TK-0035 | Grace Mushi · Mto Coaches | DAR–ARK | Fri 16 Oct',
            'TK-0040 | Peter Lyimo · Kilima Air | JRO–DAR | Sun 18 Oct',
        ], $this->rows($card));
        self::assertSame('5 of 6', $this->text($card->filter('.panel-foot .state')));
        self::assertSame('/tickets', $card->filter('.panel-foot a.more')->attr('href'));
    }

    /** The held card stands first in the left column, the travelling one after every package's first. */
    public function testTheCardsStandInTheirOrder(): void
    {
        $page = $this->dashboard($this->person('Rehema', TierEnum::Admin));

        self::assertSame(['Held, to issue', 'Travelling soon'], $page->filter('[data-cards] .panel-head h2')->each(fn (Crawler $h): string => $this->text($h)));
    }

    public function testWithNothingHeldOrTravellingTheCardsSaySo(): void
    {
        $page = $this->dashboard($this->person('Rehema', TierEnum::Admin));

        self::assertSame(['Held, to issue 0', 'Travelling this week 0 tickets', 'Issued this month 0 tickets'], $this->figures($page));
        self::assertSame('Nothing is held', $this->text($this->card($page, 'Held, to issue')->filter('[data-card-empty]')));
        self::assertSame('Nobody travels in the next seven days', $this->text($this->card($page, 'Travelling soon')->filter('[data-card-empty]')));
        self::assertSame('', $this->text($this->card($page, 'Travelling soon')->filter('.panel-foot .state')));
    }

    /** One of each reads as one; one letting go tomorrow, and none today, is said too. */
    public function testOneOfEachReadsAsOne(): void
    {
        $this->held('TK-0001', ['Lukas Weber'], '2026-10-13 15:00', ['DAR', 'ZNZ', '2026-10-20']);
        $this->held('TK-0002', ['Ana Ortega'], '2026-10-16 15:00', ['DAR', 'ZNZ', '2026-10-21']);
        $this->issued('TK-0003', ['Maria Lopes'], '2026-10-09 10:00', [['DAR', 'ZNZ', '2026-10-15', '07:30']]);
        $this->em()->flush();

        self::assertSame([
            'Held, to issue 2 1 lets go tomorrow',
            'Travelling this week 1 ticket, 1 traveller',
            'Issued this month 1 ticket',
        ], $this->figures($this->dashboard($this->person('Rehema', TierEnum::Admin))));
    }

    public function testTwoLettingGoTodayAreSaidTogether(): void
    {
        $this->held('TK-0001', ['Lukas Weber'], '2026-10-12 15:00', ['DAR', 'ZNZ', '2026-10-20']);
        $this->held('TK-0002', ['Ana Ortega'], '2026-10-12 23:30', ['DAR', 'ZNZ', '2026-10-21']);
        $this->em()->flush();

        self::assertSame('Held, to issue 2 2 let go today', $this->figures($this->dashboard($this->person('Rehema', TierEnum::Admin)))[0]);
    }

    /** The figures and cards are read with the tickets' pair, held where the department allows it too. */
    public function testNothingIsShownWithoutTheTicketsPair(): void
    {
        $this->agency();
        $desk = (new Department())->setName('Ticketing desk')->setAllows(['tickets.read']);
        $other = (new Department())->setName('Accounts')->setAllows([]);
        $this->em()->persist($desk);
        $this->em()->persist($other);
        $this->em()->flush();

        $reader = $this->dashboard($this->person('Daudi', TierEnum::Staff, ['dashboard.read', 'tickets.read'], $desk));
        self::assertCount(3, $reader->filter('.band .f'));
        self::assertCount(2, $reader->filter('[data-cards] .panel'));

        foreach ([
            'without the pair' => $this->person('Neema', TierEnum::Staff, ['dashboard.read'], $desk),
            'without the department allowing it' => $this->person('Baraka', TierEnum::Staff, ['dashboard.read', 'tickets.read'], $other),
        ] as $why => $person) {
            $page = $this->dashboard($person);
            self::assertCount(0, $page->filter('.band .f'), $why);
            self::assertCount(0, $page->filter('[data-cards] .panel'), $why);
            self::assertCount(1, $page->filter('[data-nothing-yet]'), $why);
        }
    }

    /**
     * An agency's week, Monday 12 October 2026 at 11:42 in Dar es Salaam:
     * six held still, one already let go; six issued travelling in the
     * next seven days; seven issued this month there, one of them at half
     * past midnight on the first, which is still September in UTC.
     */
    private function agency(): void
    {
        $this->held('TK-0041', ['Amina Salim'], '2026-10-12 17:00', ['DAR', 'ZNZ', '2026-10-20']);
        $this->held('TK-0043', ['Asha Mwita', 'Baraka Mwita', 'Daudi Mwita', 'Rose Mwita'], '2026-10-13 01:00', ['DAR', 'ZNZ', '2026-10-22'], $this->schools, $this->ferry);
        $this->held('TK-0044', ['Juma Rashidi'], '2026-10-15 13:00', ['DAR', 'JRO', '2026-10-25']);
        $this->held('TK-0045', ['Fatma Hassan'], '2026-10-19 12:00', ['DAR', 'ZNZ', '2026-10-26']);
        $this->held('TK-0046', ['Mwajuma Ali'], '2026-10-14 18:00', ['DAR', 'MWZ', '2026-10-23']);
        $this->held('TK-0047', ['Kassim Omari'], '2026-10-12 09:00', ['DAR', 'ZNZ', '2026-10-24']);
        $this->held('TK-0048', ['Saidi Mbwana', 'Zawadi Mbwana'], '2026-10-16 10:00', ['DAR', 'ARK', '2026-10-27'], carrier: $this->coach);

        $this->issued('TK-0031', ['Ali Juma'], '2026-10-07 10:00', [['DAR', 'ZNZ', '2026-11-02', '07:30']]);
        $this->issued('TK-0032', ['Omari Said'], '2026-09-20 10:00', [['DAR', 'ZNZ', '2026-10-11', '07:30']]);
        $this->issued('TK-0033', ['Halima Bakari'], '2026-09-15 10:00', [['DAR', 'ZNZ', '2026-10-12', '18:00']]);
        $this->issued('TK-0034', ['Yusuf Kombo'], '2026-10-12 09:00', [['DAR', 'ZNZ', '2026-10-13', '07:30']])->setStatus(TicketStatusEnum::Voided);
        $this->issued('TK-0035', ['Grace Mushi'], '2026-09-28 10:00', [['DAR', 'ARK', '2026-10-16', '06:00']], carrier: $this->coach);
        $this->issued('TK-0036', ['Elia Mollel'], '2026-10-05 10:00', [['DAR', 'ZNZ', '2026-10-19', '07:30']]);
        $this->issued('TK-0037', ['Rehema Nyange'], '2026-10-03 10:00', [['DAR', 'ZNZ', '2026-10-13', '07:30']])->setStatus(TicketStatusEnum::Refunded);
        $this->issued('TK-0038', ['Neema Kweka'], '2026-10-02 10:00', [['DAR', 'MWZ', '2026-10-13', '09:15']]);
        $this->issued('TK-0039', ['Musa Hamisi', 'Tumaini Hamisi'], '2026-10-12 08:00', [['DAR', 'ZNZ', '2026-10-14', '09:30']], $this->logistics, $this->ferry);
        $this->issued('TK-0040', ['Peter Lyimo'], '2026-10-01 00:30', [['DAR', 'JRO', '2026-10-08', '07:00'], ['JRO', 'DAR', '2026-10-18', '14:00']]);
        $this->issued('TK-0042', ['Anna Swai', 'Ben Swai', 'Cleo Swai'], '2026-10-06 10:00', [['DAR', 'ZNZ', '2026-10-18', '16:00']]);
        $this->em()->flush();
    }

    /**
     * @param list<string>                  $travellers
     * @param array{string, string, string} $leg        from, to, the day
     */
    private function held(string $reference, array $travellers, string $until, array $leg, ?Partner $client = null, ?Carrier $carrier = null): Ticket
    {
        return $this->ticket($reference, $travellers, [[...$leg, '07:30']], $client, $carrier)
            ->setHeldUntil(new \DateTimeImmutable($until, new \DateTimeZone('Africa/Dar_es_Salaam')));
    }

    /**
     * @param list<string>                                $travellers
     * @param list<array{string, string, string, string}> $legs       from, to, the day, the hour it leaves
     */
    private function issued(string $reference, array $travellers, string $at, array $legs, ?Partner $client = null, ?Carrier $carrier = null): Ticket
    {
        return $this->ticket($reference, $travellers, $legs, $client, $carrier)
            ->setStatus(TicketStatusEnum::Issued)
            ->setIssuedAt(new \DateTimeImmutable($at, new \DateTimeZone('Africa/Dar_es_Salaam')));
    }

    /**
     * @param list<string>                                $travellers
     * @param list<array{string, string, string, string}> $legs
     */
    private function ticket(string $reference, array $travellers, array $legs, ?Partner $client, ?Carrier $carrier): Ticket
    {
        $ticket = (new Ticket(
            $reference,
            $carrier ?? $this->air,
            array_map(static fn (array $leg): array => ['number' => '', 'from' => $leg[0], 'to' => $leg[1], 'day' => $leg[2], 'leaves' => $leg[3], 'arrives' => ''], $legs),
            array_map(static fn (string $name): array => ['name' => $name, 'fare_kind' => 'adult', 'fare' => '120.00', 'number' => '', 'seat' => ''], $travellers),
            'USD',
        ))->setBilledTo($client?->getPartnerId());
        $this->em()->persist($ticket);

        return $ticket;
    }

    private function dashboard(User $user): Crawler
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
        $page = $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSame('organization', $page->filter('[data-dashboard]')->attr('data-dashboard'));

        return $page;
    }

    /**
     * Each figure as read: its label, value and qualifier.
     *
     * @return list<string>
     */
    private function figures(Crawler $page): array
    {
        return $page->filter('.band .f')->each(function (Crawler $figure): string {
            $all = $this->text($figure->filter('.v'));
            $qualifier = $this->text($figure->filter('.v em'));

            return trim($this->text($figure->filter('.k')).' '.mb_substr($all, 0, mb_strlen($all) - mb_strlen($qualifier)).' '.$qualifier);
        });
    }

    private function card(Crawler $page, string $title): Crawler
    {
        $card = $page->filter('[data-cards] .panel')->reduce(fn (Crawler $panel): bool => $this->text($panel->filter('.panel-head h2')) === $title);
        self::assertCount(1, $card, $title);

        return $card;
    }

    /**
     * Each row as "title | detail | cell … | chip tone".
     *
     * @return list<string>
     */
    private function rows(Crawler $card): array
    {
        return $card->filter('tbody tr')->each(function (Crawler $row): string {
            $parts = [$this->text($row->filter('td b')), $this->text($row->filter('td small'))];
            $row->filter('td')->each(function (Crawler $cell, int $i) use (&$parts): void {
                if ($i > 0 && 0 === $cell->filter('.chip')->count()) {
                    $parts[] = $this->text($cell);
                }
            });
            $chip = $row->filter('.chip');
            if ($chip->count() > 0) {
                $parts[] = trim($this->text($chip).' '.trim(str_replace('chip', '', (string) $chip->attr('class'))));
            }

            return implode(' | ', $parts);
        });
    }

    private function text(Crawler $node): string
    {
        return 0 === $node->count() ? '' : trim((string) preg_replace('/\s+/u', ' ', $node->text()));
    }

    /**
     * @param list<string>|null $grants
     */
    private function person(string $name, TierEnum $tier, ?array $grants = null, ?Department $department = null): User
    {
        $position = null;
        if (null !== $grants) {
            $position = (new Position())->setName($name.'\'s seat')->setGrants($grants);
            $this->em()->persist($position);
        }
        $user = (new User())
            ->setEmail(strtolower($name).'@vivutio-travel.example')
            ->setFirstName($name)
            ->setLastName('Kimaro')
            ->setTier($tier)
            ->setPosition($position)
            ->setDepartment($department)
            ->setPassword('a hash, never a password');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function migrate(): void
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output), $output->fetch());
    }
}
