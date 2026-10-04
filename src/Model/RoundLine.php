<?php

namespace Base\Restaurant\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** One line of a round sent from a table's phone. */
final class RoundLine
{
    #[Assert\Positive]
    public int $dish = 0;

    #[Assert\Range(min: 1, max: 99)]
    public int $quantity = 1;

    /** @var list<string> */
    #[Assert\Count(max: 10)]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 80)])]
    public array $options = [];

    #[Assert\Length(max: 255)]
    public ?string $note = null;
}
