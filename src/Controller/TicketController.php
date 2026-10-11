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

namespace Vivutio\Ticketing\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Ticketing\Entity\Ticket;
use Vivutio\Ticketing\Enum\CarrierKindEnum;
use Vivutio\Ticketing\Enum\FareKindEnum;
use Vivutio\Ticketing\Enum\TicketStatusEnum;
use Vivutio\Ticketing\Exception\InvalidTicketException;
use Vivutio\Ticketing\Repository\TicketRepository;
use Vivutio\Ticketing\Service\CarrierService;
use Vivutio\Ticketing\Service\TicketService;

/**
 * Tickets: the register by status and kind, a new ticket recorded by hand, a
 * ticket's page, and issuing, letting go, changing, refunding or voiding it.
 */
final readonly class TicketController
{
    public const string REGISTER = 'ticketing_tickets';
    public const string NEW = 'ticketing_ticket_new';
    public const string SHOW = 'ticketing_ticket';
    public const string ISSUE = 'ticketing_ticket_issue';
    public const string RELEASE = 'ticketing_ticket_release';
    public const string CHANGE = 'ticketing_ticket_change';
    public const string REFUND = 'ticketing_ticket_refund';
    public const string VOID = 'ticketing_ticket_void';

    public const string READ = 'tickets.read';
    public const string RECORD = 'tickets.record';
    public const string MANAGE = 'tickets.manage';

    public function __construct(
        private Environment $twig,
        private TicketService $service,
        private CarrierService $carriers,
        private TicketRepository $tickets,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/tickets', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function register(Request $request): Response
    {
        $status = TicketStatusEnum::tryFrom($request->query->getString('status'));
        $kind = CarrierKindEnum::tryFrom($request->query->getString('kind'));
        $all = $this->tickets->findAll();
        usort($all, static fn (Ticket $a, Ticket $b): int => [$a->getLegs()[0]['day'], $a->getLegs()[0]['leaves'], $a->getReference()] <=> [$b->getLegs()[0]['day'], $b->getLegs()[0]['leaves'], $b->getReference()]);

        $statuses = array_fill_keys(array_map(static fn (TicketStatusEnum $case): string => $case->value, TicketStatusEnum::cases()), 0);
        $kinds = [];
        foreach ($this->carriers->byKind() as $kept) {
            $kinds[$kept[0]->getKind()->value] = 0;
        }
        $billed = [];
        $views = [];
        foreach ($all as $one) {
            $views[$one->getReference()] = $this->service->view($one);
            if (null === $kind || $one->getCarrier()->getKind() === $kind) {
                ++$statuses[$one->getStatus()->value];
            }
            if (null === $status || $one->getStatus() === $status) {
                $kinds[$one->getCarrier()->getKind()->value] = ($kinds[$one->getCarrier()->getKind()->value] ?? 0) + 1;
            }
            $billed[$one->getReference()] = $this->billedTo($one);
        }
        $shown = array_values(array_filter($all, static fn (Ticket $one): bool => (null === $status || $one->getStatus() === $status) && (null === $kind || $one->getCarrier()->getKind() === $kind)));

        return new Response($this->twig->render('@VivutioTicketing/tickets/index.html.twig', [
            'tickets' => $shown,
            'billed' => $billed,
            'views' => $views,
            'statuses' => TicketStatusEnum::cases(),
            'counts' => $statuses,
            'kinds' => array_map(static fn (string $value, int $n): array => ['kind' => CarrierKindEnum::from($value), 'tickets' => $n], array_keys($kinds), array_values($kinds)),
            'status' => $status,
            'kind' => $kind,
        ]));
    }

    #[Route('/tickets/new', name: self::NEW, methods: ['GET', 'POST'])]
    #[IsGranted(self::RECORD)]
    public function new(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->newPage([]);
        }
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('ticketing_ticket_new', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->newPage($sent, expired: true);
        }
        if (\is_string($sent['add'] ?? null)) {
            return $this->newPage($sent, add: $sent['add']);
        }

        try {
            $ticket = $this->service->record($sent, self::nameOf($user));
        } catch (InvalidTicketException $refusal) {
            return $this->newPage($sent, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($ticket);
    }

    #[Route('/tickets/{uuid}', name: self::SHOW, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Ticket $ticket,
    ): Response {
        return $this->ticketPage($ticket);
    }

    #[Route('/tickets/{uuid}/issue', name: self::ISSUE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function issue(
        Request $request,
        #[CurrentUser] UserInterface $user,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Ticket $ticket,
    ): Response {
        return $this->step($request, $ticket, fn () => $this->service->issue($ticket, $request->getPayload()->all('numbers'), self::nameOf($user)));
    }

    #[Route('/tickets/{uuid}/release', name: self::RELEASE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function release(
        Request $request,
        #[CurrentUser] UserInterface $user,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Ticket $ticket,
    ): Response {
        return $this->step($request, $ticket, fn () => $this->service->release($ticket, self::nameOf($user)));
    }

    #[Route('/tickets/{uuid}/refund', name: self::REFUND, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function refund(
        Request $request,
        #[CurrentUser] UserInterface $user,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Ticket $ticket,
    ): Response {
        $payload = $request->getPayload();

        return $this->step($request, $ticket, fn () => $this->service->refund($ticket, $payload->getString('returned'), $payload->getString('kept'), self::nameOf($user)));
    }

    #[Route('/tickets/{uuid}/void', name: self::VOID, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE)]
    public function void(
        Request $request,
        #[CurrentUser] UserInterface $user,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Ticket $ticket,
    ): Response {
        return $this->step($request, $ticket, fn () => $this->service->void($ticket, self::nameOf($user)));
    }

    #[Route('/tickets/{uuid}/change', name: self::CHANGE, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::MANAGE)]
    public function change(
        Request $request,
        #[CurrentUser] UserInterface $user,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Ticket $ticket,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->changePage($ticket, ['legs' => $ticket->getLegs(), 'travellers' => $ticket->getTravellers()]);
        }
        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('ticketing_ticket_change', \is_string($sent['_token'] ?? null) ? $sent['_token'] : ''))) {
            return $this->changePage($ticket, $sent, expired: true);
        }
        if (\is_string($sent['add'] ?? null)) {
            return $this->changePage($ticket, $sent, add: $sent['add']);
        }

        try {
            $this->service->change($ticket, $sent, self::nameOf($user));
        } catch (InvalidTicketException $refusal) {
            return $this->changePage($ticket, $sent, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($ticket);
    }

    /**
     * @param \Closure(): void $take
     */
    private function step(Request $request, Ticket $ticket, \Closure $take): Response
    {
        if (!$this->tokens->isTokenValid(new CsrfToken('ticketing_ticket_step', $request->getPayload()->getString('_token')))) {
            return $this->ticketPage($ticket, expired: true);
        }

        try {
            $take();
        } catch (InvalidTicketException $refusal) {
            return $this->ticketPage($ticket, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->to($ticket);
    }

    private function billedTo(Ticket $ticket): string
    {
        if (null === $ticket->getBilledTo()) {
            return $ticket->getTravellers()[0]['name'].', who pays';
        }

        return $this->service->clientOf($ticket)?->getName() ?? 'A partner no longer kept';
    }

    private static function nameOf(UserInterface $user): string
    {
        return $user instanceof User ? $user->getFullName() : $user->getUserIdentifier();
    }

    private function to(Ticket $ticket): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::SHOW, ['uuid' => $ticket->getUuid()]));
    }

    /**
     * @param array<mixed>          $sent
     * @param array<string, string> $wrong
     */
    private function newPage(array $sent, array $wrong = [], bool $expired = false, ?string $add = null): Response
    {
        $text = static fn (string $key, string $default = ''): string => \is_string($sent[$key] ?? null) ? $sent[$key] : $default;

        return new Response($this->twig->render('@VivutioTicketing/tickets/new.html.twig', [
            'carriers' => $this->carriers->byKind(),
            'clients' => $this->service->clients(),
            'fare_kinds' => FareKindEnum::cases(),
            'typed' => [
                'carrier' => $text('carrier'), 'their_reference' => $text('their_reference'), 'class' => $text('class', 'Economy'), 'baggage' => $text('baggage'),
                'currency' => $text('currency', 'USD'), 'fee' => $text('fee'), 'taxes' => $text('taxes'), 'billed_to' => $text('billed_to'),
                'asked_for' => $text('asked_for'), 'status' => $text('status', 'issued'), 'held_until' => $text('held_until'), 'notes' => $text('notes'),
            ],
            'legs' => self::rows($sent['legs'] ?? null, ['number', 'from', 'to', 'day', 'leaves', 'arrives'], 'leg' === $add, TicketService::MOST_LEGS),
            'travellers' => self::rows($sent['travellers'] ?? null, ['name', 'fare_kind', 'fare', 'number', 'seat'], 'traveller' === $add, TicketService::MOST_TRAVELLERS),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<mixed>          $sent
     * @param array<string, string> $wrong
     */
    private function changePage(Ticket $ticket, array $sent, array $wrong = [], bool $expired = false, ?string $add = null): Response
    {
        $text = static fn (string $key): string => \is_string($sent[$key] ?? null) ? $sent[$key] : '';

        return new Response($this->twig->render('@VivutioTicketing/tickets/change.html.twig', [
            'ticket' => $ticket,
            'legs' => self::rows($sent['legs'] ?? null, ['number', 'from', 'to', 'day', 'leaves', 'arrives'], 'leg' === $add, TicketService::MOST_LEGS),
            'travellers' => self::rows($sent['travellers'] ?? null, ['name', 'fare_kind', 'fare', 'number', 'seat'], false, TicketService::MOST_TRAVELLERS),
            'typed' => ['change_fee' => $text('change_fee'), 'fare_difference' => $text('fare_difference')],
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $wrong
     */
    private function ticketPage(Ticket $ticket, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioTicketing/tickets/ticket.html.twig', [
            'ticket' => $ticket,
            'view' => $this->service->view($ticket),
            'client' => $this->service->clientOf($ticket),
            'voidable' => $this->service->voidable($ticket),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The rows as typed, at least one, one more when asked, never more than
     * a ticket holds.
     *
     * @param list<string> $keys
     *
     * @return list<array<string, string>>
     */
    private static function rows(mixed $typed, array $keys, bool $more, int $most): array
    {
        $rows = [];
        foreach (array_values(\is_array($typed) ? $typed : []) as $row) {
            $rows[] = array_combine($keys, array_map(static fn (string $key): string => \is_array($row) && \is_string($row[$key] ?? null) ? $row[$key] : '', $keys));
        }
        if ([] === $rows || ($more && \count($rows) < $most)) {
            $rows[] = array_fill_keys($keys, '');
        }

        return \array_slice($rows, 0, $most);
    }
}
