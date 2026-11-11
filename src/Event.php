<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

final class Event {
	private string $id;
	private array $data;

	private function __construct(string $id, array $data) {
		$this->id = $id;
		$this->data = $data;
	}

	public static function create(
		string  $event,
		array   $properties = [],
		?string $anonymousId = null,
		?string $userId = null,
		?string $sessionId = null,
		?string $url = null,
		?string $userAgent = null
	): self {
		$id = self::uuidV4();
		$timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');

		$data = [
			'event_id' => $id,
			'event' => $event,
			'timestamp' => $timestamp,
			'properties' => $properties === [] ? new \stdClass() : $properties,
		];
		$optional = [
			'anonymous_id' => $anonymousId,
			'user_id' => $userId,
			'session_id' => $sessionId,
			'url' => $url,
			'user_agent' => $userAgent,
		];
		foreach ($optional as $key => $value) {
			if ($value !== null) {
				$data[$key] = $value;
			}
		}

		return new self($id, $data);
	}

	public function id(): string {
		return $this->id;
	}

	public function toArray(): array {
		return $this->data;
	}

	private static function uuidV4(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		$hex = bin2hex($bytes);

		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
	}
}
