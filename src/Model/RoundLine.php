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

    /**
     * The dish's options chosen (omnibase/marketplace's Product\Option), by
     * id; a text is kept as a word for the kitchen ("sans oignons").
     *
     * @var list<int|string>
     */
    #[Assert\Count(max: 12)]
    #[Assert\All([new Assert\Type(type: ['int', 'string']), new Assert\Length(max: 80)])]
    public array $options = [];

    /** @return list<int> the option ids */
    public function optionIds(): array
    {
        return array_values(array_map('intval', array_filter($this->options, fn ($o) => \is_int($o) || ctype_digit((string) $o))));
    }

    /** @return list<string> the words that are not option ids */
    public function optionWords(): array
    {
        return array_values(array_filter($this->options, fn ($o) => !\is_int($o) && !ctype_digit((string) $o) && '' !== trim((string) $o)));
    }

    #[Assert\Length(max: 255)]
    public ?string $note = null;
}
