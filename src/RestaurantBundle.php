<?php

namespace Base\Restaurant;

use Base\Bundle\AbstractBaseBundle;
use Base\Traits\SingletonTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A restaurant on omnibase: the dining room, the services and their
 * reservations, the menu, ordering at the table and the pass - and the
 * delivery and booking platforms through glitchr/omnifood when it is there.
 */
class RestaurantBundle extends AbstractBaseBundle
{
    use SingletonTrait;

    public function __construct()
    {
        parent::__construct();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->setMapping($this->getPath().'/src/Entity', 'Base\Restaurant\Entity', 'App\Entity\Restaurant');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Restaurant\Repository', 'App\Repository\Restaurant');
    }
}
