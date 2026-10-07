<?php

namespace Base\Restaurant\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the public reservation form holds (omnibase's FormFactory takes a
 * model, not an entity): the day, the time, how many, who, what they ask.
 * The robots are the form's guard's (glitchr/omnibase's option `guard`).
 */
class Booking
{
    #[Assert\NotBlank]
    #[Assert\Regex('/^\d{4}-\d{2}-\d{2}$/')]
    public ?string $day = null;

    #[Assert\NotBlank(message: 'book.error.time')]
    #[Assert\Regex('/^\d{2}:\d{2}$/')]
    public ?string $time = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 30)]
    public ?int $covers = 2;

    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 120)]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Regex('/^[0-9+().\s-]{6,40}$/', message: 'book.error.phone')]
    public ?string $phone = null;

    #[Assert\Length(max: 1000)]
    public ?string $notes = null;

    #[Assert\Length(max: 500)]
    public ?string $allergies = null;
}
