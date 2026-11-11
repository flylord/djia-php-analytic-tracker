<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class Client {
	private const MAX_BODY_BYTES = 1048576;
	private const ENVELOPE_BYTES = 8;

	private Config $config;
	private Outbox $outbox;
	private Transport $transport;
	private $onError;
	private ?string $lockPath;

	public function __construct(Config $config, Outbox $outbox, ?Transport $transport = null, ?callable $onError = null, ?string $lockPath = null) {
		$this->config = $config;
		$this->outbox = $outbox;
		$this->transport = $transport ?? new CurlTransport();
		$this->onError = $onError;
		$this->lockPath = $lockPath;
	}

	public function track(Event $event): void {
		try {
			$this->outbox->push($event->id(), $event->toArray());
		} catch (\Throwable $e) {
			$this->report($e);
		}
	}

	public function flush(int $max = 100): int {
		$lock = null;
		try {
			$path = $this->lockPath ?? sys_get_temp_dir() . '/ci-sdk-' . md5($this->config->endpoint() . $this->config->keyId()) . '.lock';
			$lock = @fopen($path, 'c');
			if ($lock === false) {
				$this->report(new \RuntimeException('Flush lock file could not be opened, continuing unlocked'));
			} elseif (!flock($lock, LOCK_EX | LOCK_NB)) {
				return 0;
			}

			return $this->send($max);
		} catch (\Throwable $e) {
			$this->report($e);

			return 0;
		} finally {
			if (is_resource($lock)) {
				@flock($lock, LOCK_UN);
				@fclose($lock);
			}
		}
	}

	private function send(int $max): int {
		$pending = $this->outbox->peek(max(0, min($max, 500)));
		$encoded = [];
		$unsendable = [];
		$size = self::ENVELOPE_BYTES;

		foreach ($pending as $id => $payload) {
			$json = json_encode($this->normalise($payload));
			$id = (string)$id;
			if ($json === false || self::ENVELOPE_BYTES + strlen($json) > self::MAX_BODY_BYTES) {
				$unsendable[] = $id;
				continue;
			}
			$added = strlen($json) + ($encoded === [] ? 0 : 1);
			if ($size + $added > self::MAX_BODY_BYTES) {
				break;
			}
			$size += $added;
			$encoded[$id] = $json;
		}

		if ($unsendable !== []) {
			$this->report(new \RuntimeException('Dropped ' . count($unsendable) . ' unsendable event(s): ' . implode(',', $unsendable)));
			$this->outbox->remove($unsendable);
		}
		if ($encoded === []) {
			return 0;
		}

		$body = '{"e":[' . implode(',', $encoded) . ']}';
		$timestamp = (string)time();
		$headers = [
			'Content-Type: application/json',
			'X-CI-Key-Id: ' . $this->config->keyId(),
			'X-CI-Timestamp: ' . $timestamp,
			'X-CI-Signature: ' . Signer::sign($this->config->secret(), $timestamp, $body),
		];
		$responseBody = null;
		$status = $this->transport->post($this->config->endpoint() . '/v1/s', $headers, $body, $this->config->timeoutSeconds(), $responseBody);
		if ($status === 0) {
			$this->report(new \RuntimeException('Collector request failed with a network error'));

			return 0;
		}
		if ($status < 200 || $status > 299) {
			$this->report(new \RuntimeException('Collector answered HTTP ' . $status));

			return 0;
		}

		$ids = array_map('strval', array_keys($encoded));
		$this->outbox->remove($ids);
		$this->reportRejected($responseBody);

		return count($ids);
	}

	private function reportRejected(?string $responseBody): void {
		$decoded = $responseBody === null ? null : json_decode($responseBody, true);
		if (is_array($decoded) && isset($decoded['rejected']) && is_int($decoded['rejected']) && $decoded['rejected'] > 0) {
			$this->report(new \RuntimeException('Collector rejected ' . $decoded['rejected'] . ' event(s)'));
		}
	}

	private function normalise(array $payload): array {
		if (array_key_exists('properties', $payload) && $payload['properties'] === []) {
			$payload['properties'] = new \stdClass();
		}

		return $payload;
	}

	private function report(\Throwable $error): void {
		if ($this->onError === null) {
			return;
		}
		try {
			($this->onError)($error);
		} catch (\Throwable $ignored) {
			return;
		}
	}
}
