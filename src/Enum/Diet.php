<?php

namespace Base\Restaurant\Enum;

/** What a dish suits, shown as pills and filtered on the menu (the allergens are glitchr/omnibase's Base\Enum\Allergen). */
enum Diet: string
{
    case VEGETARIAN = 'vegetarian';
    case VEGAN = 'vegan';
    case NO_PORK = 'no_pork';
    case GLUTEN_FREE = 'gluten_free';

    /** @return array<string, string> label key => value, for a ChoiceType */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->trans()] = $case->value;
        }

        return $choices;
    }

    /** @param iterable<string|self> $values @return list<self> */
    public static function fromList(iterable $values): array
    {
        $found = [];
        foreach ($values as $value) {
            $case = $value instanceof self ? $value : self::tryFrom((string) $value);
            if ($case) {
                $found[$case->value] = $case;
            }
        }

        return array_values(array_filter(self::cases(), fn (self $c) => isset($found[$c->value])));
    }

    public function trans(): string
    {
        return 'diet.'.$this->value;
    }
}
