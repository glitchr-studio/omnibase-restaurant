<?php

namespace Base\Restaurant\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One number that grows whenever the floor changes - a reservation, a
 * bill, a ticket written (EventListener\RevisionListener): the pass and the
 * guests' phones ask it every few seconds (the poll controller) and redraw
 * when it has grown. Milliseconds, kept in the cache: no query to answer it.
 */
class Revision
{
    private const KEY = 'restaurant.revision';

    public function __construct(#[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache)
    {
    }

    public function current(): int
    {
        $item = $this->cache->getItem(self::KEY);

        return $item->isHit() ? (int) $item->get() : $this->bump();
    }

    public function bump(): int
    {
        $item = $this->cache->getItem(self::KEY);
        $value = max((int) floor(microtime(true) * 1000), ($item->isHit() ? (int) $item->get() : 0) + 1);
        $this->cache->save($item->set($value));

        return $value;
    }
}
