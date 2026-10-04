<?php

namespace Base\Restaurant\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** A round sent from a table's phone (#[MapRequestPayload]): its lines, and a word for the kitchen. */
final class Round
{
    /** @var list<RoundLine> */
    #[Assert\Count(min: 1, max: 60)]
    #[Assert\Valid]
    public array $lines = [];

    #[Assert\Length(max: 500)]
    public ?string $note = null;

    #[Assert\Range(min: 1, max: 30)]
    public ?int $covers = null;
}
