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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vivutio\Contracts\Access\ConcernSourceInterface;
use Vivutio\Contracts\Partner\ChargeSourceInterface;
use Vivutio\Contracts\Partner\PartnerDirectoryInterface;
use Vivutio\Contracts\Settings\OrganizationIdentitySourceInterface;
use Vivutio\Contracts\Shell\DashboardSourceInterface;
use Vivutio\Contracts\Shell\MenuSourceInterface;
use Vivutio\Ticketing\Access\TicketingConcerns;
use Vivutio\Ticketing\Controller\CarrierController;
use Vivutio\Ticketing\Controller\TicketController;
use Vivutio\Ticketing\Partner\TicketCharges;
use Vivutio\Ticketing\Repository\CarrierRepository;
use Vivutio\Ticketing\Repository\TicketRepository;
use Vivutio\Ticketing\Service\CarrierService;
use Vivutio\Ticketing\Service\TicketService;
use Vivutio\Ticketing\Shell\TicketingDashboard;
use Vivutio\Ticketing\Shell\TicketingMenu;

/*
 * Every service is defined explicitly, with an id prefixed by the bundle's
 * alias; nothing is autowired or autoconfigured, so a tag is applied by hand.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html#services
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('ticketing.access.concerns', TicketingConcerns::class)
        ->tag(ConcernSourceInterface::TAG);
    $services->set('ticketing.menu', TicketingMenu::class)
        ->tag(MenuSourceInterface::TAG);

    $services->set(TicketRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');
    $services->set(CarrierRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set('ticketing.carriers', CarrierService::class)
        ->args([service('doctrine.orm.entity_manager'), service(CarrierRepository::class)]);
    $services->set('ticketing.tickets', TicketService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('clock'),
            service(TicketRepository::class),
            service(CarrierRepository::class),
            service(PartnerDirectoryInterface::class),
            service(OrganizationIdentitySourceInterface::SERVICE),
        ]);

    $services->set('ticketing.dashboard', TicketingDashboard::class)
        ->args([service(TicketRepository::class), service('ticketing.tickets'), service('clock')])
        ->tag(DashboardSourceInterface::TAG);

    $services->set('ticketing.charges', TicketCharges::class)
        ->args([service(TicketRepository::class), service(PartnerDirectoryInterface::class)])
        ->tag(ChargeSourceInterface::TAG);

    $services->set('ticketing.controller.tickets', TicketController::class)
        ->args([
            service('twig'),
            service('ticketing.tickets'),
            service('ticketing.carriers'),
            service(TicketRepository::class),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public();
    $services->alias(TicketController::class, 'ticketing.controller.tickets')->public();

    $services->set('ticketing.controller.carriers', CarrierController::class)
        ->args([service('twig'), service('ticketing.carriers'), service('security.csrf.token_manager'), service('router')])
        ->public();
    $services->alias(CarrierController::class, 'ticketing.controller.carriers')->public();
};
