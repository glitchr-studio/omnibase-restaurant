---
title: The take-home shop
order: 6
---

# The take-home shop

Fresh dishes to cook at home, sold beside the menu: `/traiteur`. The shop is
omnibase/marketplace's (products, prices before VAT, VAT, payment, the
hand-over `Entity\Order\Pickup`); what a restaurant adds is the dish, the cold
chain, and the pass.

## TakeHome: a fresh dish

`Entity\Product\TakeHome` extends the marketplace's `Product` (table
`restaurantProductTakeHome`): `nativeName`, `illustration`, `allergens`
(`Base\Enum\Allergen`), `chilled` and `storageTemperature` (°C at most),
`shelfLife` (days it keeps once made: `useBy($madeOn)`), `netWeight` (g),
`portions`, `cookingTime` (min), `cookingSteps` (by language:
`{fr: […], en: […]}`), `parcel` (may travel in a chilled parcel),
`shopPosition`. Back office: `TakeHomeCrudController`.

Food taken home that is not eaten at once is at 5.5 % in France, the menu at
10 %: give the take-home dishes a product taxon and a VAT scoped to it (a
taxon's rate wins over the store's).

| Route | |
|---|---|
| `restaurant_takehome` `/traiteur` | the dishes, and the order form where the shop takes orders |
| `restaurant_takehome_dish` `/traiteur/{slug}` | a dish: how it keeps, how to cook it |
| `restaurant_takehome_order` `POST /traiteur/commander` | the order (see below) |
| `restaurant_tracking` `/suivi/{token}` | the order followed, without an account |

## The cold chain

`Service\ColdChain` says how a basket may leave, and when - the marketplace
offers every way to every basket, it does not know what keeps:

| Way | Offered when |
|---|---|
| collected at the restaurant (`pickup`) | `restaurant.takehome.pickup`; on a slot of `marketplace.pickup.slots` |
| brought nearby (`delivery`) | the postcode is in `restaurant.delivery.zip_codes` ("67300", or "674*"), the basket reaches `restaurant.delivery.minimum` |
| a chilled parcel (`shipping`) | a carrier is configured; **every** dish of the basket travels (`TakeHome::isParcel()`); the shortest shelf life covers `transit_days` + `margin_days`; the day asked is one of `ship_days` (Monday to Wednesday: a parcel that left later would spend the weekend in a depot) |

The dishes are made for the day asked: `notice_days` (1: tomorrow at the
earliest) applies to every way.

```php
$coldChain->options($lines, $postcode);                 // ['pickup' => HandOver, 'delivery' => …, 'shipping' => …]: available, days, reason
$coldChain->check(PickupMode::SHIPPING, $lines, $day);  // RestaurantException takehome.error.parcel_day, .parcel_dish, .parcel_shelf_life, .too_soon…
$coldChain->shippingDays();                             // the next days a parcel may leave
$coldChain->useBy($lines, $madeOn);                     // the basket's use-by date: its shortest
```

```yaml
restaurant:
    takehome: { enabled: true, pickup: true, notice_days: 1, max_quantity: 20 }
    delivery:
        zip_codes: ['67000', '67300', '674*']
        minimum: 2000                    # cents before VAT
        fee_product: livraison-locale    # slug of the product charged for it; null: free
    parcel:
        shipping_method: colis-frais     # slug of the shop's ShippingMethod; its carrier is a glitchr/omnibus gateway
        fee_product: colis-frais
        ship_days: [1, 2, 3]             # ISO weekdays
        transit_days: 1
        margin_days: 2                   # days of use-by left on arrival
        cutoff: '12:00'                  # with notice_days 0
marketplace:
    pickup: { slots: { from: '11:30', to: '19:30', step: 30 }, notice: 60, horizon: 10, timezone: 'Europe/Paris' }
```

**The chilled parcel's carrier** (`Service\ParcelCarrier`): the shipping
method named, whose `gatewayName` is a gateway of `omnibus.gateways` -
omnibus/chronopost, with `product: fresh` (Chronofresh) in the method's
parameters (`omnibus.<slug>`). Without the method, the package or the
carrier's account, the parcel is not offered at all. The order is taken and
its hand-over written; **the label is not booked by the bundle**: the staff
books it (`Shipping::book()`), with the basket's use-by date as `use_by`.
Chronofresh was not verified against a real contract.

## An order without an account

Where omnibase/marketplace has its quick order (`Service\QuickOrder`), the
shop's form takes the whole order: how many of each dish, the way, the day
and slot, a name, an e-mail address, a phone. `Service\TakeHomeOrders::place()`
checks the cold chain, makes the order for the address (`QuickOrder::buy()`:
the member it belongs to, or a new one), takes the payment with the shop's
first able gateway, writes the hand-over (`Pickups::open()`), and the
customer lands on `/suivi/{token}` - or on the provider's page first, then
back there (`EventListener\QuickOrderListener`, on `QuickOrderDoneEvent`).

A delivery or a parcel is charged as **a line of the order** (the product
named by `fee_product`): a quick order has no shipping charge of its own.
The form works without JavaScript; a refusal comes back as a flash message
with what was typed. It is `Form\TakeHomeOrderType` on `Model\TakeHomeOrder`, a
root without a name (its fields keep the page's names: `email`, `lines[12]`,
`day`), guarded as glitchr/omnibase guards a public form - its option `guard`,
action `takehome`: a trap, the time it takes, the lists, the captcha when the
site has glitchr/omniguard. The page writes its fields by hand and prints the
guard's (trap, stamp, captcha) and the token from the form's view, before the
button. A test posts those hidden fields as the page prints them
(`tests/TakeHomeGuardTest.php`). Without the quick order, the page lists the dishes and
says to order at the counter.

Prices are kept before VAT and the VAT is counted on each line's total: two
dishes shown at 9,90 € make 19,79 €.

## On the pass, and followed

A hand-over received - its order is paid - holding dishes of the restaurant
becomes a ticket of the `takeaway` channel (`Service\TakeawayTickets`, on
`PickupChangedEvent`): the customer's name, the time, the dishes (a delivery
charge is not one), to accept and prepare like any other. As the ticket
moves, so does the hand-over: accepted, in preparation, ready, then handed
over - or on its way, for a delivery or a parcel. `/suivi/{token}` shows it,
with the basket's use-by date; it asks again every half minute while the
order is open. This works for any paid order with a `Pickup`, whoever made
it.
