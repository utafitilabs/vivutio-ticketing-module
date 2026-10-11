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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Ticketing\Enum\CarrierKindEnum;
use Vivutio\Ticketing\Exception\InvalidTicketException;
use Vivutio\Ticketing\Service\CarrierService;

/**
 * The carriers the agency buys from: read with the tickets, added by those
 * who manage them.
 */
final readonly class CarrierController
{
    public const string REGISTER = 'ticketing_carriers';
    public const string ADD = 'ticketing_carrier_add';

    public function __construct(
        private Environment $twig,
        private CarrierService $carriers,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/tickets/carriers', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(TicketController::READ)]
    public function register(): Response
    {
        return $this->page([]);
    }

    #[Route('/tickets/carriers', name: self::ADD, methods: ['POST'])]
    #[IsGranted(TicketController::MANAGE)]
    public function add(Request $request): Response
    {
        $sent = $request->getPayload();
        $typed = ['name' => $sent->getString('name'), 'kind' => $sent->getString('kind'), 'code' => $sent->getString('code'), 'prefix' => $sent->getString('prefix'), 'how' => $sent->getString('how')];
        if (!$this->tokens->isTokenValid(new CsrfToken('ticketing_carrier_add', $sent->getString('_token')))) {
            return $this->page($typed, expired: true);
        }

        try {
            $this->carriers->add($typed['name'], $typed['kind'], $typed['code'], $typed['prefix'], $typed['how']);
        } catch (InvalidTicketException $refusal) {
            return $this->page($typed, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::REGISTER));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function page(array $typed, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioTicketing/carriers/index.html.twig', [
            'carriers' => $this->carriers->register(),
            'kinds' => CarrierKindEnum::cases(),
            'typed' => [...['name' => '', 'kind' => 'air', 'code' => '', 'prefix' => '', 'how' => ''], ...$typed],
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
