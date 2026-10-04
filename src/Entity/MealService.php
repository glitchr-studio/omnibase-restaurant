<?php

namespace Base\Restaurant\Entity;

use Base\Restaurant\Repository\MealServiceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A meal service - lunch, dinner: the days it runs, the first and last
 * time a table may be booked, the step between two times, how long a table
 * is kept by party size, how many covers it takes in all, up to how many a
 * reservation is confirmed without the manager, and the layout of the room.
 *
 * When the restaurant is open at all is glitchr/omnibase's
 * Base\Service\OpeningHours (the usual week, the days off): a time is only
 * offered when the place is open then.
 */
#[ORM\Entity(repositoryClass: MealServiceRepository::class)]
#[ORM\Table(name: 'restaurant_meal_service')]
class MealService
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $name;

    /** @var list<int> ISO days, 1 (Monday) to 7 */
    #[ORM\Column(type: 'json')]
    private array $weekdays = [1, 2, 3, 4, 5, 6, 7];

    /** "12:00": the first time a table may be booked */
    #[ORM\Column(length: 5)]
    private string $firstSeating = '12:00';

    /** "13:30": the last one */
    #[ORM\Column(length: 5)]
    private string $lastSeating = '13:30';

    /** Minutes between two times offered */
    #[ORM\Column(type: 'integer')]
    private int $step = 15;

    /** @var array<string, int> up to so many covers => minutes the table is kept; the largest serves beyond */
    #[ORM\Column(type: 'json')]
    private array $durations = ['2' => 75, '4' => 90, '6' => 105, '99' => 120];

    /** Covers the kitchen takes for the whole service; null: the tables decide */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxCovers = null;

    /** Up to so many covers, a reservation is confirmed at once; beyond, the manager confirms it. Null: always. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $autoConfirm = 6;

    #[ORM\ManyToOne(targetEntity: Layout::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Layout $layout = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    public function __construct(string $name = '', string $firstSeating = '12:00', string $lastSeating = '13:30')
    {
        $this->name = $name;
        $this->setFirstSeating($firstSeating);
        $this->setLastSeating($lastSeating);
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    /** @return list<int> */
    public function getWeekdays(): array { return $this->weekdays; }

    public function setWeekdays(array $weekdays): self
    {
        $days = array_values(array_unique(array_filter(array_map('intval', $weekdays), fn (int $d) => $d >= 1 && $d <= 7)));
        sort($days);
        $this->weekdays = $days;

        return $this;
    }

    public function runsOn(\DateTimeInterface $day): bool
    {
        return $this->active && \in_array((int) $day->format('N'), $this->weekdays, true);
    }

    public function getFirstSeating(): string { return $this->firstSeating; }
    public function setFirstSeating(string $time): self { $this->firstSeating = self::time($time); return $this; }
    public function getLastSeating(): string { return $this->lastSeating; }
    public function setLastSeating(string $time): self { $this->lastSeating = self::time($time); return $this; }
    public function getStep(): int { return $this->step; }
    public function setStep(int $step): self { $this->step = max(5, min(120, $step)); return $this; }

    /** @return list<string> "12:00", "12:15"... the times offered, first to last */
    public function times(): array
    {
        $times = [];
        $from = self::minutes($this->firstSeating);
        $to = self::minutes($this->lastSeating);
        for ($m = $from; $m <= $to; $m += $this->step) {
            $times[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }

        return $times;
    }

    public function covers(string $time): bool
    {
        $m = self::minutes($time);

        return $m >= self::minutes($this->firstSeating) && $m <= self::minutes($this->lastSeating);
    }

    /** @return array<string, int> */
    public function getDurations(): array { return $this->durations; }

    public function setDurations(array $durations): self
    {
        $clean = [];
        foreach ($durations as $covers => $minutes) {
            if ((int) $covers > 0 && (int) $minutes > 0) {
                $clean[(string) (int) $covers] = (int) $minutes;
            }
        }
        uksort($clean, fn ($a, $b) => (int) $a <=> (int) $b);
        $this->durations = $clean ?: ['99' => 90];

        return $this;
    }

    /** "2: 75" per line, for a form */
    public function getDurationsText(): string
    {
        return implode("\n", array_map(fn ($c, $m) => $c.': '.$m, array_keys($this->durations), $this->durations));
    }

    public function setDurationsText(?string $text): self
    {
        $durations = [];
        foreach (preg_split('/\R/', (string) $text) as $line) {
            if (preg_match('/^\s*(\d+)\s*[:=]\s*(\d+)/', $line, $m)) {
                $durations[$m[1]] = (int) $m[2];
            }
        }

        return $this->setDurations($durations);
    }

    /** Minutes a table is kept for so many covers. */
    public function durationFor(int $covers): int
    {
        $last = 90;
        foreach ($this->durations as $upTo => $minutes) {
            $last = $minutes;
            if ($covers <= (int) $upTo) {
                return $minutes;
            }
        }

        return $last;
    }

    public function getMaxCovers(): ?int { return $this->maxCovers; }
    public function setMaxCovers(?int $maxCovers): self { $this->maxCovers = $maxCovers > 0 ? $maxCovers : null; return $this; }
    public function getAutoConfirm(): ?int { return $this->autoConfirm; }
    public function setAutoConfirm(?int $autoConfirm): self { $this->autoConfirm = null === $autoConfirm ? null : max(0, $autoConfirm); return $this; }

    public function confirmsAtOnce(int $covers): bool
    {
        return null === $this->autoConfirm || $covers <= $this->autoConfirm;
    }

    public function getLayout(): ?Layout { return $this->layout; }
    public function setLayout(?Layout $layout): self { $this->layout = $layout; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [0, 0]);

        return $h * 60 + $m;
    }

    private static function time(string $time): string
    {
        if (!preg_match('/^(\d{1,2})[:hH](\d{2})$/', trim($time), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a time (HH:MM).', $time));
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
}
