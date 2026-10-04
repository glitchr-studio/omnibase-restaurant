<?php

namespace Base\Restaurant\Event;

use Base\Restaurant\Entity\Menu\Dish;

/** A dish run out on the pass, or back: the Omnifood bridge suspends it on the platforms, or brings it back. */
final class DishAvailabilityEvent
{
    public function __construct(public readonly Dish $dish, public readonly bool $available)
    {
    }
}
