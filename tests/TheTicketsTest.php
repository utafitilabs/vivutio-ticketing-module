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
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Ticketing\Entity\Carrier;
use Vivutio\Ticketing\Entity\Ticket;
use Vivutio\Ticketing\Enum\CarrierKindEnum;

/**
 * Tickets bought from carriers for travellers, as ruled (11 October 2026)
 * and drawn (vivutio-designs tickets/): recorded by hand, held to the
 * carrier's time limit or issued with a ticket number a traveller, changed,
 * refunded or voided, each step kept; billed to the traveller who pays or to
 * a client among the partners.
 */
final class TheTicketsTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $browser;
    private Carrier $airline;
    private Carrier $ferry;
    private Partner $company;

    protected function setUp(): void
    {
        self::mockTime('2026-11-10 11:42:00');
        $this->browser = static::createClient();
        $this->migrate();
        $this->airline = (new Carrier('Kilima Air', CarrierKindEnum::Air))->setCode('KL')->setPrefix('998')->setHowWeBuy('Agency account KA-2231');
        $this->ferry = new Carrier('Pwani Express', CarrierKindEnum::Ferry);
        $this->company = (new Partner('Mtandao Logistics', PartnerKindEnum::Company, 'TZ', 'travel@mtandao.example'))->setCreditDays(30);
        foreach ([$this->airline, $this->ferry, $this->company,
            new Partner('Savanna Trails Travel', PartnerKindEnum::TourOperator, 'KE', 'desk@savanna-trails.example'),
            new Partner('Ngorongoro Rim Camp', PartnerKindEnum::Accommodation, 'TZ', 'stay@rim-camp.example'), ] as $kept) {
            $this->em()->persist($kept);
        }
        $this->em()->flush();
    }

    public function testATicketIsRecordedIssuedAndReadOnItsPageAndTheRegister(): void
    {
        $this->signedInAs($this->person('Rehema', TierEnum::Admin));

        self::assertSame('/tickets', $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Tickets')->attr('href'));
        $this->record();
        $ticket = $this->only();
        self::assertResponseRedirects('/tickets/'.$ticket->getUuid());

        $page = $this->browser->followRedirect();
        self::assertSame('TK-0001', trim($page->filter('h1')->text()));
        self::assertSame('Issued', trim($page->filter('.title .chip')->text()));
        self::assertSame(['Kilima Air air · reference Q7LMBX', 'DAR → ZNZ → DAR 14 – 16 Nov 2026', '2 2 adults', 'USD 356.00'], $this->band($page));
        self::assertSame([
            'Sat 14 Nov 2026 · KL 112 · Economy, 20 kg | DAR 07:30 → ZNZ 07:55',
            'Mon 16 Nov 2026 · KL 117 · Economy, 20 kg | ZNZ 17:10 → DAR 17:35',
        ], $this->facts($page, 'The legs'));
        self::assertSame(['Amina Said · Adult | 998 2400 018 341', 'Joseph Mrema · Adult | 998 2400 018 342'], $this->facts($page, 'Travellers'));
        self::assertSame(['Client | Mtandao Logistics', 'Pays | in 30 days, a company', 'Asked for by | Grace Lema, for two of their staff', 'Issued by | Rehema Kimaro'], $this->facts($page, 'Billed to'));
        self::assertSame(['Fare, 2 × USD 158.00 | USD 316.00', 'Our fee, 2 × USD 20.00 | USD 40.00', 'Taxes, in the fare | USD 46.00', 'Charged | USD 356.00'], $this->facts($page, 'The money'));
        self::assertSame(['Issued, two ticket numbers recorded'], $this->history($page));

        $page = $this->browser->request('GET', '/tickets');
        self::assertSame(['TK-0001 Amina Said, Joseph Mrema', 'DAR → ZNZ → DAR Kilima Air · air', 'Sat 14 Nov · 07:30', 'Mtandao Logistics', 'Issued', 'USD 356.00'], $this->row($page, 'TK-0001'));
        self::assertSame(['All 1', 'Held 0', 'Issued 1', 'Refunded 0', 'Voided 0', 'Released 0'], $this->chips($page, 'status'));
        self::assertSame(['Any kind 1', 'Air 1', 'Ferry 0'], $this->chips($page, 'kind'));
    }

    public function testAHeldTicketIsIssuedWithItsNumbersOrLetGo(): void
    {
        $this->signedInAs($this->person('Rehema', TierEnum::Admin));
        $this->record(['status' => 'held', 'held_until' => '2026-11-11T17:00', 'billed_to' => '', 'asked_for' => '', 'travellers' => [['name' => 'Lukas Weber', 'fare_kind' => 'adult', 'fare' => '194.00', 'number' => '', 'seat' => '']]]);
        $this->record(['status' => 'held', 'held_until' => '2026-11-11T17:00', 'billed_to' => '', 'asked_for' => '', 'travellers' => [['name' => 'Ana Ortega', 'fare_kind' => 'adult', 'fare' => '194.00', 'number' => '', 'seat' => '']]]);
        [$held, $other] = $this->em()->getRepository(Ticket::class)->findBy([], ['reference' => 'ASC']);

        $page = $this->browser->request('GET', '/tickets/'.$held->getUuid());
        self::assertSame('Held to 11 Nov, 17:00', trim($page->filter('.title .chip')->text()));
        self::assertSame(['Lukas Weber · Adult | Not issued yet'], $this->facts($page, 'Travellers'));
        self::assertSame(['Paid by | Lukas Weber, the traveller', 'Recorded by | Rehema Kimaro'], $this->facts($page, 'Billed to'));
        self::assertSame('To charge', trim($page->filter('.band .k')->eq(3)->text()));

        $page = $this->browser->submit($page->selectButton('Record it issued')->form(['numbers' => ['123 4567 890 123']]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Kilima Air’s ticket numbers start 998.', trim($page->filter('.field.wrong .hint')->text()));

        $this->browser->submit($page->selectButton('Record it issued')->form(['numbers' => ['998 2400 018 377']]));
        $page = $this->browser->followRedirect();
        self::assertSame('Issued', trim($page->filter('.title .chip')->text()));
        self::assertSame(['Lukas Weber · Adult | 998 2400 018 377'], $this->facts($page, 'Travellers'));
        self::assertSame(['Issued, one ticket number recorded', 'Held to 11 Nov, 17:00, the carrier’s time limit'], $this->history($page));

        $page = $this->browser->request('GET', '/tickets/'.$other->getUuid());
        $this->browser->submit($page->selectButton('Let it go')->form());
        $page = $this->browser->followRedirect();
        self::assertSame('Released', trim($page->filter('.title .chip')->text()));
        self::assertCount(0, $page->selectButton('Record it issued'));
        self::assertSame(['Released 1'], array_values(array_filter($this->chips($this->browser->request('GET', '/tickets'), 'status'), static fn (string $chip): bool => str_starts_with($chip, 'Released'))));
    }

    public function testAnIssuedTicketIsChangedRefundedOrVoidedTheDayItWasIssued(): void
    {
        $this->signedInAs($this->person('Rehema', TierEnum::Admin));
        $this->record();
        $this->record();
        [$changed, $voided] = $this->em()->getRepository(Ticket::class)->findBy([], ['reference' => 'ASC']);

        $page = $this->browser->request('GET', '/tickets/'.$changed->getUuid().'/change');
        $form = $page->selectButton('Record the change')->form();
        $legs = $this->legs();
        $legs[1] = ['number' => 'KL 119', 'from' => 'ZNZ', 'to' => 'DAR', 'day' => '2026-11-17', 'leaves' => '08:15', 'arrives' => '08:40'];
        $this->browser->request('POST', (string) $form->getUri(), ['legs' => $legs, 'travellers' => $this->travellers(), 'change_fee' => '50.00', 'fare_difference' => '0', '_token' => $form->getValues()['_token']]);
        $page = $this->browser->followRedirect();
        self::assertSame('Tue 17 Nov 2026 · KL 119 · Economy, 20 kg | ZNZ 08:15 → DAR 08:40', $this->facts($page, 'The legs')[1]);
        self::assertSame('USD 406.00', $this->band($page)[3]);
        self::assertSame('Changes by the carrier | USD 50.00', $this->facts($page, 'The money')[3]);
        self::assertSame('Changed the legs; the carrier charged USD 50.00', $this->history($page)[0]);

        $this->browser->submit($page->selectButton('Record a refund')->form(['returned' => '316.00', 'kept' => '40.00']));
        $page = $this->browser->followRedirect();
        self::assertSame('Refunded', trim($page->filter('.title .chip')->text()));
        self::assertSame('Refunded: the carrier returns USD 316.00, we keep USD 40.00', $this->history($page)[0]);
        self::assertSame('USD 40.00', $this->row($this->browser->request('GET', '/tickets'), 'TK-0001')[5]);

        self::mockTime('2026-11-11 08:00:00');
        $page = $this->browser->request('GET', '/tickets/'.$voided->getUuid());
        self::assertCount(0, $page->selectButton('Void it'), 'a ticket is voided only the day it was issued');
        self::mockTime('2026-11-10 23:00:00');
        $page = $this->browser->request('GET', '/tickets/'.$voided->getUuid());
        $this->browser->submit($page->selectButton('Void it')->form());
        $page = $this->browser->followRedirect();
        self::assertSame('Voided', trim($page->filter('.title .chip')->text()));
        self::assertSame('USD 0.00', $this->band($page)[3]);
        self::assertCount(0, $page->selectButton('Record a refund'));
    }

    public function testATicketIsBilledToTheTravellerOrAClient(): void
    {
        $this->signedInAs($this->person('Rehema', TierEnum::Admin));

        $page = $this->browser->request('GET', '/tickets/new');
        self::assertSame(['The traveller who pays', 'Mtandao Logistics', 'Savanna Trails Travel'], $page->filter('select[name="billed_to"] option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame(['Air' => ['Kilima Air'], 'Ferry' => ['Pwani Express']], $this->carrierChoices($page));

        $camp = $this->em()->getRepository(Partner::class)->findOneBy(['name' => 'Ngorongoro Rim Camp']);
        self::assertNotNull($camp);
        $page = $this->record(['billed_to' => (string) $camp->getUuid()], 422);
        self::assertSame('Bill a company or a tour operator you trade with, or the traveller.', trim($page->filter('.field.wrong .hint')->text()));
    }

    public function testWhatIsTypedIsRefusedWithItsReason(): void
    {
        $this->signedInAs($this->person('Rehema', TierEnum::Admin));

        foreach ([
            'legs' => [['legs' => []], 'At least one leg: where from, where to, the day and the time it leaves.'],
            'legs[1][day]' => [['legs' => [$this->legs()[0], ['number' => 'KL 117', 'from' => 'ZNZ', 'to' => 'DAR', 'day' => '16 Nov', 'leaves' => '17:10', 'arrives' => '17:35']]], 'A day as 16.11.2026.'],
            'travellers' => [['travellers' => []], 'At least one traveller, by the name on their ID.'],
            'travellers[0][fare]' => [['travellers' => [['name' => 'Amina Said', 'fare_kind' => 'adult', 'fare' => 'cheap', 'number' => '998 2400 018 341', 'seat' => '']]], 'A fare is an amount to the cent: 158.00.'],
            'travellers[0][number]' => [['travellers' => [['name' => 'Amina Said', 'fare_kind' => 'adult', 'fare' => '158.00', 'number' => '', 'seat' => '']]], 'An issued ticket has its number.'],
            'currency' => [['currency' => 'dollars'], 'A currency is its three-letter code: USD, TZS, EUR.'],
            'held_until' => [['status' => 'held', 'held_until' => ''], 'The day and time the carrier holds it to.'],
            'carrier' => [['carrier' => '0199b1c0-0000-7000-8000-00000000dead'], 'Choose one of the carriers you keep.'],
        ] as $field => [$values, $reason]) {
            $page = $this->record($values, 422);
            $wrong = $page->filter('.field.wrong');
            self::assertCount(1, $wrong, $field);
            $input = $wrong->filter('input, select, textarea');
            self::assertSame($field, $input->count() > 0 ? $input->attr('name') : $wrong->attr('data-field'), $field);
            self::assertSame($reason, trim($page->filter('.field.wrong .hint')->text()), $field);
        }
        self::assertCount(0, $this->em()->getRepository(Ticket::class)->findAll());
    }

    public function testCarriersAreKeptByTheAgency(): void
    {
        $this->signedInAs($this->person('Rehema', TierEnum::Admin));

        $page = $this->browser->request('GET', '/tickets/carriers');
        self::assertSame([['Kilima Air', 'Air', 'KL', '998', 'Agency account KA-2231', '0'], ['Pwani Express', 'Ferry', '—', '—', '', '0']], $page->filter('tr[data-carrier]')->each(static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => trim($cell->text()))));

        $page = $this->browser->submit($page->selectButton('Add the carrier')->form(['name' => 'Mto Coaches', 'kind' => 'air', 'code' => 'MTO', 'prefix' => '', 'how' => '']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('An airline’s code is its two letters or digits: KL.', trim($page->filter('.field.wrong .hint')->text()));
        $page = $this->browser->submit($page->selectButton('Add the carrier')->form(['name' => 'Kilima Air', 'kind' => 'air', 'code' => '', 'prefix' => '', 'how' => '']));
        self::assertSame('Kilima Air is kept already.', trim($page->filter('.field.wrong .hint')->text()));
        $page = $this->browser->submit($page->selectButton('Add the carrier')->form(['name' => 'Mto Coaches', 'kind' => 'bus', 'code' => '', 'prefix' => '', 'how' => 'By telephone to the Arusha office']));
        self::assertResponseRedirects('/tickets/carriers');
        self::assertCount(3, $this->browser->followRedirect()->filter('tr[data-carrier]'));
    }

    /** Reading tickets, recording one and managing them are three pairs, each a department must allow. */
    public function testTicketsAreReadWithTheirPairRecordedWithTheNextAndManagedWithTheLast(): void
    {
        $this->signedInAs($this->person('Rehema', TierEnum::Admin));
        $this->record();
        $ticket = $this->only();
        $desk = (new Department())->setName('Ticketing desk')->setAllows(['tickets.read', 'tickets.record', 'tickets.manage']);
        $this->em()->persist($desk);
        $this->em()->flush();
        $reader = $this->person('Daudi', TierEnum::Staff, ['tickets.read'], $desk);
        $recorder = $this->person('Neema', TierEnum::Staff, ['tickets.read', 'tickets.record'], $desk);

        $this->signedInAs($reader);
        $page = $this->browser->request('GET', '/tickets');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->selectLink('New ticket'));
        self::assertCount(0, $page->selectButton('Add the carrier'));
        $page = $this->browser->request('GET', '/tickets/'.$ticket->getUuid());
        self::assertCount(0, $page->selectButton('Record a refund'));
        $this->browser->request('GET', '/tickets/new');
        self::assertResponseStatusCodeSame(403);

        $this->signedInAs($recorder);
        $this->record();
        self::assertCount(2, $this->em()->getRepository(Ticket::class)->findAll());
        $this->browser->request('GET', '/tickets/'.$ticket->getUuid().'/change');
        self::assertResponseStatusCodeSame(403);

        $this->signedInAs($this->person('Baraka', TierEnum::Staff, ['tickets.read', 'tickets.record', 'tickets.manage']));
        $this->browser->request('GET', '/tickets');
        self::assertResponseStatusCodeSame(403, 'nobody outside a department reaches a module');
    }

    /** The module ships the migration its mapping needs, and nothing is left for a diff to write. */
    public function testTheMigrationsBuildWhatTheMappingDescribes(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:schema:validate', '--skip-property-types' => true]), $output), $output->fetch());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function record(array $values = [], int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', '/tickets/new');
        $form = $page->selectButton('Record the ticket')->form();
        $page = $this->browser->request('POST', (string) $form->getUri(), [...[
            'carrier' => (string) $this->airline->getUuid(),
            'their_reference' => 'Q7LMBX',
            'class' => 'Economy',
            'baggage' => '20 kg',
            'legs' => $this->legs(),
            'travellers' => $this->travellers(),
            'currency' => 'USD',
            'fee' => '20.00',
            'taxes' => '46.00',
            'billed_to' => (string) $this->company->getUuid(),
            'asked_for' => 'Grace Lema, for two of their staff',
            'status' => 'issued',
            'held_until' => '',
            'notes' => 'Window seats if the carrier has them.',
            '_token' => $form->getValues()['_token'],
        ], ...$values]);
        self::assertResponseStatusCodeSame($answered);

        return $page;
    }

    /**
     * @return list<array<string, string>>
     */
    private function legs(): array
    {
        return [
            ['number' => 'KL 112', 'from' => 'DAR', 'to' => 'ZNZ', 'day' => '2026-11-14', 'leaves' => '07:30', 'arrives' => '07:55'],
            ['number' => 'KL 117', 'from' => 'ZNZ', 'to' => 'DAR', 'day' => '2026-11-16', 'leaves' => '17:10', 'arrives' => '17:35'],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function travellers(): array
    {
        return [
            ['name' => 'Amina Said', 'fare_kind' => 'adult', 'fare' => '158.00', 'number' => '998 2400 018 341', 'seat' => ''],
            ['name' => 'Joseph Mrema', 'fare_kind' => 'adult', 'fare' => '158.00', 'number' => '998 2400 018 342', 'seat' => ''],
        ];
    }

    private function only(): Ticket
    {
        $tickets = $this->em()->getRepository(Ticket::class)->findAll();
        self::assertCount(1, $tickets);

        return $tickets[0];
    }

    /**
     * The band's figures, each with its note: "2 2 adults".
     *
     * @return list<string>
     */
    private function band(Crawler $page): array
    {
        return $page->filter('.band .f')->each(function (Crawler $field): string {
            $all = $this->text($field->filter('.v'));
            $note = $this->text($field->filter('.v em'));

            return trim(mb_substr($all, 0, mb_strlen($all) - mb_strlen($note)).' '.$note);
        });
    }

    /**
     * The facts of the panel headed so, "what | answer".
     *
     * @return list<string>
     */
    private function facts(Crawler $page, string $heading): array
    {
        $panel = $page->filter('section.panel')->reduce(static fn (Crawler $panel): bool => $heading === trim($panel->filter('.panel-head h2')->text('')));
        self::assertCount(1, $panel, $heading);

        return $panel->filter('.fact')->each(fn (Crawler $fact): string => $this->text($fact->filter('span')).' | '.$this->text($fact->filter('b')));
    }

    /**
     * @return list<string>
     */
    private function history(Crawler $page): array
    {
        return $page->filter('.timeline li')->each(fn (Crawler $item): string => $this->text($item->filter('[data-said]')));
    }

    /**
     * @return list<string>
     */
    private function row(Crawler $page, string $reference): array
    {
        $cells = $page->filter('tr[data-ticket="'.$reference.'"] td')->each(function (Crawler $cell): string {
            $all = $this->text($cell);
            $small = $this->text($cell->filter('small'));

            return trim(mb_substr($all, 0, mb_strlen($all) - mb_strlen($small)).' '.$small);
        });

        return \array_slice($cells, 0, 6);
    }

    /**
     * @return list<string>
     */
    private function chips(Crawler $page, string $facet): array
    {
        return $page->filter('[data-facet="'.$facet.'"] a[data-chip]')->each(fn (Crawler $chip): string => $this->text($chip));
    }

    /**
     * @return array<string, list<string>>
     */
    private function carrierChoices(Crawler $page): array
    {
        $choices = [];
        $page->filter('select[name="carrier"] optgroup')->each(static function (Crawler $group) use (&$choices): void {
            $choices[(string) $group->attr('label')] = $group->filter('option')->each(static fn (Crawler $option): string => trim($option->text()));
        });

        return $choices;
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

    private function signedInAs(User $user): void
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
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
