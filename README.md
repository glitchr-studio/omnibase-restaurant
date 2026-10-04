# omnibase/restaurant

A restaurant on [omnibase](https://github.com/glitchr-studio/omnibase): one bundle per trade, one site per restaurant.

- **The room**: rooms, tables, named layouts (tables moved, taken out, joined), a floor-plan editor in the back office (SVG, drag on a snapping grid, turn, duplicate, join), a QR poster per table.
- **Services and reservations**: meal services (`MealService`: days, seatings, table durations, automatic confirmation), opening hours and days off from glitchr/omnibase's `OpeningHours`, availability reckoned table by table and taken under a `symfony/lock`, a public form on a model, a management link (move, cancel), an ICS file.
- **The menu**: `Dish` extends omnibase/marketplace's `Product` (price before VAT, VAT, stock), sections, set menus, the EU allergens of glitchr/omnibase, diets, a filter, a printable menu.
- **Ordering at the table**: `/t/{token}` from the table's QR code, no account; rounds (`Ticket`) on the table's bill (`Session`); the waiter called, the bill asked for; `symfony/rate-limiter` per table.
- **The pass** (`/service`): the floor live, the tickets of every channel in columns, the day's book, dishes run out - polled with glitchr/omnibase's `poll` controller.
- **The platforms**, when [glitchr/omnifood](https://github.com/glitchr-studio/omnifood) is installed: webhooks made tickets and reservations, the pass's answers sent back, the menu pushed, keys typed in the back office.

```sh
composer require omnibase/restaurant
```

Documentation: [docs/](docs/index.md). Tests: `vendor/bin/phpunit` (no kernel needed).

Not there yet (waiting on omnibase/marketplace): take-home dishes (`TakeHome`, the cold chain, pickup and local delivery), dish options, paying a table's bill online - see [docs/roadmap.md](docs/roadmap.md).

License: LGPL-3.0-or-later.
