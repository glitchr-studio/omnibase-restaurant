---
title: The pass
order: 5
---

# The pass

`/service` (`restaurant_pass`, `restaurant.staff_role`): a page of the site,
one screen for the floor.

- **The room, live**: each table free, booked (within 45 minutes), seated,
  waiting on the kitchen, with something ready, calling, asking for the
  bill. A tap opens its card: its rounds, the total, "J'arrive", settled by
  card or in cash (the table is free again, its reservation finished), move
  the guests to another table, close the table to orders.
- **The tickets** of every channel in four columns - to accept, in the
  kitchen, ready, served - with one button for the next step, refuse or
  cancel; a platform's order shows its countdown and turns red
  `accept_warning` seconds before its deadline. `?poste=hot|cold|bar` keeps
  one station's lines.
  Settling a table leaves none of its rounds on the pass: what was still
  open - waiting, on the stove, ready - is served (`Session::closeTickets()`,
  a `TicketMovedEvent` each). Settling never changes what is billed: a round
  that was not made is cancelled before.
- **The shop's orders**: a paid take-home order is a ticket of the
  `takeaway` channel - "Retrait", "Livraison" or "Colis", the customer, the
  time - and moving it moves the hand-over its customer follows
  ([The take-home shop](take-home.md)).
- **The day's book**: confirm or refuse a request, seat (the table's bill
  opens, tied to the reservation), finish, absent; a form for a reservation
  taken on the phone or a walk-in seated now.
- **Run out**: a dish out of stock, or back.
- **The platforms**: pause for 30 minutes, resume (with glitchr/omnifood).

The board is drawn by the server (`_pass_board.html.twig`) and asked again
when `Service\Revision` has grown - a number bumped on every write of a
restaurant entity, kept in the cache. Two `poll` controllers read
`/service/etat`: `revision` redraws, `latest` (the newest ticket to accept)
rings once the alerts are armed by a tap. Every tap is a POST with the pass's
CSRF token (`restaurant-pass`), answered in JSON to the page's script.

The dashboard widget `restaurant_today` shows the day's covers, the
reservations to confirm and the tickets open.
