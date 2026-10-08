<?php

namespace Base\Restaurant\Twig;

use Base\Restaurant\Reviews\RestaurantReviews;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * restaurant_reviews(): the platforms the restaurant's page shows reviews
 * from - none without glitchr/omnireview, none without a configured
 * gateway. restaurant_review_widget(gateway, place): the family's own
 * widget (omnireview_widget(), @Omnireview/reviews.html.twig), named here so
 * that the bundle's templates compile on a site without the family.
 */
final class ReviewsTwigExtension extends AbstractExtension
{
    public function __construct(private readonly ?RestaurantReviews $reviews = null)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('restaurant_reviews', fn (): array => $this->reviews?->places() ?? []),
            new TwigFunction('restaurant_reviews_limit', fn (): int => $this->reviews?->limit() ?? 3),
            new TwigFunction('restaurant_review_widget', fn (Environment $twig, string $gateway, object $place, ?int $limit = null): string => $this->reviews?->widget($twig, $gateway, $place, $limit) ?? '', ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }
}
