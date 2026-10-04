---
title: The room and the reservations
order: 3
---

# The room

| Entity | |
|---|---|
| `Room` | a name, a size in centimetres, its decor (walls, counter, doors, windows, kitchen, plants, labels) |
| `Table` | label, min and max covers, shape (round, square, rectangle, counter), place and rotation, `token` (its QR code), `active`, `bookable` (offered online), `ordering` (its phones may order) |
| `Layout` | a named arrangement of a room: each table's place there, tables out, tables **joined**; the default one, the one a `MealService` names, or one taking over given dates |

`Service\FloorPlan::floor($day, $service)` gives the tables in use and the
joined groups: a layout taking over that date, else the service's, else the
room's default, else the tables where they stand.

## The floor editor

`/admin/restaurant/salle` (`restaurant_admin_floor`, the manager's role; it
opens in the nest on `@Admin/layout.html.twig`). The room is drawn to scale
in SVG. Click selects (shift-click adds), drag moves on the grid (5, 10,
25 cm), arrows nudge, `R` turns by 45°, `D` duplicates, `Delete` removes;
the panel edits the label, the covers, the shape, the size. On the base plan
the tables' own places are saved; in a layout, their places there, which are
out, and the joins (select several, "Jumeler"). Saved as JSON
(`FloorController::save`).

## QR posters

`/admin/restaurant/affiches`: one A4 poster per table, or
`?format=L7121|L7160|L7163` for label sheets (glitchr/omnibase's `QrSheet`).
Each code is the table's `/t/{token}`. "Renouveler le QR" on a table gives
it a new token: the poster printed before stops working.

# Reservations

A `MealService` says which days it runs, the first and last seating, the
step, how long a table is kept by party size (`durations`: `{2: 75, 4: 90,
99: 120}`), the covers the kitchen takes in all, up to how many guests a
reservation is confirmed at once (`autoConfirm`), and its layout.

`Service\Seating` reckons, with nothing read nor written (it is what the
unit tests exercise):

- a time is offered when the service runs that day, the place is open then
  (`OpeningHours::hoursOn()`, days off included), it is far enough ahead,
  the service has covers left, and a table is free for as long as such a
  party keeps it;
- the table given is the smallest that seats the party; joined tables only
  when no single one does; online, a table's minimum and `bookable` hold -
  the staff may seat anyone anywhere.

`Service\Availability` reads the day and takes the reservation **under a
lock on the day** (`LockFactory`, `restaurant-book-<day>`): the free tables
are read again inside it, so two guests taking the last table get it once.

```php
$availability->slots($day, 4);                 // list<Model\Slot>: time, service, tables, minutes
$availability->openDays(2, null, 5);           // the next five days with a table for two
$reservations->request($booking, $locale);     // from the public form: booked, mailed (ICS attached)
$reservations->take($reservation, $day, '20:00', Reservation::SOURCE_PHONE);   // by the staff
$reservations->change($reservation, $day, $time, $covers);   // the guest, through the link
$reservations->cancel($reservation);
$reservations->move($reservation, ReservationStatus::SEATED);                  // the pass
```

Statuses: `requested → confirmed → seated → finished`, or `no_show`,
`cancelled`, `refused`; the first three hold their tables. Each change
dispatches `Event\ReservationChangedEvent`.

**Times.** A reservation's `startsAt` is the restaurant's wall clock, stored
and compared as written. Moments (a ticket's arrival, a deadline) are stored
in UTC through `Model\Instant`: omnibase sets PHP's zone per visitor, and a
plain `datetime_immutable` would be written in whatever zone the request is in.

## Public pages

| Route | |
|---|---|
| `restaurant_book` `/reserver` | the form (`Form\BookingType` on `Model\Booking`): day, covers, a time among those free (asked again from `/reserver/creneaux` as they change), name, e-mail, phone, allergies, a note; a trap field and a minimum delay; omnibase's `PrivacyType` notice |
| `restaurant_manage` `/reservation/{token}` | the guest's page: status, calendar file, another time, cancellation - until `cancel_until` minutes before |
| `restaurant_manage_ics` | the ICS (glitchr/omnibase's `Calendar\Ics`), in the restaurant's time zone |

Mails (`@Restaurant/email/reservation.html.twig`, in the guest's language):
confirmed, requested, changed, cancelled, refused; the restaurant is told of
a request to confirm and of a guest's cancellation (`notify_email`).
