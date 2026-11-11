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
