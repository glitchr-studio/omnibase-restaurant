<?php

namespace Base\Restaurant\Model;

use Base\Marketplace\Enum\PickupMode;

/**
 * One way a take-home order may leave (Service\ColdChain::options()):
 * whether this basket can go that way, why not when it cannot (a key of the
 * "restaurant" translations), and the days it may be for.
 */
final class HandOver
{
    /**
     * @param list<\DateTimeImmutable> $days
     * @param array<string, string|int> $parameters the reason's
     */
    public function __construct(
        public readonly PickupMode $mode,
        public readonly bool $available,
        public readonly array $days = [],
        public readonly ?string $reason = null,
        public readonly array $parameters = [],
    ) {
    }
}
