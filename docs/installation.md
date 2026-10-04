---
title: Installation
order: 2
---

# Installation

```sh
composer require omnibase/restaurant          # needs glitchr/omnibase 3.x, omnibase/admin, omnibase/marketplace
composer require glitchr/omnifood omnifood/ubereats omnifood/thefork   # optional: the platforms
```

```php
// config/bundles.php
Base\Restaurant\RestaurantBundle::class => ['all' => true],
Omnifood\Bridge\Symfony\OmnifoodBundle::class => ['all' => true],   // with glitchr/omnifood
```

```yaml
# config/routes.yaml
restaurant_controller:                 # /carte, /reserver, /reservation/{token}, /t/{token}, /service, the platforms' webhook
    resource: "@RestaurantBundle/src/Controller/Client"
    type: attribute
restaurant_admin_controller:           # /admin/restaurant/salle, /admin/restaurant/affiches
    resource: "@RestaurantBundle/src/Controller/Admin/FloorController.php"
    type: attribute
```

The CRUD screens (rooms, tables, layouts, meal services, reservations,
dishes, set menus, sections, tickets) are routed by omnibase/admin
(`type: base_admin`); link them from the site's dashboard with
`MenuItem::linkToCrud(...)`, the floor editor with
`MenuItem::linkToRoute('restaurant_admin_floor')`, and add the
`restaurant_today` widget.

Then the schema: `bin/console make:migration`, `doctrine:migrations:migrate`.
The site needs `framework.lock` (symfony/lock) and a mailer.

## Configuration

```yaml
# config/packages/restaurant.yaml - the defaults
restaurant:
    layout: 'layout1.html.twig'      # the site's frame: blocks title, description, content, stylesheets
    staff_role: ROLE_STAFF           # who runs the pass
    manager_role: ROLE_ADMIN         # who edits the floor plan and prints the posters
    from_email: ~                    # sender of the reservation mails
    notify_email: ~                  # where a request to confirm is announced
    reservation:
        notice: 60                   # minutes ahead, online
        horizon: 60                  # days ahead, online
        max_covers: 8                # beyond: "call us"
        min_delay: 3                 # seconds: a form sent faster is a robot's
        cancel_until: 120            # minutes before: the guest may still change or cancel online
    table:
        ordering: true               # false: the QR code shows the menu only
        pay_online: false            # see the roadmap
        rate_limit: 20               # requests a minute per table and address
        max_lines: 40
        max_quantity: 20
    pass:
        poll: 6                      # seconds between two looks at the state
        accept_warning: 180          # seconds before a platform's deadline the ticket turns red
```

When the restaurant is open - and closed - is glitchr/omnibase's
(`base.opening_hours`, `Base\Entity\Hours\*`): a time is only offered when the
place is open then. omnibase stores no `ROLE_STAFF` on an account: give it
through a `Base\Entity\User\Group` (roles `['ROLE_STAFF']`).

## Front end

The public pages use Stimulus controllers the site registers, with
glitchr/omnibase's `poll`:

```js
// assets/bootstrap.js
import Poll from '../vendor/glitchr/omnibase/assets/controllers/poll_controller.js';
import Booking from '../vendor/omnibase/restaurant/assets/controllers/booking_controller.js';
import Filter from '../vendor/omnibase/restaurant/assets/controllers/filter_controller.js';
import Table from '../vendor/omnibase/restaurant/assets/controllers/table_controller.js';
import Pass from '../vendor/omnibase/restaurant/assets/controllers/pass_controller.js';

app.register('poll', Poll);
app.register('restaurant-booking', Booking);
app.register('restaurant-filter', Filter);
app.register('restaurant-table', Table);
app.register('restaurant-pass', Pass);
```

A page that polls loads whole: list `/t/*` and `/service*` among
`Base.boot()`'s `exceptions`. When the bundle is a symlinked path repository,
tell webpack where its imports are:
`config.resolve.modules = [path.resolve(__dirname, 'node_modules'), 'node_modules']`.

`bundles/restaurant/css/restaurant.css` is painted through custom
properties, on `:root` and again for a dark theme:

```css
:root {
    --restaurant-ink: #241b2b;  --restaurant-soft: #6b5a78;
    --restaurant-bg: #fff;      --restaurant-paper: #f7f3f9;  --restaurant-line: rgba(67, 43, 82, .16);
    --restaurant-accent: #432B52; --restaurant-on-accent: #fff; --restaurant-hot: #E07A2F;
    --restaurant-font: "Roboto Slab", serif; --restaurant-native: "M PLUS Rounded 1c", sans-serif;
    --restaurant-sticky-top: 3.4rem;   /* under the site's fixed header */
}
```

The floor editor (`bundles/restaurant/js/floor.js`, `css/floor.css`) is a
plain script of the back office: nothing to register.
