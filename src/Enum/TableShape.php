<?php

namespace Base\Restaurant\Enum;

/** How a table is drawn on the floor plan. A counter is a row of stools: one seat per 60 cm. */
enum TableShape: string
{
    case ROUND = 'round';
    case SQUARE = 'square';
    case RECTANGLE = 'rectangle';
    case COUNTER = 'counter';

    public function trans(): string
    {
        return 'table.shape.'.$this->value;
    }
}
