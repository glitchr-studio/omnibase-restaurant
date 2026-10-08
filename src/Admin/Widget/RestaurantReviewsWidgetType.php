<?php

namespace Base\Restaurant\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Restaurant\Reviews\RestaurantReviews;

/**
 * What guests said lately on the review platforms (glitchr/omnireview):
 * the latest reviews, and those not answered yet where the platform tells
 * (the owner's side: Google Business Profile, Trustpilot). Empty - the tile
 * shows nothing - without the family or without a configured gateway.
 *
 *     yield MenuItem::block('restaurant_reviews', 'Avis', 'fa-solid fa-star')->setSize(4);
 */
final class RestaurantReviewsWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(private readonly ?RestaurantReviews $reviews = null)
    {
    }

    public static function getName(): string
    {
        return 'restaurant_reviews';
    }

    public function getTemplate(): string
    {
        return '@Restaurant/admin/widget/reviews.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        if (null === $this->reviews || [] === $this->reviews->places()) {
            return ['latest' => [], 'unanswered' => [], 'shown' => false];
        }

        return ['latest' => $this->reviews->latest(5), 'unanswered' => $this->reviews->unanswered(), 'shown' => true];
    }
}
