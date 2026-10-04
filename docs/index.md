---
title: omnibase/restaurant
order: 1
---

# omnibase/restaurant

A restaurant's site on omnibase: the room and its reservations, the menu,
ordering at the table by QR code, the staff's screen, the delivery and
booking platforms. The site carries the identity; the bundle does the work.

| Page | |
|---|---|
| [Installation](installation.md) | the package, the routes, the configuration, the Stimulus controllers, the theme |
| [The room and the reservations](room-and-reservations.md) | rooms, tables, layouts, the floor editor, QR posters, meal services, availability, the public form, the management link |
| [The menu and ordering at the table](menu-and-table.md) | dishes, sections, set menus, allergens, `/t/{token}`, sessions and tickets |
| [The pass](pass.md) | `/service`: the floor, the tickets, the book, what the staff do there |
| [The platforms](platforms.md) | glitchr/omnifood: webhooks, answers, the menu, the keys |
| [Roadmap](roadmap.md) | what waits for omnibase/marketplace, and the hooks left for it |

Namespace `Base\Restaurant\`, translations in the `restaurant` domain (fr, en,
de, ja), tables prefixed `restaurant_`.
