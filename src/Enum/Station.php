<?php

namespace Base\Restaurant\Enum;

/** Where a dish is made: the hot kitchen, the cold side (sushi, salads, desserts), the bar. The pass filters by it. */
enum Station: string
{
    case HOT = 'hot';
    case COLD = 'cold';
    case BAR = 'bar';

    public function trans(): string
    {
        return 'station.'.$this->value;
    }
}
