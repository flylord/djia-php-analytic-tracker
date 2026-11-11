<?php

declare(strict_types=1);

namespace CommerceIntelligence\Sdk;

interface Outbox {
	public function push(string $eventId, array $payload): void;

	/**
	 * @return array<string, array> event id to payload, oldest first
	 */
	public function peek(int $limit): array;

	/**
	 * @param string[] $eventIds
	 */
	public function remove(array $eventIds): void;
}
