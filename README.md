# Commerce Intelligence PHP SDK

Plain PHP library that sends signed server events (orders, payments, refunds) to the Commerce Intelligence collector. It supports PHP 7.4 and later and needs `ext-curl` and `ext-json`.

## Install

The package is not on Packagist. Require it through a Composer path repository in the consuming project:

```json
{
	"repositories": [
		{ "type": "path", "url": "../path/to/app/php-sdk", "options": { "symlink": false } }
	],
	"require": {
		"commerce-intelligence/php-sdk": "@dev"
	}
}
```

After changing the SDK, run `composer update commerce-intelligence/php-sdk` in the consuming project so its `composer.lock` and `vendor/` pick up the new code.

## Usage

```php
use CommerceIntelligence\Sdk\Client;
use CommerceIntelligence\Sdk\Config;
use CommerceIntelligence\Sdk\Event;
use CommerceIntelligence\Sdk\FileOutbox;
use CommerceIntelligence\Sdk\Visitor;

$config = new Config('http://ci-collector:8080', 'sk_live_1', 'the-shared-secret', 2.0);
$outbox = new FileOutbox('/var/lib/myshop/ci-outbox');
$client = new Client($config, $outbox, null, function (\Throwable $error): void {
	error_log('ci sdk: ' . $error->getMessage());
});

$client->track(Event::create(
	'order_completed',
	['order_id' => '1001', 'items' => [['product_id' => '55', 'quantity' => 1, 'unit_price' => 12500]], 'revenue' => 12500, 'currency' => 'EUR'],
	Visitor::anonymousId($_COOKIE),
	'42'
));

if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
}
$client->flush();
```

## Event::create arguments

`Event::create($event, $properties, $anonymousId, $userId, $sessionId, $url, $userAgent)`

| Argument | Type | Required | Collector rule |
|---|---|---|---|
| `$event` | string | yes | One of the 16 names below, otherwise rejected (`unknown event "..."`) |
| `$properties` | array | no | Sent as a JSON object, at most 16 KiB. Empty becomes `{}`. A list array is rejected |
| `$anonymousId` | ?string | no | Max 128 bytes. Pass `Visitor::anonymousId($_COOKIE)` so the event joins the browser session |
| `$userId` | ?string | no | Max 128 bytes. The shop customer id as a string |
| `$sessionId` | ?string | no | Max 128 bytes |
| `$url` | ?string | no | Truncated to 2048 bytes |
| `$userAgent` | ?string | no | Truncated to 512 bytes |

The SDK fills `event_id` (UUID v4) and `timestamp` (now, UTC). The collector keeps a server timestamp up to 30 days old. An older one, or one more than 5 minutes ahead, is replaced with the receive time. The SDK checks none of these rules: the collector rejects a bad event inside a 200 answer and the rejection reaches the `onError` hook.

## Event properties

Copy of the contract in `.project_docs/events.md`, which is canonical. The collector does not check property contents, so this is a contract, not validation.

### Rules for every event

- **Money** is an integer in minor units by the currency's ISO 4217 exponent, not by the shop's display decimals. RSD and EUR have exponent 2, so 125.00 RSD is `12500`, and a shop that displays RSD without decimals still sends `12500`. JPY has exponent 0.
- **`currency`** is an ISO 4217 code, for example `RSD`.
- **Totals** (`revenue`, `payment_confirmed.amount`, `total`) are the grand total the customer pays: products after discounts, plus shipping, including tax.
- **`unit_price`** is the price the customer pays per unit: after discounts, including tax.
- **Ids** (`order_id`, `product_id`, `variation_id`, `refund_id`, `category_id`, `recommendation_id`) are strings, even when the shop uses numbers.
- **`variation_id`** is optional. Omit it when the product has no variation. Never send `0` or `null`.
- **Extra properties** are allowed. The tables list the minimum set; a sender may add its own keys.
- **Required** in the tables means a reader may rely on it. Optional keys are omitted when there is no value, not sent as `null`.

### Item

`order_completed.items`, `cart_viewed.items` and `checkout_started.items` use this shape:

| Property | Type | Required | Meaning |
|---|---|---|---|
| `product_id` | string | yes | Product id |
| `variation_id` | string | no | Variation id, omitted when none |
| `quantity` | int | yes | Units |
| `unit_price` | int | yes | Price per unit, minor units, after discounts, including tax |

### Server events

Sent by the shop server through the PHP SDK, never by the browser.

#### order_completed

| Property | Type | Required | Meaning |
|---|---|---|---|
| `order_id` | string | yes | Shop order id |
| `order_number` | string | no | Order number shown to the customer, when it differs from `order_id` |
| `items` | list of Item | yes | One entry per order line |
| `revenue` | int | yes | Grand total: products, shipping and tax |
| `shipping` | int | no | Shipping part of `revenue`, including tax |
| `currency` | string | yes | ISO code |

Send it exactly once per order. The collector deduplicates only by `event_id`, and every `Event::create()` makes a new one, so a second send is stored as a second order. The sender keeps a "tracked" flag on the order and skips the event when it is set.

#### payment_confirmed

| Property | Type | Required | Meaning |
|---|---|---|---|
| `order_id` | string | yes | Shop order id |
| `order_number` | string | no | Order number shown to the customer |
| `amount` | int | yes | Amount paid: the grand total, as in `revenue` |
| `currency` | string | yes | ISO code |
| `method` | string | yes | Payment method id, for example `bacs` |

#### order_cancelled

| Property | Type | Required | Meaning |
|---|---|---|---|
| `order_id` | string | yes | Shop order id |
| `order_number` | string | no | Order number shown to the customer |
| `status` | string | yes | Why the order was cancelled, one value from the list below |
| `reason` | string | no | Free text cancellation note, omitted when none |

`status` values. Every site maps its own statuses to exactly one of these; a value outside the list breaks reports.

| Value | Use when |
|---|---|
| `customer_cancelled` | The customer cancelled or gave up on the order |
| `payment_failed` | The payment was declined or failed |
| `payment_timeout` | The order was not paid in time and was cancelled automatically |
| `out_of_stock` | The shop cancelled because it could not supply the goods |
| `merchant_cancelled` | The shop cancelled for any other reason, for example suspected fraud or an undeliverable address |
| `other` | None of the above fits, or the cause is unknown |

#### order_refunded

| Property | Type | Required | Meaning |
|---|---|---|---|
| `order_id` | string | yes | Shop order id |
| `order_number` | string | no | Order number shown to the customer |
| `refund_id` | string | yes | Refund id |
| `amount` | int | yes | Refunded amount, positive |
| `currency` | string | yes | ISO code |
| `items` | list | no | Refunded lines, omitted for an amount only refund |
| `items[].product_id` | string | yes | Product id |
| `items[].variation_id` | string | no | Variation id, omitted when none |
| `items[].quantity` | int | yes | Refunded units, positive |
| `items[].amount` | int | yes | Refunded amount for the line, positive, including tax |

### Events sent by either side

#### product_added_to_cart

| Property | Type | Required | Meaning |
|---|---|---|---|
| `product_id` | string | yes | Product id |
| `variation_id` | string | no | Variation id, omitted when none |
| `quantity` | int | yes | Units added |
| `unit_price` | int | yes | Price per unit, minor units, after discounts, including tax |
| `currency` | string | yes | ISO code |

#### product_removed_from_cart

| Property | Type | Required | Meaning |
|---|---|---|---|
| `product_id` | string | yes | Product id |
| `variation_id` | string | no | Variation id, omitted when none |
| `quantity` | int | yes | Units removed |

#### user_identified

No properties. It links `user_id` (the shop customer id) to `anonymous_id` (the `ci_aid` visitor id). In the browser, `djia_ci('identify', userId)` sends it once per visitor and user: calling it on every page sets `user_id` on each event but sends `user_identified` only when the user differs from the last one identified in this browser. On the server, pass both to `Event::create()`. One side is enough; the WooCommerce plugin sends it on login and registration.

### Browser events

Sent by the browser tracker.

#### product_viewed

| Property | Type | Required | Meaning |
|---|---|---|---|
| `product_id` | string | yes | Product id |
| `category` | string | no | Category id or name of the product |

#### category_viewed

| Property | Type | Required | Meaning |
|---|---|---|---|
| `category_id` | string | yes | Category id |
| `page` | int | no | Listing page number, starting at 1 |

#### product_compared

| Property | Type | Required | Meaning |
|---|---|---|---|
| `product_ids` | list of string | yes | Products in the comparison |

#### search_performed

| Property | Type | Required | Meaning |
|---|---|---|---|
| `query` | string | yes | Search text |
| `filters` | object | no | Active filters, filter name to value or list of values |
| `result_count` | int | no | Number of results |

#### filter_changed

| Property | Type | Required | Meaning |
|---|---|---|---|
| `category_id` | string | no | Category being filtered |
| `filters` | object | yes | Filters after the change, filter name to value or list of values |
| `result_count` | int | no | Number of results |

#### cart_viewed

| Property | Type | Required | Meaning |
|---|---|---|---|
| `items` | list of Item | yes | Cart lines |
| `total` | int | yes | Cart total as shown, including tax |
| `currency` | string | yes | ISO code |

#### checkout_started

| Property | Type | Required | Meaning |
|---|---|---|---|
| `items` | list of Item | yes | Cart lines |
| `total` | int | yes | Cart total as shown, including tax |
| `currency` | string | yes | ISO code |

#### recommendation_clicked

| Property | Type | Required | Meaning |
|---|---|---|---|
| `recommendation_id` | string | yes | Id of the recommendation shown |
| `product_id` | string | yes | Product clicked |

#### recommendation_purchased

Not defined yet. The recommendation engine does not exist, so nothing sends it.
## Behaviour

- `track()` only writes the event to the outbox. It never sends anything and never throws.
- `flush()` sends the queued events. Call it at the end of the request, after `fastcgi_finish_request()` when available, or from a cron job. Events stay in the outbox until the collector accepts them.
- `flush()` never throws. Failures go to the `onError` hook (the fourth `Client` argument): network errors, non 2xx answers (`Collector answered HTTP 503`), events the collector rejected inside a 200
  answer, dropped unsendable events and outbox problems. The hook is optional, and an exception thrown by it is swallowed.
- `Visitor::anonymousId($_COOKIE)` returns the value of the `ci_aid` cookie set by the browser tracker, or `null` when it is missing or invalid. Pass it so server events join the browser session.
- One request carries at most 500 events and 1 MiB of body. `flush($max)` caps the batch at 500, and a batch is cut earlier if it would exceed 1 MiB. Further events go out on the next flush.
- Concurrent flushes are serialised with a lock file, so a second flush returns 0 while another one runs.
- The HTTP timeout is `Config`'s fourth argument in seconds, with a floor of 100 ms.

## Tests

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpunit --group integration
```

The integration group needs the collector from `Dockers/` running.
