<?php

namespace Base\Restaurant\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * An order of the take-home shop as its form posts it (#[MapRequestPayload]):
 * the dishes and how many, who, and how it leaves - collected on a slot,
 * brought to a postcode nearby, or sent in a chilled parcel.
 */
final class TakeHomeOrder
{
    /** @var array<int|string, int|string> dish id => quantity (zero: not taken) */
    #[Assert\Count(min: 1, max: 60)]
    #[Assert\All([new Assert\Range(min: 0, max: 99)])]
    public array $lines = [];

    #[Assert\NotBlank, Assert\Email, Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank, Assert\Length(max: 120)]
    public string $name = '';

    #[Assert\NotBlank, Assert\Length(max: 32), Assert\Regex('/^[0-9+().\s\-]{6,}$/')]
    public string $phone = '';

    #[Assert\Choice(choices: ['pickup', 'delivery', 'shipping'])]
    public string $mode = 'pickup';

    /** The day it is for: collected, brought, or handed to the carrier. */
    #[Assert\NotBlank, Assert\Date]
    public string $day = '';

    #[Assert\Regex('/^\d{2}:\d{2}-\d{2}:\d{2}$/')]
    public ?string $slot = null;

    #[Assert\Length(max: 500)]
    public ?string $address = null;

    #[Assert\Length(max: 16)]
    public ?string $postcode = null;

    #[Assert\Length(max: 120)]
    public ?string $city = null;

    #[Assert\Length(max: 500)]
    public ?string $note = null;

    /** The trap: a field people do not see, and robots fill. */
    public ?string $website = null;

    public function isRobot(): bool
    {
        return '' !== trim((string) $this->website);
    }

    /** @return array<int, int> dish id => quantity, those taken */
    public function quantities(): array
    {
        $taken = [];
        foreach ($this->lines as $dish => $quantity) {
            if ((int) $quantity > 0) {
                $taken[(int) $dish] = (int) $quantity;
            }
        }

        return $taken;
    }
}
