<?php

namespace Base\Restaurant\Reviews;

use Omnireview\Bridge\Twig\OmnireviewExtension;
use Omnireview\Model\Place;
use Omnireview\Model\Review;
use Omnireview\Registry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * The restaurant on the review platforms (glitchr/omnireview): where it
 * is on each (restaurant.reviews.places), its latest reviews, those it has
 * not answered. Everything is asked through the family's Twig functions
 * (OmnireviewExtension): kept no longer than each platform allows, nothing
 * when a platform does not answer. A platform whose gateway is not
 * configured - no key - is left out: nothing shows.
 *
 * Registered only when glitchr/omnireview is installed; it implements none
 * of its interfaces.
 */
class RestaurantReviews
{
    /**
     * @param array<string, string> $places gateway => the restaurant's ID there
     * @param array<string, string> $owners gateway => the owner's location (Google Business Profile)
     */
    public function __construct(
        private readonly ?Registry $registry = null,
        private readonly ?OmnireviewExtension $omnireview = null,
        #[Autowire('%restaurant.reviews.places%')] private readonly array $places = [],
        #[Autowire('%restaurant.reviews.owners%')] private readonly array $owners = [],
        #[Autowire('%restaurant.reviews.limit%')] private readonly int $limit = 3,
    ) {
    }

    /** @return list<array{gateway: string, place: Place}> the platforms the restaurant shows, their gateway configured */
    public function places(): array
    {
        if (null === $this->registry || null === $this->omnireview) {
            return [];
        }
        $shown = [];
        foreach ($this->places as $gateway => $id) {
            if ('' !== (string) $id && $this->registry->has($gateway)) {
                $shown[] = ['gateway' => $gateway, 'place' => new Place((string) $id, null, $this->owners[$gateway] ?? null)];
            }
        }

        return $shown;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /** The family's widget for one platform: its rating and latest reviews, their origin, date and link. */
    public function widget(Environment $twig, string $gateway, Place $place, ?int $limit = null): string
    {
        return $this->omnireview?->widget($twig, $gateway, $place, $limit ?? $this->limit) ?? '';
    }

    /**
     * The latest reviews over every platform, newest first.
     *
     * @return list<array{gateway: string, review: Review, answerable: bool}>
     */
    public function latest(int $limit = 5): array
    {
        $all = [];
        foreach ($this->places() as ['gateway' => $gateway, 'place' => $place]) {
            if (!$this->registry->get($gateway)->capabilities()->reviews) {
                continue;
            }
            $answerable = $this->registry->get($gateway)->capabilities()->reply && null !== $place->owner;
            foreach ($this->omnireview->reviews($gateway, $place, max($limit, 5)) as $review) {
                $all[] = ['gateway' => $gateway, 'review' => $review, 'answerable' => $answerable];
            }
        }
        usort($all, static fn (array $a, array $b) => ($b['review']->publishedAt?->getTimestamp() ?? 0) <=> ($a['review']->publishedAt?->getTimestamp() ?? 0));

        return \array_slice($all, 0, $limit);
    }

    /**
     * The reviews not answered yet, where the platform says whether one was:
     * the owner's side (Google Business Profile, Trustpilot's business user).
     *
     * @return list<array{gateway: string, review: Review, answerable: bool}>
     */
    public function unanswered(int $limit = 20): array
    {
        return \array_slice(array_values(array_filter($this->latest(50), static fn (array $r) => $r['answerable'] && null === $r['review']->reply)), 0, $limit);
    }
}
