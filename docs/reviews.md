---
title: What guests say elsewhere
order: 7
---

# What guests say elsewhere

The restaurant's rating and its latest reviews on Google, Tripadvisor or Trustpilot, on its page
(`/carte`, the menu) and on a tile of the back office's dashboard - through
[glitchr/omnireview](https://github.com/glitchr-studio/omnireview). **Without the family, or without
a key, nothing shows**: no section, no tile, no call.

## Setting it up

```sh
composer require glitchr/omnireview omnireview/google      # tripadvisor, trustpilot as wanted
```

Register `Omnireview\Bridge\Symfony\OmnireviewBundle` (its Twig functions, the cache), configure its
gateways (keys in `.env.local` or the vault), then say where the restaurant is on each:

```yaml
# config/packages/omnireview.yaml
omnireview:
    gateways:
        google: { factory: google, options: { api_key: '%env(GOOGLE_PLACES_KEY)%', language: fr } }

# config/packages/restaurant.yaml
restaurant:
    reviews:
        places:
            google: 'ChIJ...'                     # the place ID: the one thing Google lets you keep
            # tripadvisor: '1234567'              # its location ID
            # trustpilot: '<business unit id>'
        owners:
            google: 'accounts/<a>/locations/<l>'  # with the owner's access (Business Profile): every review, and whether it was answered
        limit: 3                                  # reviews per platform on the page
```

A platform listed in `places` whose omnireview gateway is not configured is left out.

## On the page

The menu's page ends with `@Restaurant/client/_reviews.html.twig`: a section per platform, drawn by
the family's own widget (`omnireview_widget()`, through `restaurant_review_widget()` so that the
bundle's templates compile on a site without the family). The widget always shows the origin, the
date and the link of each review, keeps them no longer than the platform allows (nothing for
Google, a day for Trustpilot), and builds **no JSON-LD**: reviews collected by a third party are not
marked up as the restaurant's own `AggregateRating`.

A site dresses it in `templates/bundles/RestaurantBundle/client/_reviews.html.twig` (the section)
and `templates/bundles/OmnireviewBundle/reviews.html.twig` (each platform), keeping what each
platform's terms ask (`omnireview_terms('google')`: the attribution, the authors, the links; for
Tripadvisor, a page kept out of search engines).

## The tile

The widget type `restaurant_reviews` lists the latest reviews over every platform, newest first,
and how many wait for an answer - where the platform says whether one was given: Google with the
owner's location, Trustpilot with its business user. A site places it on its dashboard:

```php
yield MenuItem::block('restaurant_reviews', 'Avis', 'fa-solid fa-star')->setSize(4);
```

Without a platform configured, the tile renders nothing.

## What it does not do

Replying and inviting a guest are the family's (`ReplyInterface`, `InviteInterface`): no screen of
the bundle does it yet. Nothing is stored in the restaurant's tables.
