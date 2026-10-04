<?php

namespace Base\Restaurant\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the public reservation form holds (omnibase's FormFactory takes a
 * model, not an entity): the day, the time, how many, who, what they ask.
 * $website is the trap no person fills; $startedAt how long the form took.
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

    /** The honeypot: hidden off-screen, a robot fills it */
    public ?string $website = null;

    /** Unix time the form was shown */
    public ?int $startedAt = null;

    public function isRobot(int $minDelay = 3, ?int $now = null): bool
    {
        if ('' !== trim((string) $this->website)) {
            return true;
        }

        return null !== $this->startedAt && (($now ?? time()) - $this->startedAt) < $minDelay;
    }
}
