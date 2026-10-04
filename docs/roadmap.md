---
title: Roadmap
order: 9
---

# What waits for omnibase/marketplace

These parts need changes in omnibase/marketplace, written elsewhere; the
bundle leaves a hook for each and does not work around them.

| To come | Needs in omnibase/marketplace | Hook here |
|---|---|---|
| Take-home dishes: `Entity\Product\TakeHome` (chilled, storage temperature, use-by days, portions, cooking steps) | - | a product class beside `Dish`; `DishCrudController::menuFields()` is reusable |
| Pickup on a slot, local delivery, chilled parcels | `Entity\Order\Pickup` | `Service\ColdChain` to filter the hand-over modes (zip codes in `restaurant.delivery.zip_codes`, parcels only when the whole basket travels and the use-by date covers the delay, shipped Monday to Wednesday); `/suivi/{token}` |
| Take-away orders on the pass | `QuickOrder` published, `QuickOrderDoneEvent` | `TicketChannel::TAKEAWAY` exists; a listener turns a paid order into a ticket |
| Dish options (cooking, extras) | `Product\OptionGroup`, `Option` | `TicketLine::$options` and `Model\RoundLine::$options` already carry the choices as texts |
| Paying a table's bill from the phone | `QuickOrder::buy()` (an order without an account) | `restaurant.table.pay_online` (off); the `Session`'s billed tickets give the lines (`TicketLine::getDish()`, quantity); settled, call `Session::settle('online')` |
| Chilled parcels | Chronofresh products in `omnibus/chronopost` | - |
