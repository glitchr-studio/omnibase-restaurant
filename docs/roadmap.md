---
title: Roadmap
order: 9
---

# Where it stands

Done with omnibase/marketplace's third pass (`Entity\Order\Pickup`,
`Product\OptionGroup`): the take-home dishes, the cold chain, the tracking
page, the shop's orders on the pass, the dishes' options at the table
([The take-home shop](take-home.md), [The menu and ordering at the table](menu-and-table.md)).

On omnibase/marketplace's quick order (`Service\QuickOrder`), loaded only
where it exists: the order without an account, the bill paid from a phone.

# What is left

| To come | Why not yet |
|---|---|
| A bill with paid options, paid from the phone | `QuickOrder::buy()` prices lines from the products alone: it takes neither options nor a prepared line. Such a bill is settled at the till. |
| A hand-over charge as a charge, not a line | `QuickOrder::buy()` offers nothing between the order's making and its payment: the delivery and the parcel are sold as a product (`fee_product`). |
| The chilled parcel booked by itself | The label is the staff's to book (`Shipping::book()`); booking it when the ticket is ready, with the basket's use-by date, waits for a Chronofresh contract to try it on. |
| Options on take-home dishes | same limit of `buy()`. |
