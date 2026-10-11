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

namespace Vivutio\Ticketing;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Ticketing: tickets bought from airlines, ferries, coaches and railways for
 * travellers, recorded by hand, held, issued, changed, refunded or voided,
 * and billed to the traveller who pays or to a client among the partners.
 */
final class VivutioTicketingBundle extends AbstractBundle
{
    /** Configuration lives under "ticketing:". */
    protected string $extensionAlias = 'ticketing';

    /**
     * Its entities' mapping and its migrations, prepended so the installation
     * keeps the last word; each guarded.
     *
     * @see https://symfony.com/doc/current/bundles/prepend_extension.html
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'Ticketing' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Vivutio\\Ticketing\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ], prepend: true);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Vivutio\\Ticketing\\Migrations' => \dirname(__DIR__).'/migrations',
                ],
            ], prepend: true);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
