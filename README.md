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
	['order_id' => '1001', 'total' => 12500, 'currency' => 'EUR'],
	Visitor::anonymousId($_COOKIE),
	'42'
));

if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
}
$client->flush();
```

## Events reference

### Event::create arguments

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

### Properties

The collector does not check property contents. The tables below are the convention the WooCommerce plugin and the browser tracker follow; nothing enforces them. Money is an integer in minor units, `round(price * 10 ** decimals)`, so 125.00 RSD is `12500`. `currency` is an ISO 4217 code such as `RSD`.

#### order_completed

| Property | Type | Meaning |
|---|---|---|
| `order_id` | int | Shop order id |
| `items` | list | One entry per order line |
| `items[].product_id` | int | Product id |
| `items[].variation_id` | int | Variation id. The plugin sends `0` for a product without variations |
| `items[].quantity` | int | Units on the line |
| `items[].unit_price` | int | Line total divided by `max(1, quantity)`: discounted, excluding tax |
| `revenue` | int | Order total |
| `currency` | string | ISO code |

Send it once per order. The plugin guards this with order meta `_ci_order_completed_tracked`.

#### payment_confirmed

| Property | Type | Meaning |
|---|---|---|
| `order_id` | int | Shop order id |
| `amount` | int | Amount paid, the plugin sends the order total |
| `currency` | string | ISO code |
| `method` | string | Payment method id (`$order->get_payment_method()`), for example `bacs` |

#### order_cancelled

| Property | Type | Meaning |
|---|---|---|
| `order_id` | int | Shop order id |
| `reason` | string | Cancellation note from the status transition, empty string when none |

#### order_refunded

| Property | Type | Meaning |
|---|---|---|
| `order_id` | int | Shop order id |
| `refund_id` | int | Refund id |
| `amount` | int | Refunded amount, positive |
| `currency` | string | ISO code |
| `items` | list | Refunded lines, empty for an amount only refund |
| `items[].product_id` | int | Product id |
| `items[].variation_id` | int | Variation id, `0` when none |
| `items[].quantity` | int | Refunded units, positive |
| `items[].amount` | int | Refunded amount for the line, positive |

#### product_added_to_cart

| Property | Type | Meaning |
|---|---|---|
| `product_id` | int | Product id |
| `variation_id` | int | Variation id, `0` when none |
| `quantity` | int | Units added |
| `unit_price` | int | Price per unit |
| `currency` | string | ISO code |

#### product_removed_from_cart

| Property | Type | Meaning |
|---|---|---|
| `product_id` | int | Product id |
| `variation_id` | int | Variation id, `0` when none |
| `quantity` | int | Units removed |

#### user_identified

No properties. Pass the customer id as `$userId` and the visitor id as `$anonymousId`, which links the two. The plugin sends it on login and registration.

#### Browser events

These are normally sent by the browser tracker. A server may send them too. Their types are not fixed anywhere: the tracker README gives only the names, and its examples use a string `product_id` (`'12345'`) where the plugin sends an int.

| Event | Properties |
|---|---|
| `product_viewed` | `product_id`, `category` |
| `category_viewed` | `category_id`, `page` |
| `product_compared` | `product_ids` |
| `search_performed` | `query`, `filters`, `result_count` |
| `filter_changed` | `category_id`, `filters`, `result_count` |
| `cart_viewed` | `items`, `total`, `currency` |
| `checkout_started` | `items`, `total`, `currency` |
| `recommendation_clicked` | `recommendation_id`, `product_id` |
| `recommendation_purchased` | Not defined yet |

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
