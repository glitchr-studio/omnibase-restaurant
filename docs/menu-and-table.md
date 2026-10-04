---
title: The menu and ordering at the table
order: 4
---

# The menu

`Entity\Menu\Dish` extends `Base\Marketplace\Entity\Product`: its title and
text, its price **before VAT** and the store's VAT, its stock and
availability are the shop's. A menu adds: `nativeName` (味噌ラーメン), an
`illustration`, `allergens` (values of `Base\Enum\Allergen`), `diets`
(`Enum\Diet`), `spice` (0-3), a `section` (`MenuSection`, an omnibase taxon
with its order and native name), the `station` that makes it (`Enum\Station`:
hot, cold, bar), `tableOrder`. `SetMenu` is a dish whose `courses` are listed,
served at one `MealService` when it says so. The CRUD screens extend the
shop's product screen.

Prices shown are VAT included (`marketplace_price_with_vat(dish)`,
omnibase's `format_currency`).

| Route | |
|---|---|
| `restaurant_menu` `/carte` | by section; a filter hides the dishes holding the allergens ticked, or not suiting the diets ticked (on the page: nothing is sent) |
| `restaurant_menu_print` `/carte/imprimer` | the same on paper, allergen numbers and their key |

A dish run out on the pass (`Dish::runOut()`, `ProductAvailability::OUT_OF_STOCK`)
stays on the menu, greyed, cannot be ordered, and is suspended on the
platforms (`Event\DishAvailabilityEvent`).

# Ordering at the table

`/t/{token}` is what a table's QR code opens: the menu with a stepper per
dish, the round being put together (kept on the phone until sent), "Envoyer
en cuisine". No account. The first round opens the table's `Session` (its
bill); each round is a `Ticket` with its `TicketLine`s (name and price
copied when sent). The guests see where each round stands (polled), call the
waiter, ask for the bill; the bill asked for, the phones send no more rounds.

| Route | |
|---|---|
| `restaurant_table` | the page |
| `restaurant_table_round` `POST /t/{token}/tournee` | JSON, bound with `#[MapRequestPayload]` to `Model\Round` (`{lines: [{dish, quantity, note?, options?}], note?, covers?}`) |
| `restaurant_table_call`, `restaurant_table_bill` | the waiter, the bill |
| `restaurant_table_state` | the table's state, for the poll |

Guards: the token is the only key (a renewed token locks the old poster
out); posts are JSON only; `symfony/rate-limiter` counts per table and
address (`restaurant.table.rate_limit` a minute); each line is checked
against the menu; the pass can close a table to orders.

A ticket moves along `Enum\TicketStatus`:

```
new ─▶ accepted ─▶ preparing ─▶ ready ─▶ served
 │         └──────────┴──────────┴─▶ cancelled
 └─▶ refused | cancelled
```

`Ticket::moveTo()` refuses a step the status does not allow
(`TransitionException`); each move dispatches `Event\TicketMovedEvent`.
Tickets are independent of omnibase/marketplace's orders: a ticket is the
kitchen's paper.
