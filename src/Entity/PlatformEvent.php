<?php

namespace Base\Restaurant\Entity;

use Base\Restaurant\Model\Instant;
use Doctrine\ORM\Mapping as ORM;

/**
 * A platform's webhook already handled: platforms retry, and the same event
 * (Omnifood\Model\Notification::$id) must make one ticket, not two.
 */
#[ORM\Entity]
#[ORM\Table(name: 'restaurant_platform_event')]
#[ORM\UniqueConstraint(name: 'restaurant_platform_event_id', columns: ['platform', 'eventId'])]
class PlatformEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    public function __construct(
        #[ORM\Column(length: 40)] private string $platform,
        #[ORM\Column(length: 190)] private string $eventId,
        #[ORM\Column(length: 80)] private string $event = '',
    ) {
        $this->receivedAt = Instant::now();
    }

    public function getId(): ?int { return $this->id; }
    public function getPlatform(): string { return $this->platform; }
    public function getEventId(): string { return $this->eventId; }
    public function getEvent(): string { return $this->event; }
    public function getReceivedAt(): \DateTimeImmutable { return Instant::read($this->receivedAt); }
}
