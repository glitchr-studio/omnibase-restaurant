---
title: The platforms
order: 6
---

# The platforms (glitchr/omnifood)

Loaded only when `Omnifood\Registry` exists (`config/services.php`); without
it the webhook answers 404 and the pass shows no platform.

```yaml
# config/packages/omnifood.yaml
omnifood:
    platforms:
        ubereats: { factory: ubereats, options: { client_id: '%env(default::UBEREATS_CLIENT_ID)%', client_secret: '%env(default::UBEREATS_CLIENT_SECRET)%', store_id: '%env(default::UBEREATS_STORE_ID)%' } }
        thefork:  { factory: thefork,  options: { client_id: '%env(default::THEFORK_CLIENT_ID)%', client_secret: '%env(default::THEFORK_CLIENT_SECRET)%', restaurant_id: '%env(default::THEFORK_RESTAURANT_ID)%' } }
```

**Keys typed in the back office.** `Omnifood\FoodKeysSection` declares, on
omnibase/admin's API keys page, the fields of each configured platform
(`api.food.<platform>.<option>`); `Omnifood\Platforms` builds each platform
with them over the configured options (`Registry::create()`).

**Webhooks.** `POST /restaurant/plateforme/{name}/webhook`
(`restaurant_platform_webhook`): the platform's package checks the signature
- 401 and nothing done when it does not hold -; an order becomes a ticket of
channel `platform` (`Omnifood\OrderTickets`: reference, short number, lines
tied to the menu's dishes by their slug - the item reference
`Omnifood\MenuExport` sends -, modifiers as options, the acceptance
deadline); a reservation joins the book with the platform as its source. An
event already seen (`Notification::$id`, kept in `PlatformEvent`) makes
nothing; one whose order could not be read answers 502 and is tried again.

**Answers.** `Omnifood\PlatformSubscriber` tells the platform what the pass
does: accepted (with a ready time), denied, ready, cancelled; a dish run out
or back on every platform taking a menu; a platform's reservation seated,
finished, absent, cancelled. A platform that fails is logged; the pass goes on.

```sh
bin/console restaurant:menu:push [platform] [--dry-run] [--photos=https://site/assets/images]
bin/console restaurant:reservations:sync [--days=30]      # from the cron container
```

Not verified against the live APIs: the platforms give no credentials without
a partner agreement (see glitchr/omnifood's own notes).
